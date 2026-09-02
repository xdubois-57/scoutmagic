<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Service\DateInput;
use Modules\Calendar\Api\IcsFeedBuilderInterface;
use Modules\Calendar\Api\VirtualEvent;
use Modules\News\Repository\Article;
use Modules\News\Repository\FormResponse;
use Modules\News\Repository\NewsForm;

/**
 * Everything about a ticket that ends up in an e-mail: the variables the
 * confirmation carries, the calendar file attached to it, and the
 * standalone « voici votre billet » message.
 *
 * Split out of Service\ResponseService rather than living there, for one
 * concrete reason: the catch-up that follows raising a form's ticketing
 * switch runs in a scheduled task (Task\SendPendingTicketsHandler), and a
 * task context carries a database connection, a mailer and a journal —
 * not the role resolver and the section service ResponseService needs for
 * the submission path it is actually about. A handler forced to
 * reconstruct that whole graph to send one e-mail is how a constructor
 * signature drifts out of step with its one caller.
 */
class TicketMailService
{
    public function __construct(
        private MailService $mailService,
        private EmailTemplateRenderer $emailTemplateRenderer,
        private string $siteName,
        // The calendar module is an optional dependency (ARCHITECTURE.md
        // §7.5): with it disabled the builder is null, the e-mail carries
        // no attachment, and everything else is unchanged.
        private ?IcsFeedBuilderInterface $icsBuilder = null
    ) {
    }

    /**
     * The ticket's own variables for the confirmation e-mail's context.
     *
     * `ticket_reference` and `event_summary` are DECLARED variables and
     * therefore survive a reworded body; `ticket_qr` is an image, which
     * the template editor cannot store, so it is shipped-only. That split
     * is the point: most mail clients block images by default, and a
     * buyer whose QR did not render must still have something to present
     * at the door.
     *
     * @return array{ticket_reference: string, ticket_qr: string, event_summary: string}
     */
    public function ticketVariables(NewsForm $form, ?string $canonicalReference): array
    {
        return [
            'ticket_reference' => $canonicalReference !== null ? TicketService::format($canonicalReference) : '',
            'ticket_qr' => $canonicalReference !== null ? TicketService::qrDataUri($canonicalReference) : '',
            'event_summary' => self::eventLines($form),
        ];
    }

    /**
     * Posts the ticket on its own, to somebody who registered before the
     * form started issuing them.
     *
     * This is the catch-up half of raising the switch: without it, the
     * first families to sign up for an event are the ones who turn up at
     * the door with nothing. Everybody registering afterwards gets the
     * same ticket inside their ordinary confirmation, so this message
     * exists for that one moment and no other.
     *
     * @throws \Core\Mail\MailException so a caller sending a batch can
     *         journal one failure and carry on with the rest.
     */
    public function sendTicketEmail(Article $article, NewsForm $form, FormResponse $response): void
    {
        if (!$response->hasTicket()) {
            return;
        }

        $email = $this->emailTemplateRenderer->render('news.ticket', array_merge(
            ['site_name' => $this->siteName, 'article_title' => $article->title],
            $this->ticketVariables($form, (string) $response->ticketReference)
        ));

        $this->sendWithIcs($article, $form, $response->contactEmail, $email->subject, $email->bodyHtml, $email->bodyText);
    }

    /**
     * Sends a message and attaches the event's calendar file when there
     * is one — the one place that pairing is written, so the confirmation
     * and the standalone ticket cannot disagree about when an ICS rides
     * along.
     *
     * @throws \Core\Mail\MailException
     */
    public function sendWithIcs(
        Article $article,
        NewsForm $form,
        string $to,
        string $subject,
        string $bodyHtml,
        string $bodyText
    ): void {
        $icsPath = $this->writeIcs($article, $form);

        try {
            $this->mailService->send(
                to: $to,
                subject: $subject,
                bodyHtml: $bodyHtml,
                bodyText: $bodyText,
                attachments: $icsPath !== null ? [['path' => $icsPath, 'name' => 'evenement.ics']] : []
            );
        } finally {
            if ($icsPath !== null) {
                @unlink($icsPath);
            }
        }
    }

    /**
     * The event's date and place as one block of plain text, or '' when
     * the form carries none — which is what the templates'
     * `{% if event_summary %}` reads.
     *
     * A ticket forwarded to a friend who never read the article has to be
     * self-contained. A form with no date says only what it is for, and
     * that is a perfectly usable degraded mode: nobody forgets when they
     * are going out.
     */
    public static function eventLines(NewsForm $form): string
    {
        if (!$form->hasEventDetails()) {
            return '';
        }

        $lines = ['Date : ' . self::frenchDate((string) $form->eventDate)];
        if ($form->eventLocation !== null && $form->eventLocation !== '') {
            $lines[] = 'Lieu : ' . $form->eventLocation;
        }

        return implode("\n", $lines);
    }

    /**
     * `2026-03-14` → `14/03/2026`; the raw value if it will not parse.
     *
     * Through `Core\Service\DateInput` rather than PHP's own
     * format-parsing constructor — see
     * `Tests\Security\DateParsingConvergenceTest` for the NUL-byte
     * ValueError that idiom carries.
     */
    public static function frenchDate(string $isoDate): string
    {
        return DateInput::iso($isoDate)?->format('d/m/Y') ?? $isoDate;
    }

    /**
     * The calendar file, written to a temp path because MailService
     * attaches paths rather than bytes. The caller deletes it.
     *
     * `Modules\Calendar\Api\IcsFeedBuilderInterface` already renders a
     * standalone calendar from a VirtualEvent, so nothing is written here
     * beyond the event itself.
     *
     * **Once sent, an ICS is frozen.** It lives in the recipient's own
     * calendar with the date and place it was made with, and nothing this
     * site does later updates it — which is exactly what the article
     * editor warns about before either value is changed.
     */
    private function writeIcs(Article $article, NewsForm $form): ?string
    {
        if ($this->icsBuilder === null || !$form->hasEventDetails()) {
            return null;
        }

        $event = new VirtualEvent(
            // Stable, and derived from the form's own identity: a reader
            // who ends up with two of these gets one calendar entry
            // updated rather than a second copy of the evening.
            uid: 'news-form-' . $form->id . '@scoutmagic',
            // No calendar to belong to — this file IS the calendar. Zero
            // rather than an invented id, same as the rental module's
            // renter feed.
            calendarId: 0,
            title: $article->title,
            startDate: (string) $form->eventDate,
            endDate: (string) $form->eventDate,
            startTime: null,
            endTime: null,
            location: $form->eventLocation,
            description: null,
            url: null
        );

        $path = tempnam(sys_get_temp_dir(), 'news-ics-');
        if ($path === false) {
            return null;
        }
        if (file_put_contents($path, $this->icsBuilder->buildVirtualCalendar($article->title, [$event])) === false) {
            @unlink($path);
            return null;
        }

        return $path;
    }
}
