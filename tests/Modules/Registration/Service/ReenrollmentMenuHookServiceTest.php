<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\ScoutYear\ScoutYearResolver;
use Core\View\MenuBuilder;
use Modules\Registration\Service\ReenrollmentCampaignService;
use Modules\Registration\Service\ReenrollmentFormService;
use Modules\Registration\Service\ReenrollmentMenuHookService;
use PHPUnit\Framework\TestCase;

/**
 * How the « Réinscription {année} » entry is RENDERED, which is a
 * different question from where it sorts — and the two were conflated.
 *
 * The entry was built with `isDynamic: true`. That flag does not mean
 * "belongs with the member entries"; `sortGroup` says that. It means
 * "draw this the way a member entry is drawn", and
 * partials/nav.html.twig honours it literally: in that branch it never
 * looks at `icon` at all and calls `person_avatar(page.label, …)`. With
 * no member behind the entry, the circle fell back to initials cut out
 * of the label — an administrator on iPhone saw « Ré » in an avatar
 * bubble where every neighbouring entry showed a Bootstrap icon (#332).
 *
 * So `bi-arrow-repeat` was declared and could never reach the screen:
 * an icon plus `isDynamic: true` is a contradiction, not a preference.
 * `Core\View\MenuBuilder::addPage()`'s docblock already prescribes the
 * pairing this restores — SORT_GROUP_DYNAMIC with `isDynamic: false` —
 * and the Espace des animés empty state in public/index.php is the same
 * shape: sorted among the members, drawn as a plain line.
 *
 * The entry is per-family, never per-member, so nothing here should ever
 * turn dynamic again.
 */
class ReenrollmentMenuHookServiceTest extends TestCase
{
    /**
     * The service with its collaborators stubbed to the one situation
     * that produces an entry: a campaign under way, one animé linked to
     * the visitor, their answer still missing.
     */
    private function serviceWithOneUnansweredCard(): ReenrollmentMenuHookService
    {
        $formService = $this->createStub(ReenrollmentFormService::class);
        $formService->method('cardsFor')->willReturn([[
            'member_id' => 7,
            'display_name' => 'Léa Dupont',
            'current_section_label' => 'Lutins',
            'changes_branch' => false,
            'arrival_sections' => [],
            'asks_section' => false,
            'asks_friends' => false,
            'friend_wish_limit' => 0,
            'answer' => null,
        ]]);

        $resolver = $this->createStub(ScoutYearResolver::class);
        $resolver->method('getCurrentPublicYear')->willReturn([
            'id' => 3,
            'label' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-08-31',
        ]);

        $scoutYearService = $this->createStub(ScoutYearService::class);
        $scoutYearService->method('ensureYear')->willReturn(4);

        $campaign = $this->createStub(ReenrollmentCampaignService::class);
        $campaign->method('isOpen')->willReturn(true);
        $campaign->method('currentCampaignKey')->willReturn('2026-2027');

        return new ReenrollmentMenuHookService($formService, $resolver, $scoutYearService, $campaign);
    }

    /**
     * The defect itself. `isDynamic` is the only thing standing between
     * the declared icon and the avatar bubble.
     */
    public function testTheEntryIsNotDrawnAsAMemberAvatar(): void
    {
        $entries = $this->serviceWithOneUnansweredCard()->getMenuEntries('marie@example.com');

        $this->assertCount(1, $entries);
        $this->assertFalse(
            $entries[0]->isDynamic,
            'a dynamic entry is drawn by person_avatar() from its label, which is the initials bubble of #332',
        );
    }

    /**
     * The other half: an icon nobody renders is not a fix. This is what
     * the administrator actually reported missing.
     */
    public function testTheEntryCarriesTheIconTheMenuCanNowShow(): void
    {
        $entries = $this->serviceWithOneUnansweredCard()->getMenuEntries('marie@example.com');

        $this->assertSame('bi-arrow-repeat', $entries[0]->icon);
        $this->assertFalse(
            $entries[0]->isDynamic,
            'partials/nav.html.twig ignores `icon` entirely on a dynamic entry, so the two must never be paired',
        );
    }

    /**
     * The half that must NOT change with it. Rendering moved; placement
     * did not — the entry still belongs in the member slot of « Mes
     * membres », after the per-member entries and the registration
     * requests.
     */
    public function testItKeepsItsPlaceAmongTheMemberEntries(): void
    {
        $entries = $this->serviceWithOneUnansweredCard()->getMenuEntries('marie@example.com');

        $this->assertSame(MenuBuilder::SORT_GROUP_DYNAMIC, $entries[0]->sortGroup);
        $this->assertSame('mes_membres', $entries[0]->menuGroup);
        $this->assertSame(2000, $entries[0]->order);
        $this->assertSame('/reinscription', $entries[0]->url);
        $this->assertSame('Réinscription 2027-2028', $entries[0]->label);
    }
}
