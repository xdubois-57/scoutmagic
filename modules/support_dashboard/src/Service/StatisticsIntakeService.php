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
use Modules\SupportDashboard\Repository\SupportReportRateLimitRepository;

/**
 * Accepts, authenticates and stores one incoming usage report
 * (ARCHITECTURE.md §8.48).
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
        private JournalService $journal
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

            $this->journalAcceptance($installationId, true, $unknownFields);

            return StatisticsIntakeResult::accepted(true, $unknownFields);
        }

        if (!password_verify($secret, (string) $existing['secret_hash'])) {
            return $this->reject(StatisticsIntakeResult::REJECT_BAD_CREDENTIALS, 401, $clientIp);
        }

        $this->installations->recordReport((int) $existing['id'], $rawBody, $denormalized);
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
            'instance_url' => self::stringOrNull($payload['instance_url'] ?? null, 255),
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

    private static function stringOrNull(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? mb_substr($value, 0, $maxLength) : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private static function datetimeOrNull(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
