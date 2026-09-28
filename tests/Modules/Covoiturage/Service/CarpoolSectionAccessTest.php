<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Service;

use Core\Security\Role;
use Modules\Covoiturage\Repository\Carpool;
use Modules\Covoiturage\Repository\CarpoolEvent;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Covoiturage\CovoiturageTestHelper as H;

/**
 * **Who sees a carpool's passengers, now that the answer is a union
 * (issue #650).**
 *
 * `Carpool::sectionIds()` used to return the events' sections OR the one a
 * chief had picked from the « Section concernée » field, never both. The
 * field is gone; the section is the creator's own, taken once at creation;
 * and the two sets are unioned. That widens D3, so each case the issue
 * listed is pinned here rather than left to follow from the expression.
 *
 * These are deliberately built as plain objects, with no database: the
 * decision is entirely in Carpool::sectionIds() and CarpoolViewer, and a
 * fixture would only put rows between the reader and what is being
 * asserted. What lands in the section column is CarpoolServiceTest's
 * subject instead.
 */
final class CarpoolSectionAccessTest extends TestCase
{
    private const CREATOR_ACCOUNT = 7;
    private const CREATOR_SECTION = 3;
    private const EVENT_SECTION = 11;
    private const OTHER_SECTION = 99;

    /** A carpool as #650 saves them: the creator's section, plus events. */
    private function carpool(?int $sectionId, bool $withEvent): Carpool
    {
        return new Carpool(
            1,
            'Plaine de Basse-Wavre',
            null,
            false,
            H::day(10),
            null,
            $sectionId,
            self::CREATOR_ACCOUNT,
            $withEvent
                ? [new CarpoolEvent(501, "Fête d'unité", self::EVENT_SECTION, 'Baladins')]
                : []
        );
    }

    /**
     * Case 1 of the issue — with events. The creator's section is ADDED to
     * the events', not chosen instead of them: both staffs see the
     * passengers. This is the case that did not exist before #650, since a
     * carpool with events ignored the section field entirely.
     */
    public function testWithEventsBothTheCreatorsSectionAndTheEventsSeeThePassengers(): void
    {
        $carpool = $this->carpool(self::CREATOR_SECTION, true);

        $this->assertSame([self::CREATOR_SECTION, self::EVENT_SECTION], $carpool->sectionIds());
        $this->assertTrue(
            H::viewer(20, Role::CHIEF, [self::CREATOR_SECTION])->seesPassengersOf($carpool),
            "the creator's own section lost sight of a carpool it organised"
        );
        $this->assertTrue(
            H::viewer(21, Role::CHIEF, [self::EVENT_SECTION])->seesPassengersOf($carpool),
            "an event's section stopped seeing the passengers it always saw"
        );
    }

    /**
     * Case 2 — no event at all. Where a chief used to have to name a
     * section, the creator's own now answers, and its staff sees the
     * passengers without anybody having chosen anything.
     */
    public function testWithoutEventsTheCreatorsSectionSeesThePassengers(): void
    {
        $carpool = $this->carpool(self::CREATOR_SECTION, false);

        $this->assertSame([self::CREATOR_SECTION], $carpool->sectionIds());
        $this->assertTrue(H::viewer(20, Role::CHIEF, [self::CREATOR_SECTION])->seesPassengersOf($carpool));
    }

    /**
     * Case 3 — a creator with no section. No section is substituted, so
     * NOBODY gains access through this door: the carpool stays with its
     * creator and the Staff d'U. The alternative — falling back to « the
     * first available section », which is what
     * SectionPickerHelper::resolveDefault() does for display — would have
     * handed these passengers to a section at random, and that is the
     * mistake #650 asked to avoid.
     */
    public function testACarpoolWithNoSectionIsSeenOnlyByItsCreatorAndTheUnitStaff(): void
    {
        $carpool = $this->carpool(null, false);

        $this->assertSame([], $carpool->sectionIds());
        $this->assertFalse(
            H::viewer(20, Role::CHIEF, [self::CREATOR_SECTION])->seesPassengersOf($carpool),
            'a section nobody named was given the passengers'
        );
        $this->assertFalse(
            H::viewer(20, Role::CHIEF, [self::OTHER_SECTION])->seesPassengersOf($carpool)
        );
        // Its creator still organises it, and the Staff d'U still sees all.
        $this->assertTrue(H::viewer(self::CREATOR_ACCOUNT, Role::CHIEF)->mayOrganize($carpool));
        $this->assertTrue(H::viewer(30, Role::ADMIN)->seesPassengersOf($carpool));
    }

    /**
     * Case 4 — a chief of some third section sees nothing, whichever shape
     * the carpool has. The union adds ONE section, the creator's; it is not
     * an opening of the passengers to every animateur of the unit.
     */
    public function testAChiefOfAThirdSectionSeesNoPassengers(): void
    {
        $stranger = H::viewer(40, Role::CHIEF, [self::OTHER_SECTION]);

        foreach ([
            'with events' => $this->carpool(self::CREATOR_SECTION, true),
            'without events' => $this->carpool(self::CREATOR_SECTION, false),
            'without a section' => $this->carpool(null, true),
        ] as $shape => $carpool) {
            $this->assertFalse(
                $stranger->seesPassengersOf($carpool),
                'a chief of an unrelated section saw the passengers of a carpool ' . $shape
            );
            $this->assertFalse(
                $stranger->mayOrganize($carpool),
                'a chief of an unrelated section could edit a carpool ' . $shape
            );
        }
    }

    /**
     * Case 5 — the carpools that predate #650, which the issue decided not
     * to migrate. Two shapes existed, and the new expression must return
     * exactly what the old one did for both.
     *
     * With events, the section column was always NULL: the events' sections
     * decide, alone. Without events, the column held the section a chief
     * had picked by hand: that section decides, alone. The union changes
     * neither, because in each shape one of its two halves is empty — which
     * is why « les covoiturages existants ne sont pas touchés » needed no
     * migration and no special case in the code.
     */
    public function testCarpoolsCreatedBeforeTheChangeAreUnaffected(): void
    {
        $withEvents = $this->carpool(null, true);
        $this->assertSame([self::EVENT_SECTION], $withEvents->sectionIds());
        $this->assertTrue(H::viewer(21, Role::CHIEF, [self::EVENT_SECTION])->seesPassengersOf($withEvents));
        $this->assertFalse(H::viewer(20, Role::CHIEF, [self::CREATOR_SECTION])->seesPassengersOf($withEvents));

        // A hand-picked section on a carpool with no event: unchanged too.
        $handPicked = $this->carpool(self::OTHER_SECTION, false);
        $this->assertSame([self::OTHER_SECTION], $handPicked->sectionIds());
        $this->assertTrue(H::viewer(40, Role::CHIEF, [self::OTHER_SECTION])->seesPassengersOf($handPicked));
    }

    /**
     * An animé or a parent never sees the passengers, whatever section they
     * happen to be attached to — the union is about which STAFF sees, and
     * the role check comes first.
     */
    public function testTheUnionNeverGivesPassengersToSomebodyWhoIsNotStaff(): void
    {
        $carpool = $this->carpool(self::CREATOR_SECTION, true);

        foreach ([Role::IDENTIFIED, Role::INTENDANT] as $role) {
            $this->assertFalse(
                H::viewer(50, $role, [self::CREATOR_SECTION, self::EVENT_SECTION])->seesPassengersOf($carpool),
                'role ' . $role->value . ' was handed the passengers by the section union'
            );
        }
    }
}
