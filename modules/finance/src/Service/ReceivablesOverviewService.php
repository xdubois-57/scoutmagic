<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

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
    /** @var array<string, string> */
    private const SOURCE_TYPE_LABELS = [
        'news' => 'Formulaires',
    ];

    public function __construct(
        private ExpectedReceivableRepository $repository,
        private ExpectedReceivableService $receivableService
    ) {
    }

    /**
     * @return array<int, array{
     *     source_module: string,
     *     source_label: string,
     *     amount_due: int,
     *     amount_received: int,
     *     instances: array<int, array{
     *         source_reference_id: int,
     *         instance_label: string,
     *         amount_due: int,
     *         amount_received: int,
     *         receivables: array<int, array{id: int, label: ?string, communication: string, amount_due: int, amount_received: int, status: string}>
     *     }>
     * }>
     */
    public function buildOverview(): array
    {
        $overview = [];

        foreach ($this->repository->findDistinctSourceModules() as $sourceModule) {
            $receivables = $this->repository->findAllByModule($sourceModule);

            $instancesByReference = [];
            foreach ($receivables as $receivable) {
                $instancesByReference[$receivable->sourceReferenceId][] = $receivable;
            }

            $instances = [];
            $sourceAmountDue = 0;
            $sourceAmountReceived = 0;

            foreach ($instancesByReference as $referenceId => $group) {
                $rows = [];
                $instanceAmountDue = 0;
                $instanceAmountReceived = 0;

                foreach ($group as $receivable) {
                    $status = $this->receivableService->getReceivableStatus($receivable->id);
                    $rows[] = [
                        'id' => $receivable->id,
                        'label' => $receivable->label,
                        'communication' => $receivable->communication,
                        'amount_due' => $status['amount_due'],
                        'amount_received' => $status['amount_received'],
                        'status' => $status['status'],
                    ];
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

            $overview[] = [
                'source_module' => $sourceModule,
                'source_label' => self::SOURCE_TYPE_LABELS[$sourceModule] ?? ucfirst($sourceModule),
                'amount_due' => $sourceAmountDue,
                'amount_received' => $sourceAmountReceived,
                'instances' => $instances,
            ];
        }

        return $overview;
    }

    private function instanceLabel(string $sourceModule, int $referenceId): string
    {
        $singular = match ($sourceModule) {
            'news' => 'Formulaire',
            default => ucfirst($sourceModule),
        };

        return $singular . ' #' . $referenceId;
    }
}
