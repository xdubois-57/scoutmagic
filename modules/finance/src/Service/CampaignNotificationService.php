<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Import\MemberYearRepository;
use Core\Member\MemberAccountResolver;
use Core\Member\MemberService;
use Core\Notification\NotificationService;
use Modules\Finance\Repository\Campaign;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;

/**
 * The notification behind "Notifier les familles".
 *
 * **One notification per account, aggregated — never one per
 * receivable.** A parent of three would otherwise get three in a row,
 * which is how a family learns to swipe this kind of message away
 * without reading it. The title carries the family's total and the count
 * of demands; the body names each child and their amount.
 *
 * **Every account linked to the member is notified, parent and animé
 * alike** (Core\Member\MemberAccountResolver): the Desk address, which
 * for a young member is a parent's, plus any confirmed secondary address
 * the member added themselves. A teenager who added their own address
 * hears about it too, rather than instead.
 *
 * The amount is in the title on purpose — a notification that says
 * "quelque chose vous attend" makes people open the site to find out —
 * and Core\Notification's discretion mode is what keeps that safe: it
 * replaces the pushed title without touching the notification centre, so
 * an ado whose lock screen is read over their shoulder in the school
 * corridor does not broadcast "Cotisation de 45 € impayée". That is a
 * real case, not a theoretical one.
 *
 * Fired by an explicit gesture and never at draft time: the reminder mail
 * leaves by hand from the mail-merge screen, so a notification sent when
 * the draft is created would announce a message nobody has written yet.
 */
class CampaignNotificationService
{
    public const TYPE_PAYMENT_DUE = 'finance.payment_due';

    public function __construct(
        private CampaignRowRepository $rows,
        private ExpectedReceivableRepository $receivables,
        private ReceivableAllocationService $allocations,
        private MemberAccountResolver $memberAccounts,
        private MemberService $members,
        private MemberYearRepository $memberYears,
        private ?NotificationService $notifications = null
    ) {
    }

    /**
     * Tells every family with something still owed on this campaign.
     *
     * @return int the number of accounts notified — what the flash
     *         message says, because "les familles sont prévenues" without
     *         a count hides the case where nobody could be reached
     */
    public function notifyFamilies(Campaign $campaign, ?int $actorAccountId): int
    {
        if ($this->notifications === null) {
            return 0;
        }

        $demandsByAccount = $this->demandsByAccount($campaign);
        if ($demandsByAccount === []) {
            return 0;
        }

        foreach ($demandsByAccount as $accountId => $demands) {
            $total = 0;
            foreach ($demands as $demand) {
                $total += $demand['amount_cents'];
            }

            $this->notifications->dispatch(
                self::TYPE_PAYMENT_DUE,
                [['userAccountId' => (int) $accountId, 'memberId' => $demands[0]['member_id']]],
                [
                    'title' => count($demands) > 1
                        ? self::euros($total) . ' à payer pour ' . count($demands) . ' membres'
                        : self::euros($total) . ' à payer — ' . $campaign->label,
                    'body' => $this->body($campaign, $demands),
                    // The homepage when the id could not be resolved: its
                    // band carries the same total, so the notification
                    // never lands on a 404.
                    'url' => $demands[0]['member_year_id'] > 0
                        ? '/members/' . $demands[0]['member_year_id']
                        : '/',
                ],
                $actorAccountId
            );
        }

        return count($demandsByAccount);
    }

    /**
     * @param list<array{member_id: int, member_year_id: int, name: string, amount_cents: int}> $demands
     */
    private function body(Campaign $campaign, array $demands): string
    {
        $lines = [];
        foreach ($demands as $demand) {
            $lines[] = $demand['name'] . ' : ' . self::euros($demand['amount_cents']);
        }

        return $campaign->label . ' — ' . implode(', ', $lines)
            . '. Le détail, la communication et le QR de paiement sont sur la page de chaque membre.';
    }

    /**
     * The still-owed demands of this campaign, grouped by the account
     * that will hear about them.
     *
     * A member reachable by two accounts (their Desk address and their
     * own confirmed one) puts the same demand in both lists: both are
     * real logins, and each gets ONE aggregated notification.
     *
     * @return array<int, list<array{member_id: int, member_year_id: int, name: string, amount_cents: int}>>
     */
    private function demandsByAccount(Campaign $campaign): array
    {
        $rows = $this->rows->findByCampaignId($campaign->id);
        if ($rows === []) {
            return [];
        }

        $receivablesByRowId = $this->receivables->findBySourceReferenceIds(
            CampaignService::SOURCE_MODULE,
            array_map(static fn($row): int => $row->id, $rows)
        );
        $settlements = $this->allocations->refreshAndSettle(array_values($receivablesByRowId));

        $directory = [];
        foreach ($this->members->findDirectoryForYear($campaign->scoutYearId) as $entry) {
            $directory[$entry->memberId] = $entry;
        }

        // The notification deep-links to the member's own page, which is
        // where the amount, the communication and the QR are — so it
        // needs the year's member_years id, which the directory entry
        // deliberately does not carry (it is keyed on the persistent id).
        $memberYearIdByMemberId = [];
        foreach ($this->memberYears->findAllByMemberIds(
            array_map(static fn($row): int => $row->memberId, $rows),
            $campaign->scoutYearId
        ) as $memberYearRow) {
            $memberYearIdByMemberId[(int) $memberYearRow['member_id']] = (int) $memberYearRow['id'];
        }

        $byAccount = [];
        foreach ($rows as $row) {
            $receivable = $receivablesByRowId[$row->id] ?? null;
            $settlement = $receivable !== null ? ($settlements[$receivable->id] ?? null) : null;
            if ($settlement === null) {
                continue;
            }
            // Nothing left to ask for: settled, or abandoned. Telling
            // somebody who has paid that they owe money is worse than
            // telling them nothing.
            if ($settlement->isWaived() || $settlement->amountRemainingCents() <= 0) {
                continue;
            }

            $entry = $directory[$row->memberId] ?? null;
            $demand = [
                'member_id' => $row->memberId,
                'member_year_id' => $memberYearIdByMemberId[$row->memberId] ?? 0,
                'name' => $entry !== null && trim($entry->firstName) !== ''
                    ? $entry->firstName
                    : ('Membre #' . $row->memberId),
                'amount_cents' => $settlement->amountRemainingCents(),
            ];

            foreach ($this->memberAccounts->accountIdsForMember($row->memberId) as $accountId) {
                $byAccount[$accountId][] = $demand;
            }
        }

        return $byAccount;
    }

    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}
