<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\Member\PrivateDocumentYears;
use Core\ScoutYear\ScoutYearResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The seasons whose private documents a member sees on their own page
 * (issue #1094): the site's year and the two before it, newest first —
 * moving with the site's year, and never filled in with an older season
 * when one is missing.
 */
#[Group('database')]
class PrivateDocumentYearsTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private PrivateDocumentYears $window;

    /** @var array<string, int> label => id */
    private array $years = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        foreach (['2023-2024', '2024-2025', '2025-2026', '2026-2027'] as $label) {
            [$start] = explode('-', $label);
            $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date) VALUES (?, ?, ?)')
                ->execute([$label, "{$start}-09-01", ((int) $start + 1) . '-08-31']);
            $this->years[$label] = (int) $this->pdo->lastInsertId();
        }

        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register(
            ScoutYearResolver::SETTING_PUBLIC_YEAR,
            '0',
            'number',
            'Public',
            'desc',
            null,
            '^[0-9]+$',
            null,
            false
        );
        $scoutYears = new ScoutYearService($this->pdo);
        $this->window = new PrivateDocumentYears(
            new ScoutYearResolver($scoutYears, $this->settings, new MemberYearRepository($this->pdo)),
            $scoutYears
        );
    }

    public function testTheWindowIsTheSitesYearAndTheTwoBeforeItNewestFirst(): void
    {
        $this->settings->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->years['2026-2027']);

        $this->assertSame(['2026-2027', '2025-2026', '2024-2025'], array_column($this->window->window(), 'label'));
        $this->assertSame(
            [$this->years['2026-2027'], $this->years['2025-2026'], $this->years['2024-2025']],
            array_column($this->window->window(), 'id')
        );
    }

    public function testItMovesWithTheSitesYear(): void
    {
        $this->settings->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->years['2025-2026']);

        $this->assertSame(['2025-2026', '2024-2025', '2023-2024'], array_column($this->window->window(), 'label'));
    }

    /**
     * A season the site never held cannot hold a document either: it is
     * left out, and the window is not stretched to an older one.
     */
    public function testAMissingSeasonIsLeftOutRatherThanReplaced(): void
    {
        $this->pdo->prepare('DELETE FROM scout_years WHERE id = ?')->execute([$this->years['2025-2026']]);
        $this->settings->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->years['2026-2027']);

        $this->assertSame(['2026-2027', '2024-2025'], array_column($this->window->window(), 'label'));
    }
}
