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
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Booking\RenterDecision;
use Modules\Rental\Repository\RentalAsset;
use Twig\Environment;

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
        private Environment $twig,
        private SettingService $settingService,
        private JournalService $journal
    ) {
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
        $messageId = $this->newMessageId();
        $trackingUrl = $this->trackingUrl($booking, $trackingToken);

        $context = [
            'booking' => $booking,
            'asset' => $asset,
            'tracking_url' => $trackingUrl,
            'site_name' => $this->settingService->get('site_name') ?: 'Notre unité',
        ];

        $this->mailService->send(
            $booking->renterEmail,
            $this->subjectFor($booking, 'Votre demande de location'),
            $this->twig->render('@rental/email/acknowledgement.html.twig', $context),
            $this->twig->render('@rental/email/acknowledgement.text.twig', $context),
            null,
            [],
            null,
            null,
            ['Message-ID' => $messageId]
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

        $context = [
            'booking' => $booking,
            'asset' => $asset,
            'announcement' => $decision->announcement(),
            'call_to_action' => $decision->callToAction(),
            'manager_word' => $word !== '' ? $word : null,
            'tracking_url' => $decision->carriesTheTrackingLink() && $trackingToken !== null && $trackingToken !== ''
                ? $this->trackingUrl($booking, $trackingToken)
                : null,
            'site_name' => $this->settingService->get('site_name') ?: 'Notre unité',
        ];

        try {
            $this->mailService->send(
                $booking->renterEmail,
                $this->subjectFor($booking, $decision->subject()),
                $this->twig->render('@rental/email/decision.html.twig', $context),
                $this->twig->render('@rental/email/decision.text.twig', $context),
                null,
                [],
                null,
                null,
                ['Message-ID' => $this->newMessageId()]
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
     * The managers' notification.
     *
     * Deliberately **minimal** (§15 of the conventions): it says a request
     * arrived and links to the secured page. It carries no renter identity,
     * because an inbox is not a place to scatter copies of personal data —
     * and because a manager who needs the detail is one click from the page
     * that shows it behind a real permission check.
     *
     * @param string[] $recipientEmails
     * @return string The Message-ID.
     */
    public function sendManagerNotification(
        RentalBooking $booking,
        RentalAsset $asset,
        array $recipientEmails
    ): string {
        $messageId = $this->newMessageId();
        $context = [
            'booking' => $booking,
            'asset' => $asset,
            // The booking itself, not the module's front door. A manager
            // opening this mail on a phone was landing on a list and
            // having to find the request again — and the link is safe to
            // deep-link precisely because the page behind it is behind a
            // real permission check, which is also why the mail carries no
            // renter identity of its own.
            'manage_url' => rtrim($this->baseUrl(), '/')
                . '/mes-locations/' . rawurlencode($asset->slug) . '/reservations/' . $booking->id,
            'site_name' => $this->settingService->get('site_name') ?: 'Notre unité',
        ];

        $html = $this->twig->render('@rental/email/manager_notification.html.twig', $context);
        $text = $this->twig->render('@rental/email/manager_notification.text.twig', $context);
        $subject = $this->subjectFor($booking, 'Nouvelle demande de location');

        foreach (array_unique(array_filter($recipientEmails)) as $recipient) {
            // One message each rather than a shared To/BCC: a manager's
            // address must not be disclosed to the other managers by the
            // header, and a single bad address must not sink the whole batch.
            $this->mailService->send(
                $recipient,
                $subject,
                $html,
                $text,
                null,
                [],
                null,
                null,
                ['Message-ID' => $messageId]
            );
        }

        $this->journal->log(
            'rental',
            'rental_manager_notification_sent',
            'info',
            'Gestionnaires notifiés pour ' . $booking->reference,
            ['booking_id' => $booking->id, 'recipient_count' => count(array_unique(array_filter($recipientEmails)))]
        );

        return $messageId;
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
        bool $isResend = false
    ): string {
        $messageId = $this->newMessageId();
        $context = [
            'booking' => $booking,
            'asset' => $asset,
            'document_label' => $documentLabel,
            'is_resend' => $isResend,
            'site_name' => $this->settingService->get('site_name') ?: 'Notre unité',
        ];

        $this->mailService->send(
            $booking->renterEmail,
            $this->subjectFor($booking, ($isResend ? 'À nouveau : ' : '') . $documentLabel),
            $this->twig->render('@rental/email/document.html.twig', $context),
            $this->twig->render('@rental/email/document.text.twig', $context),
            null,
            [['path' => $absolutePath, 'name' => $fileName]],
            null,
            null,
            ['Message-ID' => $messageId]
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
        $context = [
            'booking' => $booking,
            'asset' => $asset,
            'contacts' => $contacts,
            'site_name' => $this->settingService->get('site_name') ?: 'Notre unité',
        ];

        try {
            $this->mailService->send(
                $booking->renterEmail,
                $this->subjectFor($booking, 'Informations pratiques avant votre séjour'),
                $this->twig->render('@rental/email/practical_info.html.twig', $context),
                $this->twig->render('@rental/email/practical_info.text.twig', $context),
                null,
                [],
                null,
                null,
                ['Message-ID' => $this->newMessageId()]
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
        $context = [
            'booking' => $booking,
            'asset' => $asset,
            'tracking_url' => $this->trackingUrl($booking, $trackingToken),
            'site_name' => $this->settingService->get('site_name') ?: 'Notre unité',
        ];

        try {
            $this->mailService->send(
                $booking->renterEmail,
                $this->subjectFor($booking, 'Votre nouveau lien de suivi'),
                $this->twig->render('@rental/email/tracking_link.html.twig', $context),
                $this->twig->render('@rental/email/tracking_link.text.twig', $context),
                null,
                [],
                null,
                null,
                ['Message-ID' => $this->newMessageId()]
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

    public function subjectFor(RentalBooking $booking, string $subject): string
    {
        return '[' . $booking->reference . '] ' . $subject;
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
