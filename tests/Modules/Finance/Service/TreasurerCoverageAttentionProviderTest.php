<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Database\Connection;
use Core\Security\EncryptionService;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Service\TreasurerCoverageAttentionProvider;
use Modules\Finance\Service\TreasurerScopeService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * Issue #1107: without a treasurer a section account is the chefs d'unité's
 * alone, so the attention page must say which ones are uncovered — above
 * all in September, the badges being per scout year.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class TreasurerCoverageAttentionProviderTest extends TestCase
{
    private \PDO $pdo;
    private TreasurerCoverageAttentionProvider $provider;
    private int $badgeId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->provider = new TreasurerCoverageAttentionProvider(
            new AccountRepository($this->pdo, $encryption),
            new TreasurerScopeService(Connection::withPdo($this->pdo), new BadgeRepository($this->pdo))
        );

        $this->pdo->exec(
            "INSERT INTO scout_years (id, label, start_date, end_date, is_current)
             VALUES (1, '2025-2026', '2025-09-01', '2026-08-31', 1)"
        );
        $this->pdo->exec(
            "INSERT INTO age_branches (id, desk_code, label, sort_order) VALUES (1, 'LOU', 'Louveteaux', 20)"
        );
        $this->pdo->exec(
            "INSERT INTO sections (id, age_branch_id, desk_code, name)
             VALUES (1, 1, 'LOU01', 'Louveteaux'), (2, 1, 'LOU02', 'Autre meute')"
        );
        $this->pdo->exec("INSERT INTO functions (id, desk_code, label, role) VALUES (1, 'ANIM', 'Animateur', 'chief')");
        $this->pdo->prepare('INSERT INTO badges (name, is_default, is_active) VALUES (?, 1, 1)')
            ->execute([BadgeService::BADGE_TREASURER]);
        $this->badgeId = (int) $this->pdo->lastInsertId();
    }

    public function testNoSectionAccountIsNoPoint(): void
    {
        $this->account('Caisse unité', null);

        $this->assertSame([], $this->provider->collect(1));
    }

    public function testEverySectionAccountCoveredIsNoPoint(): void
    {
        $this->account('Compte Louveteaux', 1);
        $this->treasurerOf(1);

        $this->assertSame([], $this->provider->collect(1));
    }

    public function testUncoveredSectionAccountsAreNamedInOnePoint(): void
    {
        $this->account('Compte Louveteaux', 1);
        $this->account('Compte Autre meute', 2);
        $this->account('Caisse unité', null);
        $this->account('Ancien compte', 2, 'archived');
        $this->treasurerOf(1);

        $points = $this->provider->collect(1);

        $this->assertCount(1, $points);
        $this->assertSame('1 compte de section n\'a pas de trésorier cette année', $points[0]->title);
        $this->assertStringContainsString('Compte Autre meute', $points[0]->why);
        $this->assertStringNotContainsString('Compte Louveteaux', $points[0]->why);
        $this->assertStringNotContainsString('Caisse unité', $points[0]->why);
        $this->assertStringNotContainsString('Ancien compte', $points[0]->why, 'an archived account is not chased');
        $this->assertSame('/chefs/staffs', $points[0]->actionUrl);
    }

    public function testANewScoutYearWithoutBadgesUncoversEverySectionAccount(): void
    {
        $this->account('Compte Louveteaux', 1);
        $this->account('Compte Autre meute', 2);
        $this->treasurerOf(1);
        $this->pdo->exec(
            "INSERT INTO scout_years (id, label, start_date, end_date, is_current)
             VALUES (2, '2026-2027', '2026-09-01', '2027-08-31', 0)"
        );

        $points = $this->provider->collect(2);

        $this->assertCount(1, $points);
        $this->assertSame('2 comptes de section n\'ont pas de trésorier cette année', $points[0]->title);
    }

    private function account(string $name, ?int $sectionId, string $status = 'active'): void
    {
        $this->pdo->prepare(
            "INSERT INTO finance_accounts (name, account_type, section_id, role_min_view, status)
             VALUES (?, 'bank', ?, 'intendant', ?)"
        )->execute([$name, $sectionId, $status]);
    }

    private function treasurerOf(int $sectionId): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('desk-" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, 1, 'x', 'y', 1)"
        )->execute([$memberId]);
        $memberYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, 1, ?)')
            ->execute([$memberYearId, $sectionId]);
        $this->pdo->prepare('INSERT INTO member_badges (member_year_id, badge_id) VALUES (?, ?)')
            ->execute([$memberYearId, $this->badgeId]);
    }
}
