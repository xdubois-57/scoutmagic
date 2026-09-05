<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Service\TextNormalizerService;
use Core\Security\Role;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivable;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\ReceivableAllocationRepository;

/**
 * « Quelle créance ? », answered by typing a name instead of by looking
 * up a number.
 *
 * The Rapprochement page asked for a receivable's **id**, with the help
 * text « L'identifiant de la créance, repris dans l'export de la
 * campagne » — which is to say: leave this page, open a spreadsheet,
 * find the line, come back and type an integer. Nobody does that twice.
 * The same page already knows how to answer « quel mouvement ? » by
 * searching (Controller\MovementController::search(), the receipts
 * picker), and this is that same shape for the other half of an
 * imputation.
 *
 * **Narrowed to one account, because an imputation is.**
 * Service\ReceivableAllocationService::allocate() refuses a movement and
 * a receivable that are not on the same account — « rien ne s'impute à
 * distance » — so offering a receivable from anywhere else would be
 * offering a choice that can only be refused.
 *
 * **A receivable with nothing left to collect is not offered.** Waived,
 * or already covered: both are answers, and neither can absorb another
 * cent.
 */
class ReceivableSearchService
{
    /** More than a person reads; the query is what narrows, not the cap. */
    private const LIMIT = 20;

    public function __construct(
        private ExpectedReceivableRepository $receivableRepository,
        private ReceivableAllocationRepository $allocationRepository,
        private AccountRepository $accountRepository,
        private AccountVisibility $accountVisibility,
        /**
         * « Comment s'appellent ces gens ? » — the same question the
         * Paiements attendus page asks, and the same answer. Null names
         * nobody, and the rows fall back to their communication, which is
         * what a receivable carrying no member has always shown.
         *
         * @var (\Closure(int[]): array<int, string>)|null
         */
        private ?\Closure $memberNames = null
    ) {
    }

    /**
     * @param ?int $nearAmountCents the credit being attached, when there
     *        is one: with no search text, a receivable owing exactly that
     *        is the one the treasurer is looking for, and it comes first
     *        — the same idea as the movement picker's `near_date`.
     * @return list<array{id: int, label: string, communication: string, remaining_cents: int}>
     */
    public function search(int $accountId, Role $viewerRole, string $query, ?int $nearAmountCents = null): array
    {
        $account = $this->accountRepository->findById($accountId);
        if ($account === null || !$this->accountVisibility->isVisibleTo($account, $viewerRole)) {
            return [];
        }

        $receivables = $this->receivableRepository->findByAccountId($accountId);

        // One query for every allocation of the account rather than one
        // per receivable: this runs on every keystroke.
        $allocationsByReceivable = $this->allocationRepository->findByReceivableIds(
            array_map(static fn(ExpectedReceivable $r): int => $r->id, $receivables)
        );

        $open = [];
        foreach ($receivables as $receivable) {
            $remaining = self::remainingCents($receivable, $allocationsByReceivable[$receivable->id] ?? []);
            if ($receivable->isWaived() || $remaining <= 0) {
                continue;
            }
            $open[] = ['receivable' => $receivable, 'remaining' => $remaining];
        }

        $names = $this->namesFor($open);

        $rows = [];
        foreach ($open as $entry) {
            $receivable = $entry['receivable'];
            $rows[] = [
                'id' => $receivable->id,
                'label' => self::labelFor($receivable, $names),
                'communication' => $receivable->communication,
                'remaining_cents' => $entry['remaining'],
            ];
        }

        $needle = self::fold($query);
        if ($needle !== '') {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => self::matches($row, $needle)));
        } elseif ($nearAmountCents !== null) {
            usort($rows, static fn(array $a, array $b): int => abs($a['remaining_cents'] - $nearAmountCents)
                <=> abs($b['remaining_cents'] - $nearAmountCents));

            return array_slice($rows, 0, self::LIMIT);
        }

        usort($rows, static fn(array $a, array $b): int => strcoll($a['label'], $b['label']));

        return array_slice($rows, 0, self::LIMIT);
    }

    /**
     * @param array<int, array{receivable: ExpectedReceivable, remaining: int}> $open
     * @return array<int, string>
     */
    private function namesFor(array $open): array
    {
        if ($this->memberNames === null) {
            return [];
        }

        $memberIds = [];
        foreach ($open as $entry) {
            if ($entry['receivable']->label === null && $entry['receivable']->memberId !== null) {
                $memberIds[$entry['receivable']->memberId] = true;
            }
        }

        if ($memberIds === []) {
            return [];
        }

        try {
            return ($this->memberNames)(array_keys($memberIds));
        } catch (\Throwable) {
            // A resolver that throws must not cost the treasurer the
            // picker: the rows then read by their communication, which is
            // what they read before names were resolved at all.
            return [];
        }
    }

    /**
     * @param array<int, string> $names
     */
    private static function labelFor(ExpectedReceivable $receivable, array $names): string
    {
        if ($receivable->label !== null && $receivable->label !== '') {
            return $receivable->label;
        }

        if ($receivable->memberId !== null && ($names[$receivable->memberId] ?? '') !== '') {
            return $names[$receivable->memberId];
        }

        return $receivable->communication;
    }

    /**
     * @param \Modules\Finance\Repository\ReceivableAllocation[] $allocations
     */
    private static function remainingCents(ExpectedReceivable $receivable, array $allocations): int
    {
        $allocated = 0;
        foreach ($allocations as $allocation) {
            // A refund is a negative allocation, and it does not put money
            // back on the receivable's bill — the same reading
            // Service\ReceivableAllocationService::allocatedCents() makes.
            if ($allocation->amountCents > 0) {
                $allocated += $allocation->amountCents;
            }
        }

        return $receivable->amountDueCents - $allocated;
    }

    /**
     * @param array{id: int, label: string, communication: string, remaining_cents: int} $row
     */
    private static function matches(array $row, string $needle): bool
    {
        // The id too: a treasurer who DID look it up in the export must
        // not be worse off than before for having done so.
        return str_contains(self::fold($row['label']), $needle)
            || str_contains(self::fold($row['communication']), $needle)
            || (string) $row['id'] === $needle;
    }

    /**
     * Accents and case folded away, so « Léa » is found by typing « lea »
     * — the same normalisation the ticket queue's own search uses.
     */
    private static function fold(string $value): string
    {
        // The core helper, for the reason its own docblock gives:
        // iconv('ASCII//TRANSLIT') answers differently depending on the C
        // library, so this search ignored accents on glibc and quietly
        // stopped doing so on macOS and musl.
        return TextNormalizerService::fold($value);
    }
}
