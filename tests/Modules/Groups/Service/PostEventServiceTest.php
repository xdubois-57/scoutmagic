<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Security\Role;
use Modules\Calendar\Api\CalendarEventLookupInterface;
use Modules\Calendar\Api\EventSummary;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\PostEventService;
use PHPUnit\Framework\TestCase;

/**
 * The one module-to-module surface groups has towards calendar
 * (ARCHITECTURE.md §7.5): a nullable interface, and everything degrading
 * to "no event" when it is absent.
 */
class PostEventServiceTest extends TestCase
{
    private function context(Role $role = Role::IDENTIFIED): GroupSessionContext
    {
        return new GroupSessionContext(1, $role, [3], 7, true);
    }

    private function event(int $id): EventSummary
    {
        return new EventSummary($id, 'Réunion', 'Louveteaux', '2026-03-14', '2026-03-14');
    }

    // ---- calendar disabled ----

    public function testWithoutCalendarItOffersNothingAndResolvesNothing(): void
    {
        $service = new PostEventService();

        $this->assertFalse($service->isAvailable());
        $this->assertSame([], $service->options($this->context()));
        $this->assertNull($service->resolveSubmitted(9, Role::IDENTIFIED));
        $this->assertSame([], $service->summariesFor([9], Role::IDENTIFIED));
    }

    // ---- picker ----

    public function testThePickerAsksForAWindowAroundTodayAcrossEverySection(): void
    {
        $lookup = $this->createMock(CalendarEventLookupInterface::class);
        $lookup->expects($this->once())
            ->method('findEventsInWindow')
            // Null section: a group's audience routinely spans sections,
            // and an invitation group has none at all — the viewer's role
            // is what restricts the list.
            ->with($this->anything(), $this->anything(), null, Role::IDENTIFIED)
            ->willReturn([$this->event(9)]);

        $this->assertCount(1, (new PostEventService($lookup))->options($this->context()));
    }

    // ---- what a member submitted ----

    public function testASubmittedIdIsReResolvedRatherThanTrusted(): void
    {
        $lookup = $this->createMock(CalendarEventLookupInterface::class);
        $lookup->expects($this->once())->method('findEventById')->with(9, Role::IDENTIFIED)->willReturn($this->event(9));

        $this->assertSame(9, (new PostEventService($lookup))->resolveSubmitted(9, Role::IDENTIFIED));
    }

    /**
     * The reason it is re-resolved: the calendar refuses an event on a
     * calendar this viewer may not read, and an id typed into the form by
     * hand must not attach one anyway.
     */
    public function testAnEventTheViewerMayNotSeeIsNotAttached(): void
    {
        $lookup = $this->createMock(CalendarEventLookupInterface::class);
        $lookup->method('findEventById')->willReturn(null);

        $this->assertNull((new PostEventService($lookup))->resolveSubmitted(9, Role::IDENTIFIED));
    }

    public function testAMissingOrNonsensicalIdNeverReachesTheCalendar(): void
    {
        $lookup = $this->createMock(CalendarEventLookupInterface::class);
        $lookup->expects($this->never())->method('findEventById');
        $service = new PostEventService($lookup);

        $this->assertNull($service->resolveSubmitted(null, Role::IDENTIFIED));
        $this->assertNull($service->resolveSubmitted(0, Role::IDENTIFIED));
        $this->assertNull($service->resolveSubmitted(-1, Role::IDENTIFIED));
    }

    // ---- rendering a page ----

    public function testAPageAsksOncePerDistinctEventAndSkipsPostsWithNone(): void
    {
        $lookup = $this->createMock(CalendarEventLookupInterface::class);
        $lookup->expects($this->once())->method('findEventById')->with(9)->willReturn($this->event(9));

        $summaries = (new PostEventService($lookup))->summariesFor([9, null, 9, 0], Role::IDENTIFIED);

        $this->assertSame([9], array_keys($summaries));
    }

    /**
     * A stale id — the event was deleted since the post was written —
     * simply resolves to nothing, which is exactly why schema.sql has no
     * foreign key on the column.
     */
    public function testAStaleEventIdResolvesToNothingRatherThanFailing(): void
    {
        $lookup = $this->createMock(CalendarEventLookupInterface::class);
        $lookup->method('findEventById')->willReturn(null);

        $this->assertSame([], (new PostEventService($lookup))->summariesFor([9], Role::IDENTIFIED));
    }
}
