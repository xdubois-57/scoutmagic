<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * The dataset's one CODA file (issue #511), declared as data: a single
 * download that covers TWO accounts — the camps account, which the BNP
 * statements already fed for three years, and the first section account,
 * which no statement ever reached. That is the behaviour nothing else in
 * the dataset exercises: one file split across accounts by their IBANs,
 * and a first import whose opening balance comes from the file rather than
 * from anybody typing it.
 *
 * It follows the BNP statements in time — August 2027, the last month of
 * the last scout year, after the camps account's last BNP line — so that the
 * camps account's old balance can be the ledger's own figure at that point
 * (CodaWriter computes it from the committed BNP files through the real
 * BnpParser) and the import finds no discrepancy to report.
 *
 * Every text is ISO-8859-1-representable (no « œ », no dash wider than
 * « - »), because that is the encoding the file is written in, as a bank
 * would; each case below is one of the format's traps (Modules\Finance\Parser\
 * CodaParser): accented names that only survive the ISO-8859-1 conversion,
 * a communication spread over three records, a structured communication in
 * its own field, a globalised batch whose details must not be counted
 * twice, and an information record.
 */
final class CodaBlueprint
{
    public const FILE = BankBlueprint::DIRECTORY . '/2026-2027_comptes.cod';

    /** The unit account the file covers, by its BankBlueprint handle. */
    public const UNIT_ACCOUNT = 'camps';

    /** And the section account: the first one completeSectionAccounts() gives an IBAN. */
    public const SECTION_INDEX = 0;

    /** The section account's balance before the file — its first ever. */
    public const SECTION_OPENING = 312.40;

    public const OPENING_DATE = '31/07/2027';
    public const CLOSING_DATE = '31/08/2027';

    /** Statement numbers, as the bank counts them within its year. */
    public const STATEMENT_NUMBERS = ['camps' => '008', 'section' => '001'];

    /**
     * A ten-digit base turned into a structured communication by the real
     * formatter — belonging to no receivable, so it lands among the
     * unmatched credits, which is where a parent's unannounced transfer goes.
     */
    public const STRUCTURED_BASE = '1270090001';

    /**
     * @var array<string, list<array{
     *     date: string,
     *     amount: float,
     *     communication: string,
     *     counterpartyIban: string,
     *     counterpartyName: string,
     *     structured?: bool,
     *     information?: string,
     *     details?: list<float>
     * }>>
     */
    public const MOVEMENTS = [
        'camps' => [
            [
                'date' => '02/08/2027',
                'amount' => -1_284.60,
                'communication' => "Solde de l'intendance du camp d'été, facture n° 2027-0412 : livraisons du 18 au 29 juillet à la prairie de Hérinnes, pain compris",
                'counterpartyIban' => 'BE00 0000 0000 0911',
                'counterpartyName' => 'Épicerie Sénéchal & Fils',
            ],
            [
                'date' => '09/08/2027',
                'amount' => 185.00,
                'communication' => '',
                'counterpartyIban' => 'BE00 0000 0000 0912',
                'counterpartyName' => 'Hélène Lefèvre',
                'structured' => true,
            ],
            [
                'date' => '16/08/2027',
                'amount' => -642.30,
                'communication' => 'Remboursements des animateurs, frais de camp',
                'counterpartyIban' => '',
                'counterpartyName' => '',
                'details' => [-212.10, -318.45, -111.75],
            ],
            [
                'date' => '23/08/2027',
                'amount' => -96.50,
                'communication' => 'Location de la remorque',
                'counterpartyIban' => 'BE00 0000 0000 0913',
                'counterpartyName' => 'Ferme du Grand Pré',
                'information' => 'Retour de la remorque le 21 août, caution restituée',
            ],
        ],
        'section' => [
            [
                'date' => '05/08/2027',
                'amount' => 450.00,
                'communication' => 'Subside communal : activités de l\'été',
                'counterpartyIban' => 'BE00 0000 0000 0914',
                'counterpartyName' => 'Commune de Genappe',
            ],
            [
                'date' => '19/08/2027',
                'amount' => -73.80,
                'communication' => 'Goûters de la journée de rentrée',
                'counterpartyIban' => 'BE00 0000 0000 0915',
                'counterpartyName' => 'Boulangerie Hénin',
            ],
        ],
    ];
}
