<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Ticket;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Service\DateInput;
use Core\Statistics\StatisticsTransportInterface;

/**
 * Sends the diagnostic mail probes an installation's own mail
 * configuration is tested with (roadmap IT-27).
 *
 * **The point is the path, not the message.** « L'envoi a réussi » on the
 * Mail configuration page means PHPMailer handed the message to a relay;
 * it says nothing about SPF, DKIM, DMARC, a greylist adding forty
 * minutes, or a provider dropping the message silently. A probe answers
 * that, because the message is sent to somewhere that will say what
 * arrived and what the headers looked like when it did.
 *
 * **The receiver issues the key, this side only carries it.** Asking for
 * addresses and getting a key back is one call — `POST
 * /api/support/mail-probes`, the same bearer identity and the same guards
 * as a ticket, through the same transport, so the timeouts a page must
 * not exceed live in one place.
 *
 * **The prefix is the whole pitfall.** `MailService` prepends
 * `[{short_name}] ` to every subject, so a key anchored at the start of
 * the subject is a key the receiver never finds again. It is placed
 * inside the subject and matched with a word boundary on the other side
 * (`Modules\SupportDashboard\Service\MailProbeService::keyIn()`).
 *
 * **A failure per mailbox is not a failure of the run.** One address
 * refusing while two go out is exactly the kind of asymmetry the probe
 * exists to reveal, so a send that throws is counted and the loop
 * continues.
 */
class MailProbeSender
{
    /** When the last run was asked for — the local half of the rate limit. */
    public const LAST_SENT_AT_SETTING = 'support_last_mail_probe_at';
    /** The key of that run, so the page can name what to look for. */
    public const LAST_KEY_SETTING = 'support_last_mail_probe_key';

    /**
     * One run an hour, matching what the receiver enforces. Held here as
     * well so a second press answers immediately and honestly rather
     * than travelling to be refused.
     */
    public const RATE_LIMIT_WINDOW_MINUTES = 60;

    /** No identity could be provisioned — `secrets.enc` is unavailable. */
    public const FAILURE_NO_IDENTITY = 'no_identity';
    /** The receiver never answered, or answered nothing readable. */
    public const FAILURE_UNREACHABLE = 'unreachable';
    /** It answered something this version cannot read. */
    public const FAILURE_MALFORMED_ANSWER = 'malformed_answer';
    /** It answered that it synchronises no mailbox at all. */
    public const FAILURE_NO_MAILBOX = 'no_mailbox';
    /** A run was asked for less than an hour ago, here or there. */
    public const FAILURE_RATE_LIMITED = 'rate_limited';
    /** Every address refused — the local mail configuration is the suspect. */
    public const FAILURE_MAIL_REFUSED = 'mail_refused';

    public function __construct(
        private SettingService $settingService,
        private TicketIdentityService $identityService,
        private StatisticsTransportInterface $transport,
        private MailService $mailService,
        private JournalService $journalService,
        private string $appVersion
    ) {
    }

    public function send(\DateTimeImmutable $now): MailProbeResult
    {
        if ($this->rateLimitedUntil($now) !== null) {
            return MailProbeResult::failed(self::FAILURE_RATE_LIMITED);
        }

        $guard = $this->identityService->firstFailingGuard();
        if ($guard !== null) {
            return MailProbeResult::failed($guard);
        }

        $endpoint = $this->identityService->endpointFor(TicketIdentityService::MAIL_PROBE_PATH);
        if ($endpoint === null) {
            return MailProbeResult::failed(TicketIdentityService::GUARD_NO_DESTINATION);
        }

        $identity = $this->identityService->ensureIdentity();
        if ($identity === null) {
            return MailProbeResult::failed(self::FAILURE_NO_IDENTITY);
        }

        try {
            $response = $this->transport->post(
                $endpoint,
                (string) json_encode(['installation_id' => $identity->installationId]),
                $identity->secret,
                'ScoutMagic/' . $this->appVersion . ' (+mail-probe)'
            );
        } catch (\Throwable) {
            return MailProbeResult::failed(self::FAILURE_UNREACHABLE);
        }

        if (!$response->isSuccessful()) {
            return MailProbeResult::failed(self::FAILURE_UNREACHABLE);
        }

        $answer = json_decode((string) $response->body, true);
        if (!is_array($answer)) {
            return MailProbeResult::failed(self::FAILURE_MALFORMED_ANSWER);
        }

        $status = is_string($answer['status'] ?? null) ? (string) $answer['status'] : '';
        if ($status === 'rate_limited') {
            // The receiver counts from its own clock, which is the one
            // that matters; record the refusal locally so the next press
            // does not travel either.
            $this->writeSetting(self::LAST_SENT_AT_SETTING, $now->format('Y-m-d H:i:s'));

            return MailProbeResult::failed(self::FAILURE_RATE_LIMITED);
        }
        if ($status === 'unavailable') {
            return MailProbeResult::failed(self::FAILURE_NO_MAILBOX);
        }
        if ($status !== 'issued') {
            return MailProbeResult::failed(self::FAILURE_MALFORMED_ANSWER);
        }

        $key = is_string($answer['correlation_key'] ?? null) ? (string) $answer['correlation_key'] : '';
        $addresses = $this->addressesIn($answer['addresses'] ?? null);
        if ($key === '' || $addresses === []) {
            return MailProbeResult::failed(self::FAILURE_MALFORMED_ANSWER);
        }

        // Written before the first send: a key that was issued has to be
        // recoverable even if this request dies half-way through the
        // loop, and the rate limit has to hold from the moment the
        // receiver started expecting messages.
        $this->writeSetting(self::LAST_SENT_AT_SETTING, $now->format('Y-m-d H:i:s'));
        $this->writeSetting(self::LAST_KEY_SETTING, $key);

        $delivered = 0;
        foreach ($addresses as $address) {
            if ($this->deliver($address, $key)) {
                $delivered++;
            }
        }

        $this->journalService->log(
            'core',
            'support_mail_probe_sent',
            $delivered === 0 ? 'warning' : 'info',
            'Sonde e-mail de diagnostic envoyée',
            // The key, and counts. The addresses are the receiver's own
            // boxes and there is no reason for a copy of them to settle
            // in this installation's journal.
            ['correlation_key' => $key, 'mailboxes' => count($addresses), 'delivered' => $delivered]
        );

        if ($delivered === 0) {
            return MailProbeResult::failed(self::FAILURE_MAIL_REFUSED);
        }

        return MailProbeResult::sent($key, count($addresses), $delivered);
    }

    /**
     * The instant before which a new run is refused, or null when one may
     * be asked for now.
     */
    public function rateLimitedUntil(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        // Through DateInput, not the raw constructor: an empty setting
        // would otherwise read as *now* and lock the button for an hour
        // on an installation that has never sent a probe, and a corrupted
        // one would 500 the whole Support page (SECURITY.md § 35).
        $lastSent = DateInput::fromStorage($this->settingService->get(self::LAST_SENT_AT_SETTING));
        if ($lastSent === null) {
            return null;
        }

        $until = $lastSent->modify('+' . self::RATE_LIMIT_WINDOW_MINUTES . ' minutes');

        return $until > $now ? $until : null;
    }

    /**
     * The last run's key and the moment it was asked for, if any.
     *
     * @return array{key: string, sent_at: string}|null
     */
    public function lastRun(): ?array
    {
        $key = (string) ($this->settingService->get(self::LAST_KEY_SETTING) ?? '');
        if ($key === '') {
            return null;
        }

        return [
            'key' => $key,
            'sent_at' => (string) ($this->settingService->get(self::LAST_SENT_AT_SETTING) ?? ''),
        ];
    }

    /**
     * @return bool whether the relay accepted this one message
     */
    private function deliver(string $address, string $key): bool
    {
        try {
            $this->mailService->send(
                $address,
                // Deliberately NOT starting with the key: MailService
                // prefixes the subject, and a key that has to survive a
                // prefix is a key that must not depend on its position.
                'Sonde de diagnostic ' . $key,
                $this->bodyHtml($key),
                $this->bodyText($key)
            );

            return true;
        } catch (\Throwable) {
            // One box refusing is a finding, not an interruption.
            return false;
        }
    }

    private function bodyHtml(string $key): string
    {
        return '<p>Message de diagnostic automatique envoyé par une installation ScoutMagic.</p>'
            . '<p>Clé de corrélation : <strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong></p>'
            . "<p>Il ne contient aucune donnée personnelle et n'attend aucune réponse.</p>";
    }

    private function bodyText(string $key): string
    {
        return "Message de diagnostic automatique envoyé par une installation ScoutMagic.\n\n"
            . 'Clé de corrélation : ' . $key . "\n\n"
            . "Il ne contient aucune donnée personnelle et n'attend aucune réponse.\n";
    }

    /**
     * @return list<string>
     */
    private function addressesIn(mixed $addresses): array
    {
        if (!is_array($addresses)) {
            return [];
        }

        $valid = [];
        foreach ($addresses as $address) {
            if (is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                $valid[] = $address;
            }
        }

        return $valid;
    }

    /**
     * Bookkeeping must never be the reason a run that DID go out reads as
     * a failure — same posture as the ticket sender's own state writes.
     */
    private function writeSetting(string $key, string $value): void
    {
        try {
            $this->settingService->setInternal($key, $value);
        } catch (\Throwable) {
            // Swallowed on purpose — see the docblock.
        }
    }
}
