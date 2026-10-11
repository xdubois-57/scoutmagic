<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Api\ReceivableDestination;
use Modules\Finance\Api\ReceivableSourceDescriberInterface;
use Modules\Finance\Api\ReceivableSourceDestinationInterface;
use Modules\Finance\Api\ReceivableViewer;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Campaign;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRowRepository;

/**
 * What « Contrôle des créances » calls the receivables a campaign raised —
 * « Campagnes », not « Finance » (issue #836).
 *
 * A campaign files its receivables under this module's own id, and with no
 * describer the page fell back to that id with a capital letter. Finance
 * describes itself here exactly as any other source does, through the same
 * contract, so the page has no special case for its own campaigns.
 *
 * A campaign's receivable points at one row of it — one member, one
 * amount — so the reference handed in is a row id, and the campaign is
 * found through it. A page of a few hundred rows asks about a handful of
 * campaigns: they are kept once read.
 */
class CampaignReceivableDescriber implements ReceivableSourceDescriberInterface, ReceivableSourceDestinationInterface
{
    /** @var array<int, ?Campaign> */
    private array $campaignsById = [];

    public function __construct(
        private CampaignRowRepository $rows,
        private CampaignRepository $campaigns,
        private AccountRepository $accounts,
        private AccountVisibility $accountVisibility
    ) {
    }

    public function sourceModule(): string
    {
        return CampaignService::SOURCE_MODULE;
    }

    public function sourceLabel(): string
    {
        return 'Campagnes';
    }

    public function describeInstance(int $sourceReferenceId): ?string
    {
        return $this->campaignOfRow($sourceReferenceId)?->label;
    }

    public function destinationFor(int $sourceReferenceId, ReceivableViewer $viewer): ?ReceivableDestination
    {
        $campaign = $this->campaignOfRow($sourceReferenceId);
        if ($campaign === null) {
            return null;
        }

        // The campaign page's own test (CampaignService::requireCampaign()),
        // so the link is offered exactly when the page would open.
        if (!$this->accountVisibility->isVisibleTo($this->accounts->findById($campaign->accountId), $viewer->role)) {
            return null;
        }

        return new ReceivableDestination('Ouvrir la campagne', '/finance/campaigns/' . $campaign->id);
    }

    private function campaignOfRow(int $rowId): ?Campaign
    {
        $row = $this->rows->findById($rowId);
        if ($row === null) {
            return null;
        }

        if (!array_key_exists($row->campaignId, $this->campaignsById)) {
            $this->campaignsById[$row->campaignId] = $this->campaigns->findById($row->campaignId);
        }

        return $this->campaignsById[$row->campaignId];
    }
}
