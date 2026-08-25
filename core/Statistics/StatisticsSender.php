<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Statistics;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Service\DateInput;

/**
 * Reports this installation's usage statistics to the configured receiver,
 * at most once a day (ARCHITECTURE.md §8.47).
 *
 * Everything interesting here is a guard. The transmission itself is a
 * single authenticated POST; what makes this class worth reading is the
 * ordered list of situations in which it deliberately does nothing, and the
 * care taken never to write a secret — ours or the receiver's answer — into
 * a setting or the journal.
 *
 * Two entry points share that POST: send(), the daily task, and sendTest(),
 * the button on Configuration > Support. They differ only in which guards
 * apply and in whether the outcome is written to the send-state settings —
 * see sendTest() for why each of those two differences exists.
 */
class StatisticsSender
{
    public const ENDPOINT_PATH = '/api/statistics';

    /** At most one report per 24 h, whatever wakes the scheduler up. */
    private const MIN_INTERVAL_SECONDS = 86400;

    /** How much of a remote answer may ever be recorded as a failure reason. */
    private const MAX_REASON_LENGTH = 200;

    /**
     * Past this, an `update_history` row still sitting in a non-terminal
     * status is debris from a crashed install, not maintenance in
     * progress — see isMaintenanceInProgress(). Six hours is far beyond
     * any real install, migration resumes included, and the cost of
     * getting it wrong in this direction is one skipped daily report.
     */
    private const STALE_AFTER_SECONDS = 21600;

    /**
     * Skips that describe what an installation IS, rather than something
     * that happened to it: reporting switched off by its unit, and being
     * the receiver every other installation reports TO. Neither can be
     * resolved by waiting or retrying, so neither is journaled daily and
     * neither is recorded as the latest failure — a permanent condition
     * displayed as a dated error is a support request waiting to happen,
     * which is exactly what it became on the receiver.
     */
    private const STRUCTURAL_SKIPS = ['disabled', 'self_destination'];

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

    /**
     * The daily report, as Core\Statistics\Task\SendStatisticsHandler runs
     * it: every guard applies, and the outcome is written to the send-state
     * settings the Support page renders under "État des envois".
     */
    public function send(): StatisticsSendResult
    {
        $guard = $this->firstFailingGuard();
        if ($guard !== null) {
            return $this->recordSkip($guard);
        }

        return $this->transmit(true);
    }

    /**
     * A manual report, triggered by a superadmin from Configuration >
     * Support to exercise the chain end to end instead of waiting a day for
     * the next scheduled run (ARCHITECTURE.md §8.47).
     *
     * Exactly two guards are lifted. `already_sent_today` goes, because a
     * test that cannot be repeated is not a test. `self_destination` goes,
     * because the installation that IS the receiver is the only place where
     * the whole chain — payload, bearer header, intake endpoint, dashboard
     * row — can be checked at all, and it is the one installation whose
     * own report its dashboard never shows. The daily task keeps that
     * guard, so nothing here makes the receiver report to itself on a
     * schedule; it reports to itself when someone asks it to.
     *
     * Everything else holds: reporting still has to be enabled (one button
     * must not overrule a unit's opt-out), `base_url` still has to be a
     * public host (a staging clone must never register under the production
     * installation's identity), and maintenance still blocks.
     *
     * The daily bookkeeping is deliberately left alone. A test writing
     * `statistics_last_success_at` would silence the next scheduled report
     * for 24 h and overwrite "État des envois" — the panel that answers
     * "is *automatic* reporting healthy?" — with the outcome of a click.
     * The journal records the transmission instead, and the page shows the
     * result as a flash message.
     */
    public function sendTest(): StatisticsSendResult
    {
        $guard = $this->firstFailingGuard(true);
        if ($guard !== null) {
            // Not journaled: nothing left the site, and the superadmin who
            // pressed the button is reading the answer on the next page.
            return StatisticsSendResult::skipped($guard);
        }

        return $this->transmit(false);
    }

    /**
     * The transmission itself, shared by the two entry points above.
     *
     * @param bool $scheduled whether this is the daily run (writes the
     *                        send-state settings) or a manual test (does not)
     */
    private function transmit(bool $scheduled): StatisticsSendResult
    {
        $destination = rtrim((string) $this->settingService->get('statistics_destination'), '/');

        // HTTPS or nothing. There is no fallback to cleartext, not even
        // "just this once": the bearer secret travels in a header, and a
        // downgrade would hand it to anyone on the path.
        if (!str_starts_with(strtolower($destination), 'https://')) {
            return $this->recordFailure('insecure_destination', null, $scheduled);
        }

        // The same public-host rule already applied to `base_url` above,
        // applied to where the bearer secret is actually being sent. A
        // destination of `https://localhost/`, `https://10.0.0.5/` or
        // `https://intranet/` is a request leaving with our credential
        // towards something inside the hosting network — the shape
        // Core\Security\SsrfUrlValidator exists for on the other configured
        // endpoints (audit M4/M5/M6). This check is deliberately the
        // structural one and not that validator: it must run inside a
        // background task on every host, with no DNS lookup and no network
        // in the test suite, and the destination is a superadmin-typed
        // value rather than something a member supplies.
        if (!self::isPublicHost($destination)) {
            return $this->recordFailure('non_public_destination', null, $scheduled);
        }

        $secret = $this->identityService->getSecret();
        if ($secret === null || $secret === '') {
            return $this->recordFailure('secret_unavailable', null, $scheduled);
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
            return $this->recordFailure($this->redact('transport_error: ' . $e->getMessage(), $secret), null, $scheduled);
        }
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (!$response->isSuccessful()) {
            return $this->recordFailure($this->failureReasonFor($response, $secret), $durationMs, $scheduled);
        }

        return $this->recordSuccess((int) $response->statusCode, $durationMs, $scheduled);
    }

    /**
     * The guard sequence, in order. Returns the reason of the first guard
     * that stops the send, or null when the send may proceed.
     *
     * @param bool $manual a superadmin-triggered test send, which lifts the
     *                     two guards that exist only to pace the daily task
     *                     (see sendTest())
     */
    private function firstFailingGuard(bool $manual = false): ?string
    {
        if ($this->settingService->get('statistics_enabled') !== '1') {
            return 'disabled';
        }

        // There is deliberately NO development-mode guard. An installation
        // tracking the dev channel (`auto_update_level` = 'dev') reports
        // like any other, and the report says so: `scoutmagic.is_dev_build`
        // and `updates.auto_update_level` are both fields, and the receiver
        // buckets a dev build separately from the release of the same
        // number (§8.50). Knowing what the dev channel is actually running
        // is worth more than keeping it out of the numbers, and a build
        // nobody reports on is exactly the one whose bug reports arrive
        // with no idea what was installed.
        //
        // What keeps a developer's own machine out of the receiver is the
        // next guard, not this one: a working copy lives on localhost, an
        // IP, or a `.test`/`.local` name, none of which is a public host.

        $baseUrl = (string) ($this->settingService->get('base_url') ?? '');
        if (!self::isPublicHost($baseUrl)) {
            return 'non_public_host';
        }

        $destination = (string) ($this->settingService->get('statistics_destination') ?? '');
        if (!$manual && DestinationMatcher::isReceiver($baseUrl, $destination)) {
            return 'self_destination';
        }

        if ($this->isMaintenanceInProgress()) {
            return 'maintenance_in_progress';
        }

        if (!$manual && $this->sentWithinLastDay()) {
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
     *
     * The question this answers is therefore "is one running RIGHT NOW",
     * not "does a row about one exist".
     *
     * Both halves used to answer "yes" far too readily, and between them
     * they could block reporting permanently — the guard that is supposed
     * to skip a report for the few minutes an install takes was the reason
     * a site never sent one at all, and the same reason the Support page's
     * "envoyer un rapport de test" button always answered « Une opération
     * de maintenance est en cours ».
     *
     * - An `update_history` row that is neither completed, failed nor
     *   rolled back is only in progress while it is plausibly still
     *   moving. A row left mid-status by a crashed install (an install
     *   that died in `installing` writes no terminal status, and nothing
     *   ever comes back to close it) is not maintenance, it is debris —
     *   hence STALE_AFTER_SECONDS, well past the longest real install
     *   including a resumed migration.
     * - A `scheduled_actions` row that is merely *pending* for a future
     *   run_at is not maintenance either: with automatic updates enabled,
     *   an install waiting for its weekly slot sits pending for up to a
     *   week, which silenced reporting for that entire week. Only a row
     *   already claimed ('processing') or already due counts.
     */
    private function isMaintenanceInProgress(): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM update_history
                 WHERE status NOT IN ('completed', 'failed', 'rolled_back')
                   AND started_at >= ?"
            );
            $stmt->execute([
                (new \DateTimeImmutable())->modify('-' . self::STALE_AFTER_SECONDS . ' seconds')->format('Y-m-d H:i:s'),
            ]);
            if ((int) $stmt->fetchColumn() > 0) {
                return true;
            }

            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM scheduled_actions
                 WHERE module_id = 'core'
                   AND task_key IN ('install_update', 'restore_backup', 'full_reset')
                   AND (status = 'processing' OR (status = 'pending' AND run_at <= ?))"
            );
            $stmt->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s')]);

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

        $sentAt = DateInput::fromStorage($lastSuccess);
        if ($sentAt === null) {
            return false;
        }

        return (time() - $sentAt->getTimestamp()) < self::MIN_INTERVAL_SECONDS;
    }

    private function recordSuccess(int $statusCode, int $durationMs, bool $scheduled = true): StatisticsSendResult
    {
        if ($scheduled) {
            $this->writeSetting(StatisticsStateSettings::LAST_SUCCESS_AT, self::nowIso());
        }

        // Context is the HTTP status and the duration, nothing else. The
        // installation id is not personal data and would be harmless, but
        // there is no reason to write it either.
        //
        // A test send is journaled too, under its own event type: it is a
        // real transmission of this site's report, and what leaves the site
        // is recorded whoever asked for it.
        $this->journalService->log(
            'core',
            $scheduled ? 'statistics_sent' : 'statistics_test_sent',
            'info',
            $scheduled
                ? 'Statistiques d\'utilisation transmises'
                : 'Rapport de statistiques de test transmis',
            ['status' => $statusCode, 'duration_ms' => $durationMs]
        );

        return StatisticsSendResult::sent($statusCode, $durationMs);
    }

    private function recordFailure(string $reason, ?int $durationMs = null, bool $scheduled = true): StatisticsSendResult
    {
        // A failed test leaves "État des envois" alone as well. The panel
        // describes the daily task, and a manual attempt that failed —
        // typically the very first one, before anything is configured —
        // must not show up there as the automatic reporting being broken.
        if ($scheduled) {
            $this->writeSetting(StatisticsStateSettings::LAST_FAILURE_AT, self::nowIso());
            $this->writeSetting(StatisticsStateSettings::LAST_FAILURE_REASON, $reason);
        }

        $context = ['reason' => $reason];
        if ($durationMs !== null) {
            $context['duration_ms'] = $durationMs;
        }

        $this->journalService->log(
            'core',
            $scheduled ? 'statistics_send_failed' : 'statistics_test_send_failed',
            'warning',
            $scheduled
                ? 'Échec de la transmission des statistiques d\'utilisation'
                : 'Échec de la transmission du rapport de statistiques de test',
            $context
        );

        return StatisticsSendResult::failed($reason);
    }

    private function recordSkip(string $reason): StatisticsSendResult
    {
        // A deliberate opt-out is not an event. Journaling it every single
        // day would fill the journal with the fact that nothing happened.
        // `self_destination` joins it for the same reason and a stronger
        // one: on the receiver installation it is not a state that can
        // change, it is what that installation IS. A row a day saying so
        // is the definition of noise.
        if (!in_array($reason, self::STRUCTURAL_SKIPS, true)) {
            $this->journalService->log(
                'core',
                'statistics_send_skipped',
                'info',
                'Transmission des statistiques d\'utilisation ignorée',
                ['reason' => $reason]
            );
        }

        // Only the skips that mean something is genuinely in the way are
        // surfaced on the Support page. "Reporting is off", "already
        // reported today" and "this installation is the receiver" are the
        // normal state of a healthy installation, and showing any of them
        // as the latest problem would be misleading. The receiver is the
        // sharpest case: it can never send, so the failure it recorded on
        // its first day sat under "État des envois" for ever, with a
        // timestamp that made it look like something had just broken.
        if (!in_array($reason, ['already_sent_today', ...self::STRUCTURAL_SKIPS], true)) {
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

        // Byte-oriented, not `/u`: a remote error body is very often not
        // valid UTF-8, and `preg_replace()` with `/u` returns null on such
        // a subject — which the old `(string)` cast turned into an empty
        // string, throwing away the HTTP status this reason was built
        // around and leaving the Support page showing a blank "Motif".
        // The ASCII ranges matched here never occur inside a multi-byte
        // UTF-8 sequence, so dropping `/u` cannot corrupt a valid one.
        // Core\Support\SupportCollectorContext::collapseWhitespace() does
        // the same job for the support package; the three lines are copied
        // rather than shared because Core\Support already depends on
        // Core\Statistics (its statistics.json collector) and a cycle
        // between the two namespaces costs more than this does.
        $reason = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $reason);
        $reason = trim((string) preg_replace('/[ \t]+/', ' ', $reason));

        // Then made valid UTF-8, because this string is written straight
        // into a journal context that JournalService runs through
        // json_encode(). That returns `false` on an invalid byte sequence,
        // and JournalRepository::insert() takes ?string — so a receiver
        // answering with a latin-1 error body would have turned a recorded
        // failure into a TypeError escaping the daily task. Dropping the
        // offending bytes keeps the readable part of the answer.
        if (!mb_check_encoding($reason, 'UTF-8')) {
            $reason = (string) mb_convert_encoding($reason, 'UTF-8', 'UTF-8');
        }

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
