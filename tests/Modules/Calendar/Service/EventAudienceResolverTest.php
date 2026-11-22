<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Core\Config\ScoutYearService;
use Core\Member\MemberEmail;
use Core\Member\MemberEmailRepository;
use Core\Member\SectionAccountRepository;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Service\EventAudienceResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * Issue #1348: who is told about an activity — when it is announced, when it
 * changes, and the evening before — is the accounts PERSONALLY linked to its
 * section, and a role alone adds nobody.
 *
 * Real tables, real repositories and the real role queries; nothing in this
 * file decides a role by hand. The reported case drives it: an administrator
 * who is the parent of children in the Goélands and the Pionniers received the
 * reminder for a weekend of the Seeonee, a section none of their children is
 * in.
 */
#[Group('database')]
final class EventAudienceResolverTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private UserAccountRepository $accounts;
    private MemberEmailRepository $secondary;
    private CalendarRepository $calendars;
    private CalendarEventRepository $events;
    private EventAudienceResolver $resolver;
    private int $year;
    private int $goelands;
    private int $pionniers;
    private int $seeonee;
    private int $animeFunction;
    private int $chiefFunction;
    private int $adminFunction;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->accounts = new UserAccountRepository($this->pdo, $this->encryption);
        $this->secondary = new MemberEmailRepository($this->pdo, $this->encryption);
        $this->calendars = new CalendarRepository($this->pdo, $this->encryption);
        $this->events = new CalendarEventRepository($this->pdo);
        $this->resolver = new EventAudienceResolver(
            $this->accounts,
            new SectionAccountRepository($this->pdo),
            new ScoutYearService($this->pdo)
        );

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('B1', 'Branche', 10)");
        $branch = (int) $this->pdo->lastInsertId();
        foreach (['goelands' => 'GOE', 'pionniers' => 'PIO', 'seeonee' => 'SEE'] as $property => $code) {
            $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
            $stmt->execute([$code, $branch, ucfirst($property)]);
            $this->{$property} = (int) $this->pdo->lastInsertId();
        }

        $label = ScoutYearService::labelForDate(new \DateTimeImmutable('+5 days'));
        $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 1)');
        $stmt->execute([$label, substr($label, 0, 4) . '-09-01', substr($label, 5, 4) . '-08-31']);
        $this->year = (int) $this->pdo->lastInsertId();

        foreach (['animeFunction' => ['ANI', 'identified'], 'chiefFunction' => ['CHE', 'chief'], 'adminFunction' => ['CU', 'admin']] as $property => [$code, $role]) {
            $this->pdo->prepare('INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)')
                ->execute([$code, $code, $role]);
            $this->{$property} = (int) $this->pdo->lastInsertId();
        }
    }

    /** A member of $section whose Desk address is $email, holding $function. */
    private function member(string $email, int $section, ?int $function = null, ?int $year = null): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute(['D' . uniqid()]);
        $member = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
             email_encrypted, email_blind_index) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $member, $year ?? $this->year,
            $this->encryption->encrypt('Prénom', 'member_years.first_name'),
            $this->encryption->encrypt('Nom', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex(strtolower($email), 'email'),
        ]);
        $memberYear = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, ?)')
            ->execute([$memberYear, $function ?? $this->animeFunction, $section]);

        return $member;
    }

    private function account(string $email, bool $superAdmin = false): int
    {
        $id = $this->accounts->create($email)->id;
        if ($superAdmin) {
            $this->pdo->prepare('UPDATE user_accounts SET is_super_admin = 1 WHERE id = ?')->execute([$id]);
        }

        return $id;
    }

    /** @return list<int> */
    private function audience(int $calendarId, string $date = '+5 days'): array
    {
        $eventId = $this->events->create(
            $calendarId, 'Week-end', (new \DateTimeImmutable($date))->format('Y-m-d'), null, null, null, null, null, null
        );
        $event = $this->events->findById($eventId);
        $calendar = $this->calendars->findById($calendarId);
        $ids = $this->resolver->accountIdsFor($calendar, $event);
        sort($ids);

        return $ids;
    }

    private function sectionCalendar(int $section, string $visibility = Calendar::VISIBILITY_PUBLIC): int
    {
        return $this->calendars->createSectionCalendar($section, $visibility);
    }

    /** The reported case, on all three notifications at once: they share this answer. */
    public function testAnAdministratorParentIsToldOnlyAboutTheirChildrensSections(): void
    {
        $admin = $this->account('admin-parent@test.be');
        $this->member('admin-parent@test.be', $this->goelands);
        $this->member('admin-parent@test.be', $this->pionniers);
        $this->member('admin-parent@test.be', $this->goelands, $this->adminFunction);

        $this->assertSame([$admin], $this->audience($this->sectionCalendar($this->goelands)));
        $this->assertSame([$admin], $this->audience($this->sectionCalendar($this->pionniers)));
        $this->assertSame([], $this->audience($this->sectionCalendar($this->seeonee)), 'not their section');
    }

    /** A chef and a super-administrator: the role does not widen the sections told. */
    public function testChiefsAndSuperAdministratorsAreNotSubscribedToEverySection(): void
    {
        $chief = $this->account('chef@test.be');
        $this->member('chef@test.be', $this->goelands, $this->chiefFunction);
        $super = $this->account('super@test.be', superAdmin: true);
        $parent = $this->account('parent@test.be');
        $this->member('parent@test.be', $this->seeonee);

        $seeonee = $this->audience($this->sectionCalendar($this->seeonee));

        $this->assertSame([$parent], $seeonee);
        $this->assertNotContains($chief, $seeonee);
        $this->assertNotContains($super, $seeonee);
    }

    /** An ordinary parent follows the same scope. */
    public function testAnOrdinaryParentIsToldAboutTheirOwnSectionOnly(): void
    {
        $parent = $this->account('parent@test.be');
        $this->member('parent@test.be', $this->goelands);
        $other = $this->account('other@test.be');
        $this->member('other@test.be', $this->pionniers);

        $audience = $this->audience($this->sectionCalendar($this->goelands));

        $this->assertSame([$parent], $audience);
        $this->assertNotContains($other, $audience);
    }

    /** A function of their own in the section, and the Staff d'U attachment, are links like any other. */
    public function testAnOwnFunctionInTheSectionLinksTheAccount(): void
    {
        $leader = $this->account('animateur@test.be');
        $this->member('animateur@test.be', $this->goelands, $this->chiefFunction);

        $this->assertSame([$leader], $this->audience($this->sectionCalendar($this->goelands)));
    }

    public function testAConfirmedSecondaryAddressReachesTheSameSectionsAndAPendingOneDoesNot(): void
    {
        $child = $this->member('family@test.be', $this->goelands);
        $teen = $this->account('teen@test.be');
        $waiting = $this->account('waiting@test.be');
        $this->secondary->create($child, 'teen@test.be', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null);
        $this->secondary->create($child, 'waiting@test.be', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_PENDING, null, null);

        $audience = $this->audience($this->sectionCalendar($this->goelands));

        $this->assertSame([$teen], $audience);
        $this->assertNotContains($waiting, $audience);
    }

    /** One notification per account, even with several children or functions in the section. */
    public function testSeveralChildrenInTheSameSectionCountOnce(): void
    {
        $parent = $this->account('parent@test.be');
        $this->member('parent@test.be', $this->goelands);
        $this->member('parent@test.be', $this->goelands);
        $this->member('parent@test.be', $this->goelands, $this->chiefFunction);

        $this->assertSame([$parent], $this->audience($this->sectionCalendar($this->goelands)));
    }

    /**
     * Decision 2 of the ticket: a section calendar reserved to the chefs
     * notifies the accounts linked to the section WHO ALSO hold the required
     * role. The role alone subscribes nobody; the link alone is not enough.
     */
    public function testAChiefOnlySectionCalendarTellsLinkedChiefsOnly(): void
    {
        $linkedChief = $this->account('linked-chief@test.be');
        $this->member('linked-chief@test.be', $this->goelands, $this->chiefFunction);
        $parent = $this->account('parent@test.be');
        $this->member('parent@test.be', $this->goelands);
        $foreignChief = $this->account('foreign-chief@test.be');
        $this->member('foreign-chief@test.be', $this->seeonee, $this->chiefFunction);
        $super = $this->account('super@test.be', superAdmin: true);

        $audience = $this->audience($this->sectionCalendar($this->goelands, Calendar::VISIBILITY_CHIEF));

        $this->assertSame([$linkedChief], $audience);
        $this->assertNotContains($parent, $audience, 'linked, but not allowed to see the calendar');
        $this->assertNotContains($foreignChief, $audience, 'allowed, but not linked');
        $this->assertNotContains($super, $audience);
    }

    public function testAnAdminOnlySectionCalendarTellsLinkedAdministratorsOnly(): void
    {
        $linkedAdmin = $this->account('admin@test.be');
        $this->member('admin@test.be', $this->goelands, $this->adminFunction);
        $linkedChief = $this->account('chief@test.be');
        $this->member('chief@test.be', $this->goelands, $this->chiefFunction);

        $audience = $this->audience($this->sectionCalendar($this->goelands, Calendar::VISIBILITY_ADMIN));

        $this->assertSame([$linkedAdmin], $audience);
        $this->assertNotContains($linkedChief, $audience);
    }

    /** A super-administrator who IS linked to the section keeps being told, on any visibility. */
    public function testALinkedSuperAdministratorIsToldOnARestrictedCalendar(): void
    {
        $super = $this->account('super@test.be', superAdmin: true);
        $this->member('super@test.be', $this->goelands);

        $this->assertSame([$super], $this->audience($this->sectionCalendar($this->goelands, Calendar::VISIBILITY_ADMIN)));
    }

    /** Supplementary calendars keep the visibility rule « Mes évènements » already applies. */
    public function testASupplementaryCalendarFollowsItsVisibilityForTheEffectiveRole(): void
    {
        $parent = $this->account('parent@test.be');
        $this->member('parent@test.be', $this->goelands);
        $chief = $this->account('chief@test.be');
        $this->member('chief@test.be', $this->goelands, $this->chiefFunction);
        $admin = $this->account('admin@test.be');
        $this->member('admin@test.be', $this->seeonee, $this->adminFunction);
        $super = $this->account('super@test.be', superAdmin: true);

        $public = $this->calendars->createSupplementaryCalendar('Évènements', true, Calendar::VISIBILITY_PUBLIC, 't1');
        $chiefs = $this->calendars->createSupplementaryCalendar('Animateurs', true, Calendar::VISIBILITY_CHIEF, 't2');
        $admins = $this->calendars->createSupplementaryCalendar('Conseil', true, Calendar::VISIBILITY_ADMIN, 't3');

        $this->assertSame([$parent, $chief, $admin, $super], $this->audience($public));
        $this->assertSame([$chief, $admin, $super], $this->audience($chiefs));
        $this->assertSame([$admin, $super], $this->audience($admins));
        $this->assertNotContains($parent, $this->audience($chiefs), 'the title of a staff meeting is not for parents');
    }

    /**
     * The year is the event's own, not today's: an event next September
     * resolves the links of THAT year (the roster a notification of 31 August
     * must not look for in a year not yet imported), and a date with no year
     * on file falls back to the current one.
     */
    public function testTheYearOfTheEventDecidesWhoIsLinked(): void
    {
        $parent = $this->account('parent@test.be');
        $this->member('parent@test.be', $this->goelands);
        $calendar = $this->sectionCalendar($this->goelands);

        $this->assertSame([$parent], $this->audience($calendar, '+5 days'));
        // A date in a year nobody imported yet falls back to the current year.
        $this->assertSame([$parent], $this->audience($calendar, '+4 years'));
    }

    public function testNothingIsToldWhenNobodyIsLinked(): void
    {
        $this->account('lonely@test.be');

        $this->assertSame([], $this->audience($this->sectionCalendar($this->goelands)));
    }
}
