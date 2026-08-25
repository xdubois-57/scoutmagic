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
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\Role;
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
        $open = $this->openReceivablesFor([$memberId]);
        if ($open === []) {
            return [];
        }

        $labels = $this->labelsFor($open);

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

        $open = $this->openReceivablesFor(array_keys($memberYearIdByMemberId));
        if ($open === []) {
            return null;
        }

        $labels = $this->labelsFor($open);

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
     * refreshAndSettle() rather than a plain read, for the same reason
     * the QR route does it: what a family is shown they owe is the one
     * number here that must never be stale.
     *
     * @param int[] $memberIds
     * @return list<array{receivable: ExpectedReceivable, remaining_cents: int, received_cents: int}>
     */
    private function openReceivablesFor(array $memberIds): array
    {
        $receivables = $this->receivables->findByMemberIds($memberIds);
        if ($receivables === []) {
            return [];
        }

        $settlements = $this->allocations->refreshAndSettle($receivables);

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
     * @param list<array{receivable: ExpectedReceivable, remaining_cents: int, received_cents: int}> $open
     * @return array<int, string> receivable id => label
     */
    private function labelsFor(array $open): array
    {
        $rowIds = [];
        foreach ($open as $entry) {
            if ($entry['receivable']->sourceModule === CampaignService::SOURCE_MODULE) {
                $rowIds[] = $entry['receivable']->sourceReferenceId;
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
        foreach ($open as $entry) {
            $receivable = $entry['receivable'];
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
