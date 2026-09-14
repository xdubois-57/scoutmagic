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
        return self::scopedMailboxCountOf($inboundMail) > 0;
    }

    /**
     * How many boxes are open to THIS consumer — the only count that
     * decides whether a round trip can work.
     *
     * **Not « how many boxes are enabled ».** That was the bug: a scope
     * is `inert` until the superadmin opens a box to a consumer, so an
     * installation with `inbound_mail` on and no box opened to
     * « Courrier sortant » has plenty of enabled boxes and asks this
     * consumer about none of them. The probe then goes out, is never
     * offered to {@see ReturnPathConsumer::analyze()}, and reads « jamais
     * arrivé » six hours later — the exact false alarm the round trip
     * exists to avoid, raised by the round trip itself.
     *
     * `probeAddressesFor()` is the module's own scope-aware answer, and
     * its docblock gives this very reason: « probing a box it never reads
     * would produce a message nobody claims and a "jamais reçu" that
     * means nothing ». Counted rather than kept: what comes back are
     * mailbox addresses, and nothing here has any business holding one.
     */
    public static function scopedMailboxCountOf(?InboundMailInterface $inboundMail): int
    {
        if ($inboundMail === null) {
            return 0;
        }

        try {
            return count($inboundMail->probeAddressesFor(self::CONSUMER_ID));
        } catch (\Throwable) {
            return 0;
        }
    }

    /** How many boxes this verification can be seen to arrive in. */
    public function scopedMailboxCount(): int
    {
        return self::scopedMailboxCountOf($this->inboundMail);
    }

    /**
     * True when the module is there, collecting, and no box is open to
     * this check — the one form of « impossible » somebody can fix, and
     * the screen says which one it is.
     *
     * It was unreachable before: it asked whether any ENABLED box existed,
     * which is the same question `isCollecting()` answers, so the two
     * could never disagree. Asking about the boxes open to THIS consumer
     * is what makes it mean anything.
     */
    public function isCollectingWithoutScope(): bool
    {
        return $this->inboundMail !== null
            && $this->inboundMail->isCollecting()
            && $this->scopedMailboxCount() === 0;
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
     * Returns null for everything else in the box, which is the ordinary
     * answer: this consumer claims nothing but the messages the site
     * itself wrote, coming back.
     *
     * @param list<string> $toEmails every address the arriving message named
     */
    public function claim(
        string $subject,
        string $fromEmail,
        array $toEmails,
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

        // **The message has to be the probe coming back, not a report
        // about it.** A non-delivery notification quotes the original
        // subject — « Undeliverable: Vérification des retours RET-… » —
        // so the key alone cannot tell them apart, and the NDR for an
        // undeliverable address lands in the box the ENVELOPE sender
        // names, which on this site is the bounce address and therefore
        // very often a watched box. Claiming it would report « vérifié »
        // for an address that does not exist: the precise failure this
        // whole round trip was built to detect, announced as a success.
        if (!self::isAddressedTo($probe->address, $toEmails) || self::isFromAMailSystem($fromEmail)) {
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
     * Every enabled box, by id and name — used ONLY to name the box a
     * message actually landed in ({@see self::mailboxName()}).
     *
     * Deliberately not the list the screen shows as « les boîtes
     * relevées », and not what decides whether the check can run: this
     * answers « what is this box called », where those two questions are
     * about scope and are answered by {@see self::scopedMailboxCountOf()}.
     * A box that has since been closed to this consumer still has a name,
     * and a message that arrived in it still arrived there.
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

    /**
     * Whether the arriving message names the probed address among its own
     * recipients.
     *
     * **Alias-safe, and that matters here more than anywhere.** The whole
     * point of this round trip is that a unit's address is often an alias
     * delivering into a box called something else — but aliasing rewrites
     * the ENVELOPE recipient, never the `To:` header. The probe left with
     * `To: info@unite.be` and arrives carrying it, whichever box it
     * finally lands in. A notification ABOUT that message, by contrast, is
     * addressed to whoever sent it.
     *
     * @param list<string> $toEmails
     */
    private static function isAddressedTo(string $address, array $toEmails): bool
    {
        $wanted = EncryptionService::normalizeEmailForIndex($address);
        foreach ($toEmails as $recipient) {
            if (EncryptionService::normalizeEmailForIndex((string) $recipient) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * A mail system talking to itself, rather than the message coming
     * back.
     *
     * The second guard, for the relay that puts the failed recipient in
     * the notification's own `To:` — rare, and enough to defeat the first
     * check on its own. `postmaster` is reserved by RFC 5321 §4.5.1 and
     * `mailer-daemon` is universal by convention; `Modules\InboundMail\
     * Mime\BulkMailDetector` keeps the same two, which is not reachable
     * from the core (§7.5) and is why they are spelled again here rather
     * than shared.
     *
     * Deliberately this narrow: recognising a bounce properly means
     * reading `multipart/report` and its status codes, and that is the
     * subject of its own iteration. What is needed here is only « this is
     * not my message coming back ».
     */
    private static function isFromAMailSystem(string $fromEmail): bool
    {
        $at = strpos($fromEmail, '@');
        $localPart = strtolower(trim($at === false ? $fromEmail : substr($fromEmail, 0, $at)));

        return in_array($localPart, ['mailer-daemon', 'postmaster'], true);
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
