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
use Modules\Rental\Repository\RentalAsset;

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

        $this->mailService->send(
            $booking->renterEmail,
            $email->subject,
            $email->bodyHtml,
            $email->bodyText,
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
            $this->mailService->send(
                $booking->renterEmail,
                $email->subject,
                $email->bodyHtml,
                $email->bodyText,
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

        // Rendered once, sent many times: every manager receives the same
        // message, and rendering it per recipient would be six identical
        // renders and one more chance for them to differ.
        $email = $this->renderFor($booking, $asset, 'rental.manager_notification', $context);

        foreach (array_unique(array_filter($recipientEmails)) as $recipient) {
            // One message each rather than a shared To/BCC: a manager's
            // address must not be disclosed to the other managers by the
            // header, and a single bad address must not sink the whole batch.
            $this->mailService->send(
                $recipient,
                $email->subject,
                $email->bodyHtml,
                $email->bodyText,
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

        // The subject names the document and says whether this is a
        // resend, which again no fixed `default_subject` could state: the
        // manifest declares `{{ document_subject }}` and gets the same
        // string this e-mail has always carried.
        $email = $this->renderFor($booking, $asset, 'rental.document', [
            'document_subject' => ($isResend ? 'À nouveau : ' : '') . $documentLabel,
            'document_label' => $documentLabel,
        ]);

        $this->mailService->send(
            $booking->renterEmail,
            $email->subject,
            $email->bodyHtml,
            $email->bodyText,
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
        $email = $this->renderFor($booking, $asset, 'rental.practical_info', [
            'contacts' => self::contactLines($contacts),
            'timing_note' => self::timingNote($asset),
            'emergency_phone' => $asset->emergencyPhone ?? '',
        ]);

        try {
            $this->mailService->send(
                $booking->renterEmail,
                $email->subject,
                $email->bodyHtml,
                $email->bodyText,
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
        $email = $this->renderFor($booking, $asset, 'rental.tracking_link', [
            'tracking_url' => $this->trackingUrl($booking, $trackingToken),
        ]);

        try {
            $this->mailService->send(
                $booking->renterEmail,
                $email->subject,
                $email->bodyHtml,
                $email->bodyText,
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
    private function renderFor(RentalBooking $booking, RentalAsset $asset, string $templateId, array $context): RenderedEmail
    {
        $email = $this->emailTemplateRenderer->render($templateId, $context + [
            'reference' => $booking->reference,
            'asset_name' => $asset->name,
            'renter_name' => $booking->renterName,
            'arrival_date' => self::dateFr($booking->arrivalDate),
            'departure_date' => self::dateFr($booking->departureDate),
            'site_name' => $this->settingService->get('site_name') ?: 'Notre unité',
        ]);

        return new RenderedEmail(
            subject: $this->subjectFor($booking, $email->subject),
            bodyHtml: $email->bodyHtml,
            bodyText: $email->bodyText
        );
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
