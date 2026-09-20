<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

use Core\Journal\JournalService;
use PHPMailer\PHPMailer\PHPMailer;

class MailService
{
    public function __construct(
        private string $mode,
        private string $fromAddress,
        private string $fromName,
        private string $shortName,
        private DkimManager $dkimManager,
        private string $dkimSelector,
        private ?string $smtpHost = null,
        private ?int $smtpPort = null,
        private ?string $smtpUser = null,
        private ?string $smtpPassword = null,
        // How an already-configured message is actually delivered
        // (ARCHITECTURE.md §8.7). A real default rather than a nullable
        // dependency, so there is exactly one delivery path and the ~95
        // call sites of send() never learn a transport exists. The
        // composition root swaps it (and only it) to capture mail instead
        // of sending it.
        private MailTransportInterface $transport = new PhpMailerTransport(),
        /**
         * Where a delivery that did NOT happen is written down.
         *
         * Nullable, and only because of the one caller that has no
         * journal to give: Core\Http\Controller\SetupController tests
         * the values sitting in the setup form, on an installation whose
         * database may not exist yet. Every composition root passes it.
         */
        private ?JournalService $journal = null,
        /**
         * Where a message goes when a whole LANE is spent or down (D9).
         *
         * Nullable, and it is the one dependency this class could not
         * take earlier: the queue stores what `send()` was CALLED with,
         * and the chain below only ever sees an assembled PHPMailer. So
         * the decision — fail now, or keep this for later — has to be
         * made here, where the arguments still exist.
         *
         * Null means the installation has no queue (the setup wizard,
         * a narrow test double), and then a lane that cannot take a
         * message fails exactly as it did before any of this existed.
         */
        private ?Transport\DeferredMailQueue $deferred = null,
        /**
         * Where « Répondre » goes, for every message that does not name
         * its own (roadmap IT-03).
         *
         * Empty is the ordinary case and means « the From address », which
         * is what a message carrying no `Reply-To:` at all already does.
         * It is deliberately NOT stored as a copy of the From address:
         * the day somebody changes the expéditeur, a copy would go on
         * pointing at the old one and nothing would say so.
         *
         * **Last, and that position is the point.** Several call sites
         * build this service positionally, so a parameter inserted in the
         * middle silently hands `$smtpHost` an integer — which is what
         * happened, loudly, the first time this one went in next to the
         * other addresses where it reads better.
         */
        private string $replyAddress = '',
        /**
         * The send receipts (roadmap IT-05), and **last for the same
         * reason `$replyAddress` is**: several call sites build this
         * service positionally.
         *
         * The repository rather than `Bounce\BounceService`, because
         * stamping a receipt needs no notifier — and a notifier would
         * drag `NotificationService` in, which is built with a
         * MailService of its own.
         *
         * Null is a site with no bounce handling; nothing is recorded and
         * nothing breaks.
         */
        private ?Feedback\Bounce\BounceStateRepository $sendReceipts = null,
        /**
         * The seed mailboxes, when the unit has turned them on
         * (roadmap IT-07).
         *
         * **Here and not in `mass_mail`**, which is the whole point of the
         * placement: the mailing has nothing to learn, and any later
         * sender of the same shape gets the measurement by passing its own
         * run reference. Null is « no copies », which is also what a unit
         * that never enabled them gets.
         */
        private ?Feedback\Seed\SeedMailboxes $seedMailboxes = null
    ) {
    }

    /**
     * The configured delivery transport, `smtp` or `local`.
     *
     * Exposed for diagnostics (Core\Statistics\StatisticsPayloadBuilder,
     * ARCHITECTURE.md §8.47) — a transport name, never a host, a port, a
     * user or a password.
     */
    public function getDeliveryMode(): string
    {
        return $this->mode === 'smtp' ? 'smtp' : 'local';
    }

    /**
     * The site's own configured From — the address and the name a message
     * carries when its caller passes no override.
     *
     * Exposed for one reason: a screen that shows a chief what an e-mail
     * will look like before it goes out has to name the sender the
     * recipient will actually see, and `send()`'s rule for that is
     * `$fromAddressOverride ?? $this->fromAddress`. Without this, the
     * only way for such a screen to fill in the fallback half of that
     * expression is to guess at it — and a preview that names a sender
     * other than the real one is worse than a preview with no sender at
     * all.
     *
     * These are organisational values, in clear by design (design.md
     * §2.6) — never a credential, and nothing `isDeliveryConfigured()`
     * above is careful about.
     *
     * @return array{address: string, name: string}
     */
    public function getDefaultSender(): array
    {
        return ['address' => $this->fromAddress, 'name' => $this->fromName];
    }

    /**
     * Whether outgoing mail can plausibly be delivered: a From address is
     * set and, in SMTP mode, a host and credentials are present.
     *
     * A boolean, deliberately — the same diagnostics caller must be able to
     * report "email is configured" without any of the values that make it so
     * ever leaving the installation.
     */
    public function isDeliveryConfigured(): bool
    {
        if (trim($this->fromAddress) === '') {
            return false;
        }

        if ($this->getDeliveryMode() !== 'smtp') {
            return true;
        }

        return trim((string) $this->smtpHost) !== ''
            && trim((string) $this->smtpUser) !== ''
            && (string) $this->smtpPassword !== '';
    }

    /**
     * Send a transactional email.
     *
     * @param array<int, array{path: string, name: string}> $attachments Absolute filesystem path + display name pairs.
     * @param string|null $fromAddressOverride Use this address as the visible From instead of the site's configured
     *                                          default (e.g. mass_mail sending "as" the sender section). DKIM still
     *                                          signs under the site's own configured domain regardless — that's the
     *                                          only domain with a verified key/DNS record, and mismatching it against
     *                                          an arbitrary override domain would produce an invalid signature.
     * @param array<string, string> $extraHeaders Raw header name => value pairs added as-is (e.g. mass_mail's
     *                                             List-Unsubscribe / List-Unsubscribe-Post, RFC 8058) — the caller is
     *                                             responsible for values being header-safe (no newlines).
     * @param MailPurpose $purpose What this message is, for DELIVERY purposes only, and nothing else: the
     *                             transport is the only thing that reads it, and the default transport ignores
     *                             it. Left at `Ordinary` by all but one call site — see MailPurpose.
     * @throws SuppressedRecipientException when the recipient is a
     *                                      suspended address this send
     *                                      vouches for — nothing left,
     *                                      and deliberately so
     * @throws MailException                on failure
     */
    public function send(
        string $to,
        string $subject,
        string $bodyHtml,
        string $bodyText,
        ?string $replyTo = null,
        array $attachments = [],
        ?string $fromAddressOverride = null,
        ?string $fromNameOverride = null,
        array $extraHeaders = [],
        MailPurpose $purpose = MailPurpose::Ordinary,
        /**
         * Did the SITE choose this recipient, or was it handed one?
         *
         * **False by default, and that direction is the whole point.** A
         * bounce receipt is what lets a report naming an address be
         * believed, so a caller that has never heard of the rule must not
         * mint one. Forgotten here, a send records nothing and at worst a
         * bounce goes unnoticed; forgotten the other way round it hands
         * somebody a way to have an address cut off.
         *
         * The first attempt defaulted to true and was wrong in four
         * places at once — a public form, a registration twin, a claimed
         * secondary address, and the deferred-mail queue, which replays a
         * message without carrying any of this.
         *
         * True belongs to the paths that write to a correspondent the
         * site picked from its own records: a notification to a member, a
         * document sent to the person it concerns, a mailing.
         */
        bool $vouchesForRecipient = false,
        /**
         * What the SENDER calls this run, when it is sending one
         * (roadmap IT-07).
         *
         * **The transport cannot see a campaign.** It is handed one
         * message per recipient, so without this a mailing of five hundred
         * would emit five hundred sets of seed copies — and « une ligne par
         * campagne » would be five hundred lines. The sender passes the
         * reference it already has for its own run; it is opaque here,
         * never parsed, so a future sender of another shape needs nothing
         * added.
         *
         * Null on every ordinary send, and null on the seed copies
         * themselves — which is also what stops this from recursing.
         */
        ?string $bulkRunReference = null
    ): void {
        // **A blocked address is one the site has stopped writing to, and
        // that has to be true of every message it sends of its own
        // accord** — not of mailings alone, which is where the rule was
        // first enforced and where it stayed. A notification or a mailed
        // document landing in a mailbox the member was just told had been
        // suspended makes the promise false and the notice confusing.
        //
        // Gated on the same `$vouchesForRecipient` as the receipt, and
        // deliberately: it marks exactly the sends where the SITE chose
        // the correspondent. Authentication mail does not vouch and is
        // therefore never suppressed — a magic link or a password reset
        // is the one thing a person is waiting for at that moment, and
        // withholding it over a bounce two months old would lock them out
        // of the site instead of protecting its reputation (D9).
        //
        // **It throws rather than returning**, because a void method that
        // returns normally says « parti » in every language a caller
        // speaks. Returning here had the attestations batch recording
        // `DeliveryState::Sent` — never retried — and the member page
        // flashing « Document renvoyé par e-mail » for a message nobody
        // received. {@see SuppressedRecipientException} for the rest.
        //
        // **Asking the question must not be able to break the send.** This
        // runs before the `try` below, so an unguarded `find()` — a query
        // plus a `decrypt()` — threw a raw `PDOException` or a decryption
        // failure straight out of a method whose whole contract is
        // `MailException`. `NotificationMailer` catches only that, and its
        // own caller `NotificationService::deliverPendingEmails()` catches
        // nothing, so one corrupt row would abort the entire delivery loop
        // rather than cost one message.
        //
        // **And it fails OPEN, unlike the receipt below.** The two are not
        // symmetrical: a receipt not written costs a future bounce its
        // proof, while a suppression not applied costs one message to an
        // address that may be suspended. Withholding a document or a
        // notification from somebody because a database read failed is the
        // worse of the two, and it is the same judgement D9 already makes
        // about mail people are waiting for. Reputation is what this gate
        // protects, and reputation survives one message; a member who
        // never receives their attestation has no way to know they should
        // ask for it.
        $blocked = false;

        try {
            $blocked = $vouchesForRecipient && $this->sendReceipts?->find($to)?->isBlocked() === true;
        } catch (\Throwable) {
            // Deliberately silent, like the receipt: the journal is reached
            // through the same database that just refused.
        }

        if ($blocked) {
            $this->journalSuppressedToBlockedAddress();

            throw SuppressedRecipientException::blocked();
        }

        $mail = new PHPMailer(true);

        try {
            $mail->CharSet = 'UTF-8';

            // Transport mode
            if ($this->mode === 'smtp') {
                $mail->isSMTP();
                $mail->Host = $this->smtpHost ?? '';
                $mail->Port = $this->smtpPort ?? 587;
                $mail->SMTPAuth = true;
                $mail->Username = $this->smtpUser ?? '';
                $mail->Password = $this->smtpPassword ?? '';
                $mail->SMTPSecure = $mail->Port === 465
                    ? PHPMailer::ENCRYPTION_SMTPS
                    : PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->isMail();
            }

            // Sender — an override (e.g. the mailing's own sender section)
            // replaces the visible From, but never the envelope Sender,
            // which stays the site's own address for bounce handling.
            $fromAddress = $fromAddressOverride ?? $this->fromAddress;
            $fromName = $fromNameOverride ?? $this->fromName;
            $mail->setFrom($fromAddress, $fromName);
            $mail->Sender = $this->fromAddress;

            // Recipient
            $mail->addAddress($to);

            // Reply-To — the caller's own when it has one (a module
            // routing replies onto a booking), otherwise the site's
            // configured reply address, otherwise nothing at all and the
            // reply goes to the visible From.
            //
            // **Never over a From override.** A mailing sent « au nom de »
            // a section carries that section's address as its visible
            // From and no Reply-To, so « Répondre » reaches the section.
            // Applying the site-wide reply address there would silently
            // divert every section's replies to the site — a regression in
            // a module this change does not otherwise touch, on the day an
            // operator fills in a field on another page entirely. The
            // site's reply address answers for the site's own From.
            $effectiveReplyTo = $replyTo ?? (
                $fromAddressOverride === null && $this->replyAddress !== '' ? $this->replyAddress : null
            );
            if ($effectiveReplyTo !== null) {
                $mail->addReplyTo($effectiveReplyTo);
            }

            // Attachments
            foreach ($attachments as $attachment) {
                $mail->addAttachment($attachment['path'], $attachment['name']);
            }

            foreach ($extraHeaders as $name => $value) {
                $mail->addCustomHeader($name, $value);
            }

            // DKIM signing — deliberately always the site's own configured
            // address/domain, never $fromAddressOverride (see the param doc above).
            if ($this->dkimManager->hasKey()) {
                $domain = $this->extractDomain($this->fromAddress);
                $mail->DKIM_domain = $domain;
                $mail->DKIM_selector = $this->dkimSelector;
                $mail->DKIM_private = $this->dkimManager->getPrivateKeyPath();
                $mail->DKIM_identity = $this->fromAddress;
            }

            // Subject with prefix
            $mail->Subject = "[{$this->shortName}] {$subject}";

            // Multipart body
            $mail->isHTML(true);
            $mail->Body = $bodyHtml;
            $mail->AltBody = $bodyText;

            // The delivery step, and only the delivery step: everything
            // above stays here so a captured message is byte-for-byte the
            // message that would have gone out.
            $this->transport->deliver($mail, $purpose);

            // **The one place the site knows it wrote to somebody.** A
            // bounce is only credited to an address a message actually
            // went to (Feedback\Bounce\BounceStateRepository::record()),
            // and this is the single point every message passes through —
            // the confirmation of a freshly typed address as much as a
            // mailing. Wired anywhere narrower, the most likely real
            // bounce of all, a typo caught on its very first send, would
            // be the one the site threw away.
            //
            // After `deliver()` and not before: a relay that refused the
            // message wrote nothing, and a deferred one has not written
            // yet. `recordSend()` also settles the PREVIOUS send, which
            // is why it is this call and not `stampReceipt()`.
            //
            // **Two independent conditions, and forgetting either
            // fails safe.** The caller says whether the site chose this
            // recipient (`$vouchesForRecipient`, false unless stated),
            // and `recordSend()` separately refuses an address the site
            // does not already hold.
            //
            // Neither alone is enough. Asking only the address lets an
            // attacker aim a send AT an address that is on file — claim a
            // member's confirmed address as an unconfirmed secondary of
            // their own, or simply post it into a public form — and the
            // receipt is minted for the victim all the same.
            try {
                if ($vouchesForRecipient) {
                    $this->sendReceipts?->recordSend($to, new \DateTimeImmutable());
                }
            } catch (\Throwable) {
                // Deliberately silent: there is nobody to tell who could
                // act on it, and the journal is reached through the same
                // database that just refused.
            }

            // **The seed copies go out here, behind the real message.**
            //
            // After `deliver()` for the same reason the receipt is: a
            // relay that refused the message wrote nothing, and measuring
            // a campaign that never left would say nothing about where it
            // lands. On the first message of the run and not on every one
            // — the claim's unique index is what makes « first » true
            // however many recipients follow.
            //
            // And it can never cost the campaign a message: everything
            // below is wrapped, because a diagnostic that breaks the thing
            // it measures is worse than no diagnostic.
            if ($purpose === MailPurpose::Bulk && $bulkRunReference !== null) {
                try {
                    $this->emitSeedCopies(
                        $bulkRunReference,
                        $subject,
                        $bodyHtml,
                        $bodyText,
                        $fromAddressOverride,
                        $fromNameOverride
                    );
                } catch (\Throwable) {
                    // Same silence, same reason.
                }
            }
        } catch (Transport\LaneExhaustedException $e) {
            // A lane with nothing left is not a refusal: nobody has said
            // no to this message, the road is simply shut. So it is kept
            // rather than lost — except on the authentication lane, where
            // a link delivered tomorrow is not a link (D9), and where the
            // person is in front of their screen and needs the truth now.
            $reason = MailErrorRedaction::withoutAddresses($e->reason !== '' ? $e->reason : $e->getMessage());

            // **Unless somebody DID say no to this message.** A lane runs
            // out when its last candidate fails, and on the ordinary
            // installation — one enabled provider per lane, which is what
            // the seeder lays down — that last candidate is also the
            // first. So a single « 550 unknown recipient » empties the
            // lane exactly like an outage does, and deferring it would
            // retry a mistyped address against a relay that will answer
            // 550 every time, for a day, before abandoning it. The
            // distinction the breaker already draws (MailFailure) is the
            // same one the queue needs: the road being shut is worth
            // waiting for, an address that does not exist is not.
            if (Transport\MailFailure::classify($reason) === Transport\MailFailure::Recipient) {
                $this->journalFailure($reason);

                throw new MailException($reason);
            }

            $payload = $this->payloadFor(
                $to,
                $subject,
                $bodyHtml,
                $bodyText,
                $replyTo,
                $attachments,
                $fromAddressOverride,
                $fromNameOverride,
                $extraHeaders,
                $vouchesForRecipient
            );

            if ($payload !== null && $this->deferred?->defer($e->lane, $purpose, $payload, $reason) === true) {
                $this->journalDeferral($e->lane, $reason);

                return;
            }

            $this->journalFailure($reason);

            throw new MailException(MailErrorRedaction::withoutAddresses($e->getMessage()));
        } catch (\Exception $e) {
            $reason = $mail->ErrorInfo ?: $e->getMessage();
            $this->journalFailure($reason);

            // The SAME redaction as the journal entry, and for the same
            // reason. The journal was careful and the exception was not,
            // so the address the transport quoted travelled on in
            // $e->getMessage() — into a flash message on a superadmin's
            // screen, into three modules' own journal contexts, into the
            // support archive. There is one rule about addresses in a
            // message that reaches a screen or a log (SECURITY.md §11),
            // and it cannot hold in one branch of the same catch.
            throw new MailException(MailErrorRedaction::withoutAddresses($reason));
        }
    }

    /**
     * One copy of this message to each seed mailbox that has not had one
     * for this run (roadmap IT-07).
     *
     * **The copy is the campaign, byte for byte.** Same subject, same
     * bodies, same sender identity — because a copy that differed would be
     * measuring the difference. The one addition is a header carrying the
     * run reference, which is what lets the consumer match an arrival back
     * to its row; a header rather than a subject token for the reason
     * {@see Feedback\Seed\SeedMailboxes::HEADER} gives.
     *
     * **Attachments are deliberately left off.** They are the one part of
     * a mailing that costs real bytes per copy, and filing decisions turn
     * on the sender, the wording and the links far more than on whether a
     * PDF rode along. Five seed boxes × a 4 MB attachment on every campaign
     * is a cost a unit would pay for ever, for a signal nobody has shown to
     * move. Written down because it IS a difference between the copy and
     * the campaign, and the only one.
     *
     * `bulkRunReference: null` on the way out, which is what stops this
     * from recursing: a seed copy is an ordinary bulk message and must not
     * spawn its own seed copies.
     */
    private function emitSeedCopies(
        string $runReference,
        string $subject,
        string $bodyHtml,
        string $bodyText,
        ?string $fromAddressOverride,
        ?string $fromNameOverride
    ): void {
        if ($this->seedMailboxes === null) {
            return;
        }

        $seeds = $this->seedMailboxes->claimFor($runReference, new \DateTimeImmutable());
        if ($seeds === []) {
            return;
        }

        // **Keyed, not the bare reference.** A run reference is
        // `mass_mail:<id>`, a plain auto-increment, and a seed box is an
        // ordinary mailbox whose address anyone may learn — so a bare
        // reference on this header is something a stranger can forge to
        // have the site record a verdict of their choosing. See
        // {@see Feedback\Seed\SeedCopyRepository::stamp()}.
        $stamp = $this->seedMailboxes->stampFor($runReference);

        foreach ($seeds as $address) {
            try {
                $this->send(
                    $address,
                    $subject,
                    $bodyHtml,
                    $bodyText,
                    null,
                    [],
                    $fromAddressOverride,
                    $fromNameOverride,
                    [Feedback\Seed\SeedMailboxes::HEADER => $stamp],
                    MailPurpose::Bulk,
                    // The site did not choose this correspondent from its
                    // records — it is the unit's own diagnostic box. No
                    // receipt, and no suppression gate: a seed box is not
                    // a member, and neither mechanism has anything to say
                    // about it.
                    false,
                    // Null, and this is the recursion guard.
                    null
                );
            } catch (\Throwable) {
                // A copy that will not go leaves its row `pending`, which
                // the sweep turns into « jamais arrivé » — the truthful
                // answer, since nothing arrived. One box's failure never
                // costs the others theirs, and never costs the campaign.
                continue;
            }
        }
    }

    /**
     * The same service, minus the queue — for the pass that drains it.
     *
     * **A drain that could itself defer is a message that never
     * expires.** `Task\DrainDeferredMailHandler` replays `send()`, and
     * replaying it through an instance holding a queue means a lane still
     * down at retry time catches its own `LaneExhaustedException`, writes
     * a NEW row with `attempts` back to zero and a fresh deadline, and
     * returns normally — so the drain sees no exception, deletes the
     * original row, and counts a send that did not happen. The backoff
     * ladder, the fixed expiry and the abandoned state all become
     * unreachable, and the message loops at the drain cadence for ever
     * while the journal reports a success every pass.
     *
     * Handing the drain a queue-less clone is what keeps the exception
     * propagating, so the existing row is rescheduled or abandoned like
     * any other failure. It is not a second way of sending: the whole
     * chain, the signature, the counters and the sandbox are the same
     * object.
     */
    public function withoutDeferral(): self
    {
        $clone = clone $this;
        $clone->deferred = null;

        return $clone;
    }

    /**
     * The same service, delivering through another transport — what a
     * diagnostic needs to pin one relay (roadmap IT-04).
     *
     * **A clone, and only the transport swapped**, for the reason the
     * transport seam exists at all (ARCHITECTURE.md §8.7): everything
     * that makes the message what it is — the From, the envelope sender,
     * the DKIM signature, the subject prefix, the multipart body — is
     * built here and stays built here. A probe that assembled its own
     * PHPMailer to reach a chosen relay would be measuring a message the
     * site never sends, which is precisely the mistake
     * `Core\Mail\Probe\MailProbeSender` exists to avoid.
     */
    public function throughTransport(MailTransportInterface $transport): self
    {
        $clone = clone $this;
        $clone->transport = $transport;

        return $clone;
    }

    /**
     * An e-mail that did not leave, written down — **whatever** the
     * caller then does with the exception.
     *
     * Here and not in the callers, because the callers are the problem.
     * There are some ninety send() sites; a handful journal a failure
     * themselves, a handful more swallow the exception on purpose
     * (`catch (MailException) {}` — a notification is not worth failing
     * the action it accompanies), and the rest turn it into a message on
     * a screen that is gone as soon as the page is reloaded. The result
     * was an administrator watching « l'e-mail de test n'a pas pu
     * partir » and finding nothing at all in /admin/journal — which is
     * also what the diagnostic archive carries, so the failure was
     * invisible from both ends at once. One entry at the single point
     * every one of those paths goes through is the only version of this
     * that cannot be forgotten by the next call site.
     *
     * `error`, not `warning`: something the site decided to send did not
     * go out. Nothing above this line judges whether the caller thought
     * it important, and nothing should — the whole point is that the
     * journal stops depending on that judgement.
     *
     * The entry never fails the send. It is already the failure path, and
     * a journal insert that throws (no table yet, a database that just
     * went away — the very conditions under which mail also stops
     * working) must not replace a MailException the caller knows how to
     * read with a PDOException it does not.
     */
    /**
     * The arguments of this call, in the shape the queue stores (D9).
     *
     * **Attachments are read here, now.** `send()` is given filesystem
     * paths, and the ones it gets are routinely temporary files deleted
     * the moment the request ends; a queue holding the path would drain
     * successfully tomorrow and deliver a message whose receipt had
     * vanished. Reading them at the point of deferral is the only moment
     * they are all still guaranteed to exist.
     *
     * **Null when the message cannot be carried whole**, and the caller's
     * failure then stands. Two ways that happens, and neither is a
     * judgement call:
     *
     * - A file that cannot be read. Queueing the rest would have `send()`
     *   report success for a message that will arrive missing the receipt
     *   somebody asked for, and nothing downstream would ever say so.
     * - More than the queue will take. The ceiling is checked HERE,
     *   against the sizes on disk, before anything is read: checking it
     *   after would mean loading a 400 MB document into memory to find
     *   out it is too big, which is the failure the ceiling exists to
     *   prevent (`DeferredMailQueue::MAX_ATTACHMENT_BYTES`).
     *
     * @param array<int, array{path: string, name: string}> $attachments
     * @param array<string, string> $extraHeaders
     * @return array{
     *     to: string, subject: string, bodyHtml: string, bodyText: string,
     *     replyTo: ?string, fromAddressOverride: ?string, fromNameOverride: ?string,
     *     extraHeaders: array<string, string>,
     *     vouchesForRecipient: bool,
     *     attachments: array<int, array{name: string, content: string}>
     * }|null
     */
    private function payloadFor(
        string $to,
        string $subject,
        string $bodyHtml,
        string $bodyText,
        ?string $replyTo,
        array $attachments,
        ?string $fromAddressOverride,
        ?string $fromNameOverride,
        array $extraHeaders,
        bool $vouchesForRecipient
    ): ?array {
        $bytes = 0;
        foreach ($attachments as $attachment) {
            $size = @filesize($attachment['path']);
            if ($size === false) {
                return null;
            }

            $bytes += $size;
            if ($bytes > Transport\DeferredMailQueue::MAX_ATTACHMENT_BYTES) {
                return null;
            }
        }

        $carried = [];
        foreach ($attachments as $attachment) {
            $content = @file_get_contents($attachment['path']);
            if ($content === false) {
                return null;
            }

            $carried[] = ['name' => $attachment['name'], 'content' => $content];
        }

        return [
            'to' => $to,
            'subject' => $subject,
            'bodyHtml' => $bodyHtml,
            'bodyText' => $bodyText,
            'replyTo' => $replyTo,
            'fromAddressOverride' => $fromAddressOverride,
            'fromNameOverride' => $fromNameOverride,
            'extraHeaders' => $extraHeaders,
            // **Carried, because the queue outlives the decision.** The
            // suppression gate at the top of `send()` reads this flag, and
            // a replay that did not carry it read « false » — « the site
            // never chose this recipient » — for a notification the site
            // very much chose. A message deferred while the address was
            // fine and drained after it was blocked went out anyway, to
            // somebody who had just been told the site had stopped writing
            // to them. A drain re-reads the gate with the right answer.
            'vouchesForRecipient' => $vouchesForRecipient,
            'attachments' => $carried,
        ];
    }

    /**
     * A message kept rather than lost, written down at `info`.
     *
     * Not an error: nothing has gone wrong for the recipient yet, and
     * marking it `error` would put a line the colour of a real failure
     * next to the ones that are. What makes it worth recording at all is
     * that a deferral is invisible to whoever triggered it.
     */
    /**
     * A message the site chose not to send, written down at `info`.
     *
     * Not an error — nothing went wrong, the address is suspended and
     * that is the feature working. But it is invisible to whoever
     * triggered it, which is the same reason a deferral is recorded.
     * No address, per SECURITY.md §11.
     */
    private function journalSuppressedToBlockedAddress(): void
    {
        try {
            $this->journal?->log(
                'core',
                'mail_suppressed_blocked_address',
                'info',
                'Message non envoyé : adresse suspendue après des refus répétés'
            );
        } catch (\Throwable) {
            // The message is withheld either way.
        }
    }

    private function journalDeferral(Transport\MailLane $lane, string $reason): void
    {
        try {
            $this->journal?->log(
                'core',
                'mail_deferred',
                'info',
                'Message différé : la voie n\'avait plus de fournisseur disponible',
                [
                    'lane' => $lane->value,
                    'origin' => self::callerOutsideThisNamespace(),
                    'reason' => MailErrorRedaction::withoutAddresses($reason),
                ]
            );
        } catch (\Throwable) {
            // Same posture as journalFailure() — the note never outranks
            // the message it is about.
        }
    }

    private function journalFailure(string $reason): void
    {
        try {
            $this->journal?->log(
                'core',
                'mail_send_failed',
                'error',
                'Échec d\'envoi d\'un e-mail',
                [
                    // Which of the two configurations was in play, and
                    // whether it was complete at all — « not configured »
                    // and « the relay refused » are the same sentence on
                    // screen and completely different problems.
                    'mode' => $this->getDeliveryMode(),
                    'configured' => $this->isDeliveryConfigured(),
                    'origin' => self::callerOutsideThisNamespace(),
                    'reason' => MailErrorRedaction::withoutAddresses($reason),
                ]
            );
        } catch (\Throwable) {
            // Swallowed on purpose — see the docblock.
        }
    }

    /**
     * The first frame that is not this class — « which feature was trying
     * to send », in a service that is deliberately told nothing about it.
     *
     * send() takes a recipient, a subject and a body and nothing that
     * says why, which is right: ninety call sites should not each have to
     * name themselves, and the subject is the one field that regularly
     * carries somebody's name. A class name is neither personal data nor
     * something that drifts, and it is the difference between « an e-mail
     * failed » and « the support probe's e-mail failed ».
     */
    private static function callerOutsideThisNamespace(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
            $class = (string) ($frame['class'] ?? '');
            if ($class === '' || str_starts_with($class, 'Core\\Mail\\')) {
                continue;
            }

            return $class . '::' . $frame['function'];
        }

        return 'inconnu';
    }

    private function extractDomain(string $email): string
    {
        $parts = explode('@', $email);
        return $parts[1] ?? '';
    }
}
