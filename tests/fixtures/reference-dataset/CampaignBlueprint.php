<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * The unit's payment campaign — a calendar sale — declared as data.
 *
 * A campaign invoices an amount to each member of a list and follows the
 * payments. **One receivable per member**, never per household: a family of
 * three receives three requests, each with its own structured communication,
 * which is what makes a transfer identifiable when it lands. The module
 * refuses a line that resolves to nobody, so the roster is built from the
 * dataset's own Tiers rather than typed by hand.
 *
 * **What is declared here is the state of the reconciliation, not the
 * payments.** A campaign in which everybody has paid the right amount shows a
 * page of green ticks and teaches a reader nothing about the one screen that
 * matters — the one where the intendant works out who still owes what. So the
 * plan below leaves most of the unit unpaid, settles a large minority, and
 * plants four kinds of trouble on purpose:
 *
 *  - **a short payment** — the family paid a round number, not the amount;
 *  - **an overpayment** — the same, in the other direction, which the
 *    allocation never spends beyond what is due;
 *  - **a double payment** — the transfer was made twice, so a second
 *    transaction carries a communication that is already settled;
 *  - **a wrong communication** — a well-formed structured communication that
 *    corresponds to no receivable at all, which is what a family typing last
 *    year's reference produces: money on the account, attached to nobody.
 *
 * The same reasoning as ExtrasBlueprint::RECEIVABLE_AMOUNT_CENTS, applied to
 * a whole roster: a reconciliation page where everything is settled shows
 * nothing.
 */
final class CampaignBlueprint
{
    /** The scout year the campaign belongs to — the dataset's last one. */
    public const YEAR = '2026-2027';

    public const LABEL = 'Vente de calendriers 2026-2027';

    /**
     * The account the money is expected on: the unit account, which is the
     * one the six statements belong to.
     */
    public const ACCOUNT_HANDLE = 'unite';

    /** Four calendars at six euros — what every member is asked for. */
    public const AMOUNT_CENTS = 2400;

    /**
     * The name of the file the treasurer is deemed to have uploaded. It is
     * built at build time rather than committed: a `.xlsx` is a zip, and a
     * zip is not something `generate.php --check` could compare byte for
     * byte without failing on a timestamp.
     */
    public const SOURCE_FILENAME = 'vente-calendriers-2026-2027.xlsx';

    /**
     * The spreadsheet's columns. The first is the identifier the site's own
     * member export writes and the only thing that ties an amount to a
     * person — Modules\Finance\Service\CampaignImportService accepts
     * "Identifiant Desk" as an exact key. The rest are free, kept, and become
     * the merge variables of the reminder.
     */
    public const COLUMN_DESK_ID = 'Identifiant Desk';

    public const COLUMN_AMOUNT = 'Montant';

    /** @var list<string> the extra columns, in order */
    public const MERGE_COLUMNS = ['Nom', 'Prénom', 'Section'];

    /**
     * How many of the campaign's rows are paid in full, counted from the top
     * of the roster (which is Tiers order, so the choice is reproducible).
     *
     * Deliberately a large minority rather than a majority: a calendar sale
     * is still being chased in February, and that is the state the page has
     * to be seen in.
     */
    public const PAID_IN_FULL = 58;

    /**
     * Rows that pay something OTHER than what is due, by 0-based position in
     * the roster — all of them beyond PAID_IN_FULL, so they are not also
     * counted as settled.
     *
     * @var array<int, int> position => amount actually transferred, in cents
     */
    public const WRONG_AMOUNTS = [
        70 => 2000,
        71 => 1500,
        72 => 3000,
    ];

    /**
     * Rows whose transfer was made twice. Both lines carry the same
     * communication and the same amount; the second one has nothing left to
     * settle, and the allocation records it as such rather than over-paying
     * the receivable.
     *
     * @var list<int> 0-based positions, inside the PAID_IN_FULL band
     */
    public const DOUBLE_PAYMENTS = [4, 31];

    /**
     * Payments carrying a well-formed structured communication that belongs
     * to no receivable, as 10-digit bases (the check digits are computed by
     * StructuredCommunicationService::format(), like every other
     * communication in this dataset).
     *
     * @var list<array{base: string, amount: int, from: string}>
     */
    public const ORPHAN_PAYMENTS = [
        ['base' => '1200990001', 'amount' => 2400, 'from' => 'VIREMENT FAMILLE — COMMUNICATION DE L ANNEE PASSEE'],
        ['base' => '1200990002', 'amount' => 4800, 'from' => 'VIREMENT FAMILLE — DEUX CALENDRIERS'],
    ];

    /**
     * When the money arrives. An offset in days from 1 September of the
     * campaign's year, the convention the rest of this directory uses — and
     * spread over three weeks, because a bank statement on which forty
     * transfers share one date is not a statement anybody has ever seen.
     */
    public const FIRST_PAYMENT_DAY = 75;

    public const PAYMENT_SPREAD_DAYS = 21;

    /** The account handle's statement this campaign's payments are written to. */
    public const STATEMENT_SUFFIX = 'campagne';
}
