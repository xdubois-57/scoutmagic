<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

/**
 * Public contract for consuming modules (ARCHITECTURE.md §7.5): **how far
 * this account's money is known**.
 *
 * A receivable reads « impayé » until a statement carrying the transfer
 * has been imported, and nothing on the site can shorten that lag. Any
 * screen that lists unpaid people is therefore listing, alongside the
 * real ones, everybody who paid after the last import — and the only
 * thing that stops somebody sending those people a reminder is being
 * told the date the list is true as of.
 *
 * `Core\View\PageController`'s own home band already says exactly this to
 * a family (« les paiements reçus jusqu'au … »); this is the same fact,
 * published so a consuming module can say it too rather than draw its
 * list without the caveat.
 *
 * **A date, and nothing else.** No statement, no transactions, no import
 * history: a consumer is being handed the one fact it needs to caption a
 * list it built itself.
 */
interface StatementImportStatusInterface
{
    /**
     * When this account's most recent bank statement was imported
     * (`Y-m-d H:i:s`), or null when none ever was — which is also the
     * honest answer for an account that exists but has never been
     * reconciled, and reads the same way to a caller: nothing about
     * payment on this account can be trusted as complete.
     */
    public function lastStatementImportedAt(int $accountId): ?string;
}
