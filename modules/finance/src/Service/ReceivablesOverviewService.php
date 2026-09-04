<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Security\Role;
use Modules\Finance\Api\ReceivableSourceDescriberInterface;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;

/**
 * Builds the view model for the "Paiements attendus" reconciliation page
 * (Controller\ReceivablesController) — a generic, module-agnostic list of
 * every finance_expected_receivables row grouped by source_module (level
 * 1) then by source_reference_id (level 2), with each row's live-computed
 * status (level 3). Finance has no notion of what a source instance "is"
 * beyond its numeric id — a friendly source-type label is the only
 * per-module customization allowed here (SOURCE_TYPE_LABELS), to keep
 * this page working unmodified for any future source module.
 */
class ReceivablesOverviewService
{
    /** @var array<string, ReceivableSourceDescriberInterface> keyed by source module */
    private array $describers = [];

    /**
     * @param ReceivableSourceDescriberInterface[] $describers one per
     *   module that registers expected payments and is enabled right now
     *   (Api\ReceivableSourceDescriberInterface). A source with none keeps
     *   the old behaviour — its module id and its reference id — rather
     *   than disappearing.
     */
    public function __construct(
        private ExpectedReceivableRepository $repository,
        private ExpectedReceivableService $receivableService,
        private AccountRepository $accountRepository,
        private AccountVisibility $accountVisibility,
        array $describers = [],
        /**
         * Display names for the members a receivable names as its debtor
         * — `Closure(int[]): array<int, string>`, so finance depends on a
         * question rather than on Core\Member\MemberService's whole
         * surface. Null leaves the free-text label as the only name, which
         * is what this page showed before.
         *
         * @var (\Closure(int[]): array<int, string>)|null
         */
        private ?\Closure $memberNames = null
    ) {
        foreach ($describers as $describer) {
            // Keyed on what the describer says it speaks for, never on
            // where it sat in the array: a registry keyed by hand is a
            // registry where one entry eventually names the wrong module.
            $this->describers[$describer->sourceModule()] = $describer;
        }
    }

    /**
     * $viewerRole is the caller's effective role — receivables on accounts
     * it cannot view are dropped entirely (see the filter below).
     *
     * `groups_instances` says whether the middle level is worth rendering
     * at all — see where it is computed. `receivables` is every row of the
     * source with the instance boundaries removed, for when it is not.
     *
     * @return array<int, array{
     *     source_module: string,
     *     source_label: string,
     *     amount_due: int,
     *     amount_received: int,
     *     groups_instances: bool,
     *     instances: array<int, array{
     *         source_reference_id: int,
     *         instance_label: string,
     *         amount_due: int,
     *         amount_received: int,
     *         receivables: array<
     *             int,
     *             array{
     *                 id: int,
     *                 source_reference_id: int,
     *                 label: ?string,
     *                 communication: string,
     *                 amount_due: int,
     *                 amount_received: int,
     *                 status: string
     *             }
     *         >
     *     }>,
     *     receivables: array<
     *         int,
     *         array{
     *             id: int,
     *             source_reference_id: int,
     *             label: ?string,
     *             communication: string,
     *             amount_due: int,
     *             amount_received: int,
     *             status: string
     *         }
     *     >
     * }>
     */
    public function buildOverview(Role $viewerRole): array
    {
        $overview = [];
        $visibleAccountIds = $this->visibleAccountIds($viewerRole);

        foreach ($this->repository->findDistinctSourceModules() as $sourceModule) {
            // Every other finance page resolves its accounts through
            // FinanceService::getAccountsForUser()/resolveSelectedAccount(),
            // so an account whose role_min_view is above the viewer never
            // appears. This page used to skip that entirely and render every
            // row in the table, handing an intendant the label, payer
            // communication and reconciled amounts of chief/admin-only
            // accounts. Filtering here (rather than in the query) keeps the
            // repository module-agnostic and covers the amounts too, since
            // each row's amount_received is computed from its own account's
            // movements.
            $receivables = array_values(array_filter(
                $this->repository->findAllByModule($sourceModule),
                static fn($receivable) => in_array($receivable->accountId, $visibleAccountIds, true)
            ));

            if ($receivables === []) {
                continue;
            }

            // One batch call rather than getReceivableStatus() per row:
            // each of those re-read and re-decrypted every movement on the
            // account, so a page with a few hundred receivables over a few
            // thousand movements ran hundreds of thousands of decryptions.
            $statuses = $this->receivableService->getReceivableStatuses($receivables);

            $instancesByReference = [];
            foreach ($receivables as $receivable) {
                $instancesByReference[$receivable->sourceReferenceId][] = $receivable;
            }

            $instances = [];
            $sourceRows = [];
            $sourceAmountDue = 0;
            $sourceAmountReceived = 0;

            foreach ($instancesByReference as $referenceId => $group) {
                $rows = [];
                $instanceAmountDue = 0;
                $instanceAmountReceived = 0;

                foreach ($group as $receivable) {
                    $status = $statuses[$receivable->id];
                    $row = [
                        'id' => $receivable->id,
                        // Carried on the row as well as on the instance:
                        // when the instance level is dropped (see
                        // `groups_instances`) the row is what a « voir cette
                        // créance » link has to be able to point at.
                        'source_reference_id' => $receivable->sourceReferenceId,
                        // The free text the source module wrote, else the
                        // member it names. `member_id` has always said WHO
                        // owes this; the column is headed « Nom/Contact »
                        // and used to print « — » for a receivable that
                        // carried a debtor but no label.
                        'label' => $receivable->label,
                        // Carried through the build so the names can be
                        // resolved in ONE pass at the end rather than a
                        // lookup per row — see nameTheDebtors().
                        'member_id' => $receivable->memberId,
                        'communication' => $receivable->communication,
                        'amount_due' => $status['amount_due'],
                        'amount_received' => $status['amount_received'],
                        'status' => $status['status'],
                    ];

                    $rows[] = $row;
                    $sourceRows[] = $row;
                    $instanceAmountDue += $status['amount_due'];
                    $instanceAmountReceived += $status['amount_received'];
                }

                $instances[] = [
                    'source_reference_id' => $referenceId,
                    'instance_label' => $this->instanceLabel($sourceModule, $referenceId),
                    'amount_due' => $instanceAmountDue,
                    'amount_received' => $instanceAmountReceived,
                    'receivables' => $rows,
                ];

                $sourceAmountDue += $instanceAmountDue;
                $sourceAmountReceived += $instanceAmountReceived;
            }

            // Does the middle level actually GROUP anything?
            //
            // It is worth a heading and a click when one instance raised
            // many receivables — one form answered by thirty families. It is
            // worth nothing at all when every instance holds exactly one:
            // the reader then gets N collapsed headers, each named after a
            // database id nobody recognises, each hiding a single line whose
            // own subtotal is that line. That shape is not hypothetical —
            // it is what a module registering one receivable per payer
            // produces, and it turned this page into sixteen accordions to
            // read sixteen rows.
            //
            // So the level is kept where it groups and dropped where it does
            // not. Nothing is lost by dropping it: the header carried an id
            // and a subtotal, and the row carries the same amounts.
            $groupsInstances = false;
            foreach ($instances as $instance) {
                if (count($instance['receivables']) > 1) {
                    $groupsInstances = true;
                    break;
                }
            }

            $overview[] = [
                'source_module' => $sourceModule,
                'source_label' => $this->sourceLabel($sourceModule),
                'amount_due' => $sourceAmountDue,
                'amount_received' => $sourceAmountReceived,
                'groups_instances' => $groupsInstances,
                'instances' => $instances,
                // Every receivable of this source, instance boundaries
                // removed — what the page renders when those boundaries
                // separate nothing.
                'receivables' => $sourceRows,
            ];
        }

        return $this->nameTheDebtors($overview);
    }

    /**
     * Fill each row's « Nom/Contact » from the member it names, where the
     * source module wrote no free text of its own.
     *
     * `member_id` has always said WHO owes this — the schema says so in as
     * many words — and the column used to print « — » for a receivable
     * that carried a debtor and no label.
     *
     * **The name is the CURRENT one**: the member's newest annual row, so
     * somebody who has left the unit is still named. A receivable outlives
     * the scout year that saw it born, which is exactly why it points at
     * `members.id` and not at an annual row.
     *
     * One pass, one call: the ids are collected from the whole overview
     * and resolved together, so a page of two hundred rows costs one
     * lookup rather than two hundred.
     *
     * @param array<int, array<string, mixed>> $overview
     * @return array<int, array<string, mixed>>
     */
    private function nameTheDebtors(array $overview): array
    {
        $memberIds = [];
        foreach ($overview as $source) {
            foreach ($source['receivables'] as $row) {
                if ($row['label'] === null && $row['member_id'] !== null) {
                    $memberIds[$row['member_id']] = true;
                }
            }
        }

        $names = [];
        if ($this->memberNames !== null && $memberIds !== []) {
            try {
                $names = ($this->memberNames)(array_keys($memberIds));
            } catch (\Throwable) {
                // A resolver that throws must not cost the treasurer the
                // page: the rows then show a dash, which is exactly what
                // they showed before names were resolved at all.
                $names = [];
            }
        }

        foreach ($overview as $s => $source) {
            foreach ($source['receivables'] as $r => $row) {
                $overview[$s]['receivables'][$r] = self::named($row, $names);
            }

            foreach ($source['instances'] as $i => $instance) {
                foreach ($instance['receivables'] as $r => $row) {
                    $overview[$s]['instances'][$i]['receivables'][$r] = self::named($row, $names);
                }
            }
        }

        return $overview;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $names
     * @return array<string, mixed>
     */
    private static function named(array $row, array $names): array
    {
        if ($row['label'] === null && $row['member_id'] !== null) {
            $row['label'] = $names[$row['member_id']] ?? null;
        }

        // Off the view model once it has done its work: a template has no
        // use for a member id, and one it can reach is one it will
        // eventually print.
        unset($row['member_id']);

        return $row;
    }

    /**
     * Ids of the accounts $viewerRole may see, through the one predicate
     * every finance route shares (Service\AccountVisibility) —
     * role_min_view AND, for an account attached to a section, being that
     * section's treasurer. Written here as a call rather than as a copy of
     * the condition: a page that decided visibility its own way is exactly
     * how this page came to be listing accounts no other page would show.
     *
     * Deliberately NOT narrowed to Account::STATUS_ACTIVE the way
     * FinanceService::getAccountsForUser() is — AccountVisibility says
     * nothing about status for this reason: a receivable booked against an
     * account that has since been archived must still reconcile for
     * someone allowed to see that account, and hiding it would silently
     * drop money from the totals rather than protect anything.
     *
     * @return int[]
     */
    private function visibleAccountIds(Role $viewerRole): array
    {
        $ids = [];
        foreach ($this->accountRepository->findAllOrdered() as $account) {
            if ($this->accountVisibility->isVisibleTo($account, $viewerRole)) {
                $ids[] = $account->id;
            }
        }

        return $ids;
    }

    private function instanceLabel(string $sourceModule, int $referenceId): string
    {
        $named = $this->describe($sourceModule, static fn(ReceivableSourceDescriberInterface $d): ?string
            => $d->describeInstance($referenceId));

        if ($named !== null && $named !== '') {
            return $named;
        }

        // No describer, or a reference its module no longer recognises —
        // an object somebody deleted. Naming the group by its id is honest;
        // a made-up name would not be.
        return ucfirst($sourceModule) . ' #' . $referenceId;
    }

    /**
     * What the module goes by, plural — or its id with a capital letter,
     * which is « Rental » in front of a French reader and the reason the
     * describer exists.
     */
    private function sourceLabel(string $sourceModule): string
    {
        $named = $this->describe($sourceModule, static fn(ReceivableSourceDescriberInterface $d): string
            => $d->sourceLabel());

        return $named !== null && $named !== '' ? $named : ucfirst($sourceModule);
    }

    /**
     * Ask this source's describer, and treat a throw as no answer.
     *
     * A module that cannot name its own object must not take down the page
     * that lists everybody's — the same posture every other callback into
     * another module takes in this codebase.
     *
     * @param \Closure(ReceivableSourceDescriberInterface): (?string) $question
     */
    private function describe(string $sourceModule, \Closure $question): ?string
    {
        $describer = $this->describers[$sourceModule] ?? null;
        if ($describer === null) {
            return null;
        }

        try {
            return $question($describer);
        } catch (\Throwable) {
            return null;
        }
    }
}
