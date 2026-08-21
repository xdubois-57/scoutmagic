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
     * The raw token exists only for this call — it is stored hashed — so if
     * this email is not sent, the renter has no way to their booking at all.
     * That is exactly why it cannot be switched off, and why a delivery
     * failure is journaled loudly rather than swallowed.
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
        // entry carrying it would be a plaintext copy of a credential we
        // deliberately store only as a hash (§13 of the conventions).
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
            'manage_url' => rtrim($this->baseUrl(), '/') . '/mes-locations',
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
