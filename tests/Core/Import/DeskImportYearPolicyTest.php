<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\DeskImportYearDates;
use Core\Import\DeskImportYearOption;
use Core\Import\DeskImportYearPolicy;
use Core\Import\DeskImportYearRefusedException;
use Core\Import\DeskImportYearWindow;
use Core\Import\MemberYearRepository;
use Core\ScoutYear\ScoutYearResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Which scout years a Desk import may target, day by day.
 *
 * Every year here is written out (2026-2027 public, 2027-2028 next): the
 * clock is injected, so the calendar the suite runs on decides nothing.
 * Each threshold is pinned on the day before, the day itself and the day
 * after, because an off-by-one there is exactly the bug a reader of the
 * rule cannot see.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeskImportYearPolicyTest extends TestCase
{
    private \PDO $pdo;
    private ScoutYearService $scoutYears;
    private SettingService $settings;
    private int $previousId;
    private int $publicId;
    private int $nextId;
    private bool $configurationMode = false;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->scoutYears = new ScoutYearService($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));

        $this->previousId = $this->scoutYears->ensureYear('2025-2026');
        $this->publicId = $this->scoutYears->ensureYear('2026-2027');
        $this->nextId = $this->scoutYears->ensureYear('2027-2028');

        $this->settings->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'L', 'D', null, null, null, false);
        $this->settings->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->publicId);
        $this->settings->register(DeskImportYearDates::SETTING_NEXT_START, '07-01', 'text', 'L', 'D');
        $this->settings->register(DeskImportYearDates::SETTING_ENCODING, '08-15', 'text', 'L', 'D');
    }

    private function policy(string $today): DeskImportYearPolicy
    {
        return DeskImportYearPolicy::forSite(
            new ScoutYearResolver($this->scoutYears, $this->settings, new MemberYearRepository($this->pdo)),
            $this->scoutYears,
            $this->settings,
            static fn(): \DateTimeImmutable => new \DateTimeImmutable($today),
            fn(): bool => $this->configurationMode
        );
    }

    /**
     * @return list<string>
     */
    private function offeredLabels(DeskImportYearWindow $window): array
    {
        return array_map(static fn(DeskImportYearOption $o): string => $o->label, $window->options);
    }

    private function labelOf(?int $id): ?string
    {
        return $id === null ? null : ($this->scoutYears->findById($id)['label'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string, list<string>, string}>
     */
    public static function defaultThresholds(): iterable
    {
        yield 'opening D-1' => ['2027-06-30', DeskImportYearWindow::PERIOD_BEFORE, ['2026-2027'], '2026-2027'];
        yield 'opening D' => ['2027-07-01', DeskImportYearWindow::PERIOD_BETWEEN, ['2027-2028', '2026-2027'], '2027-2028'];
        yield 'opening D+1' => ['2027-07-02', DeskImportYearWindow::PERIOD_BETWEEN, ['2027-2028', '2026-2027'], '2027-2028'];
        yield 'encoding D-1' => ['2027-08-14', DeskImportYearWindow::PERIOD_BETWEEN, ['2027-2028', '2026-2027'], '2027-2028'];
        yield 'encoding D' => ['2027-08-15', DeskImportYearWindow::PERIOD_AFTER, ['2027-2028'], '2027-2028'];
        yield 'encoding D+1' => ['2027-08-16', DeskImportYearWindow::PERIOD_AFTER, ['2027-2028'], '2027-2028'];
        yield 'start of the public year' => ['2026-09-01', DeskImportYearWindow::PERIOD_BEFORE, ['2026-2027'], '2026-2027'];
    }

    /**
     * @param list<string> $offered
     */
    #[DataProvider('defaultThresholds')]
    public function testTheDefaultThresholds(string $today, string $period, array $offered, string $preselected): void
    {
        $window = $this->policy($today)->window();

        $this->assertSame($period, $window->period);
        $this->assertSame($offered, $this->offeredLabels($window));
        $this->assertSame($preselected, $this->labelOf($window->preselectedId));
    }

    public function testThePublicYearAsksForConfirmationOnlyWhileTheNextOneIsOpen(): void
    {
        $between = $this->policy('2027-07-20')->window();
        $this->assertSame(
            DeskImportYearOption::CONFIRM_PUBLIC_DURING_WINDOW,
            $between->option($this->publicId)?->confirmation
        );
        $this->assertNull($between->option($this->nextId)?->confirmation);
        $this->assertTrue($between->option($this->publicId)?->isPublic);
        $this->assertTrue($between->option($this->nextId)?->isNext);

        $before = $this->policy('2027-05-01')->window();
        $this->assertNull($before->option($this->publicId)?->confirmation);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function customThresholds(): iterable
    {
        yield 'opening D-1' => ['2027-06-14', DeskImportYearWindow::PERIOD_BEFORE];
        yield 'opening D' => ['2027-06-15', DeskImportYearWindow::PERIOD_BETWEEN];
        yield 'opening D+1' => ['2027-06-16', DeskImportYearWindow::PERIOD_BETWEEN];
        yield 'encoding D-1' => ['2027-07-31', DeskImportYearWindow::PERIOD_BETWEEN];
        yield 'encoding D' => ['2027-08-01', DeskImportYearWindow::PERIOD_AFTER];
        yield 'encoding D+1' => ['2027-08-02', DeskImportYearWindow::PERIOD_AFTER];
    }

    #[DataProvider('customThresholds')]
    public function testCustomDatesMoveTheThresholds(string $today, string $period): void
    {
        $this->settings->set(DeskImportYearDates::SETTING_NEXT_START, '06-15');
        $this->settings->set(DeskImportYearDates::SETTING_ENCODING, '08-01');

        $window = $this->policy($today)->window();

        $this->assertSame($period, $window->period);
        $this->assertSame('2027-06-15', $window->nextStartDate);
        $this->assertSame('2027-08-01', $window->encodingDate);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function incoherentPairs(): iterable
    {
        yield 'opening after encoding' => ['08-20', '08-15'];
        yield 'opening equal to encoding' => ['08-15', '08-15'];
        yield 'encoding in September' => ['07-01', '09-15'];
        yield 'opening unreadable' => ['n\'importe quoi', '08-15'];
        yield 'a day that never exists' => ['04-31', '08-15'];
    }

    /**
     * Both fall back, never only the faulty one: keeping the other would
     * be guessing what the person meant.
     */
    #[DataProvider('incoherentPairs')]
    public function testAnIncoherentStoredPairFallsBackToBothDefaults(string $nextStart, string $encoding): void
    {
        // Written behind the settings page's back, which is the only way
        // such a pair can be stored.
        $repository = new SettingRepository($this->pdo);
        $repository->updateValue(null, DeskImportYearDates::SETTING_NEXT_START, $nextStart);
        $repository->updateValue(null, DeskImportYearDates::SETTING_ENCODING, $encoding);
        $this->settings->clearCache();

        $dates = DeskImportYearDates::fromSettings($this->settings);
        $this->assertTrue($dates->fellBack);
        $this->assertSame('07-01', $dates->nextStart);
        $this->assertSame('08-15', $dates->encoding);

        $window = $this->policy('2027-07-01')->window();
        $this->assertSame('2027-07-01', $window->nextStartDate);
        $this->assertSame('2027-08-15', $window->encodingDate);
        $this->assertSame(DeskImportYearWindow::PERIOD_BETWEEN, $window->period);
    }

    /**
     * The chantier's own case: the public year switched to 2027-2028 on
     * 20 August 2027. From then on 2027-2028 alone, until 30 June 2028 —
     * the calendar never opens a second year ahead.
     */
    public function testAnEarlySwitchStartsTheRuleAgainFromTheNewPublicYear(): void
    {
        $this->settings->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->nextId);
        $afterNext = $this->scoutYears->ensureYear('2028-2029');

        foreach (['2027-08-20', '2027-12-01', '2028-06-30'] as $today) {
            $window = $this->policy($today)->window();
            $this->assertSame(['2027-2028'], $this->offeredLabels($window), $today);
            $this->assertSame($this->nextId, $window->preselectedId, $today);
            $this->assertNull($window->option($this->nextId)?->confirmation, $today);
        }

        $opening = $this->policy('2028-07-01')->window();
        $this->assertSame(['2028-2029', '2027-2028'], $this->offeredLabels($opening));
        $this->assertSame($afterNext, $opening->preselectedId);
    }

    /** A unit that never ran its transition stays on the next year, never the one after. */
    public function testAStalePublicYearNeverOpensASecondYearAhead(): void
    {
        $this->scoutYears->ensureYear('2028-2029');

        $window = $this->policy('2028-10-01')->window();

        $this->assertSame(['2027-2028'], $this->offeredLabels($window));
    }

    public function testConfigurationModeOffersEveryExistingYearAndAsksForTheUnusualOnes(): void
    {
        $this->configurationMode = true;

        $before = $this->policy('2027-03-01')->window();
        $this->assertTrue($before->configurationMode);
        $this->assertSame(['2027-2028', '2026-2027', '2025-2026'], $this->offeredLabels($before));
        $this->assertSame($this->publicId, $before->preselectedId);
        $this->assertNull($before->option($this->publicId)?->confirmation);
        $this->assertSame(DeskImportYearOption::CONFIRM_CONFIGURATION_MODE, $before->option($this->nextId)?->confirmation);
        $this->assertSame(DeskImportYearOption::CONFIRM_CONFIGURATION_MODE, $before->option($this->previousId)?->confirmation);

        // Inside the window, the public year keeps the question the usual
        // rule asks, and the next year needs none.
        $between = $this->policy('2027-07-20')->window();
        $this->assertSame(DeskImportYearOption::CONFIRM_PUBLIC_DURING_WINDOW, $between->option($this->publicId)?->confirmation);
        $this->assertNull($between->option($this->nextId)?->confirmation);
        $this->assertSame($this->nextId, $between->preselectedId);
    }

    public function testWithoutConfigurationModeAPastYearIsNeverOffered(): void
    {
        $window = $this->policy('2027-03-01')->window();

        $this->assertFalse($window->configurationMode);
        $this->assertNull($window->option($this->previousId));
    }

    public function testAMissingNextYearIsNeitherOfferedNorCreated(): void
    {
        $this->pdo->prepare('DELETE FROM scout_years WHERE id = ?')->execute([$this->nextId]);
        $count = static fn(\PDO $pdo): int => (int) $pdo->query('SELECT COUNT(*) FROM scout_years')->fetchColumn();
        $before = $count($this->pdo);

        $window = $this->policy('2027-07-20')->window();

        $this->assertSame(['2026-2027'], $this->offeredLabels($window));
        $this->assertNull($window->option($this->publicId)?->confirmation);
        $this->assertSame($before, $count($this->pdo));
    }

    public function testARequestedYearIsSelectedOnlyWhenOffered(): void
    {
        $window = $this->policy('2027-07-20')->window();
        $this->assertSame($this->publicId, $window->selectedId($this->publicId));
        $this->assertSame($this->nextId, $window->selectedId(null));

        $before = $this->policy('2027-03-01')->window();
        $this->assertSame($this->publicId, $before->selectedId($this->nextId));
        $this->assertSame($this->publicId, $before->selectedId(999999));
    }

    public function testAYearNotOfferedIsRefused(): void
    {
        try {
            $this->policy('2027-03-01')->assertImportable($this->nextId, $this->nextId);
            $this->fail('The next year is not importable before the opening.');
        } catch (DeskImportYearRefusedException $e) {
            $this->assertSame(DeskImportYearRefusedException::REASON_NOT_OFFERED, $e->reason);
            $this->assertStringContainsString('2027-2028', $e->getMessage());
            $this->assertStringContainsString("Seule l'année 2026-2027 est importable aujourd'hui.", $e->getMessage());
        }
    }

    public function testAnUnknownYearIsRefusedWithoutNamingIt(): void
    {
        $this->expectException(DeskImportYearRefusedException::class);
        $this->expectExceptionMessage("L'année choisie ne peut pas recevoir d'import Desk aujourd'hui");

        $this->policy('2027-03-01')->assertImportable(999999, null);
    }

    public function testThePublicYearInTheWindowNeedsItsOwnIdBack(): void
    {
        $policy = $this->policy('2027-07-20');

        foreach ([null, $this->nextId] as $confirmed) {
            try {
                $policy->assertImportable($this->publicId, $confirmed);
                $this->fail('An unconfirmed import into the public year must be refused.');
            } catch (DeskImportYearRefusedException $e) {
                $this->assertSame(DeskImportYearRefusedException::REASON_CONFIRMATION_MISSING, $e->reason);
            }
        }

        $policy->assertImportable($this->publicId, $this->publicId);
        $policy->assertImportable($this->nextId, null);
        $this->addToAssertionCount(2);
    }

    public function testConfigurationModeIsReadAtEveryCheck(): void
    {
        $policy = $this->policy('2027-03-01');

        $this->configurationMode = true;
        $policy->assertImportable($this->previousId, $this->previousId);

        $this->configurationMode = false;
        $this->expectException(DeskImportYearRefusedException::class);
        $policy->assertImportable($this->previousId, $this->previousId);
    }

    public function testTheUnrestrictedPolicyAcceptsAnyYearAndOffersNoPage(): void
    {
        $policy = DeskImportYearPolicy::unrestricted();

        $this->assertTrue($policy->isUnrestricted());
        $policy->assertImportable($this->previousId, null);
        $policy->assertImportable(999999, null);
        $this->addToAssertionCount(2);

        $this->expectException(\LogicException::class);
        $policy->window();
    }

    public function testTheSitePolicyIsNotUnrestricted(): void
    {
        $this->assertFalse($this->policy('2027-03-01')->isUnrestricted());
    }
}
