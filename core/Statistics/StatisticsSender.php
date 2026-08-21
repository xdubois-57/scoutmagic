<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Statistics;

use Core\Config\SettingService;
use Core\Journal\JournalService;

/**
 * Reports this installation's usage statistics to the configured receiver,
 * at most once a day (ARCHITECTURE.md §8.47).
 *
 * Everything interesting here is a guard. The transmission itself is a
 * single authenticated POST; what makes this class worth reading is the
 * ordered list of situations in which it deliberately does nothing, and the
 * care taken never to write a secret — ours or the receiver's answer — into
 * a setting or the journal.
 */
class StatisticsSender
{
    public const ENDPOINT_PATH = '/api/statistics';

    /** At most one report per 24 h, whatever wakes the scheduler up. */
    private const MIN_INTERVAL_SECONDS = 86400;

    /** How much of a remote answer may ever be recorded as a failure reason. */
    private const MAX_REASON_LENGTH = 200;

    /**
     * Host suffixes that can never designate a real, public installation.
     * A test or staging clone reporting under the production installation's
     * identity would silently corrupt the receiver's view of it.
     */
    private const NON_PUBLIC_TLDS = ['.local', '.test', '.localhost', '.invalid', '.internal'];

    public function __construct(
        private SettingService $settingService,
        private StatisticsPayloadBuilder $payloadBuilder,
        private InstallationIdentityService $identityService,
        private StatisticsTransportInterface $transport,
        private JournalService $journalService,
        private \PDO $pdo,
        private string $appVersion
    ) {
    }

    public function send(): StatisticsSendResult
    {
        $guard = $this->firstFailingGuard();
        if ($guard !== null) {
            return $this->recordSkip($guard);
        }

        $destination = rtrim((string) $this->settingService->get('statistics_destination'), '/');

        // HTTPS or nothing. There is no fallback to cleartext, not even
        // "just this once": the bearer secret travels in a header, and a
        // downgrade would hand it to anyone on the path.
        if (!str_starts_with(strtolower($destination), 'https://')) {
            return $this->recordFailure('insecure_destination');
        }

        $secret = $this->identityService->getSecret();
        if ($secret === null || $secret === '') {
            return $this->recordFailure('secret_unavailable');
        }

        $payloadJson = $this->payloadBuilder->buildJson();
        $userAgent = 'ScoutMagic/' . $this->appVersion . ' (+statistics)';

        $startedAt = microtime(true);
        try {
            $response = $this->transport->post(
                $destination . self::ENDPOINT_PATH,
                $payloadJson,
                $secret,
                $userAgent
            );
        } catch (\Throwable $e) {
            return $this->recordFailure($this->redact('transport_error: ' . $e->getMessage(), $secret));
        }
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (!$response->isSuccessful()) {
            return $this->recordFailure($this->failureReasonFor($response, $secret), $durationMs);
        }

        return $this->recordSuccess((int) $response->statusCode, $durationMs);
    }

    /**
     * The guard sequence, in order. Returns the reason of the first guard
     * that stops the send, or null when the send may proceed.
     */
    private function firstFailingGuard(): ?string
    {
        if ($this->settingService->get('statistics_enabled') !== '1') {
            return 'disabled';
        }

        // "Development mode" is auto_update_level = 'dev' while automatic
        // updates are on (ARCHITECTURE.md §8.17) — the chantier document
        // named a `dev_update_enabled` setting that does not exist.
        if ($this->settingService->get('auto_update_enabled') === '1'
            && $this->settingService->get('auto_update_level') === 'dev'
        ) {
            return 'dev_mode';
        }

        $baseUrl = (string) ($this->settingService->get('base_url') ?? '');
        if (!self::isPublicHost($baseUrl)) {
            return 'non_public_host';
        }

        $destination = (string) ($this->settingService->get('statistics_destination') ?? '');
        if (DestinationMatcher::isReceiver($baseUrl, $destination)) {
            return 'self_destination';
        }

        if ($this->isMaintenanceInProgress()) {
            return 'maintenance_in_progress';
        }

        if ($this->sentWithinLastDay()) {
            return 'already_sent_today';
        }

        return null;
    }

    /**
     * Whether a URL's host is a real, publicly-resolvable name — not
     * localhost, not a bare IP literal, not one of the reserved
     * non-public TLDs.
     */
    public static function isPublicHost(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ((!is_string($host) || $host === '') && !str_contains($url, '://')) {
            $host = parse_url('https://' . $url, PHP_URL_HOST);
        }
        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(rtrim(trim($host, '[]'), '.'));

        if ($host === 'localhost') {
            return false;
        }

        // A literal address, v4 or v6, is never a public installation name
        // even when the address itself is routable — an installation
        // reachable only by IP has no stable identity to report under.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        foreach (self::NON_PUBLIC_TLDS as $tld) {
            if (str_ends_with($host, $tld)) {
                return false;
            }
        }

        // A single-label host ("intranet") is not a public name either.
        return str_contains($host, '.');
    }

    /**
     * D-02: an installation busy updating, restoring or resetting itself is
     * skipped rather than reported on — its database and files are mid-flight
     * and whatever we measured would be a snapshot of a half-applied state.
     */
    private function isMaintenanceInProgress(): bool
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM update_history WHERE status NOT IN ('completed', 'failed', 'rolled_back')"
            );
            if ($stmt !== false && (int) $stmt->fetchColumn() > 0) {
                return true;
            }

            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM scheduled_actions
                 WHERE module_id = 'core'
                   AND task_key IN ('install_update', 'restore_backup', 'full_reset')
                   AND status IN ('pending', 'processing')"
            );
            $stmt->execute();

            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            // A database that cannot answer "is maintenance running?" is
            // itself a reason not to report right now.
            return true;
        }
    }

    private function sentWithinLastDay(): bool
    {
        $lastSuccess = $this->settingService->get(StatisticsStateSettings::LAST_SUCCESS_AT);
        if (!is_string($lastSuccess) || trim($lastSuccess) === '') {
            return false;
        }

        try {
            $timestamp = (new \DateTimeImmutable($lastSuccess))->getTimestamp();
        } catch (\Throwable) {
            return false;
        }

        return (time() - $timestamp) < self::MIN_INTERVAL_SECONDS;
    }

    private function recordSuccess(int $statusCode, int $durationMs): StatisticsSendResult
    {
        $this->writeSetting(StatisticsStateSettings::LAST_SUCCESS_AT, self::nowIso());

        // Context is the HTTP status and the duration, nothing else. The
        // installation id is not personal data and would be harmless, but
        // there is no reason to write it either.
        $this->journalService->log(
            'core',
            'statistics_sent',
            'info',
            'Statistiques d\'utilisation transmises',
            ['status' => $statusCode, 'duration_ms' => $durationMs]
        );

        return StatisticsSendResult::sent($statusCode, $durationMs);
    }

    private function recordFailure(string $reason, ?int $durationMs = null): StatisticsSendResult
    {
        $this->writeSetting(StatisticsStateSettings::LAST_FAILURE_AT, self::nowIso());
        $this->writeSetting(StatisticsStateSettings::LAST_FAILURE_REASON, $reason);

        $context = ['reason' => $reason];
        if ($durationMs !== null) {
            $context['duration_ms'] = $durationMs;
        }

        $this->journalService->log(
            'core',
            'statistics_send_failed',
            'warning',
            'Échec de la transmission des statistiques d\'utilisation',
            $context
        );

        return StatisticsSendResult::failed($reason);
    }

    private function recordSkip(string $reason): StatisticsSendResult
    {
        // A deliberate opt-out is not an event. Journaling it every single
        // day would fill the journal with the fact that nothing happened.
        if ($reason !== 'disabled') {
            $this->journalService->log(
                'core',
                'statistics_send_skipped',
                'info',
                'Transmission des statistiques d\'utilisation ignorée',
                ['reason' => $reason]
            );
        }

        // Only the skips that mean something is genuinely in the way are
        // surfaced on the Support page. "Reporting is off" and "already
        // reported today" are the normal state of a healthy installation,
        // and showing either as the latest problem would be misleading.
        if (!in_array($reason, ['disabled', 'already_sent_today'], true)) {
            $this->writeSetting(StatisticsStateSettings::LAST_FAILURE_AT, self::nowIso());
            $this->writeSetting(StatisticsStateSettings::LAST_FAILURE_REASON, $reason);
        }

        return StatisticsSendResult::skipped($reason);
    }

    /**
     * A short, sanitised reason for a non-2xx answer.
     *
     * The remote body is included because it is often the only clue what
     * went wrong — but capped, stripped of control characters, and scrubbed
     * of our own secret first. A receiver that echoes the bearer token back
     * in its error message must not be able to get it written into a
     * settings row a whole page then renders.
     */
    private function failureReasonFor(StatisticsTransportResponse $response, string $secret): string
    {
        if ($response->statusCode === null) {
            return $this->redact('transport_error: ' . (string) $response->errorMessage, $secret);
        }

        $reason = 'http_' . $response->statusCode;
        $body = trim($response->body);
        if ($body !== '') {
            $reason .= ': ' . $body;
        }

        return $this->redact($reason, $secret);
    }

    private function redact(string $reason, string $secret): string
    {
        if ($secret !== '') {
            $reason = str_ireplace($secret, '[REDACTED]', $reason);
        }

        $reason = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $reason);
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            $reason = mb_substr($reason, 0, self::MAX_REASON_LENGTH);
        }

        return $reason;
    }

    private function writeSetting(string $key, string $value): void
    {
        try {
            $this->settingService->setInternal($key, $value);
        } catch (\Throwable) {
            // Bookkeeping must never turn a completed send into a failure.
        }
    }

    private static function nowIso(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }
}
