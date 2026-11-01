<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Calendar;

use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Security\Role;
use Modules\Calendar\Api\CalendarDirectoryInterface;
use Modules\Calendar\Api\VirtualEvent;
use Modules\Calendar\Api\VirtualEventViewer;
use Modules\SosStaff\Calendar\SosVirtualEventProvider;
use Modules\SosStaff\Repository\OnCallAssignment;
use Modules\SosStaff\Repository\OnCallRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SosVirtualEventProviderTest extends TestCase
{
    private const DEFAULT_CALENDAR_ID = 7;
    private const SCOUT_YEAR_ID = 3;

    /** @var array<int, OnCallAssignment[]> what findForRange() returns */
    private array $assignments = [];
    private ?array $lastRequestedRange = null;

    private function provider(
        ?int $defaultCalendarId = self::DEFAULT_CALENDAR_ID,
        array $profilesByMemberId = []
    ): SosVirtualEventProvider {
        $onCallRepository = $this->createStub(OnCallRepository::class);
        $onCallRepository->method('findForRange')->willReturnCallback(
            function (string $from, string $to): array {
                $this->lastRequestedRange = [$from, $to];
                return array_merge(...array_values($this->assignments) ?: [[]]);
            }
        );

        $memberService = $this->createStub(MemberService::class);
        $memberService->method('findProfileByMemberAndYear')->willReturnCallback(
            fn(int $memberId, int $scoutYearId) => $profilesByMemberId[$memberId] ?? null
        );

        $directory = $this->createStub(CalendarDirectoryInterface::class);
        $directory->method('defaultCalendarId')->willReturn($defaultCalendarId);

        return new SosVirtualEventProvider($onCallRepository, $memberService, $directory);
    }

    private function oncall(int $memberId, string ...$dates): void
    {
        foreach ($dates as $date) {
            $this->assignments[$memberId][] = new OnCallAssignment($memberId, $date, OnCallAssignment::STATE_ONCALL);
        }
    }

    private function viewer(array $calendarIds = [self::DEFAULT_CALENDAR_ID]): VirtualEventViewer
    {
        return new VirtualEventViewer(Role::CHIEF, 'chef@unite.be', self::SCOUT_YEAR_ID, $calendarIds);
    }

    private function profile(
        int $memberId,
        ?string $totem,
        string $firstName = 'Jean',
        string $lastName = 'Dupont'
    ): MemberProfile
    {
        return new MemberProfile(
            memberYearId: $memberId * 100,
            memberId: $memberId,
            deskId: 'D' . $memberId,
            firstName: $firstName,
            lastName: $lastName,
            totem: $totem,
            quali: null,
            gender: null,
            birthDate: null,
            phone: null,
            mobile: '+32470000000',
            email: null,
            patrol: null,
            formationLevel: null,
            federationMailConsent: false,
            unitMailConsent: false,
            addresses: [],
            functions: [],
            scoutYearLabel: '2025-2026'
        );
    }

    /**
     * @return VirtualEvent[]
     */
    private function collect(SosVirtualEventProvider $provider, string $from, string $to, ?VirtualEventViewer $viewer = null): array
    {
        return $provider->findVirtualEvents(
            new \DateTimeImmutable($from),
            new \DateTimeImmutable($to),
            $viewer ?? $this->viewer()
        );
    }

    public function testConsecutiveOncallDaysMergeIntoOneStreakEvent(): void
    {
        $this->oncall(42, '2026-09-04', '2026-09-05', '2026-09-06');
        $provider = $this->provider(profilesByMemberId: [42 => $this->profile(42, 'Akela')]);

        $events = $this->collect($provider, '2026-09-01', '2026-09-30');

        $this->assertCount(1, $events);
        $this->assertSame("SOS Staff d'U : Akela (Jean Dupont)", $events[0]->title);
        $this->assertSame('2026-09-04', $events[0]->startDate);
        $this->assertSame('2026-09-06', $events[0]->endDate);
        $this->assertSame(self::DEFAULT_CALENDAR_ID, $events[0]->calendarId);
        $this->assertTrue($events[0]->isAllDay());
    }

    public function testNonConsecutiveDaysProduceSeparateStreaks(): void
    {
        $this->oncall(42, '2026-09-04', '2026-09-06');
        $provider = $this->provider(profilesByMemberId: [42 => $this->profile(42, 'Akela')]);

        $events = $this->collect($provider, '2026-09-01', '2026-09-30');

        $this->assertCount(2, $events);
        $this->assertSame(['2026-09-04', '2026-09-06'], [$events[0]->startDate, $events[1]->startDate]);
    }

    /** @return array<string, array{Role, ?string, ?string, string}> */
    public static function anonymousReaders(): array
    {
        return [
            'shared feed' => [Role::PUBLIC, null, 'Fennec', 'Fennec'],
            'without a totem' => [Role::PUBLIC, null, null, 'Jean'],
            'role without identity' => [Role::ADMIN, null, 'Fennec', 'Fennec'],
            'empty identity' => [Role::CHIEF, '', 'Fennec', 'Fennec'],
        ];
    }

    #[DataProvider('anonymousReaders')]
    public function testAnUnidentifiedFeedKeepsTheShortLabelWithoutTheCivilSurname(
        Role $role,
        ?string $email,
        ?string $totem,
        string $shortLabel
    ): void {
        $this->oncall(42, '2026-09-04');
        $provider = $this->provider(profilesByMemberId: [42 => $this->profile(42, $totem)]);
        $viewer = new VirtualEventViewer($role, $email, self::SCOUT_YEAR_ID, [self::DEFAULT_CALENDAR_ID]);
        $events = $this->collect($provider, '2026-09-01', '2026-09-30', $viewer);
        $ics = (new \Modules\Calendar\Service\IcsBuilder())->build('Animateurs', [], $events);
        $summaries = array_values(array_filter(explode("\r\n", $ics),
            static fn(string $line): bool => str_starts_with($line, 'SUMMARY:')));

        $this->assertSame([["SUMMARY:SOS Staff d'U : " . $shortLabel], false],
            [$summaries, str_contains($ics, 'Dupont')]);
    }

    public function testAnIdentifiedReaderCanDistinguishTwoMembersWithTheSameTotem(): void
    {
        $this->oncall(42, '2026-09-04');
        $this->oncall(43, '2026-09-04');
        $provider = $this->provider(profilesByMemberId: [
            42 => $this->profile(42, 'Fennec'),
            43 => $this->profile(43, 'Fennec', 'Paul', 'Martin'),
        ]);
        $events = $this->collect($provider, '2026-09-01', '2026-09-30');
        $this->assertSame(["SOS Staff d'U : Fennec (Jean Dupont)", "SOS Staff d'U : Fennec (Paul Martin)"],
            array_map(static fn(VirtualEvent $event): string => $event->title, $events));
    }

    public function testUnavailableDaysAreNotPublished(): void
    {
        $this->assignments[42][] = new OnCallAssignment(42, '2026-09-04', OnCallAssignment::STATE_UNAVAILABLE);
        $provider = $this->provider(profilesByMemberId: [42 => $this->profile(42, 'Akela')]);

        $this->assertSame([], $this->collect($provider, '2026-09-01', '2026-09-30'));
    }

    public function testUidIsStablePerMemberAndStreakStart(): void
    {
        $this->oncall(42, '2026-09-04', '2026-09-05');
        $provider = $this->provider(profilesByMemberId: [42 => $this->profile(42, 'Akela')]);

        $events = $this->collect($provider, '2026-09-01', '2026-09-30');

        $this->assertSame('sos-oncall-42-2026-09-04@scoutmagic', $events[0]->uid);
        // Growing the streak's END keeps the UID — a subscribed client
        // updates its copy in place instead of showing a second event.
        $this->oncall(42, '2026-09-06');
        $again = $this->collect($provider, '2026-09-01', '2026-09-30');
        $this->assertSame($events[0]->uid, $again[0]->uid);
        $this->assertSame('2026-09-06', $again[0]->endDate);
    }

    public function testStreakCrossingTheWindowEdgeKeepsItsTrueBoundaries(): void
    {
        // The streak starts in August and ends in September: a September
        // window must still carry the true August start date, which is why
        // the provider reads with a margin around the window.
        $this->oncall(42, '2026-08-30', '2026-08-31', '2026-09-01', '2026-09-02');
        $provider = $this->provider(profilesByMemberId: [42 => $this->profile(42, 'Akela')]);

        $events = $this->collect($provider, '2026-09-01', '2026-09-30');

        $this->assertCount(1, $events);
        $this->assertSame('2026-08-30', $events[0]->startDate);
        $this->assertSame('2026-09-02', $events[0]->endDate);
        $this->assertNotNull($this->lastRequestedRange);
        $this->assertLessThan('2026-09-01', $this->lastRequestedRange[0]);
        $this->assertGreaterThan('2026-09-30', $this->lastRequestedRange[1]);
    }

    public function testStreakEntirelyOutsideTheWindowIsNotPublished(): void
    {
        // Inside the read margin, outside the requested window: the margin
        // exists to complete boundary-crossing streaks, never to widen
        // what the window shows.
        $this->oncall(42, '2026-10-10', '2026-10-11');
        $provider = $this->provider(profilesByMemberId: [42 => $this->profile(42, 'Akela')]);

        $this->assertSame([], $this->collect($provider, '2026-09-01', '2026-09-30'));
    }

    public function testViewerNotLookingAtTheDefaultCalendarGetsNothing(): void
    {
        $this->oncall(42, '2026-09-04');
        $provider = $this->provider(profilesByMemberId: [42 => $this->profile(42, 'Akela')]);

        // A feed for some other calendar must not quietly carry the duty
        // roster (VirtualEventViewer's own calendarIds contract).
        $events = $this->collect($provider, '2026-09-01', '2026-09-30', $this->viewer([99]));

        $this->assertSame([], $events);
    }

    public function testMissingDefaultCalendarDegradesToNothing(): void
    {
        $this->oncall(42, '2026-09-04');
        $provider = $this->provider(defaultCalendarId: null, profilesByMemberId: [42 => $this->profile(42, 'Akela')]);

        $this->assertSame([], $this->collect($provider, '2026-09-01', '2026-09-30'));
    }

    public function testMemberWithoutAProfileThisYearStillPublishesWithGenericLabel(): void
    {
        $this->oncall(42, '2026-09-04');
        $provider = $this->provider();

        $events = $this->collect($provider, '2026-09-01', '2026-09-30');

        $this->assertCount(1, $events);
        $this->assertSame("SOS Staff d'U : Membre", $events[0]->title);
    }
}
