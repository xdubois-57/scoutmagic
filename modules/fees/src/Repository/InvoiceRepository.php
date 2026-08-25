<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Repository;

use Core\Service\DateInput;
use Modules\Fees\Invoice\InvoiceLine;
use Modules\Fees\Invoice\ParsedInvoice;
use Modules\Fees\Value\StoredInvoice;
use Modules\Fees\Value\StoredInvoiceLine;

/**
 * Reads and writes the invoices the unit has imported.
 *
 * Nothing here holds a name or a birth date: a line's people are member
 * ids, and a person the site could not match is a row with a NULL one so
 * the count stays right (see `modules/fees/schema.sql`).
 */
class InvoiceRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Stores a whole invoice — header, lines and people — in one
     * transaction. Either the document is there or it is not: there is no
     * half-imported invoice to reason about afterwards.
     *
     * @param array<string, int> $sectionIdsByCode section desk_code => sections.id
     * @param array<string, ?int> $memberIdsByPersonKey resolved by InvoicePerson::matchKey()
     */
    public function store(
        ParsedInvoice $invoice,
        int $scoutYearId,
        ?int $snapshotId,
        ?int $importedBy,
        array $sectionIdsByCode,
        array $memberIdsByPersonKey
    ): int {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO fees_invoices
                     (scout_year_id, document_number, issue_date, total_cents, iban,
                      structured_communication, template_number, ignored_row_count,
                      snapshot_id, imported_at, imported_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $scoutYearId,
                (string) $invoice->documentNumber,
                $invoice->issueDate,
                $invoice->totalCents,
                $invoice->iban,
                $invoice->structuredCommunication,
                $invoice->templateNumber,
                $invoice->ignoredRowCount,
                $snapshotId,
                date('Y-m-d H:i:s'),
                $importedBy,
            ]);
            $invoiceId = (int) $this->pdo->lastInsertId();

            $lineStmt = $this->pdo->prepare(
                'INSERT INTO fees_invoice_lines
                     (invoice_id, reference, descriptor, section_code, section_id,
                      unit_price_cents, quantity, amount_cents, nature, line_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $personStmt = $this->pdo->prepare(
                'INSERT INTO fees_invoice_people (invoice_line_id, member_id) VALUES (?, ?)'
            );

            foreach ($invoice->lines as $order => $line) {
                $lineStmt->execute([
                    $invoiceId,
                    $line->reference,
                    mb_substr($line->descriptor, 0, 255),
                    $line->sectionCode,
                    $line->sectionCode === null ? null : ($sectionIdsByCode[$line->sectionCode] ?? null),
                    $line->unitPriceCents,
                    $line->quantity,
                    $line->amountCents,
                    $line->nature(),
                    $order,
                ]);
                $lineId = (int) $this->pdo->lastInsertId();

                foreach ($line->people as $person) {
                    $personStmt->execute([$lineId, $memberIdsByPersonKey[$person->matchKey()] ?? null]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $invoiceId;
    }

    public function findByDocumentNumber(int $scoutYearId, string $documentNumber): ?StoredInvoice
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM fees_invoices WHERE scout_year_id = ? AND document_number = ?'
        );
        $stmt->execute([$scoutYearId, $documentNumber]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function findById(int $id): ?StoredInvoice
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fees_invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * The season's invoices, oldest first — the order they were issued in
     * is the order a treasurer reads the season in.
     *
     * @return StoredInvoice[]
     */
    public function findAllForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM fees_invoices
             WHERE scout_year_id = ?
             ORDER BY issue_date IS NULL, issue_date, id'
        );
        $stmt->execute([$scoutYearId]);

        return array_map(fn(array $row): StoredInvoice => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The ignored-row count of the most recent import — what the deposit
     * screen compares a new document against.
     */
    public function findLastIgnoredRowCount(int $scoutYearId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT ignored_row_count FROM fees_invoices
             WHERE scout_year_id = ?
             ORDER BY imported_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$scoutYearId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /** @return StoredInvoiceLine[] */
    public function findLines(int $invoiceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM fees_invoice_lines WHERE invoice_id = ? ORDER BY line_order, id'
        );
        $stmt->execute([$invoiceId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }

        $peopleByLine = $this->findPeopleByLine(array_map(static fn(array $r): int => (int) $r['id'], $rows));

        return array_map(
            static function (array $row) use ($peopleByLine): StoredInvoiceLine {
                $people = $peopleByLine[(int) $row['id']] ?? ['members' => [], 'unmatched' => 0];

                return new StoredInvoiceLine(
                    (int) $row['id'],
                    (string) $row['reference'],
                    (string) $row['descriptor'],
                    $row['section_code'] === null ? null : (string) $row['section_code'],
                    $row['section_id'] === null ? null : (int) $row['section_id'],
                    (int) $row['unit_price_cents'],
                    (int) $row['quantity'],
                    (int) $row['amount_cents'],
                    (string) $row['nature'],
                    $people['members'],
                    $people['unmatched']
                );
            },
            $rows
        );
    }

    /** @param int $fileId what Finances gave back — an id `/files/{id}` opens. */
    public function attachFinanceFile(int $invoiceId, int $fileId): void
    {
        $stmt = $this->pdo->prepare('UPDATE fees_invoices SET finance_file_id = ? WHERE id = ?');
        $stmt->execute([$fileId, $invoiceId]);
    }

    /**
     * @param int[] $lineIds
     * @return array<int, array{members: int[], unmatched: int}>
     */
    private function findPeopleByLine(array $lineIds): array
    {
        $placeholders = implode(',', array_fill(0, count($lineIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT invoice_line_id, member_id FROM fees_invoice_people WHERE invoice_line_id IN ($placeholders)"
        );
        $stmt->execute($lineIds);

        $byLine = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $lineId = (int) $row['invoice_line_id'];
            $byLine[$lineId] ??= ['members' => [], 'unmatched' => 0];
            if ($row['member_id'] === null) {
                $byLine[$lineId]['unmatched']++;
                continue;
            }
            $byLine[$lineId]['members'][] = (int) $row['member_id'];
        }

        return $byLine;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): StoredInvoice
    {
        return new StoredInvoice(
            (int) $row['id'],
            (int) $row['scout_year_id'],
            (string) $row['document_number'],
            $row['issue_date'] === null ? null : (string) $row['issue_date'],
            (int) $row['total_cents'],
            $row['structured_communication'] === null ? null : (string) $row['structured_communication'],
            $row['template_number'] === null ? null : (string) $row['template_number'],
            (int) $row['ignored_row_count'],
            $row['snapshot_id'] === null ? null : (int) $row['snapshot_id'],
            DateInput::requireFromStorage((string) $row['imported_at'], 'imported_at'),
            $row['finance_file_id'] === null ? null : (int) $row['finance_file_id']
        );
    }
}
