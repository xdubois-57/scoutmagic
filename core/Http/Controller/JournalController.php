<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalRepository;
use Core\Security\UserAccountRepository;
use Twig\Environment;

class JournalController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private JournalRepository $journalRepository,
        private UserAccountRepository $userAccountRepository
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
        $ip = $request->getQuery('ip') ?: null;
        $email = $request->getQuery('email') ?: null;
        $page = max(1, (int) ($request->getQuery('page') ?: '1'));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        // Resolve an email filter to a user account id (exact match via blind index).
        // Unknown email → sentinel that matches no rows.
        $userAccountId = null;
        if ($email !== null) {
            $account = $this->userAccountRepository->findByEmail(trim($email));
            $userAccountId = $account !== null ? $account->id : -1;
        }

        $entries = $this->journalRepository->search($category, $level, $search, $dateFrom, $dateTo, $ip, $userAccountId, $perPage, $offset);
        $total = $this->journalRepository->count($category, $level, $search, $dateFrom, $dateTo, $ip, $userAccountId);
        $categories = $this->journalRepository->getDistinctCategories();
        $totalPages = max(1, (int) ceil($total / $perPage));

        $entries = $this->attachUserEmails($entries);

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
            'filter_ip' => $ip,
            'filter_email' => $email,
        ]);
        return new Response($html);
    }

    /**
     * Attach the (decrypted) email of the acting user to each entry. Emails stay
     * encrypted at rest; they are resolved here only for display.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function attachUserEmails(array $entries): array
    {
        $emails = [];
        foreach ($entries as $entry) {
            $id = $entry['user_account_id'] ?? null;
            if ($id !== null && !array_key_exists((int) $id, $emails)) {
                $account = $this->userAccountRepository->findById((int) $id);
                $emails[(int) $id] = $account !== null ? $account->email : null;
            }
        }

        foreach ($entries as &$entry) {
            $id = $entry['user_account_id'] ?? null;
            $entry['user_email'] = $id !== null ? ($emails[(int) $id] ?? null) : null;
        }

        return $entries;
    }
}
