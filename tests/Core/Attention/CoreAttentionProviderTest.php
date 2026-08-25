<?php

declare(strict_types=1);

namespace Tests\Core\Attention;

use Core\Attention\AttentionPoint;
use Core\Attention\CoreAttentionProvider;
use Core\Attention\CoreAttentionRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The core's own attention points — and the property that defines them:
 * each one stops being reported the moment it stops being true, with
 * nobody acknowledging anything.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CoreAttentionProviderTest extends TestCase
{
    private \PDO $pdo;
    private CoreAttentionProvider $provider;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->provider = new CoreAttentionProvider(new CoreAttentionRepository($this->pdo));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    public function testAHealthyUnitProducesNoPoint(): void
    {
        $memberYearId = $this->addMember('T001');
        $this->addFunction($memberYearId, $this->createFunction('ANIM', true));
        $this->assignBadge($memberYearId, $this->createBadge('Trésorier'));

        $this->assertSame([], $this->provider->collect($this->scoutYearId));
    }

    public function testABadgeNobodyHoldsIsReported(): void
    {
        $memberYearId = $this->addMember('T001');
        $this->addFunction($memberYearId, $this->createFunction('ANIM', true));
        $this->createBadge('Trésorier');

        $points = $this->provider->collect($this->scoutYearId);

        $this->assertCount(1, $points);
        $this->assertStringContainsString('Trésorier', $points[0]->title);
        $this->assertStringContainsString("n'est porté par personne", $points[0]->title);
    }

    public function testTheBadgePointDisappearsWhenSomebodyTakesTheBadge(): void
    {
        // The whole mechanism in one test: nothing is acknowledged, the
        // point simply stops being true.
        $memberYearId = $this->addMember('T001');
        $this->addFunction($memberYearId, $this->createFunction('ANIM', true));
        $badgeId = $this->createBadge('Trésorier');

        $this->assertCount(1, $this->provider->collect($this->scoutYearId));

        $this->assignBadge($memberYearId, $badgeId);

        $this->assertSame([], $this->provider->collect($this->scoutYearId));
    }

    public function testADeactivatedBadgeIsNotAVacancy(): void
    {
        $memberYearId = $this->addMember('T001');
        $this->addFunction($memberYearId, $this->createFunction('ANIM', true));
        $badgeId = $this->createBadge('Trésorier');
        $stmt = $this->pdo->prepare('UPDATE badges SET is_active = 0 WHERE id = ?');
        $stmt->execute([$badgeId]);

        $this->assertSame([], $this->provider->collect($this->scoutYearId));
    }

    public function testABadgeHeldOnlyByAnInactiveMemberIsAVacancy(): void
    {
        // Precisely the case this exists for: the holder left with the
        // last import, and nothing else on the site says so.
        $gone = $this->addMember('T001', active: false);
        $present = $this->addMember('T002');
        $this->addFunction($present, $this->createFunction('ANIM', true));
        $this->assignBadge($gone, $this->createBadge('Trésorier'));

        $points = $this->provider->collect($this->scoutYearId);

        $this->assertCount(1, $points);
        $this->assertStringContainsString('Trésorier', $points[0]->title);
    }

    public function testAnUnconfirmedFunctionIsReportedAsUrgent(): void
    {
        $memberYearId = $this->addMember('T001');
        $this->addFunction($memberYearId, $this->createFunction("Équipier d'unité adjoint", false));

        $points = $this->provider->collect($this->scoutYearId);

        $this->assertCount(1, $points);
        $this->assertStringContainsString("attend d'être qualifiée", $points[0]->title);
        $this->assertStringContainsString("Équipier d'unité adjoint", $points[0]->why);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
        $this->assertSame('/config/functions', $points[0]->actionUrl);
    }

    public function testStaleLeavingFlagsAreReported(): void
    {
        $functionId = $this->createFunction('ANIM', true);
        foreach (['T001', 'T002'] as $deskId) {
            $memberYearId = $this->addMember($deskId, leaving: true);
            $this->addFunction($memberYearId, $functionId);
        }

        $points = $this->provider->collect($this->scoutYearId);

        $this->assertCount(1, $points);
        $this->assertStringContainsString('2 membres annoncés partants', $points[0]->title);
        $this->assertStringContainsString('facturés', $points[0]->why);
    }

    public function testAMemberWithNoFunctionAtAllIsReported(): void
    {
        $this->addMember('T001');

        $points = $this->provider->collect($this->scoutYearId);

        $this->assertCount(1, $points);
        $this->assertStringContainsString('ni fonction ni section', $points[0]->title);
    }

    public function testOnlyTheCurrentYearIsLookedAt(): void
    {
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2024-2025', '2024-09-01', '2025-08-31', 0)");
        $previousYearId = (int) $this->pdo->lastInsertId();

        $functionId = $this->createFunction('ANIM', true);
        $current = $this->addMember('T001');
        $this->addFunction($current, $functionId);
        $badgeId = $this->createBadge('Trésorier');
        $this->assignBadge($current, $badgeId);

        // Last year's leaving flag says nothing about this year.
        $old = $this->addMember('T002', scoutYearId: $previousYearId, leaving: true);
        $this->addFunction($old, $functionId);

        $this->assertSame([], $this->provider->collect($this->scoutYearId));
    }

    public function testEveryPointCarriesWhatToDo(): void
    {
        $this->addMember('T001', leaving: true);
        $this->createBadge('Infirmier');
        $memberYearId = $this->addMember('T002');
        $this->addFunction($memberYearId, $this->createFunction('NOUVELLE', false));

        $points = $this->provider->collect($this->scoutYearId);

        $this->assertNotSame([], $points);
        foreach ($points as $point) {
            $this->assertNotSame('', $point->title);
            $this->assertNotSame('', $point->why);
            $this->assertNotNull($point->actionLabel, "« {$point->title} » must say what to do");
            $this->assertNotNull($point->actionUrl);
        }
    }

    /* ------------------------------------------------------------------ */

    private function addMember(string $deskId, ?int $scoutYearId = null, bool $active = true, bool $leaving = false): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$deskId]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active, leaving)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $scoutYearId ?? $this->scoutYearId, 'x', 'y', $active ? 1 : 0, $leaving ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createFunction(string $label, bool $confirmed): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, ?)');
        $stmt->execute([$label, $label, 'identified', $confirmed ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    private function addFunction(int $memberYearId, int $functionId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id) VALUES (?, ?)');
        $stmt->execute([$memberYearId, $functionId]);
    }

    private function createBadge(string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO badges (name, is_default, is_active) VALUES (?, 1, 1)');
        $stmt->execute([$name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function assignBadge(int $memberYearId, int $badgeId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO member_badges (member_year_id, badge_id) VALUES (?, ?)');
        $stmt->execute([$memberYearId, $badgeId]);
    }
}
