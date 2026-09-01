<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Security\Role;
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
    /**
     * The name a source module goes by on this page, plural.
     *
     * The fallback is `ucfirst($sourceModule)`, which is a module ID with a
     * capital letter — « Rental » in front of a French chef d'unité, and
     * « Reference_dataset » on a demonstration instance. Fine as a last
     * resort for a module nobody anticipated; not fine for the two that
     * actually register receivables today, so both are named here with the
     * name their own manifest declares.
     *
     * @var array<string, string>
     */
    private const SOURCE_TYPE_LABELS = [
        'news' => 'Formulaires',
        'rental' => 'Locations',
    ];

    public function __construct(
        private ExpectedReceivableRepository $repository,
        private ExpectedReceivableService $receivableService,
        private AccountRepository $accountRepository,
        private AccountVisibility $accountVisibility
    ) {
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
     *         receivables: array<int, array{id: int, source_reference_id: int, label: ?string, communication: string, amount_due: int, amount_received: int, status: string}>
     *     }>,
     *     receivables: array<int, array{id: int, source_reference_id: int, label: ?string, communication: string, amount_due: int, amount_received: int, status: string}>
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
                        'label' => $receivable->label,
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
                'source_label' => self::SOURCE_TYPE_LABELS[$sourceModule] ?? ucfirst($sourceModule),
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

        return $overview;
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
        $singular = match ($sourceModule) {
            'news' => 'Formulaire',
            'rental' => 'Location',
            default => ucfirst($sourceModule),
        };

        return $singular . ' #' . $referenceId;
    }
}
