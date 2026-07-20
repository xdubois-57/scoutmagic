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

/**
 * Accounts, categories, and fiscal years — the configuration data the
 * rest of the finance module (dashboard, movements, import) is built on
 * top of in later iterations. Import/movements/statistics functionality
 * itself is out of scope here — see Service\ImportService,
 * Service\CategoryRuleEngine (stubs) and the module spec's "itération 3".
 */
class FinanceService
{
    private const VALID_ROLE_MIN_VIEW = ['intendant', 'chief', 'admin'];
    private const VALID_ACCOUNT_TYPES = [Account::TYPE_BANK, Account::TYPE_CASH];

    public function __construct(
        private AccountRepository $accountRepository,
        private CategoryRepository $categoryRepository,
        private FiscalYearRepository $fiscalYearRepository,
        private SectionService $sectionService
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
        $account = $this->accountRepository->findById($id);
        \assert($account !== null);
        return $account;
    }

    /**
     * A draft account can only become active once it has both an IBAN and
     * a holder name — validated here, not just in the form (module spec:
     * "Un compte ne peut passer en active que si iban et holder_name sont
     * renseignés").
     *
     * @throws FinanceException when the account is missing iban/holder_name, or is unknown
     */
    public function activateAccount(int $id): void
    {
        $account = $this->accountRepository->findById($id);
        if ($account === null) {
            throw new FinanceException('Compte introuvable.');
        }
        if ($account->iban === null || $account->iban === '' || $account->holderName === null || $account->holderName === '') {
            throw new FinanceException("Le compte doit avoir un IBAN et un nom de titulaire avant de pouvoir être activé.");
        }
        $this->accountRepository->updateStatus($id, Account::STATUS_ACTIVE);
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

    /**
     * @throws FinanceException on an invalid date range
     */
    public function createFiscalYear(string $label, string $startDate, string $endDate): FiscalYear
    {
        $label = trim($label);
        if ($label === '') {
            throw new FinanceException("Le libellé de l'exercice est obligatoire.");
        }
        if ($startDate >= $endDate) {
            throw new FinanceException('La date de fin doit être postérieure à la date de début.');
        }
        $id = $this->fiscalYearRepository->create($label, $startDate, $endDate);
        $fiscalYear = $this->fiscalYearRepository->findById($id);
        \assert($fiscalYear !== null);
        return $fiscalYear;
    }

    /**
     * @throws FinanceException when the fiscal year is unknown
     */
    public function setCurrentFiscalYear(int $id): void
    {
        if ($this->fiscalYearRepository->findById($id) === null) {
            throw new FinanceException('Exercice introuvable.');
        }
        $this->fiscalYearRepository->setCurrent($id);
    }
}
