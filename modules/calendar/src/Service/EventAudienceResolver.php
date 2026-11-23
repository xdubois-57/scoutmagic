<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Core\Config\ScoutYearService;
use Core\Member\SectionAccountRepository;
use Core\Security\UserAccountRepository;
use Core\Service\DateInput;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarEvent;

/**
 * Who is told about an activity (issue #1348) — the single answer for the
 * three notifications that announce it: « Nouvelle activité », « Activité
 * modifiée » and the evening-before « Rappel d'activité ».
 *
 * **A section's activity is told to the accounts personally linked to that
 * section**: linked to a member who holds a function in it, through the
 * member's Desk address or a confirmed secondary address
 * (Core\Member\SectionAccountRepository). A role alone adds nobody. The
 * reminder used to go to every account of the unit, and the two others added
 * every chef, admin and super-administrator to every section, so an
 * administrator who is the parent of children in two sections received the
 * reminder for a weekend of a third.
 *
 * **Visibility still applies on top** — the notification carries the
 * activity's title, so it must never reach someone the grid would refuse the
 * calendar to:
 *
 *  - a section calendar reserved to the chefs (or to the administrators)
 *    tells the accounts linked to the section who ALSO hold that role. The
 *    role alone subscribes nobody; the link alone is not enough;
 *  - a supplementary calendar has no section to be linked to, so it keeps
 *    the rule « Mes évènements » applies to it: everybody when public, the
 *    cadres when reserved to them.
 *
 * Built from a PDO and two repositories, so the scheduled reminder — whose
 * TaskContext carries neither a RoleResolver nor a MemberService — resolves
 * exactly what the publication path resolves, in one place.
 */
class EventAudienceResolver
{
    public function __construct(
        private UserAccountRepository $accounts,
        private SectionAccountRepository $sectionAccounts,
        private ScoutYearService $scoutYears
    ) {
    }

    /**
     * @return list<int> account ids, each once
     */
    public function accountIdsFor(?Calendar $calendar, CalendarEvent $event): array
    {
        if ($calendar === null) {
            return [];
        }

        $years = $this->scoutYearIdsFor($event);
        $audience = $this->audienceInYears($calendar, $years);

        // The event's own year may exist — the next one is created ahead so it
        // can be previewed — before the Desk roster has been imported into it:
        // no link yet, so nobody to tell, which would drop the notification
        // silently. An empty answer in a year other than the current one is
        // read as « not imported yet » and asked again of the current year
        // (review of #1379).
        if ($audience === []) {
            $current = [(int) $this->scoutYears->getCurrentYear()['id']];
            if ($current !== $years) {
                $audience = $this->audienceInYears($calendar, $current);
            }
        }

        return $audience;
    }

    /**
     * @param int[] $years
     * @return list<int>
     */
    private function audienceInYears(Calendar $calendar, array $years): array
    {
        if ($calendar->sectionId === null) {
            return array_values(array_unique(match ($calendar->visibility) {
                Calendar::VISIBILITY_ADMIN => $this->sectionAccounts->cadreAccountIds($years, ['admin']),
                Calendar::VISIBILITY_CHIEF => $this->sectionAccounts->cadreAccountIds($years),
                default => $this->accounts->findAllIds(),
            }));
        }

        $linked = $this->sectionAccounts->accountIdsLinkedToSections([$calendar->sectionId], $years);

        // The role filter follows the same two addresses as the link — Desk and
        // confirmed secondary — so a chef who signs in by a secondary address is
        // both linked and allowed (review of #1379).
        $allowed = match ($calendar->visibility) {
            Calendar::VISIBILITY_ADMIN => $this->sectionAccounts->cadreAccountIds($years, ['admin']),
            Calendar::VISIBILITY_CHIEF => $this->sectionAccounts->cadreAccountIds($years),
            default => null,
        };

        return $allowed === null
            ? $linked
            : array_values(array_intersect($linked, $allowed));
    }

    /**
     * The scout year the event's start date falls in, or the current one when
     * that year does not exist in this installation.
     *
     * Not « today's »: a notification sent on 31 August about an event in
     * September must not resolve the links of a year whose roster has not
     * been imported yet (the trap the multi-day reminder fell into). Never a
     * year stored in a session — this also runs from cron.
     *
     * @return int[]
     */
    private function scoutYearIdsFor(CalendarEvent $event): array
    {
        $date = DateInput::iso($event->startDate);
        $year = $date !== null
            ? $this->scoutYears->findByLabel(ScoutYearService::labelForDate($date))
            : null;
        $year ??= $this->scoutYears->getCurrentYear();

        return [(int) $year['id']];
    }
}
