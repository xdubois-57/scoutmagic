<?php

declare(strict_types=1);

namespace Core\Security\HumanCheck;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;

/**
 * Generic anti-bot protection for a public form submitted by a
 * non-identified session — ARCHITECTURE.md §8, ITERATION 1's "core
 * component, three integration points" spec. Three cumulative barriers,
 * none visible to a human: a honeypot field, a minimum-delay-since-render
 * check, and a per-IP sliding-window rate limit. A form only needs
 * partials/human_check_fields.html.twig plus one verify() call — nothing
 * here is specific to any single form or module.
 *
 * Stateless by design: the trap field's name and the render timestamp
 * travel inside the form itself, HMAC-signed (EncryptionService::
 * blindIndex(), the same technique as every other blind-indexed value in
 * this codebase) with a key derived from the existing master key via
 * secrets.enc — never stored server-side. A session-based challenge would
 * break the moment a user has two tabs open, or a PWA is restored from a
 * frozen state; a signature is what stops a forged timestamp instead.
 *
 * Never touches $_SESSION or $_POST (ARCHITECTURE.md §13) — the caller
 * (a Controller) reads the request, decides whether the session is
 * identified, and passes both in. "This person is identified" is always
 * an input, never something this service resolves itself.
 */
class HumanCheckService
{
    private const TOKEN_VERSION = 'v1';
    private const TOKEN_FIELD = 'human_check_token';

    public function __construct(
        private EncryptionService $encryption,
        private HumanCheckRateLimitRepository $rateLimitRepository,
        private SettingService $settings,
        private JournalService $journal
    ) {
    }

    /**
     * Called when a protected form is rendered (GET, or a re-render after
     * a rejected submission) — never cached, a fresh trap field name and
     * timestamp every time.
     */
    public function generateChallenge(string $formKey): HumanCheckChallenge
    {
        $trapField = 'hc_' . bin2hex(random_bytes(6));
        $timestamp = time();
        $signature = $this->sign($formKey, $trapField, $timestamp);
        $tokenValue = base64_encode((string) json_encode(['f' => $trapField, 't' => $timestamp, 's' => $signature]));

        return new HumanCheckChallenge($trapField, $tokenValue);
    }

    /**
     * @param array<string, mixed> $formData Every submitted field
     *   (Request::getBodyAll()) — the trap field's real name isn't known
     *   ahead of time, so the full body is needed, not one key.
     * @param bool $enforceRateLimit Set false to skip the third barrier
     *   only — see Core\Http\Controller\AuthController for the one call
     *   site that does this and why (magic-link requests already have
     *   their own, differently-scoped rate limit).
     */
    public function verify(
        string $formKey,
        bool $sessionIdentified,
        array $formData,
        ?string $ipAddress,
        bool $enforceRateLimit = true
    ): HumanCheckResult {
        // Explicit short-circuit, not an optimization — module spec:
        // "aucune des trois barrières ne s'applique" for an identified
        // session, unconditionally.
        if ($sessionIdentified) {
            return HumanCheckResult::accepted();
        }

        $decoded = $this->decodeToken((string) ($formData[self::TOKEN_FIELD] ?? ''));
        if ($decoded === null) {
            return $this->reject($formKey, 'invalid_token');
        }
        [$trapField, $timestamp, $signature] = $decoded;

        if (!hash_equals($this->sign($formKey, $trapField, $timestamp), $signature)) {
            return $this->reject($formKey, 'invalid_signature');
        }

        // Barrier 1: honeypot. A human never sees or fills this field
        // (hidden via CSS class, tabindex="-1", aria-hidden — never
        // type="hidden", which real bots simply skip over).
        if (trim((string) ($formData[$trapField] ?? '')) !== '') {
            return $this->reject($formKey, 'honeypot_filled');
        }

        // Barrier 2: minimum delay since render, capped by a maximum age
        // so a form left open for hours can't be replayed indefinitely.
        $elapsedSeconds = time() - $timestamp;
        $minDelaySeconds = (int) $this->settings->get('human_check_min_delay_seconds', null, 3);
        if ($elapsedSeconds < $minDelaySeconds) {
            return $this->reject($formKey, 'submitted_too_fast');
        }

        $maxAgeSeconds = (int) $this->settings->get('human_check_form_validity_seconds', null, 14400);
        if ($elapsedSeconds > $maxAgeSeconds) {
            return $this->reject($formKey, 'form_expired');
        }

        // Barrier 3: per-IP sliding window. Skipped entirely when the
        // caller opted out (magic link, see docblock) or has no IP to
        // check (CLI context) — degrades to "barrier not applied" rather
        // than rejecting, since it's genuinely inapplicable, not failed.
        if ($enforceRateLimit && $ipAddress !== null && $ipAddress !== '') {
            // Hashed, never stored in clear — SECURITY.md: an IP address
            // is personal data. Same HMAC technique as every blind index
            // in this codebase, exact-match only, never reversible.
            $ipHash = $this->encryption->blindIndex('human_check_ip:' . $ipAddress);
            $windowMinutes = (int) $this->settings->get('human_check_rate_limit_window_minutes', null, 10);
            $maxAttempts = (int) $this->settings->get('human_check_rate_limit_max_attempts', null, 5);
            $since = (new \DateTimeImmutable('-' . $windowMinutes . ' minutes'))->format('Y-m-d H:i:s');

            if ($this->rateLimitRepository->countSince($ipHash, $formKey, $since) >= $maxAttempts) {
                return $this->reject($formKey, 'rate_limited');
            }

            $this->rateLimitRepository->record($ipHash, $formKey);
        }

        return HumanCheckResult::accepted();
    }

    private function reject(string $formKey, string $reason): HumanCheckResult
    {
        // Never the IP, never the trap field's content, never anything
        // that could identify the submitter — just which form was
        // involved. event_log.ip_address still gets populated by
        // JournalService::log() itself (same as every other journal
        // entry in this codebase); this is only about not ALSO
        // duplicating it into context, unlike a few other log() call
        // sites do.
        $this->journal->log('core', 'human_check_failed', 'security', 'Échec de la vérification anti-robot', ['form' => $formKey]);

        return HumanCheckResult::rejected($reason);
    }

    private function sign(string $formKey, string $trapField, int $timestamp): string
    {
        return $this->encryption->blindIndex(self::TOKEN_VERSION . ':' . $formKey . ':' . $trapField . ':' . $timestamp);
    }

    /**
     * @return array{0: string, 1: int, 2: string}|null
     */
    private function decodeToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $raw = base64_decode($token, true);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (
            !is_array($decoded)
            || !isset($decoded['f'], $decoded['t'], $decoded['s'])
            || !is_string($decoded['f'])
            || !is_numeric($decoded['t'])
            || !is_string($decoded['s'])
        ) {
            return null;
        }

        return [$decoded['f'], (int) $decoded['t'], $decoded['s']];
    }
}
