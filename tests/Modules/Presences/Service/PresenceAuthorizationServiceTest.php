<?php

declare(strict_types=1);

namespace Tests\Modules\Presences\Service;

use Core\Security\EncryptionService;
use Modules\Presences\Service\PresenceAuthorizationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Presences\PresencesTestHelper;

/**
 * IT-01's whole point: the section boundary, written before anything
 * else, because every other screen of this module leans on it.
 *
 * A misplaced boundary here does not produce a visible bug — it produces
 * an animateur of the Louveteaux quietly reading the Éclaireurs' animés
 * and the comments written about them. So these five cases are asserted
 * against the real `Core\Member\SectionStaffAuthorizationService` on a
 * real roster, never a mock.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PresenceAuthorizationServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private PresenceAuthorizationService $authorization;
    private int $scoutYearId;
    private int $louveteaux1;
    private int $louveteaux2;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        PresencesTestHelper::createTables($this->pdo);
        $this->encryption = PresencesTestHelper::encryption();
        $this->authorization = PresencesTestHelper::authorization($this->pdo, $this->encryption);

        [$label, $start, $end] = DatabaseTestHelper::scoutYear();
        $this->scoutYearId = PresencesTestHelper::createScoutYear($this->pdo, $label, $start, $end);

        $branch = PresencesTestHelper::createBranch($this->pdo, 'LOU', 'Louveteaux', 20);
        $this->louveteaux1 = PresencesTestHelper::createSection($this->pdo, $branch, 'LOU1', 'Louveteaux 1');
        $this->louveteaux2 = PresencesTestHelper::createSection($this->pdo, $branch, 'LOU2', 'Louveteaux 2');
    }

    public function testAnAnimateurReachesTheirOwnSectionAndNoOther(): void
    {
        PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId,
            'Akéla', 'Dupont', 'chief', $this->louveteaux1, 'akela@test.be'
        );

        $ids = $this->authorization->staffedSectionIds('akela@test.be', 'chief', $this->scoutYearId);

        $this->assertSame([$this->louveteaux1], $ids);
        $this->assertTrue(
            $this->authorization->maySeeSection('akela@test.be', 'chief', $this->scoutYearId, $this->louveteaux1)
        );
        $this->assertFalse(
            $this->authorization->maySeeSection('akela@test.be', 'chief', $this->scoutYearId, $this->louveteaux2)
        );
    }

    public function testAUnitChiefReachesEverySection(): void
    {
        // No function in either section: the rule for admin is the role
        // itself, and a chef d'unité who animates nothing still runs the
        // unit.
        $ids = $this->authorization->staffedSectionIds('cu@test.be', 'admin', $this->scoutYearId);

        sort($ids);
        $this->assertSame([$this->louveteaux1, $this->louveteaux2], $ids);
    }

    public function testASuperadminReachesEverySection(): void
    {
        $ids = $this->authorization->staffedSectionIds('root@test.be', 'superadmin', $this->scoutYearId);

        sort($ids);
        $this->assertSame([$this->louveteaux1, $this->louveteaux2], $ids);
    }

    public function testAnIdentifiedVisitorReachesNothing(): void
    {
        // Even with a member row in the section: a parent is linked to a
        // section through their child and must reach none of this.
        PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId,
            'Basile', 'Hargot', 'animated', $this->louveteaux1, 'parent@test.be'
        );

        $this->assertSame(
            [],
            $this->authorization->staffedSectionIds('parent@test.be', 'identified', $this->scoutYearId)
        );
        $this->assertFalse(
            $this->authorization->maySeeSection('parent@test.be', 'identified', $this->scoutYearId, $this->louveteaux1)
        );
    }

    public function testAnAnimateurWhoLeavesTheSectionLosesAccessImmediately(): void
    {
        $animateur = PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId,
            'Baloo', 'Martin', 'chief', $this->louveteaux1, 'baloo@test.be'
        );

        $this->assertTrue(
            $this->authorization->maySeeSection('baloo@test.be', 'chief', $this->scoutYearId, $this->louveteaux1)
        );

        PresencesTestHelper::removeFromSection($this->pdo, $animateur['memberYearId'], $this->louveteaux1);

        // The SAME service instance, asked again: rights are recomputed
        // on every call and nothing memorises them, so there is nothing
        // to revoke and no cache to invalidate.
        $this->assertFalse(
            $this->authorization->maySeeSection('baloo@test.be', 'chief', $this->scoutYearId, $this->louveteaux1)
        );
        $this->assertSame(
            [],
            $this->authorization->staffedSectionIds('baloo@test.be', 'chief', $this->scoutYearId)
        );
    }

    public function testAnEmptyAddressReachesNothing(): void
    {
        // An unauthenticated session hands the controller '' — refusing
        // is the default, and it must not become "every section whose
        // blind index happens to be that of the empty string".
        $this->assertSame([], $this->authorization->staffedSectionIds('', 'chief', $this->scoutYearId));
    }

    public function testRightsAreScopedToTheScoutYearAsked(): void
    {
        [$label, $start, $end] = DatabaseTestHelper::scoutYear(-1);
        $lastYear = PresencesTestHelper::createScoutYear($this->pdo, $label, $start, $end);

        PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId,
            'Akéla', 'Dupont', 'chief', $this->louveteaux1, 'akela@test.be'
        );

        $this->assertSame(
            [$this->louveteaux1],
            $this->authorization->staffedSectionIds('akela@test.be', 'chief', $this->scoutYearId)
        );
        $this->assertSame(
            [],
            $this->authorization->staffedSectionIds('akela@test.be', 'chief', $lastYear)
        );
    }
}
