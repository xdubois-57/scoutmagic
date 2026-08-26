<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

use Core\Member\MemberService;
use Core\Module\HomePaymentDueProvider;
use Core\Module\MemberPaymentProvider;
use Core\Module\MemberPaymentView;
use Core\Module\MemberSettledPaymentView;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\Role;
use Core\Service\DateInput;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Repository\ExpectedReceivable;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\StatementImportRepository;
use Modules\Finance\Service\CampaignService;
use Modules\Finance\Service\IbanNormalizer;
use Modules\Finance\Service\ReceivableAllocationService;
use Modules\Finance\Service\ReceivableQrTokenService;
use Modules\Finance\Service\ReceivableSettlement;

/**
 * This module's answer to core's two family-facing hooks: the payment
 * block on a member's own page (Core\Module\MemberPaymentProvider) and
 * the homepage band summarising a whole family's open demands
 * (Core\Module\HomePaymentDueProvider).
 *
 * One class for both because they are one question asked at two scales —
 * "what does this member still owe" and "what does everybody at this
 * address still owe" — and answering it twice would eventually answer it
 * differently.
 *
 * Lives under Api\ rather than Service\ for the same reason
 * Modules\Groups\Api\HomeActivityService does (ARCHITECTURE.md §7.5): it
 * is the surface another part of the codebase consumes, not an internal
 * collaborator of this module.
 *
 * **The homepage hook reads the session directly**, unlike everything
 * else here. That is deliberate and confined to the one method: the core
 * hook is called from a page that knows nothing about finance, and
 * inventing a parameter for core to fill in would mean core learning
 * what a receivable is. The member-page hook does the opposite — it
 * decides nothing about who may look, because the member page has
 * already decided that.
 */
class FamilyPaymentService implements MemberPaymentProvider, HomePaymentDueProvider
{
    /**
     * The band lists this many members and then stops naming them. A
     * family of four is real; a list of twenty on the homepage is a
     * screen nobody reads, and the total above it stays exact either way.
     */
    private const MAX_BAND_LINES = 8;

    public function __construct(
        private ExpectedReceivableRepository $receivables,
        private ReceivableAllocationService $allocations,
        private AccountRepository $accounts,
        private CampaignRowRepository $campaignRows,
        private CampaignRepository $campaigns,
        private StatementImportRepository $statementImports,
        private ReceivableQrTokenService $qrTokens,
        private MemberService $members,
        private ScoutYearResolver $scoutYears,
        private string $baseUrl
    ) {
    }

    /**
     * @return list<MemberPaymentView>
     */
    public function getOpenPayments(int $memberId): array
    {
        $open = $this->openReceivablesFor([$memberId], refresh: true);
        if ($open === []) {
            return [];
        }

        $labels = $this->labelsFor(array_column($open, 'receivable'));

        $views = [];
        foreach ($open as $entry) {
            $receivable = $entry['receivable'];
            $account = $this->accounts->findById($receivable->accountId);
            $iban = $account?->iban;
            $hasIban = $iban !== null && $iban !== '';

            $views[] = new MemberPaymentView(
                $labels[$receivable->id] ?? 'Paiement demandé',
                $entry['remaining_cents'],
                $receivable->amountDueCents,
                $entry['received_cents'],
                $receivable->communication,
                $account !== null ? ($account->holderName ?? $account->name) : null,
                $hasIban ? IbanNormalizer::format(IbanNormalizer::normalize($iban)) : null,
                // No IBAN, no code: a QR for an account nobody can pay
                // into would be a payment request a bank refuses, which
                // reads as a broken site rather than a missing setting.
                $hasIban ? $this->qrTokens->urlFor($receivable->id, $this->baseUrl) : null
            );
        }

        // Most recent first: the receivable somebody is being asked about
        // today is the one created last, not the one from two years ago.
        return array_reverse($views);
    }

    /**
     * The other half of Core\Module\MemberPaymentProvider: what is over.
     *
     * Reads through settlementsFor(), NOT refreshAndSettle() and not
     * storedSettlementsFor(). refreshAndSettle() writes: a page that only
     * reports history has no business re-allocating on every view.
     * storedSettlementsFor() reads too little — it deliberately skips the
     * credit scan, so `amountDesignatedCents` never exceeds what was
     * absorbed and a surplus is invisible. That would report a
     * receivable overpaid by 5,00 € as exactly paid and could never
     * reach STATUS_OVERPAYMENT_REFUNDED at all, which is the one thing
     * this block exists to make visible. settlementsFor() is the full
     * picture with no write.
     *
     * @return list<MemberSettledPaymentView>
     */
    public function getSettledPayments(int $memberId): array
    {
        $receivables = $this->receivables->findByMemberIds([$memberId]);
        if ($receivables === []) {
            return [];
        }

        $settlements = $this->allocations->settlementsFor($receivables);

        $settled = [];
        foreach ($receivables as $receivable) {
            $settlement = $settlements[$receivable->id] ?? null;
            if ($settlement === null || !$settlement->isSettled()) {
                continue;
            }
            $settled[] = ['receivable' => $receivable, 'settlement' => $settlement];
        }
        if ($settled === []) {
            return [];
        }

        $labels = $this->labelsFor(array_column($settled, 'receivable'));

        $views = [];
        foreach ($settled as $entry) {
            $receivable = $entry['receivable'];
            $settlement = $entry['settlement'];

            $views[] = new MemberSettledPaymentView(
                $labels[$receivable->id] ?? $receivable->label ?? 'Paiement demandé',
                $receivable->amountDueCents,
                $settlement->amountDesignatedCents,
                self::outcomeOf($settlement),
                // A waiver is dated by the waiver; anything else by the
                // row itself. requireFromStorage() is not used here: an
                // unreadable date makes the outcome undated on screen,
                // which is honest, where a 500 would take the whole page
                // down over one old row.
                DateInput::fromStorage($receivable->waivedAt ?? $receivable->createdAt)
            );
        }

        // Most recent first, then capped: the newest closed rows are the
        // ones somebody is being asked about, and a member of ten years
        // has dozens. The complete history lives in the finance module,
        // which is built to page through it.
        $views = array_reverse($views);

        return array_slice($views, 0, MemberPaymentProvider::SETTLED_LIMIT);
    }

    /**
     * Which of core's three outcomes a settlement is.
     *
     * A refunded surplus wins over "paid", deliberately: on its status
     * alone such a row reads as simply paid, and a chef d'unité looking
     * at « payé · 50,00 € » when 5,00 € of it went back out is being
     * told the wrong thing about the money.
     *
     * @return MemberSettledPaymentView::STATUS_*
     */
    private static function outcomeOf(ReceivableSettlement $settlement): string
    {
        if ($settlement->refundState === ReceivableSettlement::REFUND_DONE) {
            return MemberSettledPaymentView::STATUS_OVERPAYMENT_REFUNDED;
        }

        return $settlement->isWaived()
            ? MemberSettledPaymentView::STATUS_WAIVED
            : MemberSettledPaymentView::STATUS_PAID;
    }

    /**
     * @return null|array{
     *     total_cents: int,
     *     demands: list<array{member_year_id: int, member_name: string, label: string, amount_cents: int}>,
     *     single_member_year_id: ?int,
     *     statement_date: ?string
     * }
     */
    public function getHomePaymentSummaryForCurrentUser(): ?array
    {
        $email = AuthSession::getEmail();
        if ($email === null || $email === '') {
            return null;
        }

        // The year the visitor is actually looking at — the same
        // resolution every other page uses, so a chief previewing another
        // year sees that year's demands rather than two answers.
        $scoutYearId = $this->scoutYears->getEffectiveYear(
            ScoutYearSession::getPreviewId(),
            Role::fromString(AuthSession::getRole())
        )->id;

        // The same "members linked to this address" rule the "Mes
        // membres" menu itself uses (Core\Member\MemberService::
        // getLinkedMembers) — one resolution, so the band can never name
        // a member the menu does not, nor miss one it does.
        $linked = $this->members->getLinkedMembers($email, $scoutYearId);
        if ($linked === []) {
            return null;
        }

        $memberYearIdByMemberId = [];
        $nameByMemberId = [];
        foreach ($linked as $profile) {
            $memberYearIdByMemberId[$profile->memberId] = $profile->memberYearId;
            $nameByMemberId[$profile->memberId] = trim($profile->firstName) !== ''
                ? $profile->firstName
                : $profile->getDisplayName();
        }

        // refresh: false — the home band reads the STORED allocations. The
        // number a family acts on stays never-stale where they act on it
        // (the member page and the QR route both refresh), while the most
        // visited page of the site no longer re-reads and re-decrypts an
        // account's entire movement history — nor writes allocations — for
        // every parent with an open receivable. Staleness here is bounded
        // by the layers that already keep allocations current: they are
        // written the moment a bank import lands, when a receivable is
        // created or its amount moves, and the nightly
        // Task\ReconcileReceivablesHandler sweeps whatever those missed.
        $open = $this->openReceivablesFor(array_keys($memberYearIdByMemberId), refresh: false);
        if ($open === []) {
            return null;
        }

        $labels = $this->labelsFor(array_column($open, 'receivable'));

        $total = 0;
        $demands = [];
        $memberIdsSeen = [];
        $accountIds = [];
        foreach ($open as $entry) {
            $receivable = $entry['receivable'];
            $memberId = $receivable->memberId;
            if ($memberId === null || !isset($memberYearIdByMemberId[$memberId])) {
                continue;
            }

            $total += $entry['remaining_cents'];
            $accountIds[$receivable->accountId] = true;
            $memberIdsSeen[$memberId] = true;

            if (count($demands) < self::MAX_BAND_LINES) {
                $demands[] = [
                    'member_year_id' => $memberYearIdByMemberId[$memberId],
                    'member_name' => $nameByMemberId[$memberId] ?? ('Membre #' . $memberId),
                    'label' => $labels[$receivable->id] ?? 'Paiement demandé',
                    'amount_cents' => $entry['remaining_cents'],
                ];
            }
        }

        if ($demands === [] || $total <= 0) {
            return null;
        }

        $distinctMemberIds = array_keys($memberIdsSeen);

        return [
            'total_cents' => $total,
            'demands' => $demands,
            // One member owing: the band can send them straight to that
            // page. Several: each line carries its own link, because
            // there is no "my family" page to send them to and inventing
            // a destination is worse than not offering a button.
            'single_member_year_id' => count($distinctMemberIds) === 1
                ? $memberYearIdByMemberId[$distinctMemberIds[0]]
                : null,
            'statement_date' => $this->lastStatementDate(array_keys($accountIds)),
        ];
    }

    /**
     * The still-open receivables of a set of members, oldest first, each
     * with what is left on it.
     *
     * $refresh chooses between the two read stances the allocation model
     * offers. true — refreshAndSettle(), the same as the QR route: what a
     * family is shown where they PAY must never be stale, and the member
     * page is that place. false — storedSettlementsFor(), the stored
     * state: for the home band, whose freshness is already guaranteed by the writes
     * at import/creation time and the nightly reconcile task, and whose
     * traffic must not carry a full account-history scan per view.
     *
     * @param int[] $memberIds
     * @return list<array{receivable: ExpectedReceivable, remaining_cents: int, received_cents: int}>
     */
    private function openReceivablesFor(array $memberIds, bool $refresh): array
    {
        $receivables = $this->receivables->findByMemberIds($memberIds);
        if ($receivables === []) {
            return [];
        }

        $settlements = $refresh
            ? $this->allocations->refreshAndSettle($receivables)
            : $this->allocations->storedSettlementsFor($receivables);

        $open = [];
        foreach ($receivables as $receivable) {
            $settlement = $settlements[$receivable->id] ?? null;
            if ($settlement === null || $settlement->isWaived() || $settlement->amountRemainingCents() <= 0) {
                continue;
            }

            $open[] = [
                'receivable' => $receivable,
                'remaining_cents' => $settlement->amountRemainingCents(),
                'received_cents' => $settlement->amountDesignatedCents,
            ];
        }

        return $open;
    }

    /**
     * What each receivable is for, in the family's words — the campaign's
     * own label, which is what the treasurer typed and what the reminder
     * mail says.
     *
     * @param list<ExpectedReceivable> $receivables
     * @return array<int, string> receivable id => label
     */
    private function labelsFor(array $receivables): array
    {
        $rowIds = [];
        foreach ($receivables as $receivable) {
            if ($receivable->sourceModule === CampaignService::SOURCE_MODULE) {
                $rowIds[] = $receivable->sourceReferenceId;
            }
        }
        if ($rowIds === []) {
            return [];
        }

        $campaignIdByRowId = [];
        foreach ($this->campaignRows->findByIds($rowIds) as $row) {
            $campaignIdByRowId[$row->id] = $row->campaignId;
        }

        $labelByCampaignId = [];
        foreach (array_unique(array_values($campaignIdByRowId)) as $campaignId) {
            $campaign = $this->campaigns->findById($campaignId);
            if ($campaign !== null) {
                $labelByCampaignId[$campaignId] = $campaign->label;
            }
        }

        $labels = [];
        foreach ($receivables as $receivable) {
            if ($receivable->sourceModule !== CampaignService::SOURCE_MODULE) {
                continue;
            }
            $campaignId = $campaignIdByRowId[$receivable->sourceReferenceId] ?? null;
            if ($campaignId !== null && isset($labelByCampaignId[$campaignId])) {
                $labels[$receivable->id] = $labelByCampaignId[$campaignId];
            }
        }

        return $labels;
    }

    /**
     * The date up to which payments have been read — the OLDEST of the
     * involved accounts' most recent imports.
     *
     * Oldest rather than newest on purpose: the sentence promises that
     * everything received up to that date is taken into account, and
     * with two accounts imported on different days only the earlier date
     * makes that promise true for both.
     *
     * @param int[] $accountIds
     */
    private function lastStatementDate(array $accountIds): ?string
    {
        $oldest = null;
        foreach ($accountIds as $accountId) {
            $import = $this->statementImports->findMostRecentForAccount($accountId);
            if ($import === null) {
                // An account with nothing imported yet knows nothing about
                // any payment: no date can be promised for the family as
                // a whole, and saying nothing is the honest answer.
                return null;
            }
            $date = substr($import->importedAt, 0, 10);
            if ($oldest === null || $date < $oldest) {
                $oldest = $date;
            }
        }

        return $oldest;
    }

}
