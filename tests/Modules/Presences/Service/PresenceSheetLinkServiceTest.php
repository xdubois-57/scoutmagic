<?php

declare(strict_types=1);

namespace Tests\Modules\Presences\Service;

use Core\Security\EncryptionService;
use Core\Security\Role;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Modules\Presences\Repository\PresenceEventLinkRepository;
use Modules\Presences\Service\PresenceSheetLinkService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Presences\PresencesTestHelper;

/**
 * The link an animateur finds in their own agenda.
 *
 * Two properties carry the whole feature. It is resolved FOR THE READER
 * on every call, so leaving the section removes it at the next refresh
 * with nothing to revoke; and the code is minted once and reused, so a
 * calendar syncing every few hours does not get a new address each time.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PresenceSheetLinkServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private PresenceSheetLinkService $service;
    private int $scoutYearId;
    private int $sectionA;
    private int $sectionB;
    private int $eventA;
    private int $eventB;
    private int $eventStaff;
    private int $animateurYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        PresencesTestHelper::createTables($this->pdo);
        $this->encryption = PresencesTestHelper::encryption();

        [$label, $start, $end] = DatabaseTestHelper::scoutYear();
        $this->scoutYearId = PresencesTestHelper::createScoutYear($this->pdo, $label, $start, $end);
        $year = substr($start, 0, 4);

        $branch = PresencesTestHelper::createBranch($this->pdo, 'LOU', 'Louveteaux', 20);
        $this->sectionA = PresencesTestHelper::createSection($this->pdo, $branch, 'LOU1', 'Louveteaux 1');
        $this->sectionB = PresencesTestHelper::createSection($this->pdo, $branch, 'LOU2', 'Louveteaux 2');

        $this->eventA = PresencesTestHelper::createEvent(
            $this->pdo,
            PresencesTestHelper::createSectionCalendar($this->pdo, $this->sectionA),
            'Réunion',
            $year . '-09-13'
        );
        $this->eventB = PresencesTestHelper::createEvent(
            $this->pdo,
            PresencesTestHelper::createSectionCalendar($this->pdo, $this->sectionB),
            'Réunion',
            $year . '-09-13'
        );
        $this->eventStaff = PresencesTestHelper::createEvent(
            $this->pdo,
            PresencesTestHelper::createSupplementaryCalendar($this->pdo, 'Animateurs'),
            'Réunion de staff',
            $year . '-09-14'
        );

        $this->animateurYearId = PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId,
            'Akéla', 'Dupont', 'chief', $this->sectionA, 'akela@test.be'
        )['memberYearId'];

        $this->service = $this->buildService('https://sv025.be');
    }

    public function testAnAnimateurGetsAShortLinkToTheirOwnSectionsSheet(): void
    {
        $link = $this->service->findSheetLink($this->eventA, Role::CHIEF, 'akela@test.be', $this->scoutYearId);

        $this->assertNotNull($link);
        $this->assertMatchesRegularExpression(
            '#^https://sv025\.be/s/[A-Za-z0-9]{6}$#',
            $link->url
        );
    }

    /**
     * The short link abbreviates, it defends nothing: what it points at
     * is the ordinary sheet URL, and the page behind it is what refuses
     * somebody who does not staff the section.
     */
    public function testTheCodeResolvesToTheSheetItself(): void
    {
        $link = $this->service->findSheetLink($this->eventA, Role::CHIEF, 'akela@test.be', $this->scoutYearId);
        $this->assertNotNull($link);

        $code = substr($link->url, -6);
        $target = (new ShortUrlService(new ShortUrlRepository($this->pdo, $this->encryption)))->resolve($code);

        $this->assertSame('/chefs/presences/feuille/' . $this->eventA, $target);
    }

    public function testTheCodeIsMintedOnceAndReusedForEver(): void
    {
        $first = $this->service->findSheetLink($this->eventA, Role::CHIEF, 'akela@test.be', $this->scoutYearId);
        $second = $this->service->findSheetLink($this->eventA, Role::CHIEF, 'akela@test.be', $this->scoutYearId);

        $this->assertSame($first?->url, $second?->url);
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM presences_event_links')->fetchColumn()
        );
    }

    public function testTwoEveningsGetTwoDifferentLinks(): void
    {
        $a = $this->service->findSheetLink($this->eventA, Role::CHIEF, 'akela@test.be', $this->scoutYearId);
        $b = $this->service->findSheetLink($this->eventB, Role::ADMIN, 'cu@test.be', $this->scoutYearId);

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertNotSame($a->url, $b->url);
    }

    public function testAnotherSectionsEveningCarriesNoLink(): void
    {
        $this->assertNull(
            $this->service->findSheetLink($this->eventB, Role::CHIEF, 'akela@test.be', $this->scoutYearId)
        );
    }

    public function testAnEveningThatOpensNoSheetCarriesNoLink(): void
    {
        $this->assertNull(
            $this->service->findSheetLink($this->eventStaff, Role::ADMIN, 'cu@test.be', $this->scoutYearId)
        );
    }

    /**
     * An anonymous feed has no reader to qualify — the unit feed and a
     * calendar's own feed therefore never carry the link.
     */
    public function testAFeedWithNoIdentifiedReaderCarriesNoLink(): void
    {
        $this->assertNull(
            $this->service->findSheetLink($this->eventA, Role::CHIEF, null, $this->scoutYearId)
        );
        $this->assertNull(
            $this->service->findSheetLink($this->eventA, Role::CHIEF, 'akela@test.be', null)
        );
    }

    /**
     * The rights are asked again on every read. That is the whole point:
     * an animateur who leaves the section stops seeing the link at their
     * next agenda refresh, and there is nothing to revoke.
     */
    public function testAnAnimateurWhoLeavesTheSectionStopsSeeingTheLink(): void
    {
        $this->assertNotNull(
            $this->service->findSheetLink($this->eventA, Role::CHIEF, 'akela@test.be', $this->scoutYearId)
        );

        PresencesTestHelper::removeFromSection($this->pdo, $this->animateurYearId, $this->sectionA);

        $this->assertNull(
            $this->service->findSheetLink($this->eventA, Role::CHIEF, 'akela@test.be', $this->scoutYearId)
        );
    }

    /**
     * ShortUrlService hands back a CODE and has « aucune notion de schéma
     * ni d'hôte ». With no base_url configured there is no address to
     * compose, and a relative URL in an ICS description resolves against
     * nothing — so the link is not offered at all.
     */
    public function testWithNoBaseUrlConfiguredNoLinkIsOffered(): void
    {
        $this->assertNull(
            $this->buildService('')->findSheetLink($this->eventA, Role::CHIEF, 'akela@test.be', $this->scoutYearId)
        );
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM presences_event_links')->fetchColumn()
        );
    }

    public function testATrailingSlashOnTheBaseUrlDoesNotDoubleUp(): void
    {
        $link = $this->buildService('https://sv025.be/')
            ->findSheetLink($this->eventA, Role::CHIEF, 'akela@test.be', $this->scoutYearId);

        $this->assertNotNull($link);
        $this->assertStringNotContainsString('//s/', $link->url);
    }

    private function buildService(string $baseUrl): PresenceSheetLinkService
    {
        return new PresenceSheetLinkService(
            PresencesTestHelper::sheetService(
                $this->pdo,
                $this->encryption,
                PresencesTestHelper::calendarService($this->pdo, $this->encryption)
            ),
            new PresenceEventLinkRepository($this->pdo),
            new ShortUrlService(new ShortUrlRepository($this->pdo, $this->encryption)),
            $baseUrl
        );
    }
}
