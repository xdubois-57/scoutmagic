<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\Role;
use Modules\Finance\Api\ReceivableViewer;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Campaign;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRow;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Service\CampaignReceivableDescriber;
use Modules\Finance\Service\CampaignService;
use Modules\Finance\Service\TreasurerScope;
use PHPUnit\Framework\TestCase;

/**
 * Campaign receivables used to be grouped under « Finance », the module's
 * own id with a capital letter (issue #836).
 */
class CampaignReceivableDescriberTest extends TestCase
{
    private function describer(?Campaign $campaign, string $accountRoleMinView = 'intendant'): CampaignReceivableDescriber
    {
        $rows = $this->createStub(CampaignRowRepository::class);
        $rows->method('findById')->willReturnCallback(
            static fn(int $id): ?CampaignRow => $id === 11 && $campaign !== null
                ? new CampaignRow(11, $campaign->id, 5, 6500, 2, [], null, null, null, '2026-09-01')
                : null
        );
        $campaigns = $this->createStub(CampaignRepository::class);
        $campaigns->method('findById')->willReturn($campaign);
        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findById')->willReturn(
            new Account(4, 'Compte unité', 'bank', null, null, null, $accountRoleMinView, 'active')
        );

        return new CampaignReceivableDescriber($rows, $campaigns, $accounts, new AccountVisibility(TreasurerScope::systemCaller()));
    }

    private function campaign(): Campaign
    {
        return new Campaign(3, 'Cotisations 2026-2027', 1, 4, 'open', null, 'cotisations.xlsx', [], null, null, null, null, '2026-09-01');
    }

    public function testItSpeaksForTheModuleIdCampaignsFileTheirReceivablesUnder(): void
    {
        $this->assertSame(CampaignService::SOURCE_MODULE, $this->describer($this->campaign())->sourceModule());
        $this->assertSame('Campagnes', $this->describer($this->campaign())->sourceLabel());
    }

    public function testARowIsNamedAfterItsCampaign(): void
    {
        $this->assertSame('Cotisations 2026-2027', $this->describer($this->campaign())->describeInstance(11));
    }

    public function testItLeadsToTheCampaignTheRowBelongsTo(): void
    {
        $destination = $this->describer($this->campaign())
            ->destinationFor(11, new ReceivableViewer('t@example.org', Role::INTENDANT));

        $this->assertNotNull($destination);
        $this->assertSame('/finance/campaigns/3', $destination->url);
        $this->assertSame('Ouvrir la campagne', $destination->label);
    }

    public function testNoLinkToACampaignTheViewersRoleCannotOpen(): void
    {
        $destination = $this->describer($this->campaign(), 'chief')
            ->destinationFor(11, new ReceivableViewer('t@example.org', Role::INTENDANT));

        $this->assertNull($destination);
    }

    public function testARowThatNoLongerExistsHasNeitherNameNorLink(): void
    {
        $describer = $this->describer(null);

        $this->assertNull($describer->describeInstance(11));
        $this->assertNull($describer->destinationFor(11, new ReceivableViewer('t@example.org', Role::ADMIN)));
    }
}
