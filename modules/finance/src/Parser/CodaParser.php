<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Parser;

use Core\Service\DateInput;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Service\IbanNormalizer;

/**
 * CODA — the Febelfin "coded statement of account" every Belgian bank
 * exports: fixed-width records of 128 characters, the first one or two
 * telling the record's kind (0 header, 1 old balance, 21/22/23 a movement
 * and its continuations, 31/32/33 information, 4 free message, 8 new
 * balance, 9 trailer). One file may hold several statements of several
 * accounts, one after the other.
 *
 * The five traps of the format, each handled here on purpose:
 *
 * 1. **Encoding.** A CODA file is ISO-8859-1 or CP850, never announced.
 *    It is converted to UTF-8 before any field is read — a counterparty
 *    name read in the wrong encoding would be encrypted damaged, and could
 *    never be repaired. Positions are counted in characters on the
 *    converted line, so the conversion cannot shift a field.
 * 2. **Amounts** are fifteen digits in thousandths, with a separate
 *    debit/credit digit. Read as an integer and divided — never floatval()
 *    on the raw string, which is a thousand times too large and plausible.
 *    Every statement is then checked: its old balance plus its movements
 *    must give its new balance, or the file is refused whole.
 * 3. **Records continue.** A communication, a counterparty, a name spread
 *    over records 21, 22 and 23 (and 31–33); they are sewn back together
 *    before a StatementLine exists.
 * 4. **There is no dedup key as such.** It is composed from what identifies
 *    the movement itself — account, entry date, the bank's reference,
 *    detail number, amount — and deliberately NOT from the statement or
 *    sequence numbers, which restart every year and depend on how the
 *    export was cut. The same movement exported twice, in two files of two
 *    years, gets the same key; TransactionRepository::insertOrSkip()
 *    relies on nothing else.
 * 5. **Several accounts and statements per file** — which is what
 *    extractAccountIbans() and StatementLine::$accountIban exist for.
 *
 * Globalised movements (a batch the bank books as one line) arrive as the
 * global movement, detail number 0000, followed by its details. Only the
 * global one is a movement of the account — importing the details as well
 * would count the batch twice — so details are read for nothing else.
 */
final class CodaParser implements BankStatementParserInterface
{
    private const RECORD_LENGTH = 128;

    /** Record 2.1/3.1 communication type: a structured communication follows. */
    private const COMMUNICATION_STRUCTURED = '1';

    /** Structured communication kinds carrying a Belgian "+++…+++" reference. */
    private const BELGIAN_REFERENCE_TYPES = ['101', '102'];

    public function recognizes(string $filePath): bool
    {
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            return false;
        }
        $first = rtrim((string) fgets($handle, 1024), "\r\n");
        $second = rtrim((string) fgets($handle, 1024), "\r\n");
        fclose($handle);

        // A header record (0, then four zeros) followed by an old balance
        // record: a CSV cannot start that way, and nothing else is 128
        // characters of digits and spaces from its first byte.
        return strlen($first) >= self::RECORD_LENGTH
            && preg_match('/^0{5}\d{6}/', $first) === 1
            && str_starts_with($second, '1');
    }

    public function extractAccountIbans(string $filePath): array
    {
        $ibans = array_keys($this->read($filePath)['balances']);
        if ($ibans === []) {
            throw new FinanceException("Le fichier CODA ne contient aucun relevé de compte.");
        }

        return array_map('strval', $ibans);
    }

    public function parse(string $filePath): array
    {
        return $this->read($filePath)['lines'];
    }

    public function closingBalances(string $filePath): array
    {
        return $this->read($filePath)['balances'];
    }

    /**
     * @return array{lines: list<StatementLine>, balances: array<string, ClosingBalance>}
     * @throws FinanceException
     */
    private function read(string $filePath): array
    {
        $records = $this->records($filePath);

        $lines = [];
        /** @var array<string, ClosingBalance> $balances */
        $balances = [];

        $account = null;
        $statementNumber = '';
        $openingMillis = 0;
        $movementsMillis = 0;
        /** @var array<string, mixed>|null $movement */
        $movement = null;

        foreach ($records as $index => $record) {
            $kind = $record[0] === '2' || $record[0] === '3' ? substr($record, 0, 2) : $record[0];

            switch ($kind) {
                case '0':
                case '4':
                case '9':
                    // Header, free message, trailer: nothing a ledger needs.
                    break;

                case '1':
                    $this->flush($movement, $lines);
                    $account = $this->accountIban(self::field($record, 1, 1), self::field($record, 5, 37));
                    $statementNumber = trim(self::field($record, 2, 3));
                    $openingMillis = $this->millis(self::field($record, 42, 1), self::field($record, 43, 15), $index);
                    $movementsMillis = 0;
                    break;

                case '21':
                    $this->flush($movement, $lines);
                    $this->requireStatement($account, $index);
                    \assert($account !== null);
                    $movement = $this->startMovement($record, $account, $index);
                    if ($movement['detail'] === '0000') {
                        $movementsMillis += $movement['millis'];
                    }
                    break;

                case '22':
                    $this->requireMovement($movement, $index);
                    $movement['communication'] .= self::field($record, 10, 53);
                    break;

                case '23':
                    $this->requireMovement($movement, $index);
                    $movement['counterpartyAccount'] = trim(self::field($record, 10, 34));
                    $movement['counterpartyName'] = trim(self::field($record, 47, 35));
                    $movement['communication'] .= self::field($record, 82, 43);
                    break;

                case '31':
                    $this->requireMovement($movement, $index);
                    $movement['information'] .= self::field($record, 40, 73);
                    break;

                case '32':
                    $this->requireMovement($movement, $index);
                    $movement['information'] .= self::field($record, 10, 105);
                    break;

                case '33':
                    $this->requireMovement($movement, $index);
                    $movement['information'] .= self::field($record, 10, 90);
                    break;

                case '8':
                    $this->flush($movement, $lines);
                    $this->requireStatement($account, $index);
                    \assert($account !== null);
                    $closingMillis = $this->millis(self::field($record, 41, 1), self::field($record, 42, 15), $index);
                    if ($openingMillis + $movementsMillis !== $closingMillis) {
                        // Never the amounts themselves: no bank data leaves
                        // the import, not even in an error message.
                        throw new FinanceException(
                            "Le fichier CODA est incohérent : les mouvements du relevé n° {$statementNumber}"
                            . " ne mènent pas à son solde final. Aucun mouvement n'a été importé."
                        );
                    }
                    $date = $this->date(self::field($record, 57, 6), $index);
                    $previous = $balances[$account] ?? null;
                    if ($previous === null || $date >= $previous->date) {
                        $balances[$account] = new ClosingBalance($date, $closingMillis / 1000);
                    }
                    $account = null;
                    break;

                default:
                    throw new FinanceException(
                        'Le fichier CODA contient un enregistrement inconnu (ligne ' . ($index + 1) . ').'
                    );
            }
        }

        if ($account !== null) {
            throw new FinanceException("Le fichier CODA est incomplet : un relevé n'a pas de solde final.");
        }

        return ['lines' => $lines, 'balances' => $balances];
    }

    /**
     * The file's records as UTF-8 strings of exactly RECORD_LENGTH
     * characters.
     *
     * @return list<string>
     * @throws FinanceException
     */
    private function records(string $filePath): array
    {
        $raw = @file_get_contents($filePath);
        if ($raw === false) {
            throw new FinanceException('Impossible de lire le fichier de relevé.');
        }

        $utf8 = mb_convert_encoding($raw, 'UTF-8', self::encodingOf($raw));

        $records = [];
        foreach (preg_split('/\r\n|\n|\r/', $utf8) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            // Some exports strip trailing spaces; the fields they held
            // were blank, so padding restores them exactly.
            $records[] = mb_str_pad(mb_substr($line, 0, self::RECORD_LENGTH), self::RECORD_LENGTH);
        }

        if ($records === [] || !str_starts_with($records[0], '0')) {
            throw new FinanceException("Le fichier n'est pas un fichier CODA valide.");
        }

        return $records;
    }

    /**
     * UTF-8 when it already is (plain ASCII included); otherwise CP850 as
     * soon as a byte falls in 0x80–0x9F — where CP850 keeps its accented
     * letters (é is 0x82) and ISO-8859-1 only control codes that no
     * statement contains — and ISO-8859-1 for the rest.
     */
    private static function encodingOf(string $raw): string
    {
        if (mb_check_encoding($raw, 'UTF-8')) {
            return 'UTF-8';
        }

        return preg_match('/[\x80-\x9F]/', $raw) === 1 ? 'CP850' : 'ISO-8859-1';
    }

    /**
     * @return array<string, mixed>
     * @throws FinanceException
     */
    private function startMovement(string $record, string $account, int $index): array
    {
        $communicationType = self::field($record, 61, 1);
        $communication = self::field($record, 62, 53);
        $structured = null;
        if ($communicationType === self::COMMUNICATION_STRUCTURED) {
            $kind = substr($communication, 0, 3);
            if (in_array($kind, self::BELGIAN_REFERENCE_TYPES, true)
                && preg_match('/^\d{12}$/', substr($communication, 3, 12)) === 1
            ) {
                $structured = substr($communication, 3, 12);
            }
            // Other structured kinds (SEPA direct debit, detail of a
            // globalisation…) keep their text after the three-digit kind.
            $communication = substr($communication, 3);
        }

        return [
            'account' => $account,
            'detail' => self::field($record, 6, 4),
            'bankReference' => trim(self::field($record, 10, 21)),
            'millis' => $this->millis(self::field($record, 31, 1), self::field($record, 32, 15), $index),
            'valueDate' => $this->date(self::field($record, 47, 6), $index),
            'transactionCode' => self::field($record, 53, 8),
            'entryDate' => $this->date(self::field($record, 115, 6), $index),
            'sequence' => self::field($record, 2, 4),
            'structured' => $structured,
            'communication' => $communication,
            'counterpartyAccount' => '',
            'counterpartyName' => '',
            'information' => '',
        ];
    }

    /**
     * Turns the movement being sewn together into a StatementLine — or
     * nothing, for the detail of a globalised movement.
     *
     * @param array<string, mixed>|null $movement
     * @param-out null $movement
     * @param list<StatementLine> $lines
     */
    private function flush(?array &$movement, array &$lines): void
    {
        if ($movement === null) {
            return;
        }
        $m = $movement;
        $movement = null;

        if ($m['detail'] !== '0000') {
            return;
        }

        /** @var \DateTimeImmutable $entryDate */
        $entryDate = $m['entryDate'];
        /** @var \DateTimeImmutable $valueDate */
        $valueDate = $m['valueDate'];
        $structured = is_string($m['structured']) ? $m['structured'] : null;
        $communication = self::collapse((string) $m['communication']);
        $counterpartyName = self::collapse((string) $m['counterpartyName']);
        $counterpartyAccount = IbanNormalizer::normalize((string) $m['counterpartyAccount']);

        $label = $structured !== null ? self::formatStructured($structured) : $communication;
        if ($label === '') {
            $label = $counterpartyName !== '' ? $counterpartyName : 'Mouvement sans communication';
        }

        $extra = [];
        if ($valueDate->format('Y-m-d') !== $entryDate->format('Y-m-d')) {
            $extra[] = 'Date valeur : ' . $valueDate->format('d/m/Y');
        }
        $extra[] = 'Code opération : ' . $m['transactionCode'];
        $information = self::collapse((string) $m['information']);
        if ($information !== '') {
            $extra[] = 'Information : ' . $information;
        }

        $lines[] = new StatementLine(
            accountIban: (string) $m['account'],
            bankReference: self::dedupKey($m),
            transactionDate: $entryDate,
            amount: round(((int) $m['millis']) / 1000, 2),
            label: $label,
            counterpartyAccount: $counterpartyAccount !== '' ? $counterpartyAccount : null,
            counterpartyName: $counterpartyName !== '' ? $counterpartyName : null,
            extraDetails: implode(' ; ', $extra),
            structuredCommunication: $structured
        );
    }

    /**
     * Stable across exports and years: what identifies the movement, never
     * where the export happened to cut it. The bank's own reference when
     * there is one; the sequence number only as a last resort, for a bank
     * that leaves it blank.
     *
     * @param array<string, mixed> $m
     */
    private static function dedupKey(array $m): string
    {
        /** @var \DateTimeImmutable $entryDate */
        $entryDate = $m['entryDate'];
        $parts = [
            $m['account'],
            $entryDate->format('Y-m-d'),
            $m['bankReference'] !== '' ? $m['bankReference'] : 'seq:' . $m['sequence'],
            $m['detail'],
            (string) $m['millis'],
        ];

        return 'coda-' . substr(sha1(implode('|', $parts)), 0, 32);
    }

    /**
     * The account a record 1 names, as a normalized IBAN. Structure 2 is a
     * Belgian IBAN (16 characters), 3 a foreign one (34); structure 0 is a
     * Belgian account number, turned into the IBAN it is; 1, a foreign
     * number, is kept as written — it will match no account and be
     * reported as unknown, which is the truth.
     */
    private function accountIban(string $structure, string $field): string
    {
        return match ($structure) {
            '2' => IbanNormalizer::normalize(substr($field, 0, 16)),
            '0' => self::belgianIban(substr($field, 0, 12)),
            default => IbanNormalizer::normalize(substr($field, 0, 34)),
        };
    }

    private static function belgianIban(string $bban): string
    {
        $remainder = 0;
        foreach (str_split($bban . '111400') as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return sprintf('BE%02d%s', 98 - $remainder, $bban);
    }

    /**
     * Signed thousandths of a euro.
     *
     * @throws FinanceException
     */
    private function millis(string $sign, string $digits, int $index): int
    {
        if (preg_match('/^\d{15}$/', $digits) !== 1 || ($sign !== '0' && $sign !== '1')) {
            throw new FinanceException('Montant invalide dans le fichier CODA (ligne ' . ($index + 1) . ').');
        }

        return ($sign === '1' ? -1 : 1) * (int) $digits;
    }

    /**
     * @throws FinanceException
     */
    private function date(string $ddmmyy, int $index): \DateTimeImmutable
    {
        $date = DateInput::parse('!dmy', $ddmmyy);
        if ($date === null) {
            throw new FinanceException('Date invalide dans le fichier CODA (ligne ' . ($index + 1) . ').');
        }

        return $date;
    }

    /**
     * @throws FinanceException
     */
    private function requireStatement(?string $account, int $index): void
    {
        if ($account === null) {
            throw new FinanceException(
                'Le fichier CODA est mal formé : un mouvement précède son relevé (ligne ' . ($index + 1) . ').'
            );
        }
    }

    /**
     * @param array<string, mixed>|null $movement
     * @throws FinanceException
     */
    private function requireMovement(?array $movement, int $index): void
    {
        if ($movement === null) {
            throw new FinanceException(
                'Le fichier CODA est mal formé : une suite de mouvement sans mouvement (ligne ' . ($index + 1) . ').'
            );
        }
    }

    /** $length characters from 0-based $offset. */
    private static function field(string $record, int $offset, int $length): string
    {
        return mb_substr($record, $offset, $length);
    }

    private static function collapse(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private static function formatStructured(string $digits): string
    {
        return '+++' . substr($digits, 0, 3) . '/' . substr($digits, 3, 4) . '/' . substr($digits, 7, 5) . '+++';
    }
}
