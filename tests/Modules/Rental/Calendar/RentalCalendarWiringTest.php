<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Calendar;

use Modules\Calendar\Api\VirtualEvent;
use Modules\Calendar\Api\VirtualEventProviderInterface;
use Modules\Calendar\Api\VirtualEventViewer;
use Modules\Calendar\Service\IcsBuilder;
use Modules\Calendar\Service\VirtualEventRegistry;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;

/**
 * The `rental` ↔ `calendar` circularity, in both directions of disabling
 * (§6.31).
 *
 * The two modules genuinely need each other: `rental` publishes its
 * occupancy onto a calendar, and `calendar` renders events `rental` owns.
 * Wired naively that is a construction cycle — neither can be built first.
 * The composition root breaks it with a **mutable registry**: `calendar`
 * creates an empty VirtualEventRegistry and hands the object (not a
 * snapshot of its contents) to its controllers; `rental`, constructed
 * afterwards, appends its provider to that same object. Neither block
 * reaches into the other's variables.
 *
 * Both halves must therefore survive the other's absence, and that is what
 * this file pins — behaviourally where the objects allow it, and at the
 * source level for the two guards that live in the procedural bootstrap
 * which no unit test loads.
 */
class RentalCalendarWiringTest extends TestCase
{
    private static function indexSource(): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        self::assertNotFalse($contents);

        return $contents;
    }

    // ── `rental` disabled: calendar carries on ──────────────────────────

    public function testAnEmptyRegistryIsHarmlessSoCalendarNeedsNoProviderToWork(): void
    {
        $registry = new VirtualEventRegistry();

        $this->assertFalse($registry->hasProviders());
        $this->assertSame([], $registry->collect(
            new \DateTimeImmutable('2027-01-01'),
            new \DateTimeImmutable('2027-12-31'),
            new VirtualEventViewer(Role::fromString('identified'), 'chef@unite.test', 1, [3])
        ));
    }

    public function testAFeedWithNoVirtualEventsIsStillAValidCalendar(): void
    {
        // With `rental` off there is no provider at all, so every ICS the
        // calendar module serves goes through the same builder with an
        // empty third argument. That must produce an ordinary, complete
        // VCALENDAR — not a truncated one, and not an error.
        $ics = (new IcsBuilder())->build('Animateurs', [], []);

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertStringNotContainsString('BEGIN:VEVENT', $ics);
    }

    public function testTheRegistryIsCreatedEmptyAndOnlyLaterFilled(): void
    {
        // The property that actually breaks the cycle: a provider
        // registered AFTER the calendar controllers were constructed still
        // reaches them, because they hold the registry object itself.
        $registry = new VirtualEventRegistry();
        $heldByCalendarControllers = $registry;

        $registry->register($this->providerYielding([$this->event('late@scoutmagic')]));

        $this->assertTrue($heldByCalendarControllers->hasProviders());
        $this->assertCount(1, $heldByCalendarControllers->collect(
            new \DateTimeImmutable('2027-01-01'),
            new \DateTimeImmutable('2027-12-31'),
            new VirtualEventViewer(Role::fromString('identified'), 'chef@unite.test', 1, [3])
        ));
    }

    // ── `calendar` disabled: rental carries on ──────────────────────────

    public function testWithCalendarDisabledRentalNeverBuildsAProvider(): void
    {
        // The registry is null in that case, and the rental block guards on
        // it rather than assuming it exists.
        $this->assertMatchesRegularExpression(
            '/if \(\$calendarVirtualEventRegistry !== null\) \{\s*\$calendarVirtualEventRegistry->register\(/',
            self::indexSource(),
            'The rental block must only register its provider when the calendar registry exists.'
        );
    }

    public function testTheRegistryIsSeededNullBeforeTheCalendarBlockDecidesToBuildIt(): void
    {
        $source = self::indexSource();

        $seeded = strpos($source, '$calendarVirtualEventRegistry = null;');
        $built = strpos($source, '$calendarVirtualEventRegistry = new \\Modules\\Calendar\\Service\\VirtualEventRegistry();');
        $used = strpos($source, '$calendarVirtualEventRegistry->register(');

        $this->assertIsInt($seeded);
        $this->assertIsInt($built);
        $this->assertIsInt($used);

        // Seeded before the calendar block, filled after it: the ordering
        // that makes both variables provably defined whichever module is
        // enabled.
        $this->assertLessThan($built, $seeded);
        $this->assertLessThan($used, $built);
    }

    public function testRentalConsumesCalendarThroughTheNullableForOthersHandlesOnly(): void
    {
        // The other direction of the same circularity: `rental` borrows
        // CalendarService and IcsBuilder. It must take them from the
        // null-seeded ...ForOthers handles, never by reaching for
        // $calendarService — which does not exist when `calendar` is off.
        $source = self::indexSource();

        $this->assertStringContainsString('$calendarServiceForOthers = null;', $source);
        $this->assertStringContainsString('$calendarIcsBuilderForOthers = null;', $source);

        $rentalBlock = substr($source, (int) strpos($source, 'Modules\\Rental\\Controller\\RentalRequestController('));
        $rentalBlock = substr($rentalBlock, 0, 2000);

        $this->assertStringNotContainsString('$calendarIcsBuilder,', $rentalBlock);
    }

    public function testTheRenterFeedBuilderIsOnlyBuiltWhenTheGeneratorExists(): void
    {
        $this->assertStringContainsString(
            '$calendarIcsBuilderForOthers !== null',
            self::indexSource(),
            'The renter ICS feed must degrade to null rather than half-exist.'
        );
    }

    // ── Neither module knows the other's classes ────────────────────────

    public function testTheCalendarApiNeverMentionsRental(): void
    {
        // If it did, disabling `rental` would break `calendar` at autoload
        // time rather than merely leaving the registry empty.
        foreach (glob(dirname(__DIR__, 4) . '/modules/calendar/src/Api/*.php') ?: [] as $file) {
            $this->assertStringNotContainsString(
                'Modules\\Rental',
                (string) file_get_contents($file),
                basename($file) . ' must not name the rental module.'
            );
        }
    }

    public function testTheRegistryItselfNeverMentionsRental(): void
    {
        $this->assertStringNotContainsString(
            'Modules\\Rental',
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/calendar/src/Service/VirtualEventRegistry.php')
        );
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    /**
     * @param VirtualEvent[] $events
     */
    private function providerYielding(array $events): VirtualEventProviderInterface
    {
        return new class ($events) implements VirtualEventProviderInterface {
            /**
             * @param VirtualEvent[] $events
             */
            public function __construct(private array $events)
            {
            }

            public function providerId(): string
            {
                return 'fake';
            }

            public function findVirtualEvents(
                \DateTimeImmutable $windowStart,
                \DateTimeImmutable $windowEnd,
                VirtualEventViewer $viewer
            ): array {
                return $this->events;
            }
        };
    }

    private function event(string $uid): VirtualEvent
    {
        return new VirtualEvent(
            uid: $uid,
            calendarId: 3,
            title: 'Occupation',
            startDate: '2027-07-01',
            endDate: '2027-07-04'
        );
    }
}
