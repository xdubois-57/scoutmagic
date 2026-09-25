<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Core\View\MonthGrid\MonthGridBuilder;
use Modules\Calendar\Api\EventDescriptionEnricherInterface;
use Modules\Calendar\Api\VirtualEventViewer;
use Modules\Calendar\Controller\CalendarPublicController;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarPersonalTokenRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use Modules\Calendar\Service\CalendarPickerService;
use Modules\Calendar\Service\CalendarService;
use Modules\Calendar\Service\EventDescriptionEnricherRegistry;
use Modules\Calendar\Service\IcsBuilder;
use Modules\Calendar\Service\PersonalFeedService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Api\EventDescriptionEnricherInterface reaches the personal feed, and
 * NEVER a calendar's own feed nor the whole-unit feed — one test per feed.
 *
 * The two others are bearer links with no identified reader: a line about
 * a family's seat in a car written there would leak to whoever holds the
 * link (docs/chantiers/covoiturage.md, IT-03).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class EventDescriptionEnrichmentFeedTest extends TestCase
{
    public const LINE = 'Covoiturage — aller 8 h 30, parking des locaux : en attente';

    private \PDO $pdo;
    private CalendarPublicController $controller;
    private CalendarService $calendars;
    private CalendarEventRepository $events;
    private PersonalFeedService $personalFeed;
    private EncryptionService $encryption;
    /** @var list<array{ids: list<int>, viewer: VirtualEventViewer}> */
    private array $calls = [];
    private string $calendarToken;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $this->events = new CalendarEventRepository($this->pdo);
        $sections = new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo));
        $this->calendars = new CalendarService(
            new CalendarRepository($this->pdo, $this->encryption),
            $this->events,
            $sections,
            new CalendarUnitFeedTokenRepository($this->pdo, $this->encryption)
        );

        $calls = &$this->calls;
        $registry = new EventDescriptionEnricherRegistry();
        $registry->register(new class ($calls) implements EventDescriptionEnricherInterface {
            /** @param list<array{ids: list<int>, viewer: VirtualEventViewer}> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function enricherId(): string
            {
                return 'test';
            }

            public function describeEvents(array $eventIds, VirtualEventViewer $viewer): array
            {
                $this->calls[] = ['ids' => $eventIds, 'viewer' => $viewer];
                $lines = [];
                foreach ($eventIds as $id) {
                    $lines[$id] = [EventDescriptionEnrichmentFeedTest::LINE];
                }

                return $lines;
            }
        });

        $memberYears = new MemberYearRepository($this->pdo);
        $this->personalFeed = new PersonalFeedService(
            new CalendarPersonalTokenRepository($this->pdo, $this->encryption),
            $this->calendars,
            $this->events,
            new RoleResolver($memberYears, $this->encryption, $this->pdo),
            new MemberService($memberYears, $this->encryption, $connection),
            new UserAccountRepository($this->pdo, $this->encryption),
            $sections,
            null,
            null,
            null,
            $registry
        );

        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)"
        );

        $this->controller = new CalendarPublicController(
            new Environment(new ArrayLoader([])),
            $this->calendars,
            new CalendarPickerService($this->calendars, $this->personalFeed),
            new MonthGridBuilder(),
            $this->personalFeed,
            new IcsBuilder(),
            new ScoutYearResolver(
                new ScoutYearService($this->pdo),
                new SettingService(new SettingRepository($this->pdo)),
                $memberYears
            ),
            new JournalService(new JournalRepository($this->pdo))
        );

        // A public supplementary calendar: every identified reader's
        // personal feed carries it, and it has its own ICS token too.
        $calendar = $this->calendars->addCalendar('Unité', 'public');
        $this->calendarToken = (string) $calendar->icsToken;
        $this->events->create($calendar->id, "Fête d'unité", '2026-10-04', null, null, null, null, 'Au programme : jeux.', null);
    }

    private function personalToken(string $email): string
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([
            $this->encryption->encrypt($email, 'user_accounts.email'),
            $this->encryption->blindIndex(strtolower($email), 'email'),
        ]);

        return $this->personalFeed->getOrCreateToken((int) $this->pdo->lastInsertId());
    }

    /** ICS folds long lines; the assertions read the unfolded text. */
    private static function unfolded(string $ics): string
    {
        return str_replace(["\r\n ", "\r\n\t"], '', $ics);
    }

    public function testThePersonalFeedCarriesTheLineUnderTheEventsOwnDescription(): void
    {
        $token = $this->personalToken('parent@test.be');

        $ics = self::unfolded(
            $this->controller->personalFeed(new Request('GET', '/x', [], [], [], []), ['token' => $token])->getBody()
        );

        $this->assertStringContainsString('Au programme : jeux.', $ics);
        // RFC 5545 escapes the comma; the line is otherwise untouched.
        $this->assertStringContainsString(str_replace(',', '\\,', self::LINE), $ics);
        // One call for the whole feed, with an identified reader.
        $this->assertCount(1, $this->calls);
        $this->assertSame('parent@test.be', $this->calls[0]['viewer']->email);
    }

    public function testACalendarsOwnFeedNeverCarriesIt(): void
    {
        $ics = self::unfolded(
            $this->controller->calendarFeed(new Request('GET', '/x', [], [], [], []), ['token' => $this->calendarToken])->getBody()
        );

        $this->assertStringContainsString("Fête d'unité", $ics);
        $this->assertStringNotContainsString('Covoiturage', $ics);
        $this->assertSame([], $this->calls, 'An enricher must not even be asked for a feed with no identified reader.');
    }

    public function testTheWholeUnitFeedNeverCarriesIt(): void
    {
        $token = $this->calendars->getOrCreateUnitFeedToken();

        $ics = self::unfolded(
            $this->controller->unitFeed(new Request('GET', '/x', [], [], [], []), ['token' => $token])->getBody()
        );

        $this->assertStringContainsString("Fête d'unité", $ics);
        $this->assertStringNotContainsString('Covoiturage', $ics);
        $this->assertSame([], $this->calls);
    }

    public function testAFailingEnricherLeavesTheFeedIntact(): void
    {
        $registry = new EventDescriptionEnricherRegistry();
        $registry->register(new class implements EventDescriptionEnricherInterface {
            public function enricherId(): string
            {
                return 'broken';
            }

            public function describeEvents(array $eventIds, VirtualEventViewer $viewer): array
            {
                throw new \RuntimeException('boom');
            }
        });

        $viewer = new VirtualEventViewer(\Core\Security\Role::IDENTIFIED, 'a@b.be', 1);
        $this->assertSame([], $registry->linesFor([1, 2], $viewer));
    }
}
