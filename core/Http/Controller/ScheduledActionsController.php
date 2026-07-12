<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Scheduler\SchedulerRepository;
use Twig\Environment;

class ScheduledActionsController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private SchedulerRepository $schedulerRepository
    ) {
    }

    /**
     * GET /config/scheduled
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $page = max(1, (int) ($request->getQuery('page') ?: '1'));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $actions = $this->schedulerRepository->findAll($perPage, $offset);
        $total = $this->schedulerRepository->countAll();
        $totalPages = max(1, (int) ceil($total / $perPage));

        $html = $this->twig->render('config/scheduled.html.twig', [
            'actions' => $actions,
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
        ]);
        return new Response($html);
    }
}
