<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalRepository;
use Twig\Environment;

class JournalController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private JournalRepository $journalRepository
    ) {
    }

    /**
     * GET /admin/journal
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $category = $request->getQuery('category') ?: null;
        $level = $request->getQuery('level') ?: null;
        $search = $request->getQuery('search') ?: null;
        $dateFrom = $request->getQuery('date_from') ?: null;
        $dateTo = $request->getQuery('date_to') ?: null;
        $page = max(1, (int) ($request->getQuery('page') ?: '1'));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $entries = $this->journalRepository->search($category, $level, $search, $dateFrom, $dateTo, $perPage, $offset);
        $total = $this->journalRepository->count($category, $level, $search, $dateFrom, $dateTo);
        $categories = $this->journalRepository->getDistinctCategories();
        $totalPages = max(1, (int) ceil($total / $perPage));

        $html = $this->twig->render('admin/journal.html.twig', [
            'entries' => $entries,
            'categories' => $categories,
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'filter_category' => $category,
            'filter_level' => $level,
            'filter_search' => $search,
            'filter_date_from' => $dateFrom,
            'filter_date_to' => $dateTo,
        ]);
        return new Response($html);
    }
}
