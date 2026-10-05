<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Mail\Template\RenderedEmail;
use Core\Service\DateInput;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Booking\RenterDecision;
use Modules\Rental\Booking\RenterNextStep;
use Modules\Rental\Mail\SentEmail;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalSentEmailRepository;

/**
 * Every email a booking sends. All of it through `MailService` (AGENTS.md),
 * never PHPMailer directly.
 *
 * **Every subject carries the reference** — `[LOC-2027-0042] …` — and every
 * outgoing message carries a stable `Message-ID` we generate ourselves and
 * record. That is what later lets a reply be threaded back onto the right
 * booking through `In-Reply-To`/`References` rather than by guessing from
 * the sender (§6.28, §7.6). Generating the id here rather than letting the
 * MTA invent one is the whole point: an id we never saw is an id we cannot
 * match a reply against.
 *
 * **Two of these are not optional** (§6.28): the acknowledgement, because it
 * carries the renter's only way back to their booking, and the managers'
 * notification, because without it a request can sit unread indefinitely.
 * Neither takes an "enabled" flag, and neither should grow one.
 */
class RentalBookingMailService
{
    public function __construct(
        private MailService $mailService,
        private EmailTemplateRenderer $emailTemplateRenderer,
        private SettingService $settingService,
        private JournalService $journal,
        /**
         * Where the Message-IDs this service mints are remembered, so a
         * renter's reply threads onto their booking (§7.6). Null without
         * `inbound_mail`, and nothing is remembered — which is exactly
         * true on a site that collects no mail.
         */
        private ?\Modules\InboundMail\Api\InboundMailInterface $inboundMail = null,
        /**
         * The archive of the conditions (issue #494), so every email to the
         * renter ends with a link to the version THEY accepted. Nullable so
         * the service stays constructible where nothing is sent to a renter;
         * both composition roots wire it.
         */
        private ?RentalConditionsService $conditions = null,
        /**
         * Where « Et maintenant ? » comes from (#708, IT-15): the booking's
         * next step, as the manager's dashboard reads it. Nullable like the
         * conditions; without it the e-mails go out without the block.
         */
        private ?RentalJourneyService $journey = null,
        /**
         * The booking's log of what was sent (#720, step 2): every e-mail
         * below is recorded there, sent or failed, by `deliver()`. Null:
         * nothing is recorded, and the e-mails go out all the same.
         */
        private ?RentalSentEmailRepository $sentEmails = null
    ) {
    }

    /**
     * The one write point of every e-mail to the renter (#720): send it,
     * then record it in the booking's log — sent, or failed and re-thrown
     * so each caller keeps the answer it always gave.
     *
     * **The tracking link is masked before it is stored**: it is a
     * credential to the renter's page, and a copy of it sitting in the log
     * would outlive a regeneration. `resend()` puts the booking's current
     * link back in its place.
     *
     * Attachments are named by the booking's document they are
     * (`document_id`), never copied.
     *
     * @param list<array{path: string, name: string, document_id?: int|null}> $attachments
     * @throws \Throwable whatever MailService throws, once the failure is recorded
     */
    private function deliver(
        RentalBooking $booking,
        string $kind,
        RenderedEmail $email,
        array $attachments,
        string $messageId,
        ?string $trackingToken
    ): void {
        try {
            $this->mailService->send(
                $booking->renterEmail,
                $email->subject,
                $email->bodyHtml,
                $email->bodyText,
                $this->replyAddressFor($booking),
                array_map(static fn(array $a): array => ['path' => $a['path'], 'name' => $a['name']], $attachments),
                null,
                null,
                ['Message-ID' => $messageId]
            );
        } catch (\Throwable $e) {
            $this->record($booking, $kind, $email, $attachments, $messageId, $trackingToken, SentEmail::STATUS_FAILED);

            throw $e;
        }

        $this->record($booking, $kind, $email, $attachments, $messageId, $trackingToken, SentEmail::STATUS_SENT);
    }

    /**
     * Writes the log row. Never in the way of the e-mail itself: a log that
     * cannot be written is journaled, and the renter still got their mail.
     *
     * @param list<array{path: string, name: string, document_id?: int|null}> $attachments
     */
    private function record(
        RentalBooking $booking,
        string $kind,
        RenderedEmail $email,
        array $attachments,
        string $messageId,
        ?string $trackingToken,
        string $status
    ): void {
        if ($this->sentEmails === null) {
            return;
        }

        try {
            $this->sentEmails->record(
                $booking->id,
                $kind,
                $booking->renterEmail,
                $email->subject,
                $this->masked($booking, $email->bodyText, $trackingToken, false),
                $this->masked($booking, $email->bodyHtml, $trackingToken, true),
                array_values(array_filter(array_map(
                    static fn(array $a): ?int => $a['document_id'] ?? null,
                    $attachments
                ), static fn(?int $id): bool => $id !== null)),
                $messageId,
                $status,
                new \DateTimeImmutable()
            );
        } catch (\Throwable) {
            $this->journal->log(
                'rental',
                'rental_sent_email_not_recorded',
                'warning',
                "Un e-mail envoyé pour " . $booking->reference . " n'a pas pu être inscrit dans son courrier.",
                ['booking_id' => $booking->id, 'kind' => $kind]
            );
        }
    }

    /**
     * The body with the tracking link — and the bare token, wherever else
     * it could appear — replaced by `SentEmail::MASKED_LINK`.
     */
    private function masked(RentalBooking $booking, string $body, ?string $trackingToken, bool $isHtml): string
    {
        if ($trackingToken === null || $trackingToken === '') {
            return $body;
        }

        $url = $this->trackingUrl($booking, $trackingToken);
        $replacements = [$url => SentEmail::MASKED_LINK];
        if ($isHtml) {
            $replacements[htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')] = SentEmail::MASKED_LINK;
        }

        return str_replace($trackingToken, SentEmail::MASKED_LINK, strtr($body, $replacements));
    }

    /**
     * « Renvoyer » (#720): the logged e-mail again, as it was written —
     * with the booking's CURRENT tracking link where the old one was masked,
     * and its attachments read from the documents they are. Recorded as a
     * new entry of the log, sent or failed.
     *
     * @param \Closure(int): ?array{path: string, name: string} $attachmentOf the
     *     booking's document as a file to attach, null when it is gone
     * @throws RentalException in French, when it cannot be resent as it was
     * @throws \Throwable whatever MailService throws, once recorded
     */
    public function resend(
        SentEmail $sent,
        RentalBooking $booking,
        ?string $trackingToken,
        \Closure $attachmentOf
    ): void {
        if ($sent->bookingId !== $booking->id) {
            throw new RentalException("Cet e-mail n'appartient pas à cette réservation.");
        }

        $link = '';
        if ($sent->carriesTheTrackingLink()) {
            if ($trackingToken === null || $trackingToken === '') {
                throw new RentalException(
                    "Cet e-mail portait le lien de suivi, qui n'est plus disponible : régénérez-le d'abord."
                );
            }
            $link = $this->trackingUrl($booking, $trackingToken);
        }

        $attachments = [];
        foreach ($sent->documentIds as $documentId) {
            $file = $attachmentOf($documentId);
            if ($file === null) {
                throw new RentalException("Une pièce jointe de cet e-mail n'existe plus : il ne peut pas être renvoyé tel quel.");
            }
            $attachments[] = $file + ['document_id' => $documentId];
        }

        $email = new RenderedEmail(
            $sent->subject,
            str_replace(SentEmail::MASKED_LINK, htmlspecialchars($link, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $sent->bodyHtml),
            str_replace(SentEmail::MASKED_LINK, $link, $sent->bodyText)
        );

        $this->deliver($booking, $sent->kind, $email, $attachments, $this->messageIdFor($booking), $trackingToken);
    }

    /**
     * A fresh Message-ID for a message about this booking, remembered so
     * the reply to it is recognised.
     */
    /**
     * The signed reply address of this booking (§8.58), so a bare
     * « Répondre » comes back naming it — null without `inbound_mail`,
     * when the operator turned it off, or when no box can receive it,
     * and the mail then goes out with the site's ordinary sender.
     */
    private function replyAddressFor(RentalBooking $booking): ?string
    {
        // The signed address only (#720): it lands on a box the rentals
        // read AND names the booking. Rentals no longer tell a box
        // « dedicated » to them from a shared one, so there is no box of
        // their own to fall back on; without a signed address the mail goes
        // out with the site's ordinary sender, and a reply quoting the
        // reference is still matched wherever it lands.
        return $this->inboundMail?->replyAddressFor(
            \Modules\Rental\Mail\RentalMessageConsumer::CONSUMER_ID,
            $booking->reference
        );
    }

    private function messageIdFor(RentalBooking $booking): string
    {
        $messageId = $this->newMessageId();
        $this->inboundMail?->recordOutboundMessageId(
            \Modules\Rental\Mail\RentalMessageConsumer::CONSUMER_ID,
            $booking->reference,
            $messageId
        );

        return $messageId;
    }

    /**
     * The renter's acknowledgement, carrying their tracking link.
     *
     * If this email is not sent, the renter has nothing: no account, no
     * notification centre, and no link. That is exactly why it cannot be
     * switched off, and why a delivery failure is journaled loudly rather
     * than swallowed.
     *
     * @return string The Message-ID, for threading later replies.
     */
    public function sendAcknowledgement(
        RentalBooking $booking,
        RentalAsset $asset,
        string $trackingToken
    ): string {
        $messageId = $this->messageIdFor($booking);
        $trackingUrl = $this->trackingUrl($booking, $trackingToken);

        $email = $this->renderFor($booking, $asset, 'rental.acknowledgement', [
            'tracking_url' => $trackingUrl,
            // A whole sentence rather than a date: `hold_note` is a
            // DECLARED variable, so a booking with no hold has to leave
            // nothing behind in a customised body — an empty date would
            // have left « Nous bloquons ces dates jusqu'au . »
            'hold_note' => $booking->holdUntil !== null
                ? 'Nous bloquons ces dates jusqu\'au ' . $booking->holdUntil->format('d/m/Y')
                    . ', le temps de vous répondre.'
                : '',
        ]);

        $this->deliver(
            $booking,
            'rental.acknowledgement',
            $email,
            [],
            $messageId,
            $trackingToken
        );

        // The URL contains the token, so it is NEVER journaled — a journal
        // entry carrying one is a permanent, readable copy of a credential
        // (§13 of the conventions), and journals are read by more people
        // and kept for longer than the row it came from.
        $this->journal->log(
            'rental',
            'rental_acknowledgement_sent',
            'info',
            'Accusé de réception envoyé pour ' . $booking->reference,
            ['booking_id' => $booking->id]
        );

        return $messageId;
    }

    /**
     * Tells the renter what a manager decided (§6.15, §6.16).
     *
     * None of these were sent. A booking was confirmed, refused, answered
     * with a proposal or with a question, and the renter — who has no
     * account, no notification centre and no reason to reload a page they
     * saw once — was told nothing at all. `propose()` set a flash reading
     * « Proposition envoyée » next to an outbox that had stayed empty.
     *
     * Automatic rather than a checkbox. A decision that reaches nobody is
     * not a decision, and an opt-in would make "did the renter get told?"
     * a question with two answers instead of one. What the manager DOES
     * choose is `$managerWord`: the sentence that turns « nous ne pouvons
     * pas donner suite » into « le gîte est déjà pris ce week-end-là ». It
     * is optional, and its absence changes nothing structural — the email
     * still says what happened.
     *
     * Returns whether it went out. A failed email must not undo a decision
     * that is already recorded: the manager sees a warning and can resend,
     * which is far better than a confirmation that rolls itself back
     * because an SMTP server was briefly down.
     *
     * @param string|null $trackingToken null skips the link rather than
     *        failing the email — see RentalBookingService::trackingTokenFor().
     */
    public function sendDecision(
        RentalBooking $booking,
        RentalAsset $asset,
        RenterDecision $decision,
        ?string $trackingToken,
        ?string $managerWord = null
    ): bool {
        $word = $managerWord !== null ? trim($managerWord) : '';

        // Seven decisions share this one e-mail and each has its own
        // subject line, which no single `default_subject` could state. It
        // is therefore a DECLARED VARIABLE — the manifest's default
        // subject is `{{ decision_subject }}` — so the shipped subject is
        // still exactly $decision->subject(), and a unit that reworded the
        // e-mail can put the decision's own words back wherever it wants.
        $email = $this->renderFor($booking, $asset, 'rental.decision', [
            'decision_subject' => $decision->subject(),
            'announcement' => $decision->announcement(),
            'call_to_action' => $decision->callToAction() ?? '',
            'manager_word' => $word,
            'tracking_url' => $decision->carriesTheTrackingLink() && $trackingToken !== null && $trackingToken !== ''
                ? $this->trackingUrl($booking, $trackingToken)
                : '',
        ]);

        try {
            $this->deliver(
                $booking,
                'rental.decision',
                $email,
                [],
                $this->messageIdFor($booking),
                $trackingToken
            );
        } catch (\Throwable) {
            // Not journaled with the address, which would put personal data
            // in the journal (SECURITY.md §5), and not re-thrown: see above.
            $this->journal->log(
                'rental',
                'rental_decision_email_failed',
                'warning',
                "L'email de décision n'a pas pu partir pour " . $booking->reference,
                ['booking_id' => $booking->id, 'decision' => $decision->value]
            );

            return false;
        }

        // The decision and the reference. Never the manager's word, which
        // is a message between two people, and never the URL, which
        // contains the token.
        $this->journal->log(
            'rental',
            'rental_decision_email_sent',
            'info',
            'Décision communiquée au locataire pour ' . $booking->reference,
            ['booking_id' => $booking->id, 'decision' => $decision->value]
        );

        return true;
    }

    /**
     * Sends a generated document to the renter (§6.24, §6.26).
     *
     * **This is the only way a renter ever receives their contract or their
     * invoice.** They have no account, and the tracking token is a
     * capability for their own page rather than a file credential — so
     * there is no download link anywhere, and the only recourse for a lost
     * email is a manager pressing this again. That is why resending is a
     * first-class action rather than something to work around.
     *
     * @param string $absolutePath The file's real path on disk, resolved by the caller.
     * @return string The Message-ID, for threading later replies.
     * @throws \Core\Mail\MailException
     */
    public function sendDocument(
        RentalBooking $booking,
        RentalAsset $asset,
        string $documentLabel,
        string $absolutePath,
        string $fileName,
        bool $isResend = false,
        ?\Modules\Rental\Document\DocumentType $type = null,
        ?int $documentId = null
    ): string {
        $messageId = $this->messageIdFor($booking);

        // The subject names the document and says whether this is a
        // resend, which again no fixed `default_subject` could state: the
        // manifest declares `{{ document_subject }}` and gets the same
        // string this e-mail has always carried.
        $email = $this->renderFor($booking, $asset, 'rental.document', [
            'document_subject' => ($isResend ? 'À nouveau : ' : '') . $documentLabel,
            'document_label' => $documentLabel,
        ], $type === \Modules\Rental\Document\DocumentType::INVOICE ? RenterNextStep::payInvoice() : null);

        $this->deliver(
            $booking,
            'rental.document',
            $email,
            [['path' => $absolutePath, 'name' => $fileName, 'document_id' => $documentId]],
            $messageId,
            null
        );

        // The reference and the document's label, never the renter and
        // never the file's path (SECURITY.md §5).
        $this->journal->log(
            'rental',
            'rental_document_sent',
            'info',
            $documentLabel . ' envoyé pour ' . $booking->reference,
            ['booking_id' => $booking->id, 'is_resend' => $isResend]
        );

        return $messageId;
    }

    /**
     * `[LOC-2027-0042] Votre demande de location`.
     *
     * The reference goes in **every** subject, and first: it is the most
     * reliable of the matching rules for an inbound reply (§7.6), far ahead
     * of guessing from the sender's address.
     */
    /**
     * The contract, attached, saying what to do with it (#708, IT-16): sign
     * it and send a copy back from the renter's page before the dates stop
     * being held — with the link, and the date. The generic document e-mail
     * says the opposite (« ne peut pas être téléchargé »), and the contract
     * is the one document that comes back.
     *
     * @return string The Message-ID, for threading later replies.
     * @throws \Core\Mail\MailException
     */
    public function sendContract(
        RentalBooking $booking,
        RentalAsset $asset,
        string $documentLabel,
        string $absolutePath,
        string $fileName,
        bool $isResend,
        ?string $trackingToken,
        ?\DateTimeImmutable $holdUntil,
        ?int $documentId = null
    ): string {
        $messageId = $this->messageIdFor($booking);
        $email = $this->renderFor($booking, $asset, 'rental.contract', [
            'document_subject' => ($isResend ? 'À nouveau : ' : '') . $documentLabel,
            'tracking_url' => $trackingToken !== null ? $this->trackingUrl($booking, $trackingToken) : '',
            'hold_until' => $holdUntil !== null ? $holdUntil->format('d/m/Y') : '',
        ], RenterNextStep::signContract($booking, new \DateTimeImmutable(), $holdUntil));

        $this->deliver(
            $booking,
            'rental.contract',
            $email,
            [['path' => $absolutePath, 'name' => $fileName, 'document_id' => $documentId]],
            $messageId,
            $trackingToken
        );

        $this->journal->log(
            'rental',
            'rental_document_sent',
            'info',
            $documentLabel . ' envoyé pour ' . $booking->reference,
            ['booking_id' => $booking->id, 'is_resend' => $isResend]
        );

        return $messageId;
    }

    /**
     * The hold that waits for the signed copy ends soon, and no copy came
     * back (#708, IT-16): said once, so the renter's dates do not free
     * themselves without them ever knowing.
     *
     * @return bool whether it went out
     */
    public function sendSignedCopyReminder(RentalBooking $booking, RentalAsset $asset, ?string $trackingToken): bool
    {
        $email = $this->renderFor($booking, $asset, 'rental.signed_copy_reminder', [
            'tracking_url' => $trackingToken !== null ? $this->trackingUrl($booking, $trackingToken) : '',
            'hold_until' => $booking->holdUntil !== null ? $booking->holdUntil->format('d/m/Y') : '',
        ]);

        try {
            $this->deliver(
                $booking,
                'rental.signed_copy_reminder',
                $email,
                [],
                $this->messageIdFor($booking),
                $trackingToken
            );
        } catch (\Throwable) {
            return false;
        }

        $this->journal->log(
            'rental',
            'rental_signed_copy_reminder_sent',
            'info',
            'Rappel de la copie signée envoyé pour ' . $booking->reference,
            ['booking_id' => $booking->id]
        );

        return true;
    }

    /**
     * The renter's signed copy was refused (#708, IT-16): the unit's reason,
     * and the way back to their page to send another.
     *
     * @return bool whether it went out
     */
    public function sendCopyRefused(
        RentalBooking $booking,
        RentalAsset $asset,
        string $reason,
        ?string $trackingToken
    ): bool {
        $email = $this->renderFor($booking, $asset, 'rental.copy_refused', [
            'refusal_reason' => $reason,
            'tracking_url' => $trackingToken !== null ? $this->trackingUrl($booking, $trackingToken) : '',
        ]);

        try {
            $this->deliver(
                $booking,
                'rental.copy_refused',
                $email,
                [],
                $this->messageIdFor($booking),
                $trackingToken
            );
        } catch (\Throwable) {
            return false;
        }

        // The reference, never the reason: it may name the renter.
        $this->journal->log(
            'rental',
            'rental_signed_copy_refused_sent',
            'info',
            'Refus de la copie signée envoyé pour ' . $booking->reference,
            ['booking_id' => $booking->id]
        );

        return true;
    }

    /**
     * The contract signed by both parties (#708, IT-16), attached — and,
     * unlike every other document, also downloadable from the renter's
     * page, which this e-mail says rather than the opposite.
     *
     * @return string The Message-ID, for threading later replies.
     * @throws \Core\Mail\MailException
     */
    public function sendSignedContract(
        RentalBooking $booking,
        RentalAsset $asset,
        string $absolutePath,
        string $fileName,
        ?string $trackingToken,
        ?int $documentId = null
    ): string {
        $messageId = $this->messageIdFor($booking);
        $email = $this->renderFor($booking, $asset, 'rental.signed_contract', [
            'tracking_url' => $trackingToken !== null ? $this->trackingUrl($booking, $trackingToken) : '',
        ]);

        $this->deliver(
            $booking,
            'rental.signed_contract',
            $email,
            [['path' => $absolutePath, 'name' => $fileName, 'document_id' => $documentId]],
            $messageId,
            $trackingToken
        );

        $this->journal->log(
            'rental',
            'rental_signed_contract_sent',
            'info',
            'Contrat signé par les deux parties envoyé pour ' . $booking->reference,
            ['booking_id' => $booking->id]
        );

        return $messageId;
    }

    /**
     * The practical-info email, a week before arrival (§6.29).
     *
     * **The only reminder that reaches the renter**, and it goes by email
     * because a renter has no `user_account` and therefore no notification
     * centre — dispatching it as a notification would silently reach
     * nobody.
     *
     * Deliberately carries no tracking link. This is a reminder, not a new
     * authorisation: the renter already has their link from the
     * acknowledgement, and re-issuing a capability inside an email nobody
     * asked for is how one ends up forwarded.
     *
     * @param array<int, array{display_name: string, phone: ?string}> $contacts
     * @return bool whether it actually went out
     */
    public function sendPracticalInfo(RentalBooking $booking, RentalAsset $asset, array $contacts = []): bool
    {
        $email = $this->renderFor($booking, $asset, 'rental.practical_info', [
            'contacts' => self::contactLines($contacts),
            'timing_note' => self::timingNote($asset),
            'emergency_phone' => $asset->emergencyPhone ?? '',
        ]);

        try {
            $this->deliver(
                $booking,
                'rental.practical_info',
                $email,
                [],
                $this->messageIdFor($booking),
                null
            );
        } catch (\Throwable) {
            // A reminder that could not be sent must not take the whole
            // reminder run down with it — and the failure is deliberately
            // not logged with the address, which would be personal data in
            // the journal (§6.29, SECURITY.md §5).
            return false;
        }

        // The reference and nothing else: no name, no address (§6.28).
        $this->journal->log(
            'rental',
            'rental_practical_info_sent',
            'info',
            'Informations pratiques envoyées pour ' . $booking->reference,
            ['booking_id' => $booking->id]
        );

        return true;
    }

    /**
     * The renter's new tracking link, after a manager regenerated it.
     *
     * Its own message rather than a second acknowledgement: « Nous avons
     * bien reçu votre demande » on a booking confirmed three weeks ago
     * reads as a duplicate the renter has to work out, and the one thing
     * this email has to be is unambiguous — the link they had has just
     * stopped working.
     *
     * The manager never sees the token (§8.52): the only way it reaches
     * anybody is this message, addressed to the renter.
     *
     * @return bool whether it went out — a manager who is told it did not
     *         still knows the old link is dead.
     */
    public function sendTrackingLink(
        RentalBooking $booking,
        RentalAsset $asset,
        string $trackingToken
    ): bool {
        $email = $this->renderFor($booking, $asset, 'rental.tracking_link', [
            'tracking_url' => $this->trackingUrl($booking, $trackingToken),
        ]);

        try {
            $this->deliver(
                $booking,
                'rental.tracking_link',
                $email,
                [],
                $this->messageIdFor($booking),
                $trackingToken
            );
        } catch (\Throwable) {
            $this->journal->log(
                'rental',
                'rental_tracking_link_email_failed',
                'warning',
                "Le nouveau lien de suivi n'a pas pu partir pour " . $booking->reference,
                ['booking_id' => $booking->id]
            );

            return false;
        }

        // The URL contains the token, so neither it nor the token is ever
        // journaled — a journal entry carrying one is a permanent, readable
        // copy of a credential.
        $this->journal->log(
            'rental',
            'rental_tracking_link_sent',
            'info',
            'Nouveau lien de suivi envoyé pour ' . $booking->reference,
            ['booking_id' => $booking->id]
        );

        return true;
    }

    /**
     * A stored `Y-m-d` as the site writes dates everywhere else — the
     * PHP twin of Twig's `date_fr` filter, needed here because a declared
     * variable is a finished string by the time it reaches a template.
     */
    private static function dateFr(string $date): string
    {
        // Through DateInput, the one parsing seam in the project
        // (Tests\Security\DateParsingConvergenceTest).
        return DateInput::iso($date)?->format('d/m/Y') ?? $date;
    }

    /**
     * The on-site contacts as one block of plain text, one per line — the
     * shape `contacts` has to have to be a declared variable that survives
     * into a reworded e-mail.
     *
     * @param array<int, array{display_name: string, phone: ?string}> $contacts
     */
    private static function contactLines(array $contacts): string
    {
        $lines = [];
        foreach ($contacts as $contact) {
            $phone = $contact['phone'] ?? null;
            $lines[] = $contact['display_name'] . ($phone !== null && $phone !== '' ? ' — ' . $phone : '');
        }

        return implode("\n", $lines);
    }

    /**
     * Arrival and departure times as one sentence, empty when the asset
     * declares neither. One variable rather than two, for the same reason
     * `hold_note` is a sentence: half of a sentence substituted into a
     * customised body is worse than none of it.
     */
    private static function timingNote(RentalAsset $asset): string
    {
        $parts = [];
        if ($asset->arrivalTime !== null && $asset->arrivalTime !== '') {
            $parts[] = 'Arrivée à partir de ' . $asset->arrivalTime . '.';
        }
        if ($asset->departureTime !== null && $asset->departureTime !== '') {
            $parts[] = 'Départ pour ' . $asset->departureTime . ' au plus tard.';
        }

        return implode(' ', $parts);
    }

    public function subjectFor(RentalBooking $booking, string $subject): string
    {
        return '[' . $booking->reference . '] ' . $subject;
    }

    /**
     * One of this module's declared e-mails, rendered through the register
     * (ARCHITECTURE.md §8.7bis) rather than by rendering Twig here.
     *
     * **Declared scalars, and nothing else.** The shipped templates used
     * to walk `booking` and `asset` as objects while the manifest declared
     * a handful of flat strings, so the two paths said different things:
     * a customised e-mail lost the renter's name and both dates, and the
     * default wording Configuration > E-mails offered an administrator was
     * that same template rendered with no booking at all — « Bonjour , du
     *  au  ». One context, one substitutable surface, one message.
     *
     * **The reference prefix stays outside.** `subjectFor()` is applied to
     * whatever subject comes back, shipped or customised, because
     * `[LOC-2027-0042]` is what ties a renter's reply back to their
     * booking (the module's inbound-mail matching reads it). An
     * administrator rewording the subject must not be able to break the
     * threading of every conversation without noticing.
     *
     * @param array<string, mixed> $context
     */
    private function renderFor(
        RentalBooking $booking,
        RentalAsset $asset,
        string $templateId,
        array $context,
        ?RenterNextStep $nextStep = null
    ): RenderedEmail
    {
        $email = $this->emailTemplateRenderer->render(
            $templateId,
            $context + $this->acceptedConditionsNote($booking, $asset, $templateId)
            + $this->nextStepContext($booking, $asset, $templateId, $context, $nextStep) + [
                'reference' => $booking->reference,
                'asset_name' => $asset->name,
                'renter_name' => $booking->renterName,
                'arrival_date' => self::dateFr($booking->arrivalDate),
                'departure_date' => self::dateFr($booking->departureDate),
                'site_name' => $this->settingService->get('site_name') ?: 'Notre unité',
            ]
        );

        return new RenderedEmail(
            subject: $this->subjectFor($booking, $email->subject),
            bodyHtml: $email->bodyHtml,
            bodyText: $email->bodyText
        );
    }

    /**
     * The renter's « Et maintenant ? » as their e-mails say it (#708,
     * IT-15) — for the tracking page, which says the very same sentence
     * under the status: the page and the e-mails cannot contradict each
     * other. Null when it cannot be worked out.
     */
    public function renterNextStep(RentalBooking $booking, RentalAsset $asset): ?RenterNextStep
    {
        try {
            return $this->journey?->renterNextStep($booking, $asset, new \DateTimeImmutable());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * « Et maintenant ? » (#708, IT-15): what the renter has to do next, in
     * the frame of every e-mail they receive (email/base.html.twig), so a
     * customised body cannot drop it. The booking's next step unless the
     * e-mail says better — the contract calls for its signature before the
     * booking has moved, an invoice for its payment. The tracking link
     * goes with it whenever the thing to do is done there and the e-mail
     * already carries the link.
     *
     * Never fails an e-mail: a block that cannot be worked out is left
     * out, and the message still goes.
     *
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private function nextStepContext(
        RentalBooking $booking,
        RentalAsset $asset,
        string $templateId,
        array $context,
        ?RenterNextStep $nextStep
    ): array {
        if (!in_array($templateId, self::RENTER_TEMPLATES, true) && $templateId !== 'rental.tracking_link') {
            return [];
        }

        $nextStep ??= $this->renterNextStep($booking, $asset);
        if ($nextStep === null) {
            return [];
        }

        $trackingUrl = $context['tracking_url'] ?? null;
        $link = $nextStep->onTrackingPage && is_string($trackingUrl) ? $trackingUrl : '';

        return [
            'next_step' => $nextStep->sentence,
            'next_step_link' => $link,
            'next_step_link_label' => $link !== '' ? 'Ouvrir ma page de suivi' : '',
        ];
    }

    /**
     * The e-mails that go to the renter, and so carry the link to the
     * conditions they accepted (issue #494). Not the managers' notification:
     * they have the settings page, and the note would read as addressed to
     * the wrong person. Not the tracking-link resend either: it answers a
     * « j'ai perdu mon lien » and nothing else.
     */
    private const RENTER_TEMPLATES = [
        'rental.acknowledgement',
        'rental.decision',
        'rental.document',
        'rental.practical_info',
        'rental.copy_refused',
        'rental.signed_contract',
        'rental.contract',
        'rental.signed_copy_reminder',
    ];

    /**
     * « Conditions de location acceptées le … » and the permanent address of
     * THAT version — never today's, which may say something else by the
     * time the renter clicks.
     *
     * Through the frame's footer note (email/base.html.twig) rather than a
     * declared variable: the frame is code, so an administrator rewording
     * one of these e-mails cannot drop the one line a renter may need to
     * prove what they agreed to.
     *
     * Nothing at all when the booking's version is not in the archive —
     * a booking whose conditions were overwritten before the archive
     * existed. A link to some other text would be worse than none.
     *
     * @return array{footer_note?: string, footer_link?: string}
     */
    private function acceptedConditionsNote(RentalBooking $booking, RentalAsset $asset, string $templateId): array
    {
        if ($this->conditions === null
            || !in_array($templateId, self::RENTER_TEMPLATES, true)
            || $booking->conditionsVersion === null
            || $booking->conditionsAcceptedAt === null
        ) {
            return [];
        }

        $version = $this->conditions->find($asset->id, $booking->conditionsVersion);
        if ($version === null
            || $booking->conditionsHash === null
            || !hash_equals($version->hash, $booking->conditionsHash)
        ) {
            return [];
        }

        return [
            'footer_note' => 'Conditions de location acceptées le '
                . $booking->conditionsAcceptedAt->format('d/m/Y') . ' :',
            'footer_link' => rtrim($this->baseUrl(), '/')
                . '/locations/' . $asset->slug . '/conditions/' . $version->version,
        ];
    }

    /**
     * The renter's tracking URL, token included.
     *
     * Never logged and never shown to anyone but the renter: possession of
     * this URL *is* the authorisation.
     */
    public function trackingUrl(RentalBooking $booking, string $trackingToken): string
    {
        return rtrim($this->baseUrl(), '/') . '/locations/suivi/' . $booking->id . '/' . $trackingToken;
    }

    /**
     * A stable, globally-unique `Message-ID` of our own.
     *
     * Kept so a reply's `In-Reply-To`/`References` can be matched back to
     * this booking with certainty. The domain half comes from the configured
     * base URL so the id looks like it belongs to this installation; the
     * local half is random, never derived from the booking, so an id cannot
     * be guessed and used to forge a threaded reply.
     */
    public function newMessageId(): string
    {
        $host = parse_url($this->baseUrl(), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            $host = 'scoutmagic.local';
        }

        return '<' . bin2hex(random_bytes(16)) . '@' . $host . '>';
    }

    private function baseUrl(): string
    {
        return (string) ($this->settingService->get('base_url') ?: '');
    }
}
