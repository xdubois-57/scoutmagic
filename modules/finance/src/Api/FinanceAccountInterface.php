<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

/**
 * Public contract for consuming modules (ARCHITECTURE.md §7.5).
 * Read-only access to Finance's configured accounts, for a consuming
 * module's own account picker (e.g. the news module's payment settings).
 */
interface FinanceAccountInterface
{
    /**
     * holder_name is the account's own beneficiary name for SEPA transfers
     * (Modules\Finance\Api\SepaQrCodeInterface's $beneficiaryName) — null
     * when the account has none set.
     *
     * @return array<
     *     int,
     *     array{id: int, name: string, iban: ?string, holder_name: ?string, section_id: ?int}
     * > active accounts only
     */
    public function getConfiguredAccounts(): array;

    /**
     * Default account for a section: the section's own account if one is
     * configured, else the unit's default account, else null.
     */
    public function getDefaultAccountForSection(int $sectionId): ?int;

    /**
     * Finance's active categories, in their configured order — for a
     * consuming module's own category picker, next to its account picker:
     * every receivable names the category its payments are booked under
     * (ExpectedReceivableInterface::createReceivable(), issue #838).
     *
     * @return list<array{id: int, name: string}>
     */
    public function getCategories(): array;
}
