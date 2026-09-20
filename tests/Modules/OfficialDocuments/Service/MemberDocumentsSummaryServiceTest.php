<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Service;

use Modules\OfficialDocuments\Api\MemberOfficialDocumentsProvider;
use Modules\OfficialDocuments\Service\MemberDocumentsSummaryService;
use PHPUnit\Framework\TestCase;

/**
 * The block on a member's own page, as the composition root actually builds
 * it.
 *
 * `MemberPageServiceTest` uses a hand-written double, because what it tests
 * is who gets the block; this is the real implementation, and the two things
 * worth pinning here are the ones a double would happily get wrong: the URL
 * is the route the module declares, and the warning is on it at all.
 */
final class MemberDocumentsSummaryServiceTest extends TestCase
{
    public function testItIsTheContractTheMemberPageAsksFor(): void
    {
        $this->assertInstanceOf(
            MemberOfficialDocumentsProvider::class,
            new MemberDocumentsSummaryService()
        );
    }

    /**
     * The link has to be the route `module.json` declares, keyed on the
     * member-year — a block whose link 404s is worse than no block.
     */
    public function testTheLinkIsThisMembersOwnParentalAuthorizationRoute(): void
    {
        $summary = (new MemberDocumentsSummaryService())->summaryFor(7, 42);

        $this->assertCount(1, $summary->links);
        $this->assertSame('/members/7/autorisation-parentale', $summary->links[0]->url);
        $this->assertSame('Autorisation parentale', $summary->links[0]->label);
        $this->assertFalse($summary->isEmpty());
    }

    /**
     * §44.1: the sentence the whole module exists around. Its place is the
     * web page, and a block that lost it would be a block inviting somebody
     * to hand in an unsigned document.
     */
    public function testTheBlockCarriesTheWarningThatOnlyASignedPaperCounts(): void
    {
        $summary = (new MemberDocumentsSummaryService())->summaryFor(7, 42);

        $this->assertSame(MemberDocumentsSummaryService::WARNING, $summary->warning);
        $this->assertStringContainsString('signé', $summary->warning);
    }

    /**
     * Keyed on the member-year the page is showing, so two children of the
     * same household never get each other's link.
     */
    public function testTwoMembersGetTheirOwnLinks(): void
    {
        $service = new MemberDocumentsSummaryService();

        $this->assertSame('/members/7/autorisation-parentale', $service->summaryFor(7, 42)->links[0]->url);
        $this->assertSame('/members/9/autorisation-parentale', $service->summaryFor(9, 43)->links[0]->url);
    }
}
