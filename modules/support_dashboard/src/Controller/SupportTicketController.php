<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Modules\SupportDashboard\Service\MailProbeReport;
use Modules\SupportDashboard\Service\SupportTicketService;
use Modules\SupportDashboard\Service\TicketAnalysisOutcome;
use Modules\SupportDashboard\Service\TicketAnalysisService;
use Modules\SupportDashboard\Service\TicketListFilters;
use Twig\Environment;

/**
 * The receiver's ticket queue (roadmap IT-28), `role_min: superadmin` on
 * every route like the rest of this module.
 *
 * The floor is not a formality here: a ticket carries a description
 * somebody wrote about their own installation, a contact address to answer
 * them on, and — when they chose to send one — an archive of their server
 * logs. `superadmin` is the only role on a receiver installation that
 * answers for any of that.
 *
 * The controller orchestrates only: query string in, one service call,
 * one render.
 */
class SupportTicketController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private SupportTicketService $ticketService,
        /**
         * Null when `llm_connector` is absent — the analysis block then
         * does not render and the rest of the page is untouched
         * (ARCHITECTURE.md §7.5).
         */
        private ?TicketAnalysisService $analysisService = null
    ) {
    }

    /**
     * `GET /support-dashboard/tickets`
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $filters = TicketListFilters::fromQuery($request->getQueryAll());

        return $this->render('@support_dashboard/tickets.html.twig', [
            'filters' => $filters,
            'tickets' => $this->ticketService->list($filters),
            'categories' => $this->ticketService->categoriesInUse(),
            // The whole analysis block hangs off this one boolean: no
            // module, no provider, no block — and no mention of a feature
            // this installation does not have.
            'analysis_available' => $this->analysisService?->isAvailable() ?? false,
            'analysis' => $this->analysisService?->latest(),
            'analysis_pending' => $this->analysisService?->pendingCount() ?? 0,
        ]);
    }

    /**
     * `GET /support-dashboard/tickets/{id}`
     *
     * @param array<string, string> $params
     */
    public function detail(Request $request, array $params): Response
    {
        $ticket = $this->ticketService->detail((int) ($params['id'] ?? 0));
        if ($ticket === null) {
            return new Response('', 404);
        }

        return $this->render('@support_dashboard/ticket.html.twig', [
            'ticket' => $ticket,
            // The two readings side by side: what the instance reported
            // WITH the ticket, and what it has reported since.
            'statistics_comparison' => SupportTicketService::statisticsComparison($ticket),
            'statistics_drifted' => SupportTicketService::statisticsDrifted($ticket),
            // The queue is a real ancestor PAGE, not a menu label, so it
            // travels as a breadcrumb_trail: a `parents` entry naming it
            // would render as inert grey text (design.md §7.3).
            'breadcrumb_trail' => [
                ['label' => 'Tickets de support', 'url' => '/support-dashboard/tickets'],
            ],
        ]);
    }

    /**
     * `GET /support-dashboard/tickets/{id}/sondes` — the probes of this
     * ticket's installation, headers and all, as a text file.
     *
     * **Not folded into the diagnostic archive**, although that is where
     * it was looked for: the archive is the file the instance uploaded,
     * built and encrypted before any of this happened, and there is
     * nothing to add to it that would not mean rewriting somebody else's
     * evidence. What was missing is on this side of the wire. So it is
     * its own file, offered next to the archive.
     *
     * Plain text because a header block is plain text — see
     * `Service\MailProbeReport`.
     *
     * @param array<string, string> $params
     */
    public function probes(Request $request, array $params): Response
    {
        $ticket = $this->ticketService->detail((int) ($params['id'] ?? 0));
        if ($ticket === null) {
            return new Response('', 404);
        }

        $report = MailProbeReport::build(
            (string) ($ticket['reference'] ?? ''),
            is_array($ticket['probes'] ?? null) ? $ticket['probes'] : []
        );

        return (new Response($report))
            ->setHeader('Content-Type', 'text/plain; charset=utf-8')
            ->setHeader(
                'Content-Disposition',
                'attachment; filename="sondes-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($ticket['reference'] ?? 'ticket')) . '.txt"'
            )
            ->setHeader('Content-Length', (string) strlen($report));
    }

    /**
     * `POST /support-dashboard/tickets/{id}/close`
     *
     * @param array<string, string> $params
     */
    public function close(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/support-dashboard/tickets')) !== null) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $note = trim((string) $request->getBody('resolution_note', ''));

        if ($this->ticketService->close($id, $note !== '' ? $note : null, new \DateTimeImmutable())) {
            FlashMessage::set('success', 'Ticket clôturé.');
        } else {
            FlashMessage::set('error', 'Ce ticket est introuvable ou déjà clôturé.');
        }

        return $this->redirect('/support-dashboard/tickets/' . $id);
    }

    /**
     * `POST /support-dashboard/tickets/{id}/reopen`
     *
     * @param array<string, string> $params
     */
    public function reopen(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/support-dashboard/tickets')) !== null) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);

        if ($this->ticketService->reopen($id)) {
            FlashMessage::set('success', 'Ticket rouvert.');
        } else {
            FlashMessage::set('error', 'Ce ticket est introuvable ou déjà ouvert.');
        }

        return $this->redirect('/support-dashboard/tickets/' . $id);
    }

    /**
     * `POST /support-dashboard/tickets/analyse` — one run, on an explicit
     * gesture.
     *
     * **Never on page load.** The descriptions leave this installation for
     * an external provider, which makes that provider a sub-processor; a
     * transmission that happened because somebody opened a page would be
     * one nobody decided to make.
     *
     * @param array<string, string> $params
     */
    public function analyse(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/support-dashboard/tickets')) !== null) {
            return $guard;
        }

        // Every answer here is a named outcome that says its own subject
        // (`Service\TicketAnalysisOutcome`). It used to be a bare
        // true/false, and « le fournisseur n'a rien renvoyé
        // d'exploitable » was shown even when no provider had been
        // contacted at all — a sentence blaming a third party for a
        // request nobody made.
        //
        // A flash lives until some page renders it, so the answer to a
        // request nobody waited for lands on whatever page comes next.
        // That is how this one turned up on « Maintenance », where
        // « L'analyse n'a pas abouti » reads as the update having failed.
        // Naming the subject in the sentence is what makes a misplaced
        // message merely misplaced.
        $outcome = $this->analysisService === null
            ? TicketAnalysisOutcome::UNAVAILABLE
            : $this->analysisService->run(new \DateTimeImmutable());

        FlashMessage::set($outcome->flashType(), $outcome->message());

        return $this->redirect('/support-dashboard/tickets');
    }
}
