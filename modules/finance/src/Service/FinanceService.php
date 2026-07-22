<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Config\SettingService;
use Core\Member\SectionService;
use Core\Security\Role;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Category;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRuleRepository;
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
     * created once ever, the first time the categories config page loads,
     * tracked via the internal 'categories_seeded' setting rather than
     * "the table happens to be empty right now": an admin emptying the
     * list out entirely (deleting every category, default or custom) must
     * never be mistaken for "never seeded" and have the whole default set
     * silently resurrected out from under them on the next page load —
     * see ensureDefaultCategories(). An admin is free to rename,
     * deactivate, or delete any of these afterward; they are never
     * re-created once removed (matched by name at ensure time only, and
     * only that one time).
     */
    private const DEFAULT_CATEGORY_NAMES = [
        "Fête d'unité", 'Camp été', 'Weekend de section', 'Grande journée', 'Formations',
        'Calendriers', 'Matériel', 'Locaux', 'Subsides', 'Cotisations',
    ];

    /**
     * Auto-categorization rules seeded alongside DEFAULT_CATEGORY_NAMES
     * (ensureDefaultCategories()) and restorable on demand
     * (resetDefaultCategoryRules(), the config page's "Réinitialiser les
     * règles par défaut" button) — adapted from a similar unit's own
     * hand-tuned rule set (keyword patterns observed to actually appear
     * in real Belgian bank statement labels), trimmed to the patterns
     * generic enough to be a sensible starting point for any unit rather
     * than specific to that one (e.g. their own site/vendor names, IBANs,
     * and month-restricted catch-alls were left out). "Locaux" has no
     * equivalent in that source and gets no seeded rule. Every pattern
     * targets un-accented text — Belgian bank exports routinely strip
     * accents from labels, and CategoryRuleEngine's matching isn't
     * accent-folding, so an accented pattern would silently never match.
     *
     * @var array<string, string[]>
     */
    private const DEFAULT_CATEGORY_RULE_PATTERNS = [
        "Fête d'unité" => ['fete\\s*d.{0,3}u', '(^|\\s)fu(\\s|\\d|$)'],
        'Camp été' => ['camp'],
        'Weekend de section' => ['(we($|\\s)|weekend|week-end|w-e|wk)'],
        'Grande journée' => ['grande journee'],
        'Formations' => ['formation'],
        'Calendriers' => ['calendrier'],
        'Matériel' => ['materiel', 'pharmacie'],
        'Subsides' => ['subside|subvention'],
        'Cotisations' => ['cotisation', 'quadri'],
    ];

    private const SEEDED_SETTING_KEY = 'categories_seeded';

    public function __construct(
        private AccountRepository $accountRepository,
        private CategoryRepository $categoryRepository,
        private FiscalYearRepository $fiscalYearRepository,
        private SectionService $sectionService,
        private TransactionRepository $transactionRepository,
        private BalanceService $balanceService,
        private SettingService $settingService,
        private CategoryRuleRepository $categoryRuleRepository,
        private AccountTransferCategoryService $accountTransferCategoryService
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
     * ever opened, tracked by the internal SEEDED_SETTING_KEY flag rather
     * than "the table happens to be empty right now" (a bug: an admin who
     * deletes every category — the whole point of being able to delete a
     * default category at all — would otherwise see the entire default
     * set silently resurrected the next time this page loads, undoing
     * every deletion, not just the defaults'). Unlike
     * ensureDefaultAccountsForSections() this deliberately does NOT
     * re-merge by name on every load, because a category has no stable
     * identity besides its name: matching by name forever would silently
     * resurrect a default category an admin had renamed or deleted on
     * purpose. A no-op once the flag is set, which happens exactly once.
     */
    public function ensureDefaultCategories(): void
    {
        if ($this->settingService->get(self::SEEDED_SETTING_KEY, 'finance', '0') === '1') {
            return;
        }

        if ($this->categoryRepository->findAllOrdered() === []) {
            foreach (self::DEFAULT_CATEGORY_NAMES as $name) {
                $this->categoryRepository->create($name);
            }
        }

        // Not gated behind the block above: on an existing installation
        // upgrading into this feature, the default categories already
        // exist from a previous run (so the block above is skipped) but
        // never got default rules — this still finds them by name and
        // seeds rules for them, exactly once.
        $this->seedDefaultCategoryRules();

        $this->settingService->register(self::SEEDED_SETTING_KEY, '0', 'boolean', 'Catégories par défaut initialisées', 'Indicateur interne — ne pas modifier.', 'finance', null, null, false);
        $this->settingService->setInternal(self::SEEDED_SETTING_KEY, '1', 'finance');
    }

    /**
     * Re-creates every is_default rule from DEFAULT_CATEGORY_RULE_PATTERNS
     * from scratch — the config page's "Réinitialiser les règles par
     * défaut" button, for undoing edits/deletions to that specific set
     * without touching an admin's own custom rules or a category renamed
     * away from its default name (silently skipped, same as
     * seedDefaultCategoryRules() — matched by name, not stable identity).
     */
    public function resetDefaultCategoryRules(): void
    {
        $this->categoryRuleRepository->deleteAllDefault();
        $this->seedDefaultCategoryRules();
    }

    /**
     * Re-creates whichever of DEFAULT_CATEGORY_NAMES is currently missing
     * — the config page's "Réinitialiser les catégories par défaut"
     * button, for undoing an accidental (or since-regretted) deletion of
     * one of them. Same name-matching caveat as everywhere else in this
     * class: a default category an admin renamed rather than deleted
     * looks identical to a deleted one from here, and gets a fresh
     * same-named category recreated alongside it. Never touches a
     * default category that's still present, so it never duplicates that
     * one's rules.
     */
    public function resetDefaultCategories(): void
    {
        $existingNames = array_map(fn(Category $category) => $category->name, $this->categoryRepository->findAllOrdered());

        $recreatedNames = [];
        foreach (self::DEFAULT_CATEGORY_NAMES as $name) {
            if (!in_array($name, $existingNames, true)) {
                $this->categoryRepository->create($name);
                $recreatedNames[] = $name;
            }
        }

        if ($recreatedNames !== []) {
            $this->seedDefaultCategoryRulesFor($recreatedNames);
        }
    }

    private function seedDefaultCategoryRules(): void
    {
        $this->seedDefaultCategoryRulesFor(array_keys(self::DEFAULT_CATEGORY_RULE_PATTERNS));
    }

    /**
     * @param string[] $categoryNames
     */
    private function seedDefaultCategoryRulesFor(array $categoryNames): void
    {
        $categoriesByName = [];
        foreach ($this->categoryRepository->findAllOrdered() as $category) {
            $categoriesByName[$category->name] = $category;
        }

        foreach ($categoryNames as $categoryName) {
            $patterns = self::DEFAULT_CATEGORY_RULE_PATTERNS[$categoryName] ?? null;
            $category = $categoriesByName[$categoryName] ?? null;
            if ($patterns === null || $category === null) {
                continue;
            }

            foreach ($patterns as $pattern) {
                $priority = count($this->categoryRuleRepository->findAllOrderedByPriority());
                $this->categoryRuleRepository->create($category->id, $priority, $pattern, null, null, isSystem: false, isDefault: true);
            }
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
        $this->accountTransferCategoryService->sync($account);
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
        // Covers both "just activated" and "IBAN changed on an account
        // that was already active" — activateIfEligible() only ever acts
        // on a still-draft account, so this is the only place that also
        // catches the second case.
        $this->accountTransferCategoryService->sync($account);
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
        $this->accountTransferCategoryService->removeFor($id);
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
     * Deleting a category — default or custom — always succeeds: any
     * transaction referencing it falls back to uncategorized
     * (category_id = NULL, "Non catégorisé" in the UI) rather than
     * blocking the deletion, cleared explicitly here instead of relying
     * on the schema's ON DELETE SET NULL so behavior doesn't depend on
     * the underlying database engine actually enforcing it. Any
     * categorization rule that targeted this category is deleted too —
     * a rule with no category to assign is meaningless.
     *
     * @throws FinanceException when the category is unknown
     */
    public function deleteCategory(int $id): void
    {
        if ($this->categoryRepository->findById($id) === null) {
            throw new FinanceException('Catégorie introuvable.');
        }
        $this->transactionRepository->clearCategory($id);
        $this->categoryRuleRepository->deleteAllForCategory($id);
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
