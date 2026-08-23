<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * Builds one BNP statement per account and per scout year.
 *
 * Every case the finance import has to survive is placed deliberately rather
 * than hoped for, and each is written by its own method below:
 *
 *   - membership payments carrying a real structured communication, which
 *     IT-06's expected receivables reconcile against;
 *   - a `Refusé` line, which BnpParser skips outright — it never happened on
 *     the account;
 *   - an amount with a thousands separator (`1.234,56`);
 *   - an amount written with a dot decimal (`35.98`), the case that used to
 *     silently import as 3598,00 €;
 *   - a line with no communication at all, where the label falls back to the
 *     Détails column;
 *   - labels that trip the default categorisation rules;
 *   - a transfer between the unit's two own accounts, appearing as a debit on
 *     one and a credit on the other;
 *   - the first lines of the previous year repeated at the head of the next
 *     file, carrying the same REFERENCE BANQUE, so deduplication is exercised
 *     rather than assumed.
 *
 * Amounts are chosen so no account ever goes implausibly negative, but no
 * running balance is asserted anywhere: the opening balance the builder hands
 * ImportService is BankBlueprint's, and the checkpoints follow from it.
 */
final class BankStatementBuilder
{
    public function __construct(private readonly Rng $rng)
    {
    }

    /**
     * @return array<string, list<StatementDraft>> keyed by relative file path
     */
    public function build(): array
    {
        /** @var array<string, array<string, list<StatementDraft>>> $byYear */
        $byYear = [];

        foreach (UnitBlueprint::YEARS as $year) {
            foreach (array_keys(BankBlueprint::ACCOUNTS) as $account) {
                $byYear[$year][$account] = $this->linesFor($year, $account);
            }
        }

        $files = [];
        foreach (UnitBlueprint::YEARS as $index => $year) {
            foreach (array_keys(BankBlueprint::ACCOUNTS) as $account) {
                $lines = $byYear[$year][$account];

                // The overlap: a real download of "the last fifteen months"
                // repeats the tail of the previous period. Those lines keep
                // their original REFERENCE BANQUE and their original date, so
                // they land in the earlier exercise and are recognised as
                // already imported.
                if ($index > 0) {
                    $previous = $byYear[UnitBlueprint::YEARS[$index - 1]][$account];
                    $overlap = array_slice($previous, -BankBlueprint::OVERLAP_LINES);
                    $lines = [...$overlap, ...$lines];
                }

                $files[BankBlueprint::fileFor($year, $account)] = $lines;
            }
        }

        return $files;
    }

    /**
     * @return list<StatementDraft>
     */
    private function linesFor(string $year, string $account): array
    {
        $lines = [];

        if ($account === 'unite') {
            $lines = [...$lines, ...$this->membershipPayments($year)];
            $lines[] = $this->refusedLine($year);
            $lines[] = $this->thousandsSeparatorLine($year);
            $lines[] = $this->dotDecimalLine($year);
            $lines[] = $this->lineWithoutCommunication($year);
            $lines[] = $this->transferOut($year);
        } else {
            $lines[] = $this->transferIn($year);
        }

        $lines = [...$lines, ...$this->recurring($year, $account)];

        usort($lines, static fn (StatementDraft $a, StatementDraft $b): int => strcmp(
            self::sortableDate($a->date) . $a->reference,
            self::sortableDate($b->date) . $b->reference,
        ));

        return $lines;
    }

    // -------------------------------------------------------------- les cas

    /**
     * @return list<StatementDraft>
     */
    private function membershipPayments(string $year): array
    {
        $lines = [];
        foreach (BankBlueprint::communicationsFor($year) as $index => $communication) {
            $date = $this->dateIn($year, 9, 1, 45);
            $lines[] = new StatementDraft(
                date: $date,
                valueDate: $date,
                amount: $this->money($this->rng->int(3500, 9500) / 100),
                transactionType: 'Virement en euros',
                counterpartyIban: $this->fakeCounterpartyIban(100 + $index),
                counterpartyName: $this->householdName(),
                communication: $communication,
                reference: $this->reference($date, 1000 + $index),
            );
        }

        return $lines;
    }

    /**
     * A line BnpParser drops on the floor: `Statut` is anything other than
     * "Accepté", so the transaction never happened on the account and must not
     * appear in the imported movements.
     */
    private function refusedLine(string $year): StatementDraft
    {
        $date = $this->dateIn($year, 11, 3, 20);

        return new StatementDraft(
            date: $date,
            valueDate: $date,
            amount: $this->money(-142.60),
            transactionType: 'Virement en euros',
            counterpartyIban: $this->fakeCounterpartyIban(701),
            counterpartyName: 'Fournitures Bivouac SPRL',
            communication: 'Materiel de section',
            reference: $this->reference($date, 7010),
            status: 'Refusé',
            refusalReason: 'Fonds insuffisants',
        );
    }

    /** `1.234,56` — the dot is a thousands separator here. */
    private function thousandsSeparatorLine(string $year): StatementDraft
    {
        $date = $this->dateIn($year, 12, 1, 25);

        return new StatementDraft(
            date: $date,
            valueDate: $date,
            amount: '1.284,50',
            transactionType: 'Virement en euros',
            counterpartyIban: $this->fakeCounterpartyIban(702),
            counterpartyName: 'Commune de Genappe',
            communication: 'Subside communal jeunesse',
            reference: $this->reference($date, 7020),
        );
    }

    /**
     * `35.98` — no comma anywhere, so the lone dot is the DECIMAL point.
     * Read as a thousands separator this would import as 3598,00 € with no
     * error at all, and a wrong balance checkpoint behind it.
     */
    private function dotDecimalLine(string $year): StatementDraft
    {
        $date = $this->dateIn($year, 2, 1, 25);

        return new StatementDraft(
            date: $date,
            valueDate: $date,
            amount: '-35.98',
            transactionType: 'Paiement par carte',
            counterpartyIban: '',
            counterpartyName: 'Pharmacie du Village',
            communication: 'Pharmacie — recharge trousses',
            reference: $this->reference($date, 7030),
        );
    }

    /**
     * No communication at all: BnpParser falls back to the Détails column for
     * the label, which is the only thing left to show a treasurer.
     */
    private function lineWithoutCommunication(string $year): StatementDraft
    {
        $date = $this->dateIn($year, 3, 1, 25);

        return new StatementDraft(
            date: $date,
            valueDate: $date,
            amount: $this->money(-63.40),
            transactionType: 'Paiement par carte',
            counterpartyIban: '',
            counterpartyName: 'Imprimerie du Sart',
            communication: '',
            reference: $this->reference($date, 7040),
        );
    }

    /** The unit moving its own money: a debit on `unite`… */
    private function transferOut(string $year): StatementDraft
    {
        $date = $this->transferDate($year);

        return new StatementDraft(
            date: $date,
            valueDate: $date,
            amount: $this->money(-1500.00),
            transactionType: 'Virement en euros',
            counterpartyIban: BankBlueprint::ACCOUNTS['camps']['iban'],
            counterpartyName: BankBlueprint::ACCOUNTS['camps']['name'],
            communication: 'Provision camp ete',
            reference: $this->reference($date, 7050),
        );
    }

    /** …and the matching credit on `camps`, same day, same amount. */
    private function transferIn(string $year): StatementDraft
    {
        $date = $this->transferDate($year);

        return new StatementDraft(
            date: $date,
            valueDate: $date,
            amount: $this->money(1500.00),
            transactionType: 'Virement en euros',
            counterpartyIban: BankBlueprint::ACCOUNTS['unite']['iban'],
            counterpartyName: BankBlueprint::ACCOUNTS['unite']['name'],
            communication: 'Provision camp ete',
            reference: $this->reference($date, 7060),
        );
    }

    /**
     * @return list<StatementDraft>
     */
    private function recurring(string $year, string $account): array
    {
        $templates = array_values(array_filter(
            BankBlueprint::RECURRING,
            static fn (array $entry): bool => $entry['account'] === $account,
        ));

        $lines = [];
        $count = BankBlueprint::RECURRING_PER_YEAR[$account];

        for ($i = 0; $i < $count; $i++) {
            $template = $templates[$i % count($templates)];
            $date = $this->dateIn($year, 9, 1, 330);
            $cents = $this->rng->int(min($template['min'], $template['max']), max($template['min'], $template['max']));

            $lines[] = new StatementDraft(
                date: $date,
                valueDate: $date,
                amount: $this->money((float) $cents),
                transactionType: $cents < 0 ? 'Paiement par carte' : 'Virement en euros',
                counterpartyIban: $this->rng->chance(70) ? $this->fakeCounterpartyIban(200 + $i) : '',
                counterpartyName: $this->rng->pick(BankBlueprint::COUNTERPARTIES),
                communication: $template['label'],
                reference: $this->reference($date, 2000 + $i),
            );
        }

        return $lines;
    }

    // ------------------------------------------------------------- fabriques

    /**
     * A date inside the scout year, counted forward from 1 September of its
     * start year — never from today, and never outside the exercise, or
     * ImportService refuses the whole file.
     */
    private function dateIn(string $year, int $startMonth, int $minOffset, int $maxOffset): string
    {
        $reference = UnitBlueprint::referenceYear($year);
        $calendarYear = $startMonth >= 9 ? $reference : $reference + 1;

        $base = new \DateTimeImmutable(sprintf('%04d-%02d-01', $calendarYear, $startMonth));
        $date = $base->modify('+' . $this->rng->int($minOffset, $maxOffset) . ' days');

        // Clamp into the exercise: 1 September to 31 August.
        $first = new \DateTimeImmutable(sprintf('%04d-09-01', $reference));
        $last = new \DateTimeImmutable(sprintf('%04d-08-31', $reference + 1));
        if ($date < $first) {
            $date = $first;
        }
        if ($date > $last) {
            $date = $last;
        }

        return $date->format('d/m/Y');
    }

    /** Both sides of the internal transfer must fall on the same day. */
    private function transferDate(string $year): string
    {
        return (new \DateTimeImmutable(sprintf('%04d-05-14', UnitBlueprint::referenceYear($year) + 1)))->format('d/m/Y');
    }

    /**
     * The bank's own per-line reference: YYMMDD followed by a counter, the
     * shape `tests/fixtures/finance/bnp_statement_sample.csv` shows. Stable
     * across regenerations, which is what makes the overlap between two files
     * recognisable as the same line.
     */
    private function reference(string $date, int $serial): string
    {
        [$day, $month, $year] = explode('/', $date);

        return sprintf('%s%s%s%010d', substr($year, 2, 2), $month, $day, $serial);
    }

    /** Belgian comma decimal, no thousands separator. */
    private function money(float $amount): string
    {
        return number_format($amount, 2, ',', '');
    }

    private function fakeCounterpartyIban(int $serial): string
    {
        return sprintf('BE00 0000 0000 %04d', $serial % 10000);
    }

    private function householdName(): string
    {
        return $this->rng->pick(UnitBlueprint::FIRST_NAMES_F) . ' ' . $this->rng->pick(UnitBlueprint::LAST_NAMES);
    }

    private static function sortableDate(string $ddmmyyyy): string
    {
        [$day, $month, $year] = explode('/', $ddmmyyyy);

        return $year . $month . $day;
    }
}
