<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportMonthlyAggregateRepository;
use Modules\SupportDashboard\Repository\SupportReportRateLimitRepository;

/**
 * Accepts, authenticates and stores one incoming usage report
 * (ARCHITECTURE.md §8.49).
 *
 * The whole endpoint is an unauthenticated entry point until the bearer
 * token is checked, so the order of operations matters as much as the
 * checks themselves: transport, then size, then rate limit, then parse.
 * Nothing expensive runs before something cheap has had a chance to refuse.
 *
 * **Trust-on-first-use.** An unknown installation id is a first
 * registration: the secret it presents is hashed and kept, and every later
 * report must match it. That is the only model available — the sender
 * generates its own identity with nobody to vouch for it — and it makes the
 * exposure precise: an attacker can register a fake installation (noise in
 * a dashboard, deletable) but can never take over a real one.
 */
class StatisticsIntakeService
{
    /** A payload is ~2 KB; 64 KB is room for a decade of growth. */
    public const MAX_BODY_BYTES = 65536;

    /** A well-behaved sender reports once a day. Ten an hour is generous. */
    public const RATE_LIMIT_MAX_REQUESTS = 10;
    public const RATE_LIMIT_WINDOW_MINUTES = 60;

    private const SUPPORTED_SCHEMA_VERSIONS = [1];

    /** The largest value an `INT UNSIGNED` column will accept. */
    private const MAX_UNSIGNED_INT = 4294967295;

    /** The `DATETIME` range, which is narrower than PHP's date range. */
    private const MIN_DATETIME_YEAR = 1000;
    private const MAX_DATETIME_YEAR = 9999;

    /**
     * How many unknown top-level field names one rejected-shape warning may
     * name, and how long each may be. The names come verbatim from a
     * stranger's JSON, and a 64 KB body can carry a few thousand of them —
     * the journal entry is a warning about a sender being ahead of us, not
     * a place to mirror an arbitrary document.
     */
    private const MAX_UNKNOWN_FIELDS_REPORTED = 20;
    private const MAX_UNKNOWN_FIELD_LENGTH = 64;

    /**
     * The payload fields this receiver understands. Anything else is kept
     * in the raw JSON and warned about, never rejected.
     *
     * @var array<int, string>
     */
    private const KNOWN_TOP_LEVEL_FIELDS = [
        'statistics_schema_version', 'installation_id', 'instance_url', 'generated_at',
        'scoutmagic', 'scout_year', 'usage', 'modules', 'installation', 'runtime',
        'database', 'host', 'security', 'email', 'scheduler', 'updates', 'lifecycle', 'storage',
    ];

    public function __construct(
        private SupportInstallationRepository $installations,
        private SupportReportRateLimitRepository $rateLimits,
        private EncryptionService $encryption,
        private JournalService $journal,
        private ?SupportMonthlyAggregateRepository $monthlyAggregates = null
    ) {
    }

    /**
     * @param string $rawBody the request body, exactly as received
     * @param string $authorizationHeader the raw `Authorization` header
     * @param string $clientIp the source address, used for rate limiting
     *        and journaling only — never stored in clear
     */
    public function receive(string $rawBody, string $authorizationHeader, string $clientIp, bool $isSecureTransport): StatisticsIntakeResult
    {
        if (!$isSecureTransport) {
            return $this->reject(StatisticsIntakeResult::REJECT_INSECURE_TRANSPORT, 400, $clientIp);
        }

        // Size is checked BEFORE parsing, on the raw string: a 1 MB body
        // must cost a strlen(), not a JSON decode.
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return $this->reject(StatisticsIntakeResult::REJECT_PAYLOAD_TOO_LARGE, 413, $clientIp);
        }

        $ipHash = $this->hashIp($clientIp);
        if ($this->isRateLimited($ipHash)) {
            return $this->reject(StatisticsIntakeResult::REJECT_RATE_LIMITED, 429, $clientIp);
        }
        $this->rateLimits->record($ipHash);

        $secret = self::extractBearerToken($authorizationHeader);
        if ($secret === null) {
            return $this->reject(StatisticsIntakeResult::REJECT_MISSING_CREDENTIALS, 401, $clientIp);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !$this->isStructurallyValid($payload)) {
            return $this->reject(StatisticsIntakeResult::REJECT_MALFORMED, 400, $clientIp);
        }

        $installationId = (string) $payload['installation_id'];
        $existing = $this->installations->findByInstallationId($installationId);

        $denormalized = self::denormalize($payload);
        $unknownFields = self::unknownFieldsOf($payload);

        if ($existing === null) {
            $this->installations->register(
                $installationId,
                password_hash($secret, PASSWORD_DEFAULT),
                $rawBody,
                $denormalized
            );

            $this->recordMonthlyContribution($installationId);
            $this->journalAcceptance($installationId, true, $unknownFields);

            return StatisticsIntakeResult::accepted(true, $unknownFields);
        }

        if (!password_verify($secret, (string) $existing['secret_hash'])) {
            return $this->reject(StatisticsIntakeResult::REJECT_BAD_CREDENTIALS, 401, $clientIp);
        }

        $this->installations->recordReport((int) $existing['id'], $rawBody, $denormalized);
        $this->recordMonthlyContribution($installationId);
        $this->journalAcceptance($installationId, false, $unknownFields);

        return StatisticsIntakeResult::accepted(false, $unknownFields);
    }

    /**
     * Strict enough to refuse nonsense, loose enough never to refuse a
     * newer sender: the required shape is an installation id, a supported
     * schema version, and correctly-typed values for the fields this
     * receiver actually reads.
     *
     * @param array<string, mixed> $payload
     */
    private function isStructurallyValid(array $payload): bool
    {
        $installationId = $payload['installation_id'] ?? null;
        if (!is_string($installationId) || preg_match('/^[0-9a-zA-Z_-]{8,64}$/', $installationId) !== 1) {
            return false;
        }

        $version = $payload['statistics_schema_version'] ?? null;
        if (!is_int($version) || !in_array($version, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            return false;
        }

        foreach (['scoutmagic', 'usage', 'updates', 'lifecycle', 'scout_year', 'installation'] as $section) {
            if (array_key_exists($section, $payload) && !is_array($payload[$section])) {
                return false;
            }
        }

        if (array_key_exists('instance_url', $payload) && $payload['instance_url'] !== null) {
            if (!is_string($payload['instance_url']) || mb_strlen($payload['instance_url']) > 255) {
                return false;
            }
        }

        if (array_key_exists('modules', $payload) && $payload['modules'] !== null && !is_array($payload['modules'])) {
            return false;
        }

        return true;
    }

    /**
     * Notes that this installation contributed to the current calendar
     * month (ARCHITECTURE.md §8.51). Repeated reports in the same month
     * collapse to one contribution, enforced by the table's unique index.
     *
     * Deliberately never fatal: the history is a nice-to-have next to
     * actually accepting the report. A receiver that started refusing
     * reports because a bookkeeping insert failed would trade the thing
     * that matters for the thing that does not.
     */
    private function recordMonthlyContribution(string $installationId): void
    {
        try {
            $this->monthlyAggregates?->recordContribution(
                (new \DateTimeImmutable())->format('Y-m'),
                $installationId
            );
        } catch (\Throwable) {
            // Swallowed on purpose — see the docblock.
        }
    }

    /**
     * The denormalised columns, straight from the payload.
     *
     * **A missing or unreadable value becomes NULL, never 0 and never
     * false.** That is the same rule the sender applies when building the
     * payload, carried through to storage so the dashboard can keep telling
     * "no members" apart from "did not say".
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function denormalize(array $payload): array
    {
        return [
            'instance_url' => self::httpUrlOrNull($payload['instance_url'] ?? null, 255),
            'statistics_schema_version' => self::intOrNull($payload['statistics_schema_version'] ?? null),
            'scoutmagic_version' => self::stringOrNull(self::path($payload, ['scoutmagic', 'version']), 50),
            'is_dev_build' => self::boolOrNull(self::path($payload, ['scoutmagic', 'is_dev_build'])),
            'active_members' => self::intOrNull(self::path($payload, ['usage', 'active_members'])),
            'active_sections' => self::intOrNull(self::path($payload, ['usage', 'active_sections'])),
            'installation_method' => self::stringOrNull(self::path($payload, ['installation', 'method']), 30),
            'auto_update_enabled' => self::boolOrNull(self::path($payload, ['updates', 'auto_update_enabled'])),
            'auto_update_level' => self::stringOrNull(self::path($payload, ['updates', 'auto_update_level']), 20),
            'scout_year_label' => self::stringOrNull(self::path($payload, ['scout_year', 'label']), 50),
            'installed_at' => self::datetimeOrNull(self::path($payload, ['lifecycle', 'installed_at'])),
            'last_upgraded_at' => self::datetimeOrNull(self::path($payload, ['lifecycle', 'last_upgraded_at'])),
        ];
    }

    /**
     * Top-level fields this receiver does not know about. Never a reason to
     * reject — a sender one version ahead must keep working — but worth a
     * warning, and the values themselves survive in the stored raw JSON.
     *
     * Bounded in both count and per-name length: these names are a
     * stranger's text, and the journal entry exists to say "somebody is
     * ahead of us", not to copy an arbitrary document into `event_log`. A
     * truncated list ends with a count of what it left out, so the entry
     * never reads as complete when it is not.
     *
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    public static function unknownFieldsOf(array $payload): array
    {
        $unknown = [];
        foreach (array_keys($payload) as $field) {
            if (!in_array((string) $field, self::KNOWN_TOP_LEVEL_FIELDS, true)) {
                $unknown[] = (string) $field;
            }
        }

        $overflow = count($unknown) - self::MAX_UNKNOWN_FIELDS_REPORTED;
        if ($overflow > 0) {
            $unknown = array_slice($unknown, 0, self::MAX_UNKNOWN_FIELDS_REPORTED);
        }

        $unknown = array_map(
            static fn(string $field): string => mb_substr($field, 0, self::MAX_UNKNOWN_FIELD_LENGTH),
            $unknown
        );

        if ($overflow > 0) {
            $unknown[] = '… (+' . $overflow . ')';
        }

        return $unknown;
    }

    /**
     * The bearer token of an `Authorization` header, or null. Case-
     * insensitive on the scheme, as RFC 7235 requires.
     */
    public static function extractBearerToken(string $header): ?string
    {
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function isRateLimited(string $ipHash): bool
    {
        $since = (new \DateTimeImmutable('-' . self::RATE_LIMIT_WINDOW_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

        return $this->rateLimits->countSince($ipHash, $since) >= self::RATE_LIMIT_MAX_REQUESTS;
    }

    private function hashIp(string $clientIp): string
    {
        return $this->encryption->blindIndex('support_report_ip:' . $clientIp);
    }

    /**
     * A rejection is journaled with its source IP and a reason **category**
     * — the one place in this codebase where the spec asks for the raw
     * address, because an intake endpoint under abuse is unreadable without
     * it. The reason is never free text and never echoes the payload.
     */
    private function reject(string $reason, int $statusCode, string $clientIp): StatisticsIntakeResult
    {
        $this->journal->log(
            'support_dashboard',
            'statistics_report_rejected',
            'warning',
            'Rapport de statistiques rejeté',
            ['reason' => $reason, 'source_ip' => $clientIp]
        );

        return StatisticsIntakeResult::rejected($reason, $statusCode);
    }

    /**
     * @param array<int, string> $unknownFields
     */
    private function journalAcceptance(string $installationId, bool $firstRegistration, array $unknownFields): void
    {
        $context = [
            'installation_id' => $installationId,
            'first_registration' => $firstRegistration,
        ];
        if ($unknownFields !== []) {
            $context['unknown_fields'] = $unknownFields;
        }

        $this->journal->log(
            'support_dashboard',
            'statistics_report_received',
            $unknownFields === [] ? 'info' : 'warning',
            $unknownFields === []
                ? 'Rapport de statistiques accepté'
                : 'Rapport de statistiques accepté avec des champs inconnus',
            $context
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $path
     */
    private static function path(array $payload, array $path): mixed
    {
        $value = $payload;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * `instance_url`, but only when it really is one.
     *
     * This is the single field of an unauthenticated stranger's payload
     * that the dashboard turns into a clickable link, and a scheme is not a
     * detail there: `javascript:…` and `data:text/html,…` are perfectly
     * valid strings that Twig's HTML escaping does nothing about, so
     * storing one would put a superadmin one click away from running a
     * remote installation's script in the receiver's own origin. The
     * codebase's CSP (`script-src 'self' 'nonce-…'`, no `unsafe-inline`)
     * blocks the payload today; that is a mitigation, not a reason to
     * accept the value.
     *
     * Anything that is not `http://` or `https://` becomes NULL — the same
     * "not reported" the rest of this class uses — rather than rejecting
     * the whole report: the URL is one optional field among twenty, and a
     * sender whose `base_url` is a typo still has useful counters to
     * contribute. The verbatim value survives in the stored raw JSON, which
     * is where the detail dialog shows it as plain text.
     */
    private static function httpUrlOrNull(mixed $value, int $maxLength): ?string
    {
        $url = self::stringOrNull($value, $maxLength);
        if ($url === null) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    private static function stringOrNull(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? mb_substr($value, 0, $maxLength) : null;
    }

    /**
     * A counter, or NULL.
     *
     * Bounded on both ends, and that is not defensive decoration: every
     * integer column here is `INT UNSIGNED`, so a payload saying
     * `"active_members": -1` or `"active_members": 9000000000` is an
     * out-of-range write that MySQL refuses under strict mode. The
     * resulting PDOException escapes an endpoint whose whole contract is to
     * answer a status code and nothing else, turning a crafted 2 KB body
     * into a 500 on a `public` route. Out of range is "not reported",
     * exactly like a missing field.
     */
    private static function intOrNull(mixed $value): ?int
    {
        if (!is_int($value)) {
            return null;
        }

        return ($value >= 0 && $value <= self::MAX_UNSIGNED_INT) ? $value : null;
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * An instant, normalised to UTC, or NULL.
     *
     * Range-checked for the same reason as intOrNull(): `DATETIME` accepts
     * 1000-01-01 through 9999-12-31 and refuses anything else under strict
     * mode, so `"installed_at": "-5000-01-01"` — which `DateTimeImmutable`
     * parses perfectly happily — would otherwise reach the column and fault
     * the request.
     */
    private static function datetimeOrNull(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $normalized = (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }

        $year = (int) $normalized->format('Y');
        if ($year < self::MIN_DATETIME_YEAR || $year > self::MAX_DATETIME_YEAR) {
            return null;
        }

        return $normalized->format('Y-m-d H:i:s');
    }
}
