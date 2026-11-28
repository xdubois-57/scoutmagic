<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Repository\Campaign;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Repository\ExpectedReceivable;

/**
 * Which campaign each receivable belongs to, and the one rule the family
 * surfaces draw from it: **a closed campaign stops asking** (issue #1436).
 *
 * A campaign's receivable points at one of its rows
 * (`source_module = 'finance'`, `source_reference_id` = the row id), so the
 * campaign is found through the row — this module's own tables, nothing
 * another module owns. A receivable booked by any other source (a rental,
 * a hand-made one) has no campaign and is never filtered here: closing a
 * campaign says nothing about money somebody else asked for.
 *
 * One class because three surfaces ask the same question — the member
 * page's payment block, the homepage band (both
 * Service\FamilyPaymentService) and the public QR route
 * (Controller\ReceivableQrController) — and answering it three times would
 * eventually answer it three ways: a band that hides a demand whose QR
 * still scans is exactly the kind of disagreement nobody notices.
 *
 * Nothing is written. Reopening the campaign is therefore enough to bring
 * every one of its unpaid receivables back on those surfaces.
 */
class ReceivableCampaigns
{
    public function __construct(
        private CampaignRowRepository $rows,
        private CampaignRepository $campaigns
    ) {
    }

    /**
     * @param ExpectedReceivable[] $receivables
     * @return array<int, Campaign> receivable id => its campaign; a
     *         receivable with none is absent
     */
    public function campaignsOf(array $receivables): array
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
        foreach ($this->rows->findByIds($rowIds) as $row) {
            $campaignIdByRowId[$row->id] = $row->campaignId;
        }

        $campaignById = [];
        foreach (array_unique(array_values($campaignIdByRowId)) as $campaignId) {
            $campaign = $this->campaigns->findById($campaignId);
            if ($campaign !== null) {
                $campaignById[$campaignId] = $campaign;
            }
        }

        $result = [];
        foreach ($receivables as $receivable) {
            if ($receivable->sourceModule !== CampaignService::SOURCE_MODULE) {
                continue;
            }
            $campaignId = $campaignIdByRowId[$receivable->sourceReferenceId] ?? null;
            if ($campaignId !== null && isset($campaignById[$campaignId])) {
                $result[$receivable->id] = $campaignById[$campaignId];
            }
        }

        return $result;
    }

    /**
     * The receivables a family may still be asked to pay: every one except
     * those of a closed campaign. Order is kept.
     *
     * @param ExpectedReceivable[] $receivables
     * @return list<ExpectedReceivable>
     */
    public function withoutClosedCampaigns(array $receivables): array
    {
        $campaigns = $this->campaignsOf($receivables);

        return array_values(array_filter(
            $receivables,
            static fn(ExpectedReceivable $receivable): bool => !isset($campaigns[$receivable->id])
                || $campaigns[$receivable->id]->isOpen()
        ));
    }
}
