<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Support;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\MemberEmailRepository;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\Repository\StaffedSectionRepository;
use Core\Member\SectionService;
use Core\Member\SectionStaffAuthorizationService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\ScoutYear\AuthorizationYearService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\StaffYearEligibility;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarEvent;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use Modules\Calendar\Service\AccountCalendarRights;
use Modules\Calendar\Service\CalendarEventService;
use Modules\Calendar\Service\CalendarImportWriter;
use Modules\Calendar\Service\CalendarNotificationService;
use Modules\Calendar\Service\CalendarService;
use Modules\DataMigration\Importer\IcsCalendarImporter;
use Tests\DatabaseTestHelper;

/**
 * The ICS import as public/index.php composes it, over a production-engine
 * database: the calendar module's real write contract, the importing
 * account's rights resolved for real from its account, and the download
 * answered by fixture files. Only the « Nouvelle activité » broadcast is
 * recorded rather than sent, so a test can say it never happened.
 */
final class IcsCalendarStack
{
    public const ADDRESS = 'https://agenda.exemple.test/private-abc/basic.ics';
    public const RECURRENCES = 'https://agenda.exemple.test/recurrences.ics';
    public const INCOMPLETE = 'https://agenda.exemple.test/incomplets.ics';

    public readonly UserAccountRepository $accounts;
    public readonly CalendarImportWriter $writer;
    public readonly IcsCalendarImporter $importer;
    public readonly int $calendarId;
    public readonly int $adminId;
    /** @var \ArrayObject<int, string> what was broadcast */
    public readonly \ArrayObject $dispatched;

    public function __construct(\PDO $pdo, Connection $connection, EncryptionService $encryption)
    {
        [$year, $start, $end] = DatabaseTestHelper::scoutYear();
        $pdo->prepare('INSERT INTO scout_years (label, start_date, end_date) VALUES (?, ?, ?)')
            ->execute([$year, $start, $end]);

        $this->accounts = new UserAccountRepository($pdo, $encryption);
        $scoutYears = new ScoutYearService($pdo);
        $settings = new SettingService(new SettingRepository($pdo));
        $sections = new SectionService(
            new SectionRepository($connection),
            new MemberProfileRepository($connection, $encryption, new MemberBadgeRepository($pdo))
        );
        $memberEmails = new MemberEmailRepository($pdo, $encryption);
        $calendarRepository = new CalendarRepository($pdo, $encryption);
        $events = new CalendarEventRepository($pdo);
        $calendars = new CalendarService(
            $calendarRepository,
            $events,
            $sections,
            new CalendarUnitFeedTokenRepository($pdo, $encryption)
        );

        $this->dispatched = new \ArrayObject();
        $notifications = new class (
            new SchedulerService(new SchedulerRepository($pdo)),
            $settings,
            $calendars,
            $events,
            $this->dispatched
        ) extends CalendarNotificationService {
            /** @param \ArrayObject<int, string> $dispatched */
            public function __construct(
                SchedulerService $s,
                SettingService $t,
                CalendarService $c,
                CalendarEventRepository $e,
                private \ArrayObject $dispatched
            )
            {
                parent::__construct($s, $t, $c, $e);
            }

            public function dispatchEventPublished(CalendarEvent $event, ?int $actorUserAccountId): void
            {
                $this->dispatched->append('published:' . $event->id);
            }
        };

        $this->writer = new CalendarImportWriter(
            new CalendarEventService($events, $calendars, $notifications),
            $calendars,
            new AccountCalendarRights(
                $this->accounts,
                new RoleResolver(new MemberYearRepository($pdo), $encryption, $pdo, $memberEmails),
                new AuthorizationYearService($scoutYears, $settings),
                new SectionStaffAuthorizationService(
                    new StaffedSectionRepository($connection, $encryption, $memberEmails),
                    $sections
                ),
                new ScoutYearResolver($scoutYears, $settings, new MemberYearRepository($pdo)),
                new StaffYearEligibility(
                    new RoleResolver(new MemberYearRepository($pdo), $encryption, $pdo, $memberEmails)
                )
            )
        );
        $this->calendarId = $calendarRepository
            ->createSupplementaryCalendar('Animateurs', true, Calendar::VISIBILITY_PUBLIC, 'tok');
        // A super-administrator: every calendar is theirs to write to.
        $this->adminId = $this->accounts->create('chef.unite@exemple.test', true)->id;

        $this->importer = new IcsCalendarImporter($this->writer, new ScriptedCalendarFetcher([
            self::ADDRESS => ScriptedCalendarFetcher::fixture('valide.ics'),
            self::RECURRENCES => ScriptedCalendarFetcher::fixture('recurrences.ics'),
            self::INCOMPLETE => ScriptedCalendarFetcher::fixture('incomplets.ics'),
        ]));
    }
}
