<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Badge\BadgeHolderService;
use Core\Badge\BadgeRepository;
use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\Http\Controller\BadgeHoldersController;
use Core\Http\Request;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\TestTwig;

/**
 * GET /admin/badges through the real controller (issue #621): the year it
 * reads is the year in effect, and what it renders is that year's
 * holders. The role floor is Tests\Core\Http\BadgesRbacTest's; the
 * grouping and ordering are Tests\Core\Badge\BadgeHolderServiceTest's.
 */
#[Group('database')]
final class BadgeHoldersControllerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private BadgeHoldersController $controller;
    private int $currentYearId;
    private int $otherYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 0)");
        $this->otherYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2026-2027', '2026-09-01', '2027-08-31', 1)");
        $this->currentYearId = (int) $this->pdo->lastInsertId();

        $twig = TestTwig::create([], ['param' => fn(string $k) => 'Test']);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'admin@test.com');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/admin/badges');

        $connection = Connection::withPdo($this->pdo);
        $memberBadges = new MemberBadgeRepository($this->pdo);
        $sections = new SectionService(
            new SectionRepository($connection),
            new MemberProfileRepository($connection, $this->encryption, $memberBadges)
        );

        $resolver = $this->createStub(ScoutYearResolver::class);
        $resolver->method('getEffectiveYear')->willReturn(new EffectiveScoutYear($this->currentYearId, '2026-2027', null));

        $this->controller = new BadgeHoldersController(
            $twig,
            new BadgeHolderService(new BadgeRepository($this->pdo), $memberBadges, $sections),
            $resolver,
            new ScoutYearService($this->pdo)
        );
    }

    public function testItRendersTheHoldersOfTheYearInEffect(): void
    {
        $nurse = (new BadgeRepository($this->pdo))->create('Infirmier', true);
        $now = $this->memberYear('Claire', 'Renard', $this->currentYearId);
        $past = $this->memberYear('Paul', 'Ancien', $this->otherYearId);
        $badges = new MemberBadgeRepository($this->pdo);
        $badges->assign($now, $nurse, null);
        $badges->assign($past, $nurse, null);

        $response = $this->controller->current(new Request('GET', '/admin/badges', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('Badges — 2026-2027', $body);
        $this->assertMatchesRegularExpression('~<a href="/admin/members/' . $now . '"[^>]*>.*?Claire Renard~s', $body);
        $this->assertStringContainsString('1 porteur<', $body);
        $this->assertStringNotContainsString('Paul Ancien', $body, 'A holder of another year leaked in.');
        // The rail's first tab is named after that same year.
        $this->assertMatchesRegularExpression('~<a href="/admin/badges"[^>]*aria-current="page"[^>]*>\s*(?:<[^>]+>\s*)*2026-2027~', $body);
    }

    public function testAYearNobodyWearsABadgeInSaysSo(): void
    {
        $response = $this->controller->current(new Request('GET', '/admin/badges', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Aucun badge n&#039;est attribué pour 2026-2027.', $response->getBody());
    }

    /**
     * GET /admin/badges/annee-precedente: the year before the one in
     * effect, as that year knew its holders — and no action at all.
     */
    public function testThePreviousYearShowsItsOwnHoldersAndNothingToDo(): void
    {
        $nurse = (new BadgeRepository($this->pdo))->create('Infirmier', true);
        $badges = new MemberBadgeRepository($this->pdo);
        $badges->assign($this->memberYear('Paul', 'Ancien', $this->otherYearId), $nurse, null);
        $badges->assign($this->memberYear('Claire', 'Renard', $this->currentYearId), $nurse, null);

        $response = $this->controller->previous(new Request('GET', '/admin/badges/annee-precedente', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('Badges — 2025-2026', $body);
        $this->assertStringContainsString('Paul Ancien', $body);
        $this->assertStringNotContainsString('Claire Renard', $body, 'A holder of the year in effect leaked in.');
        $this->assertStringNotContainsString('/chefs/staffs', $body);
    }

    /**
     * A unit in its first year has no year before on record: the tab is
     * still there, and the page says there is nothing — never fabricating
     * the missing year.
     */
    public function testAPreviousYearNeverImportedSaysSoAndIsNotCreated(): void
    {
        $this->pdo->exec("DELETE FROM scout_years WHERE label = '2025-2026'");

        $response = $this->controller->previous(new Request('GET', '/admin/badges/annee-precedente', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Aucun badge n&#039;était attribué en 2025-2026.', $response->getBody());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM scout_years WHERE label = '2025-2026'")->fetchColumn());
    }

    private function memberYear(string $firstName, string $lastName, int $yearId): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute(['D-' . bin2hex(random_bytes(4))]);
        $memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        )->execute([
            $memberId,
            $yearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt($lastName, 'member_years.last_name'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
