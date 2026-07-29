<?php

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Security\Role;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Modules\Calendar\Repository\CalendarEvent;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarPersonalTokenRepository;
use Modules\Retro\Api\RetroEventLinkLookupInterface;

/**
 * The personal ICS feed: a per-user_account bearer token (see
 * calendar_personal_tokens) that resolves — at fetch time, never
 * pre-computed — to the events of the sections where the visitor has
 * linked animés, plus every supplementary calendar visible to their
 * effective role (not just the one flagged "default"). See
 * resolveCalendarIdsForEmail() for the shared resolution logic, also used
 * directly by the chefs calendar page's "Mes évènements" entry.
 */
class PersonalFeedService
{
    public function __construct(
        private CalendarPersonalTokenRepository $tokenRepository,
        private CalendarService $calendarService,
        private CalendarEventRepository $eventRepository,
        private RoleResolver $roleResolver,
        private MemberService $memberService,
        private UserAccountRepository $userAccountRepository,
        private SectionService $sectionService,
        private ?RetroEventLinkLookupInterface $retroEventLinkLookup = null
    ) {
    }

    public function getOrCreateToken(int $userAccountId): string
    {
        $existing = $this->tokenRepository->findTokenByUserAccountId($userAccountId);
        if ($existing !== null) {
            return $existing;
        }

        $token = $this->calendarService->generateToken();
        $this->tokenRepository->setToken($userAccountId, $token);
        return $token;
    }

    public function regenerateToken(int $userAccountId): string
    {
        $token = $this->calendarService->generateToken();
        $this->tokenRepository->setToken($userAccountId, $token);
        return $token;
    }

    /**
     * Resolve a personal ICS token to its events. Returns an empty array
     * (never an error) for an unknown token or a visitor with no linked
     * animés and no visible supplementary calendars — the ICS feed must
     * still be a syntactically valid, empty calendar in that case.
     *
     * @return CalendarEvent[]
     */
    public function getEventsForToken(string $token, int $scoutYearId): array
    {
        $userAccountId = $this->tokenRepository->findUserAccountIdByToken($token);
        if ($userAccountId === null) {
            return [];
        }

        $userAccount = $this->userAccountRepository->findById($userAccountId);
        if ($userAccount === null) {
            return [];
        }

        $calendarIds = $this->resolveCalendarIdsForEmail($userAccount->email, $scoutYearId);
        if (count($calendarIds) === 0) {
            return [];
        }

        $events = $this->eventRepository->findByCalendarIds($calendarIds);

        if ($this->retroEventLinkLookup === null) {
            return $events;
        }

        // The only ICS feed with a real, identified viewer — calendarFeed()/
        // unitFeed() have no viewer at all, so the retro link must never
        // appear there regardless of a board's configured link_visibility
        // (see Api\RetroEventLinkLookupInterface's docblock).
        $role = Role::fromString($this->roleResolver->resolve($userAccount->email, $scoutYearId));

        return array_map(function (CalendarEvent $event) use ($role, $userAccount, $scoutYearId): CalendarEvent {
            $link = $this->retroEventLinkLookup->findLinkedBoardLink($event->id, $role, $userAccount->email, $scoutYearId);
            if ($link === null) {
                return $event;
            }

            return $this->withAppendedRetroLink($event, $link->url);
        }, $events);
    }

    /**
     * CalendarEvent is a readonly DTO — construct a new instance with the
     * link appended to its description, in plain unescaped text: IcsBuilder
     * escapes the WHOLE description as one unit when it builds the VEVENT
     * block, so appending here (before it ever reaches IcsBuilder) is the
     * only correct place to do this — appending after escaping would
     * double-escape or bypass folding entirely.
     */
    private function withAppendedRetroLink(CalendarEvent $event, string $url): CalendarEvent
    {
        $description = $event->description !== null && $event->description !== ''
            ? $event->description . "\n\nRétrospective : " . $url
            : 'Rétrospective : ' . $url;

        return new CalendarEvent(
            id: $event->id,
            calendarId: $event->calendarId,
            title: $event->title,
            startDate: $event->startDate,
            endDate: $event->endDate,
            startTime: $event->startTime,
            endTime: $event->endTime,
            location: $event->location,
            description: $description,
            sequence: $event->sequence,
            autoCreateRetro: $event->autoCreateRetro,
            createdBy: $event->createdBy,
            updatedAt: $event->updatedAt
        );
    }

    /**
     * The calendar ids behind both the personal ICS feed AND the "Mes
     * évènements" entry on the chefs calendar page (same underlying
     * scope, just reached via a token there and via the logged-in
     * session's own email here) — every supplementary calendar visible to
     * the viewer's effective role, plus the calendar(s) of any section
     * where they have a linked member (by email). A chef d'unité's "Staff
     * d'U" events fall out automatically here with no special-casing:
     * once their function is synced into the real "Staff d'U" section
     * (see Core\Member\UnitStaffSectionService), its desk_code is just
     * another entry in their linked sections, exactly like any real
     * section.
     *
     * @return int[]
     */
    public function resolveCalendarIdsForEmail(string $email, int $scoutYearId): array
    {
        $role = Role::fromString($this->roleResolver->resolve($email, $scoutYearId));
        $linkedMembers = $this->memberService->getLinkedMembers($email, $scoutYearId);

        $linkedSectionCodes = [];
        foreach ($linkedMembers as $member) {
            foreach ($member->functions as $fn) {
                if ($fn->sectionCode !== null) {
                    $linkedSectionCodes[] = $fn->sectionCode;
                }
            }
        }
        $linkedSectionCodes = array_unique($linkedSectionCodes);

        $sectionsById = [];
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            $sectionsById[$section['id']] = $section;
        }

        $calendarIds = [];
        foreach ($this->calendarService->getSectionCalendars() as $calendar) {
            $section = $calendar->sectionId !== null ? ($sectionsById[$calendar->sectionId] ?? null) : null;
            if ($section !== null && in_array($section['desk_code'], $linkedSectionCodes, true)) {
                $calendarIds[] = $calendar->id;
            }
        }

        foreach ($this->calendarService->getSupplementaryCalendars() as $calendar) {
            if ($this->calendarService->isVisibleToRole($calendar, $role)) {
                $calendarIds[] = $calendar->id;
            }
        }

        return array_values(array_unique($calendarIds));
    }
}
