<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Service;

use Core\Member\SectionService;
use Core\Service\TextNormalizerService;
use Modules\Calendar\Api\SectionEvent;
use Modules\Calendar\Api\SectionEventLookupInterface;
use Modules\Presences\Repository\PresenceRepository;
use Modules\Presences\Value\PresenceStatus;

/**
 * Assembles one evening's sheet, and is the single place the module turns
 * « an event id somebody asked for » into « a sheet this account may
 * touch ».
 *
 * The whole boundary is here, in one method, on purpose: every screen and
 * every write endpoint goes through resolveEvent(), so there is one place
 * to read when asking whether an animateur of the Louveteaux 1 can reach
 * the Éclaireurs' evening. It answers null for all three refusals at once
 * — no such event, an event that is not a section's, a section this
 * account does not staff — because a caller able to tell them apart could
 * map out which ids exist.
 */
class PresenceSheetService
{
    public function __construct(
        private SectionEventLookupInterface $sectionEventLookup,
        private PresenceAuthorizationService $authorization,
        private SectionService $sectionService,
        private PresenceRepository $repository
    ) {
    }

    /**
     * The event behind an id, if and only if this account staffs the
     * section it belongs to. Null for every other case — see the class
     * docblock for why they are not told apart.
     */
    public function resolveEvent(int $eventId, string $email, string $role, int $scoutYearId): ?SectionEvent
    {
        $event = $this->sectionEventLookup->findSectionEvent($eventId);
        if ($event === null) {
            return null;
        }

        if (!$this->authorization->maySeeSection($email, $role, $scoutYearId, $event->sectionId)) {
            return null;
        }

        return $event;
    }

    /**
     * The whole sheet, or null when resolveEvent() refuses.
     *
     * The animés it offers come from the section's composition **at the
     * effective scout year**, never from a list frozen when the event was
     * created: somebody who joined in November appears on October's sheet
     * as « non renseigné », which is what that evening actually knows
     * about them.
     */
    public function buildSheet(int $eventId, string $email, string $role, int $scoutYearId): ?Sheet
    {
        $event = $this->resolveEvent($eventId, $email, $role, $scoutYearId);
        if ($event === null) {
            return null;
        }

        $section = $this->sectionService->getSection($event->sectionId);
        $records = $this->repository->findByEvent($eventId);

        $lines = [];
        foreach ($this->sectionService->getSectionAnimes($event->sectionId, $scoutYearId) as $profile) {
            $record = $records[$profile->memberId] ?? null;
            $lines[] = new SheetLine(
                memberId: $profile->memberId,
                lastName: $profile->lastName,
                firstName: $profile->firstName,
                totem: $profile->totem,
                // No row is « non renseigné » — the fourth state is the
                // absence of a decision, not a value somebody stored.
                status: $record !== null ? $record->status : PresenceStatus::UNSET,
                comment: $record?->comment
            );
        }

        return new Sheet(
            eventId: $event->id,
            title: $event->title,
            startDate: $event->startDate,
            startTime: $event->startTime,
            sectionId: $event->sectionId,
            sectionLabel: self::sectionLabel($section),
            sectionColor: $section !== null ? SectionService::colorForSection($section) : null,
            lines: self::sortedByName($lines)
        );
    }

    /**
     * Record one animé's state, keeping whatever comment is already
     * beside them.
     *
     * **A state and a comment are written separately, never as one
     * payload, and the write touches only its own column**
     * (`PresenceRepository::saveStatus()`). Two animateurs pointing the
     * same list at the same moment is the ordinary case in a local, and a
     * save carrying both fields would silently overwrite the other one's
     * work — the same concurrency rule the Départs page states for its
     * own two fields.
     *
     * @return bool false for every refusal, worded identically by the
     *         caller so the endpoint cannot be used to map out which
     *         events exist or which animés belong to which section
     */
    public function recordStatus(
        int $eventId,
        int $memberId,
        PresenceStatus $status,
        string $email,
        string $role,
        int $scoutYearId,
        ?int $userId
    ): bool {
        if (!$this->mayRecord($eventId, $memberId, $email, $role, $scoutYearId)) {
            return false;
        }

        $this->repository->saveStatus($eventId, $memberId, $status, $userId);

        return true;
    }

    /**
     * Record what the staff wrote beside one animé, keeping the state
     * they are already in — see recordStatus() for why the two never
     * travel together.
     *
     * Nobody has pointed this animé yet? The row is created « non
     * renseigné », which is what that evening still knows about them.
     */
    public function recordComment(
        int $eventId,
        int $memberId,
        ?string $comment,
        string $email,
        string $role,
        int $scoutYearId,
        ?int $userId
    ): bool {
        if (!$this->mayRecord($eventId, $memberId, $email, $role, $scoutYearId)) {
            return false;
        }

        $this->repository->saveComment($eventId, $memberId, $comment, $userId);

        return true;
    }

    /**
     * Both halves of a write, re-derived server-side on every call: the
     * event's own section, and whether this animé is one of ITS animés.
     *
     * The second half is not redundant. Without it, an event id of a
     * section this account staffs and a member id of a section it does
     * not could be combined into a write neither of them alone allows —
     * and the row created would then surface on somebody else's screens.
     * Nothing the page submitted is trusted for either.
     */
    private function mayRecord(
        int $eventId,
        int $memberId,
        string $email,
        string $role,
        int $scoutYearId
    ): bool {
        $event = $this->resolveEvent($eventId, $email, $role, $scoutYearId);
        if ($event === null) {
            return false;
        }

        return in_array($memberId, $this->animeMemberIds($event->sectionId, $scoutYearId), true);
    }

    /**
     * Alphabetical by surname then first name, accents and case folded
     * through the site's one comparison form
     * (`Core\Service\TextNormalizerService::fold()`, ARCHITECTURE.md §8.0)
     * — never `strcasecmp` on the raw value, which would file « Éclaire »
     * after « Zwart » on one host and not on another.
     *
     * A roll call is read down the page while names are called out, so the
     * order has to be the order of a class list, not of display names.
     *
     * @param list<SheetLine> $lines
     * @return list<SheetLine>
     */
    private static function sortedByName(array $lines): array
    {
        usort(
            $lines,
            static fn(SheetLine $a, SheetLine $b): int
                => [TextNormalizerService::fold($a->lastName), TextNormalizerService::fold($a->firstName)]
                <=> [TextNormalizerService::fold($b->lastName), TextNormalizerService::fold($b->firstName)]
        );

        return $lines;
    }

    /**
     * A section with no name of its own shows its Desk code, exactly as
     * `partials/section_picker.html.twig` does — never an empty heading.
     *
     * @param array<string, mixed>|null $section
     */
    public static function sectionLabel(?array $section): string
    {
        if ($section === null) {
            return 'Section';
        }

        $name = $section['name'] ?? null;

        return is_string($name) && trim($name) !== '' ? $name : (string) $section['desk_code'];
    }

    /**
     * The persistent member ids of a section's animés at a given year —
     * the set every write is checked against, so that an event id and a
     * member id of two different sections cannot be combined into a write
     * neither of them alone would allow.
     *
     * The lightweight core read on purpose
     * (`SectionService::getSectionAnimeMemberIds()`, one query, nothing
     * decrypted): this runs on every tap of a sheet, and answering it
     * through getSectionAnimes() would hydrate and decrypt the whole
     * section for each of twenty-five names — the same trap the Départs
     * write path already hit.
     *
     * @return list<int>
     */
    public function animeMemberIds(int $sectionId, int $scoutYearId): array
    {
        return array_values($this->sectionService->getSectionAnimeMemberIds($sectionId, $scoutYearId));
    }
}
