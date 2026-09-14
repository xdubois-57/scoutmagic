<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback;

use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\InboundMailInterface;

/**
 * « Est-ce que ce qui revient arrive à quelqu'un ? », answered by writing
 * to the address and waiting to see the message land (roadmap IT-03).
 *
 * **A real round trip, and deliberately not a comparison of strings.** The
 * obvious implementation — check that the configured return address is
 * one of the addresses `inbound_mail` synchronises — is wrong in the
 * ordinary case: a unit's `info@unite.be` is very often an alias
 * delivering into a box the provider calls something else entirely, and
 * the comparison would raise a red alert on a healthy installation. The
 * only question worth asking is whether a message sent there comes back,
 * so that is the question asked.
 *
 * **The `inbound_mail` module is a nullable dependency (D2, §7.5).** The
 * core does not depend on a module to send — that is the whole asymmetry
 * of this chantier — so with the module disabled this answers
 * `ReturnState::IMPOSSIBLE` and everything else on the page goes on
 * working. It is an answer, not an error.
 *
 * The key is chosen by this side, because this is the side that has to
 * recognise the message when it comes back — the same reasoning, and the
 * same shape, as `Modules\SupportDashboard\Service\MailProbeService`.
 */
final class ReturnPathVerifier
{
    /** The site's own consumer id with `inbound_mail`. */
    public const CONSUMER_ID = 'outbound_mail';

    /**
     * How long the site keeps waiting before calling a message lost.
     *
     * Long enough for a relay chain that is retrying and for an hourly
     * mailbox synchronisation to have run several times; short enough
     * that « jamais arrivé » still means something on the day somebody
     * reads it.
     */
    public const VALIDITY_HOURS = 6;

    public function __construct(
        private ReturnProbeRepository $probes,
        private MailService $mail,
        private JournalService $journal,
        private ?InboundMailInterface $inboundMail = null
    ) {
    }

    /**
     * Whether the round trip can be run at all.
     *
     * Two ways for it to be no, and the screen distinguishes them: the
     * module is not installed or is disabled, or it is collecting but no
     * mailbox has been opened to this verification. The second is a
     * configuration act somebody can take; the first is not.
     */
    public function isPossible(): bool
    {
        return self::possibleWith($this->inboundMail);
    }

    /**
     * The same question, asked of a gateway rather than of an instance.
     *
     * It exists so that the support collector — which holds the stored
     * rows and must never be able to SEND a probe — can tell « jamais
     * vérifié » from « vérification impossible » without a second copy of
     * the rule. Two copies of a two-line rule is how the screen and the
     * archive end up disagreeing about the same installation.
     */
    public static function possibleWith(?InboundMailInterface $inboundMail): bool
    {
        return $inboundMail !== null
            && $inboundMail->isCollecting()
            && self::enabledMailboxesOf($inboundMail) !== [];
    }

    /** True when the module is there but no box is open to this check. */
    public function isCollectingWithoutScope(): bool
    {
        return $this->inboundMail !== null
            && $this->inboundMail->isCollecting()
            && $this->watchedMailboxes() === [];
    }

    /**
     * Send one message to each address, and remember what was sent.
     *
     * @param list<string> $addresses the distinct addresses to verify
     * @return array{sent: int, failed: int, impossible: bool}
     */
    public function launch(array $addresses, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        if (!$this->isPossible()) {
            return ['sent' => 0, 'failed' => 0, 'impossible' => true];
        }

        $sent = 0;
        $failed = 0;

        foreach ($this->distinct($addresses) as $address) {
            $key = self::generateKey();
            $expiresAt = $now->modify('+' . self::VALIDITY_HOURS . ' hours');

            // Recorded BEFORE the send, not after: the message can arrive
            // and be claimed while send() is still returning, and a probe
            // the consumer cannot find is a probe that reads « jamais
            // arrivé » for ever. A row for a send that then fails is
            // corrected two lines down.
            $id = $this->probes->issue($address, $key, $now, $expiresAt);

            try {
                // **Through a queue-less clone** ({@see MailService::
                // withoutDeferral()}), and that is the whole point of a
                // diagnostic. A probe that the deferral queue silently
                // accepts because the transactional lane is spent is a
                // probe that has told nobody anything: it would read
                // « en attente », then « jamais arrivé » hours later, and
                // send the operator looking at the return path when the
                // problem was the transport all along. Refused now is a
                // usable answer; queued now is not.
                $this->mail->withoutDeferral()->send(
                    to: $address,
                    subject: self::subjectFor($key),
                    bodyHtml: self::bodyHtml($key),
                    bodyText: self::bodyText($key)
                );
                $sent++;
            } catch (\Throwable) {
                // The send never left, so there is nothing to wait for —
                // and leaving the row would turn a transport failure into
                // « jamais arrivé », which points the operator at the
                // wrong half of the problem entirely.
                $this->probes->forgetById($id);
                $failed++;
            }
        }

        $this->journal->log(
            'core',
            'mail_return_probe_sent',
            'info',
            'Vérification des retours lancée',
            ['sent' => $sent, 'failed' => $failed]
        );

        return ['sent' => $sent, 'failed' => $failed, 'impossible' => false];
    }

    /**
     * What is known about one address today.
     *
     * @return array{state: ReturnState, sent_at: ?\DateTimeImmutable,
     *     received_at: ?\DateTimeImmutable, mailbox: ?string}
     */
    public function stateFor(string $address, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $empty = ['state' => ReturnState::NEVER_VERIFIED, 'sent_at' => null, 'received_at' => null, 'mailbox' => null];

        if ($address === '') {
            return $empty;
        }

        if (!$this->isPossible()) {
            return ['state' => ReturnState::IMPOSSIBLE, 'sent_at' => null, 'received_at' => null, 'mailbox' => null];
        }

        $probe = $this->probes->findByAddress($address);
        $state = ReturnState::forProbe($probe, $now);
        if ($probe === null) {
            return $empty;
        }

        return [
            'state' => $state,
            'sent_at' => $probe->sentAt,
            'received_at' => $probe->receivedAt,
            'mailbox' => $state === ReturnState::VERIFIED ? $this->mailboxName($probe->mailboxId) : null,
        ];
    }

    /**
     * Attach an arriving message to the round trip it answers.
     *
     * Returns false for everything else in the box, which is the ordinary
     * answer: this consumer claims nothing but the messages the site
     * itself wrote.
     */
    public function claim(
        string $subject,
        int $mailboxId,
        \DateTimeImmutable $receivedAt,
        ?\DateTimeImmutable $now = null
    ): ?string {
        $now ??= new \DateTimeImmutable();

        $key = self::keyIn($subject);
        if ($key === null) {
            return null;
        }

        $probe = $this->probes->findPending($key, $now);
        if ($probe === null) {
            return null;
        }

        $this->probes->markReceived($probe->id, $receivedAt, $mailboxId);

        $this->journal->log(
            'core',
            'mail_return_probe_received',
            'info',
            'Un message de vérification des retours est revenu',
            ['mailbox_id' => $mailboxId]
        );

        return $key;
    }

    /**
     * Drop whatever was remembered about addresses no longer in use —
     * what makes « modifier une adresse remet l'état à jamais vérifié »
     * also stop keeping a copy of the old one.
     *
     * @param list<string> $addresses the addresses still in use
     */
    public function forgetAllExcept(array $addresses): void
    {
        $this->probes->forgetAllExcept($this->distinct($addresses));
    }

    /**
     * The boxes a returning message could land in — what the screen names
     * when it explains what « vérifié » would be measuring.
     *
     * @return array<int, string> mailbox id => name
     */
    public function watchedMailboxes(): array
    {
        return self::enabledMailboxesOf($this->inboundMail);
    }

    /**
     * @return array<int, string> mailbox id => name
     */
    private static function enabledMailboxesOf(?InboundMailInterface $inboundMail): array
    {
        if ($inboundMail === null) {
            return [];
        }

        $names = [];
        foreach ($inboundMail->listMailboxSummaries() as $id => $summary) {
            if ($summary['is_enabled']) {
                $names[(int) $id] = (string) $summary['name'];
            }
        }

        return $names;
    }

    /**
     * @param list<string> $addresses
     * @return list<string>
     */
    private function distinct(array $addresses): array
    {
        // Normalised the way the repository indexes them, not merely
        // trimmed: « Info@unite.be » and « info@unite.be » are one row
        // there, so treating them as two here sends two probes, the
        // second of which replaces the first's row — and the first key
        // could then never be claimed.
        $seen = [];
        foreach ($addresses as $address) {
            $address = EncryptionService::normalizeEmailForIndex((string) $address);
            if ($address === '' || in_array($address, $seen, true)) {
                continue;
            }
            $seen[] = $address;
        }

        return $seen;
    }

    private function mailboxName(?int $mailboxId): ?string
    {
        if ($mailboxId === null) {
            return null;
        }

        // Resolved now rather than stored, so a box renamed in the
        // inbound-mail configuration is not quoted back under its old
        // name here. A box that has since been deleted has no name left
        // to give, and null is the honest answer.
        return $this->watchedMailboxes()[$mailboxId] ?? null;
    }

    public static function subjectFor(string $key): string
    {
        return 'Vérification des retours ' . $key;
    }

    public static function keyIn(string $subject): ?string
    {
        return preg_match('/\b(RET-[A-Z0-9]{10})\b/', $subject, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * Upper case and unambiguous, because it travels through a subject
     * line people read — and because a lower-case `l` next to a `1` in a
     * support screenshot costs somebody an hour.
     */
    private static function generateKey(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $key = 'RET-';
        for ($i = 0; $i < 10; $i++) {
            $key .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $key;
    }

    private static function bodyText(string $key): string
    {
        return "Ce message a été envoyé par le site lui-même pour vérifier que le courrier adressé à cette "
            . "adresse revient bien dans une boîte relevée.\n\n"
            . "Référence : {$key}\n\n"
            . "Il n'appelle aucune réponse et peut être supprimé.";
    }

    private static function bodyHtml(string $key): string
    {
        return '<p>Ce message a été envoyé par le site lui-même pour vérifier que le courrier adressé à cette '
            . 'adresse revient bien dans une boîte relevée.</p>'
            . '<p>Référence : <strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong></p>'
            . '<p>Il n\'appelle aucune réponse et peut être supprimé.</p>';
    }
}
