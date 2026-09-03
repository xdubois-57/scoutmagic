<?php

declare(strict_types=1);

namespace Tests\Modules\UsageStats\Service;

use Core\Security\Role;
use Modules\UsageStats\Repository\PageViewRepository;
use Modules\UsageStats\Service\PageViewRecorder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\UsageStats\UsageStatsTestHelper;

class PageViewRecorderTest extends TestCase
{
    private const BROWSER = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/120.0.0.0 Mobile Safari/537.36';

    private \PDO $pdo;
    private PageViewRecorder $recorder;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        UsageStatsTestHelper::createTables($this->pdo);
        $this->recorder = new PageViewRecorder(new PageViewRepository($this->pdo));
    }

    public function testAPageViewIsStoredUnderItsMonthPatternModuleAndAudience(): void
    {
        $this->assertTrue($this->record('/members/{id}', 'core', Role::IDENTIFIED));

        $this->assertSame([[
            'month' => '2026-08',
            'route_pattern' => '/members/{id}',
            'module_id' => 'core',
            'audience' => 'identified',
            'view_count' => 1,
        ]], $this->rows());
    }

    /**
     * The decision this whole feature rests on: the URL never reaches the
     * table, only the pattern the router declared. There is no column an
     * identifier could land in, and the recorder is handed the pattern
     * rather than the path so it could not write one if it tried.
     */
    public function testTheStoredPatternKeepsItsPlaceholderRatherThanAnIdentifier(): void
    {
        $this->record('/members/{id}', 'core', Role::IDENTIFIED);
        $this->record('/members/{id}', 'core', Role::IDENTIFIED);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('/members/{id}', $rows[0]['route_pattern']);
        $this->assertSame(2, $rows[0]['view_count']);
    }

    public function testACoreRouteIsCountedUnderCore(): void
    {
        $this->record('/', null, Role::PUBLIC);

        $this->assertSame('core', $this->rows()[0]['module_id']);
    }

    /**
     * Six roles, three audiences: the counters answer « qui consulte » and
     * never « qui ».
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rolesAndAudiences')]
    public function testEveryRoleFlattensToOneOfThreeAudiences(Role $role, string $expected): void
    {
        $this->record('/calendar', 'calendar', $role);

        $this->assertSame($expected, $this->rows()[0]['audience']);
    }

    /** @return array<string, array{0: Role, 1: string}> */
    public static function rolesAndAudiences(): array
    {
        return [
            'public' => [Role::PUBLIC, 'anonymous'],
            'identified' => [Role::IDENTIFIED, 'identified'],
            'intendant' => [Role::INTENDANT, 'staff'],
            'chief' => [Role::CHIEF, 'staff'],
            'admin' => [Role::ADMIN, 'staff'],
            'superadmin' => [Role::SUPERADMIN, 'staff'],
        ];
    }

    public function testARequestThePolicyRefusesWritesNothingAtAll(): void
    {
        $this->assertFalse($this->recorder->record(
            'POST',
            '/calendar',
            'calendar',
            200,
            null,
            false,
            self::BROWSER,
            Role::IDENTIFIED,
            new \DateTimeImmutable('2026-08-14 10:00:00')
        ));

        $this->assertSame([], $this->rows());
    }

    /**
     * The response is already on the wire when this runs, so there is
     * nothing a failure could usefully do — the table being mid-migration
     * must not turn into a fatal on a page the visitor has already read.
     */
    public function testATableThatDoesNotExistCostsTheCounterAndNothingElse(): void
    {
        $this->pdo->exec('DROP TABLE usage_page_views');

        $this->assertFalse($this->record('/calendar', 'calendar', Role::IDENTIFIED));
    }

    private function record(string $pattern, ?string $moduleId, Role $role): bool
    {
        return $this->recorder->record(
            'GET',
            $pattern,
            $moduleId,
            200,
            null,
            false,
            self::BROWSER,
            $role,
            new \DateTimeImmutable('2026-08-14 10:00:00')
        );
    }

    /** @return list<array{month: string, route_pattern: string, module_id: string, audience: string, view_count: int}> */
    private function rows(): array
    {
        $stmt = $this->pdo->query(
            'SELECT month, route_pattern, module_id, audience, view_count FROM usage_page_views ORDER BY id'
        );
        $this->assertNotFalse($stmt);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'month' => (string) $row['month'],
                'route_pattern' => (string) $row['route_pattern'],
                'module_id' => (string) $row['module_id'],
                'audience' => (string) $row['audience'],
                'view_count' => (int) $row['view_count'],
            ];
        }

        return $rows;
    }
}
