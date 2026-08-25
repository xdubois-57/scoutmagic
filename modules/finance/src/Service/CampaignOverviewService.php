<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Member\MemberService;
use Core\Security\Role;
use Core\Security\UserAccountRepository;
use Core\Service\TextNormalizerService;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Campaign;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Repository\ExpectedReceivable;
use Modules\Finance\Repository\ExpectedReceivableRepository;

/**
 * What the campaign screens read: the list of campaigns with their
 * progress, and one campaign's lines with where each stands.
 *
 * **Sorted by family name**, accent-insensitively, so the receivables of
 * one household follow each other and a treasurer can deal with a family
 * in one go rather than hunting for its three children in an
 * alphabetical soup of first names.
 *
 * The default filter is « À traiter », and it deliberately includes a
 * PAID receivable that carries an unsettled surplus: that line still
 * wants a gesture, and a filter that hid it would be quietly telling the
 * treasurer there is nothing left to do.
 */
class CampaignOverviewService
{
    public const FILTER_TODO = 'todo';
    public const FILTER_PAID = 'paid';
    public const FILTER_WAIVED = 'waived';
    public const FILTER_ALL = 'all';

    public function __construct(
        private CampaignRepository $campaigns,
        private CampaignRowRepository $rows,
        private ExpectedReceivableRepository $receivables,
        private ReceivableAllocationService $allocations,
        private AccountRepository $accountRepository,
        private AccountVisibility $accountVisibility,
        private MemberService $members,
        private UserAccountRepository $userAccounts
    ) {
    }

    /**
     * Campaigns of one scout year, narrowed to the accounts this session
     * may see, each with what it asks for and what has come in.
     *
     * @return array<int, array{campaign: Campaign, row_count: int, amount_due: int, amount_received: int, percent: int, todo_count: int}>
     */
    public function listForYear(int $scoutYearId, Role $viewerRole): array
    {
        $summaries = [];

        foreach ($this->campaigns->findByScoutYear($scoutYearId) as $campaign) {
            if (!$this->accountVisibility->isVisibleTo($this->accountRepository->findById($campaign->accountId), $viewerRole)) {
                continue;
            }

            $rows = $this->rows->findByCampaignId($campaign->id);
            $receivables = $this->receivables->findBySourceReferenceIds(
                CampaignService::SOURCE_MODULE,
                array_map(static fn($row): int => $row->id, $rows)
            );
            $settlements = $this->allocations->refreshAndSettle(array_values($receivables));

            $due = 0;
            $received = 0;
            $todo = 0;
            foreach ($receivables as $receivable) {
                $settlement = $settlements[$receivable->id] ?? null;
                if ($settlement === null) {
                    continue;
                }
                $due += $settlement->amountDueCents;
                $received += min($settlement->amountAllocatedCents, $settlement->amountDueCents);
                if ($settlement->needsAttention()) {
                    $todo++;
                }
            }

            $summaries[] = [
                'campaign' => $campaign,
                'row_count' => count($rows),
                'amount_due' => $due,
                'amount_received' => $received,
                'percent' => $due > 0 ? (int) round($received / $due * 100) : 100,
                'todo_count' => $todo,
            ];
        }

        return $summaries;
    }

    /**
     * One campaign's lines, filtered, sorted by family name.
     *
     * @return array{
     *     campaign: Campaign,
     *     rows: array<int, array<string, mixed>>,
     *     counts: array<string, int>,
     *     totals: array{amount_due: int, amount_received: int, unpaid_count: int, overpaid_count: int}
     * }
     */
    public function detail(Campaign $campaign, string $filter): array
    {
        $rows = $this->rows->findByCampaignId($campaign->id);
        $receivablesByRowId = $this->receivables->findBySourceReferenceIds(
            CampaignService::SOURCE_MODULE,
            array_map(static fn($row): int => $row->id, $rows)
        );
        $settlements = $this->allocations->refreshAndSettle(array_values($receivablesByRowId));

        // One pass over the year's roster rather than a query per line:
        // a campaign is a few hundred lines, and a lookup each would be
        // a few hundred round trips for three strings apiece.
        $identities = [];
        foreach ($this->members->findDirectoryForYear($campaign->scoutYearId) as $entry) {
            $identities[$entry->memberId] = $entry;
        }

        $authorNames = $this->userAccounts->findNamesByIds(array_values(array_filter(
            array_map(static fn($row): ?int => $row->noteAuthorId, $rows),
            static fn(?int $id): bool => $id !== null
        )));

        $built = [];
        $counts = [self::FILTER_TODO => 0, self::FILTER_PAID => 0, self::FILTER_WAIVED => 0, self::FILTER_ALL => 0];
        $totals = ['amount_due' => 0, 'amount_received' => 0, 'unpaid_count' => 0, 'overpaid_count' => 0];

        foreach ($rows as $row) {
            $receivable = $receivablesByRowId[$row->id] ?? null;
            $settlement = $receivable !== null ? ($settlements[$receivable->id] ?? null) : null;
            if ($receivable === null || $settlement === null) {
                // A row whose receivable is gone (a source deleted by
                // hand, a half-restored backup) is shown rather than
                // hidden: a line that vanishes silently is how a family
                // stops being asked without anybody noticing.
                $built[] = $this->orphanRow($row, $identities[$row->memberId] ?? null, $authorNames);
                $counts[self::FILTER_ALL]++;
                continue;
            }

            $entry = $identities[$row->memberId] ?? null;
            $built[] = [
                'row_id' => $row->id,
                'receivable_id' => $receivable->id,
                'member_id' => $row->memberId,
                'last_name' => $entry !== null ? $entry->lastName : '',
                'first_name' => $entry !== null ? $entry->firstName : '',
                'display_name' => $entry !== null ? trim($entry->lastName . ' ' . $entry->firstName) : ('Membre #' . $row->memberId),
                'section' => $entry?->sectionName,
                'communication' => $receivable->communication,
                'amount_due' => $settlement->amountDueCents,
                'amount_received' => $settlement->amountAllocatedCents,
                'amount_designated' => $settlement->amountDesignatedCents,
                'amount_remaining' => $settlement->amountRemainingCents(),
                'amount_overpaid' => $settlement->amountOverpaidCents,
                'status' => $settlement->status,
                'refund_state' => $settlement->refundState,
                'needs_attention' => $settlement->needsAttention(),
                'note' => $row->note,
                'note_author' => $this->authorLabel($row->noteAuthorId, $authorNames),
                'note_updated_at' => $row->noteUpdatedAt,
            ];

            $counts[self::FILTER_ALL]++;
            if ($settlement->needsAttention()) {
                $counts[self::FILTER_TODO]++;
            }
            if ($settlement->status === ReceivableSettlement::STATUS_PAID) {
                $counts[self::FILTER_PAID]++;
            }
            if ($settlement->status === ReceivableSettlement::STATUS_WAIVED) {
                $counts[self::FILTER_WAIVED]++;
            }

            $totals['amount_due'] += $settlement->amountDueCents;
            $totals['amount_received'] += min($settlement->amountAllocatedCents, $settlement->amountDueCents);
            if ($settlement->status === ReceivableSettlement::STATUS_UNPAID || $settlement->status === ReceivableSettlement::STATUS_PARTIAL) {
                $totals['unpaid_count']++;
            }
            if ($settlement->amountOverpaidCents > 0) {
                $totals['overpaid_count']++;
            }
        }

        usort($built, static function (array $a, array $b): int {
            $byLast = strcmp(
                TextNormalizerService::fold((string) $a['last_name']),
                TextNormalizerService::fold((string) $b['last_name'])
            );

            return $byLast !== 0 ? $byLast : strcmp(
                TextNormalizerService::fold((string) $a['first_name']),
                TextNormalizerService::fold((string) $b['first_name'])
            );
        });

        return [
            'campaign' => $campaign,
            'rows' => array_values(array_filter($built, static fn(array $row): bool => self::matches($row, $filter))),
            'counts' => $counts,
            'totals' => $totals,
        ];
    }

    public static function normalizeFilter(?string $filter): string
    {
        return in_array($filter, [self::FILTER_TODO, self::FILTER_PAID, self::FILTER_WAIVED, self::FILTER_ALL], true)
            ? $filter
            : self::FILTER_TODO;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function matches(array $row, string $filter): bool
    {
        return match ($filter) {
            self::FILTER_PAID => $row['status'] === ReceivableSettlement::STATUS_PAID,
            self::FILTER_WAIVED => $row['status'] === ReceivableSettlement::STATUS_WAIVED,
            self::FILTER_ALL => true,
            default => (bool) ($row['needs_attention'] ?? true),
        };
    }

    /**
     * @param array<int, array{first_name: ?string, last_name: ?string}> $authorNames
     * @return array<string, mixed>
     */
    private function orphanRow(\Modules\Finance\Repository\CampaignRow $row, ?\Core\Member\MemberDirectoryEntry $entry, array $authorNames): array
    {
        return [
            'row_id' => $row->id,
            'receivable_id' => null,
            'member_id' => $row->memberId,
            'last_name' => $entry !== null ? $entry->lastName : '',
            'first_name' => $entry !== null ? $entry->firstName : '',
            'display_name' => $entry !== null ? trim($entry->lastName . ' ' . $entry->firstName) : ('Membre #' . $row->memberId),
            'section' => $entry?->sectionName,
            'communication' => null,
            'amount_due' => $row->amountCents,
            'amount_received' => 0,
            'amount_designated' => 0,
            'amount_remaining' => $row->amountCents,
            'amount_overpaid' => 0,
            'status' => ReceivableSettlement::STATUS_UNPAID,
            'refund_state' => ReceivableSettlement::REFUND_NONE,
            'needs_attention' => true,
            'note' => $row->note,
            'note_author' => $this->authorLabel($row->noteAuthorId, $authorNames),
            'note_updated_at' => $row->noteUpdatedAt,
        ];
    }

    /**
     * @param array<int, array{first_name: ?string, last_name: ?string}> $authorNames
     */
    private function authorLabel(?int $authorId, array $authorNames): ?string
    {
        if ($authorId === null || !isset($authorNames[$authorId])) {
            return null;
        }

        $label = trim(($authorNames[$authorId]['first_name'] ?? '') . ' ' . ($authorNames[$authorId]['last_name'] ?? ''));

        return $label !== '' ? $label : null;
    }

    /**
     * @return ExpectedReceivable[]
     */
    public function receivablesOf(Campaign $campaign): array
    {
        return array_values($this->receivables->findBySourceReferenceIds(
            CampaignService::SOURCE_MODULE,
            array_map(static fn($row): int => $row->id, $this->rows->findByCampaignId($campaign->id))
        ));
    }
}
