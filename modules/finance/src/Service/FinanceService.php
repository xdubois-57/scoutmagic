<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Member\SectionService;
use Core\Security\Role;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Category;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\FiscalYear;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\TransactionRepository;

/**
 * Accounts, categories, fiscal years, and dashboard statistics — the
 * configuration data and aggregates the rest of the finance module
 * (dashboard, movements, import) is built on top of.
 */
class FinanceService
{
    private const VALID_ROLE_MIN_VIEW = ['intendant', 'chief', 'admin'];
    private const VALID_ACCOUNT_TYPES = [Account::TYPE_BANK, Account::TYPE_CASH];

    /**
     * The categorization baseline every unit is expected to start from —
     * created once, idempotently, the first time the categories config
     * page loads (same "ensure on read" pattern as
     * ensureDefaultAccountsForSections()). An admin is free to rename,
     * deactivate, or delete any of these afterward; they are never
     * re-created once removed (matched by name at ensure time only).
     */
    private const DEFAULT_CATEGORY_NAMES = [
        "Fête d'unité", 'Camp été', 'Weekend de section', 'Grande journée', 'Formations',
        'Calendriers', 'Matériel', 'Locaux', 'Subsides', 'Cotisations',
    ];

    public function __construct(
        private AccountRepository $accountRepository,
        private CategoryRepository $categoryRepository,
        private FiscalYearRepository $fiscalYearRepository,
        private SectionService $sectionService,
        private TransactionRepository $transactionRepository,
        private BalanceService $balanceService
    ) {
    }

    // --- Accounts ---

    /**
     * Active accounts visible to $role — role_min_view is the floor a
     * viewer's own role must clear; draft/archived accounts never appear
     * here regardless of role (they're not ready for day-to-day use).
     *
     * @return Account[]
     */
    public function getAccountsForUser(Role $role): array
    {
        return array_values(array_filter(
            $this->accountRepository->findAllOrdered(),
            fn(Account $account) => $account->status === Account::STATUS_ACTIVE
                && $role->hasAccess(Role::fromString($account->roleMinView))
        ));
    }

    /**
     * Every account regardless of status — for the config page, which
     * manages drafts and archived accounts too.
     *
     * @return Account[]
     */
    public function getAllAccountsForConfig(): array
    {
        return $this->accountRepository->findAllOrdered();
    }

    public function getAccount(int $id): ?Account
    {
        return $this->accountRepository->findById($id);
    }

    /**
     * The account the "Finances" pages (dashboard, movements, receipts —
     * one shared account picker across all three) resolve to: the
     * requested id when it's valid and visible to $role, otherwise the
     * first visible account, or null when none are visible at all.
     */
    public function resolveSelectedAccount(Role $role, ?string $requestedAccountId): ?Account
    {
        $accounts = $this->getAccountsForUser($role);
        if ($accounts === []) {
            return null;
        }

        if ($requestedAccountId !== null) {
            foreach ($accounts as $account) {
                if ($account->id === (int) $requestedAccountId) {
                    return $account;
                }
            }
        }

        return $accounts[0];
    }

    /**
     * Idempotently creates a draft account for every active section that
     * doesn't already have one (module spec: "un compte par section est
     * créé par défaut") — same idempotent-ensure pattern as
     * Core\Badge\BadgeService::ensureDefaults(), called on every config
     * page load rather than only at module activation, so a section
     * added by a later Desk import also gets one.
     */
    public function ensureDefaultAccountsForSections(): void
    {
        $sectionIdsWithAccount = array_filter(
            array_map(fn(Account $account) => $account->sectionId, $this->accountRepository->findAllOrdered())
        );

        foreach ($this->sectionService->getAllWithBranches() as $section) {
            if (in_array($section['id'], $sectionIdsWithAccount, true)) {
                continue;
            }
            $this->accountRepository->create(
                $section['name'] ?? $section['desk_code'],
                Account::TYPE_BANK,
                $section['id'],
                null,
                null,
                Role::INTENDANT->value
            );
        }
    }

    /**
     * Seeds DEFAULT_CATEGORY_NAMES the first time the categories page is
     * ever opened (i.e. only while the category list is still empty) —
     * unlike ensureDefaultAccountsForSections() this deliberately does
     * NOT re-merge by name on every load, because a category has no
     * stable identity besides its name: matching by name forever would
     * silently resurrect a default category an admin had renamed or
     * deleted on purpose. A no-op once any category exists.
     */
    public function ensureDefaultCategories(): void
    {
        if ($this->categoryRepository->findAllOrdered() !== []) {
            return;
        }

        foreach (self::DEFAULT_CATEGORY_NAMES as $name) {
            $this->categoryRepository->create($name);
        }
    }

    /**
     * @throws FinanceException on an invalid account type or role_min_view
     */
    public function createAccount(
        string $name,
        string $accountType,
        ?int $sectionId,
        ?string $iban,
        ?string $holderName,
        string $roleMinView
    ): Account {
        $this->validateAccountFields($name, $accountType, $roleMinView);
        $id = $this->accountRepository->create($name, $accountType, $sectionId, $iban, $holderName, $roleMinView);
        $this->activateIfEligible($id);
        $account = $this->accountRepository->findById($id);
        \assert($account !== null);
        return $account;
    }

    /**
     * @throws FinanceException on an invalid account type/role_min_view, or an unknown account
     */
    public function updateAccount(
        int $id,
        string $name,
        string $accountType,
        ?int $sectionId,
        ?string $iban,
        ?string $holderName,
        string $roleMinView
    ): Account {
        if ($this->accountRepository->findById($id) === null) {
            throw new FinanceException('Compte introuvable.');
        }
        $this->validateAccountFields($name, $accountType, $roleMinView);
        $this->accountRepository->update($id, $name, $accountType, $sectionId, $iban, $holderName, $roleMinView);
        $this->activateIfEligible($id);
        $account = $this->accountRepository->findById($id);
        \assert($account !== null);
        return $account;
    }

    /**
     * A draft account activates itself the moment it no longer has
     * anything to wait for: a cash account has no bank details to
     * collect at all, and a bank account is ready as soon as it has both
     * an IBAN and a holder name. Never touches an already-active or
     * archived account.
     */
    private function activateIfEligible(int $id): void
    {
        $account = $this->accountRepository->findById($id);
        if ($account === null || $account->status !== Account::STATUS_DRAFT) {
            return;
        }

        $eligible = $account->accountType === Account::TYPE_CASH
            || ($account->iban !== null && $account->iban !== '' && $account->holderName !== null && $account->holderName !== '');

        if ($eligible) {
            $this->accountRepository->updateStatus($id, Account::STATUS_ACTIVE);
        }
    }

    /**
     * @throws FinanceException when the account is unknown
     */
    public function archiveAccount(int $id): void
    {
        if ($this->accountRepository->findById($id) === null) {
            throw new FinanceException('Compte introuvable.');
        }
        $this->accountRepository->updateStatus($id, Account::STATUS_ARCHIVED);
    }

    private function validateAccountFields(string $name, string $accountType, string $roleMinView): void
    {
        if (trim($name) === '') {
            throw new FinanceException('Le nom du compte est obligatoire.');
        }
        if (!in_array($accountType, self::VALID_ACCOUNT_TYPES, true)) {
            throw new FinanceException('Type de compte invalide.');
        }
        // role_min_view can never go below 'intendant' — the module's own
        // access floor (module spec: enforced here, not just the form,
        // which only ever offers these three choices to begin with).
        if (!in_array($roleMinView, self::VALID_ROLE_MIN_VIEW, true)) {
            throw new FinanceException("Le rôle minimum de visualisation doit être 'intendant', 'chief' ou 'admin'.");
        }
    }

    // --- Categories ---

    /**
     * @return Category[]
     */
    public function getAllCategories(): array
    {
        return $this->categoryRepository->findAllOrdered();
    }

    /**
     * @return Category[]
     */
    public function getActiveCategories(): array
    {
        return $this->categoryRepository->findActiveOrdered();
    }

    /**
     * @throws FinanceException on an empty name
     */
    public function createCategory(string $name): Category
    {
        $name = trim($name);
        if ($name === '') {
            throw new FinanceException('Le nom de la catégorie est obligatoire.');
        }
        $id = $this->categoryRepository->create($name);
        $category = $this->categoryRepository->findById($id);
        \assert($category !== null);
        return $category;
    }

    /**
     * @throws FinanceException on an empty name or unknown category
     */
    public function updateCategoryName(int $id, string $name): void
    {
        if ($this->categoryRepository->findById($id) === null) {
            throw new FinanceException('Catégorie introuvable.');
        }
        $name = trim($name);
        if ($name === '') {
            throw new FinanceException('Le nom de la catégorie est obligatoire.');
        }
        $this->categoryRepository->updateName($id, $name);
    }

    /**
     * @throws FinanceException when the category is unknown
     */
    public function setCategoryActive(int $id, bool $active): void
    {
        if ($this->categoryRepository->findById($id) === null) {
            throw new FinanceException('Catégorie introuvable.');
        }
        $this->categoryRepository->setActive($id, $active);
    }

    /**
     * A category referenced by at least one transaction can never be
     * deleted, only deactivated (module spec: "Désactivation plutôt que
     * suppression si des transactions référencent la catégorie") — same
     * "used elsewhere" pattern as Core\Badge.
     *
     * @throws FinanceException when referenced by transactions, or unknown
     */
    public function deleteCategory(int $id): void
    {
        if ($this->categoryRepository->findById($id) === null) {
            throw new FinanceException('Catégorie introuvable.');
        }
        if ($this->categoryRepository->isReferencedByTransactions($id)) {
            throw new FinanceException('Cette catégorie est utilisée par des mouvements — désactivez-la au lieu de la supprimer.');
        }
        $this->categoryRepository->delete($id);
    }

    // --- Fiscal years ---
    //
    // A fiscal year is a scout year — there is nothing for finance to
    // create or set-current itself; see Repository\FiscalYearRepository.

    /**
     * @return FiscalYear[]
     */
    public function getFiscalYears(): array
    {
        return $this->fiscalYearRepository->findAllOrdered();
    }

    public function getCurrentFiscalYear(): ?FiscalYear
    {
        return $this->fiscalYearRepository->findCurrent();
    }

    // --- Dashboard statistics ---

    /**
     * Per-category income/expense/total for an account's fiscal year,
     * sorted by income descending — backs the dashboard's "bilan par
     * catégorie" table. Uncategorized movements group under a null
     * category_id/category_name (rendered as "Non catégorisé").
     *
     * @return array<int, array{category_id: ?int, category_name: ?string, income: float, expense: float, total: float}>
     */
    public function getCategorySummary(int $accountId, int $fiscalYearId): array
    {
        return $this->transactionRepository->getCategorySummary($accountId, $fiscalYearId);
    }

    /**
     * Month-end cumulative balance across a fiscal year, from its start
     * up to today (never projected into the future) — backs the
     * dashboard's balance-evolution line chart. Each point reuses
     * Service\BalanceService::getBalanceAt(), so it's seeded from
     * whatever checkpoint is closest to that month's end, exactly like
     * every other balance figure in the module.
     *
     * @return array<int, array{month: string, balance: ?float}>
     */
    public function getBalanceEvolution(int $accountId, int $fiscalYearId): array
    {
        $fiscalYear = $this->fiscalYearRepository->findById($fiscalYearId);
        $account = $this->accountRepository->findById($accountId);
        if ($fiscalYear === null || $account === null) {
            return [];
        }

        $end = new \DateTimeImmutable($fiscalYear->endDate);
        $today = new \DateTimeImmutable('today');
        if ($end > $today) {
            $end = $today;
        }

        $cursor = new \DateTimeImmutable($fiscalYear->startDate);
        if ($cursor > $end) {
            return [];
        }

        $evolution = [];
        while ($cursor <= $end) {
            $monthEnd = $cursor->modify('last day of this month');
            if ($monthEnd > $end) {
                $monthEnd = $end;
            }

            $evolution[] = [
                'month' => $cursor->format('Y-m'),
                'balance' => $this->balanceService->getBalanceAt($account, $monthEnd),
            ];

            $cursor = $cursor->modify('first day of next month');
        }

        return $evolution;
    }

    /**
     * Splits a category summary into a net-per-category pie's two halves
     * — categories with a positive net (income categories) and
     * categories with a negative net (expense categories), each capped
     * at 4 entries (sorted by magnitude) with any remainder folded into
     * a single "Autres" bucket per side, per the dashboard's net-category
     * pie spec (top 4 + top 4 + up to 2 "autres" slices, never more).
     *
     * @param array<int, array{category_id: ?int, category_name: ?string, income: float, expense: float, total: float}> $categorySummary
     * @return array{positive: array<int, array{label: string, net: float}>, negative: array<int, array{label: string, net: float}>}
     */
    public function buildNetCategoryBreakdown(array $categorySummary): array
    {
        $positive = [];
        $negative = [];

        foreach ($categorySummary as $row) {
            $label = $row['category_name'] ?? 'Non catégorisé';
            if ($row['total'] > 0.0) {
                $positive[] = ['label' => $label, 'net' => $row['total']];
            } elseif ($row['total'] < 0.0) {
                $negative[] = ['label' => $label, 'net' => $row['total']];
            }
        }

        usort($positive, fn(array $a, array $b) => $b['net'] <=> $a['net']);
        usort($negative, fn(array $a, array $b) => $a['net'] <=> $b['net']);

        return [
            'positive' => $this->capWithOthers($positive, 'Autres (+)'),
            'negative' => $this->capWithOthers($negative, 'Autres (-)'),
        ];
    }

    /**
     * @param array<int, array{label: string, net: float}> $sorted
     * @return array<int, array{label: string, net: float}>
     */
    private function capWithOthers(array $sorted, string $othersLabel): array
    {
        if (count($sorted) <= 4) {
            return $sorted;
        }

        $top = array_slice($sorted, 0, 4);
        $top[] = ['label' => $othersLabel, 'net' => array_sum(array_column(array_slice($sorted, 4), 'net'))];
        return $top;
    }
}
