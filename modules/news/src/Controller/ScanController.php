<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Pdf\DocumentPdfService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\News\Repository\FormResponse;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\ArticleService;
use Modules\News\Service\FormService;
use Modules\News\Service\ScanService;
use Modules\News\Service\TicketService;
use Twig\Environment;

/**
 * « Scanner un billet » — the door.
 *
 * **`role_min: chief`, because it is the animateurs who hold the door**,
 * not only the unit staff. No public route is created anywhere in this
 * feature: the ticket lives in the buyer's e-mail, the control behind a
 * session.
 *
 * **The event lives in the URL, never in a page state.** Two reasons, and
 * both are about an evening rather than a demo: the screen stays open for
 * two hours and a reload must not send the animateur back to a chooser,
 * and the address gets shared between two people taking turns. It is also
 * what makes the breadcrumb possible — a trail is built from a route, never
 * from a choice made inside a JavaScript panel.
 *
 * **Validating writes that the seat is consumed, so a connection is
 * required.** The site reads offline and never writes offline. Rather than
 * let somebody discover that in front of a queue, the screen says so, and
 * the fallback is a printed list.
 */
class ScanController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ArticleService $articleService,
        private FormService $formService,
        private ScanService $scanService,
        private TicketService $ticketService,
        private DocumentPdfService $documentPdfService,
        private JournalService $journalService,
        private string $siteName = ''
    ) {
    }

    /**
     * GET /news/scan — the generic page: the event search, and nothing
     * else.
     *
     * It is where the menu shortcut lands, since a menu cannot know which
     * evening is being held. **With exactly one controllable event it
     * redirects straight to it**: a unit running one dinner a year should
     * not have to pick it out of a list of one.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $events = $this->scanService->listControllableEvents();

        if (count($events) === 1) {
            return $this->redirect('/news/scan/' . $events[0]['form_id']);
        }

        return $this->render('@news/scan/index.html.twig', [
            'events' => $events,
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * GET /news/scan/events — the JSON behind the event search.
     *
     * Same shape as associating a receipt in finance: a JSON route, the
     * results redrawn as one types. But **picking a result navigates**
     * rather than swapping a panel's contents — changing event is a rare
     * gesture, once an evening, and nothing justifies avoiding a
     * navigation when the shareable address is the whole point.
     *
     * @param array<string, string> $params
     */
    public function searchEvents(Request $request, array $params): Response
    {
        $events = $this->scanService->listControllableEvents((string) $request->getQuery('q', ''));

        return $this->json(['success' => true, 'events' => $events]);
    }

    /**
     * GET /news/scan/{form_id} — one evening's door.
     *
     * @param array<string, string> $params
     */
    public function event(Request $request, array $params): Response
    {
        $form = $this->requireTicketedForm($params);
        if ($form === null) {
            return $this->notFound();
        }

        $article = $this->articleService->findById($form->newsArticleId);

        return $this->render('@news/scan/event.html.twig', [
            'form' => $form,
            'article' => $article,
            // A genuinely dynamic ancestor, so a controller trail rather
            // than the route's static breadcrumb: the trail reads
            // « Actualités / Souper spaghetti / Scanner un billet », and
            // the middle step is this article's management page — the one
            // this screen is a tab of.
            'breadcrumb_trail' => [
                ['label' => (string) $article?->title, 'url' => '/news/' . $form->newsArticleId . '/gerer'],
            ],
            'counters' => $this->scanService->counters($form),
            'expects_payment' => $this->scanService->expectsPayment($form),
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * GET /news/scan/{form_id}/lookup — what a scan or a typed query
     * resolves to.
     *
     * `q` is the single field the screen offers: a reference, a name, or
     * an e-mail address. A reference is looked up FIRST and site-wide,
     * which is what lets a ticket for another evening be named rather than
     * called « introuvable » — an « introuvable » would send somebody
     * looking for a fault that does not exist.
     *
     * @param array<string, string> $params
     */
    public function lookup(Request $request, array $params): Response
    {
        $form = $this->requireTicketedForm($params);
        if ($form === null) {
            return $this->json(['success' => false, 'error' => 'Évènement introuvable.'], 404);
        }

        // A row picked out of the several the search offered: the id is
        // resolved against THIS event, so it can never be steered onto
        // another evening's booking by editing the query string.
        $responseId = (int) $request->getQuery('response_id', 0);
        if ($responseId > 0) {
            $response = $this->resolveResponseOfForm($form, $responseId);

            return $this->json([
                'success' => true,
                'verdict' => $response !== null
                    ? $this->presentVerdict($this->scanService->verdictForResponse($form, $response))
                    : $this->presentVerdict($this->scanService->verdictFor($form, '')),
                'matches' => [],
            ]);
        }

        $query = trim((string) $request->getQuery('q', ''));
        if ($query === '') {
            return $this->json(['success' => true, 'verdict' => null, 'matches' => []]);
        }

        // A reference resolves on its own, whatever event it belongs to.
        if (TicketService::canonicalize($query) !== null) {
            $verdict = $this->scanService->verdictFor($form, $query);

            return $this->json([
                'success' => true,
                'verdict' => $this->presentVerdict($verdict),
                'matches' => [],
            ]);
        }

        // Otherwise it is a person: a name or an address, inside THIS
        // event. Several matches are a list to choose from — the QR fails
        // more often than one expects, and « quelqu'un venu à la place de
        // celui qui a réservé » is a normal evening.
        $matches = $this->scanService->searchResponses($form, $query);
        if (count($matches) === 1) {
            return $this->json([
                'success' => true,
                'verdict' => $this->presentVerdict($this->scanService->verdictForResponse($form, $matches[0]['response'])),
                'matches' => [],
            ]);
        }

        return $this->json([
            'success' => true,
            'verdict' => null,
            'matches' => array_map(static fn (array $m) => [
                'response_id' => $m['response']->id,
                'label' => $m['label'],
                'seat_total' => $m['seat_total'],
                'used_at' => $m['used_at'],
            ], $matches),
        ]);
    }

    /**
     * POST /news/scan/{form_id}/validate — the door's one write.
     *
     * **It marks the entry and never touches the receivable.** Paying and
     * coming in are two distinct facts: no extra confirmation is asked for
     * on an unpaid ticket, because a confirmation would slow the door at
     * the exact moment it must not, to produce a trace the responses
     * screen gives for free. The screen shows the payment state so the
     * staff can ask out loud; the gesture stays single.
     *
     * `used` false is the un-marking — a scan by mistake, a validation
     * made too early. The previous site's own operation wrote true or
     * false indifferently, and so does this one.
     *
     * @param array<string, string> $params
     */
    public function validate(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrfJson($request)) !== null) {
            return $guard;
        }

        $form = $this->requireTicketedForm($params);
        if ($form === null) {
            return $this->json(['success' => false, 'error' => 'Évènement introuvable.'], 404);
        }

        // A JSON payload (window.ScoutMagicApi.postJson), so the fields
        // are read off the raw body rather than $_POST — same as
        // NewsController::delete(). The CSRF token travelled in the
        // X-CSRF-Token header and guardCsrfJson() has already checked it.
        $payload = json_decode($request->getRawBody(), true);
        $payload = is_array($payload) ? $payload : [];

        $response = $this->resolveResponseOfForm($form, (int) ($payload['response_id'] ?? 0));
        if ($response === null) {
            return $this->json(['success' => false, 'error' => 'Billet introuvable.'], 404);
        }

        $markUsed = (string) ($payload['used'] ?? '1') === '1';
        if ($markUsed) {
            $this->ticketService->markUsed($response);
        } else {
            $this->ticketService->markUnused($response);
        }

        $this->journalService->log(
            'news',
            $markUsed ? 'ticket_validated' : 'ticket_unvalidated',
            'info',
            $markUsed ? 'Entrée enregistrée pour un billet' : 'Entrée annulée pour un billet',
            // Identifiers only — never the holder's name or address.
            ['form_id' => $form->id, 'response_id' => $response->id],
            (int) AuthSession::getUserAccountId()
        );

        $fresh = $this->ticketService->findByReference((string) $response->ticketReference);

        return $this->json([
            'success' => true,
            'verdict' => $this->presentVerdict($this->scanService->verdictForResponse($form, $fresh ?? $response)),
            'counters' => $this->scanService->counters($form),
        ]);
    }

    /**
     * GET /news/scan/{form_id}/liste — the printable list of expected
     * attendees.
     *
     * **This is the fallback for a decision, not a feature of its own.**
     * Validating writes to the server, so the door needs a connection; the
     * honest answer to "and when it is not there" is a sheet printed the
     * evening before — name, seats, payment state — that the staff ticks
     * with a pen. It does not replace the system, it stops an evening
     * collapsing because the network went down.
     *
     * `Core\Pdf\DocumentPdfService` rather than `PosterPdfService`: a
     * poster is one page built around a QR code, deliberately truncating
     * its title and its body, which is exactly wrong for a list whose
     * whole point is to say everything it says.
     *
     * @param array<string, string> $params
     */
    public function printableList(Request $request, array $params): Response
    {
        $form = $this->requireTicketedForm($params);
        if ($form === null) {
            return $this->notFound();
        }

        $article = $this->articleService->findById($form->newsArticleId);
        $rows = $this->scanService->expectedAttendees($form);
        $expectsPayment = $this->scanService->expectsPayment($form);

        $meta = [];
        if ($form->eventDate !== null) {
            $meta[] = 'Le ' . \Core\Service\DateInput::iso($form->eventDate)?->format('d/m/Y');
        }
        if ($form->eventLocation !== null && $form->eventLocation !== '') {
            $meta[] = $form->eventLocation;
        }
        $meta[] = count($rows) . ' réservation(s)';

        // Every value is escaped here: DocumentPdfService renders whatever
        // HTML it is handed, and these rows carry text a visitor typed.
        $pdf = $this->documentPdfService->generate(
            $article !== null ? $article->title : 'Liste des attendus',
            $this->renderToString('@news/pdf/expected_attendees.html.twig', [
                'rows' => $rows,
                'expects_payment' => $expectsPayment,
            ]),
            $this->siteName,
            $meta,
            'Liste imprimée le ' . (new \DateTimeImmutable())->format('d/m/Y') . '. '
                . 'Cochez les arrivées au stylo si le réseau vient à manquer : '
                . 'les entrées notées ici ne remontent pas dans le site.'
        );

        return (new Response($pdf))
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="liste-des-attendus.pdf"');
    }

    /**
     * The form named by the route, or null — and « null » covers a form
     * that exists but delivers no ticket, which has no door to hold.
     *
     * @param array<string, string> $params
     */
    private function requireTicketedForm(array $params): ?NewsForm
    {
        $form = $this->formService->findById((int) ($params['form_id'] ?? 0));

        return $form !== null && $form->issuesTicket ? $form : null;
    }

    private function resolveResponseOfForm(NewsForm $form, int $responseId): ?FormResponse
    {
        if ($responseId <= 0) {
            return null;
        }

        $response = $this->formService->findResponseById($responseId);

        // Scoped to the event in the URL: a validation is a write, and a
        // write must never be steerable onto another evening's booking by
        // editing an id in a payload.
        return $response !== null && $response->formId === $form->id && $response->hasTicket() ? $response : null;
    }

    /**
     * The verdict as the screen reads it — no entity, no id the page has
     * no use for, and the reference already grouped for the eye.
     *
     * @param array<string, mixed> $verdict
     * @return array<string, mixed>
     */
    private function presentVerdict(array $verdict): array
    {
        /** @var ?FormResponse $response */
        $response = $verdict['response'];
        /** @var ?NewsForm $form */
        $form = $verdict['form'];

        return [
            'status' => $verdict['status'],
            'response_id' => $response?->id,
            'reference' => $response !== null && $response->hasTicket()
                ? TicketService::format((string) $response->ticketReference)
                : null,
            'holder' => $verdict['holder'],
            'article_title' => $verdict['article_title'],
            'event_date' => $form?->eventDate,
            'seats' => $verdict['seats'],
            'seat_total' => $verdict['seat_total'],
            'payment' => $verdict['payment'],
            'used_at' => $verdict['used_at'],
        ];
    }
}
