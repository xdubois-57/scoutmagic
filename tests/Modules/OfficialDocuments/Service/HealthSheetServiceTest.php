<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Service;

use Core\Journal\JournalService;
use Modules\OfficialDocuments\Repository\HealthSheetRepository;
use Modules\OfficialDocuments\Service\HealthSheetService;
use Modules\OfficialDocuments\Value\HealthSheet;
use PHPUnit\Framework\TestCase;

/**
 * What is allowed to be written down about a child's health sheet.
 *
 * This is the whole reason the service exists: the Repository owns the
 * cipher, the controller owns the screen, and the one decision neither
 * should make is what reaches the journal. The chantier's rule is absolute
 * — no health data in the journal, not a field, not a count of fields, not
 * « the allergies section was filled in » — and a rule like that is kept by
 * a test or it is not kept at all, because breaking it produces no symptom
 * whatsoever. Nothing turns red. A journal simply starts holding things it
 * should not, and nobody reads it until the day it matters.
 */
final class HealthSheetServiceTest extends TestCase
{
    private const MEMBER_ID = 42;

    private static function sheet(): HealthSheet
    {
        return HealthSheet::fromArray([
            'allergies' => 'Arachides',
            'treatment' => 'Ventoline',
            'conditions' => ['asthma' => true],
        ]);
    }

    // --- the rule ---

    /**
     * An ordinary save is not journaled at all. The only interesting thing
     * to say about it would be what changed, which is exactly what must
     * never be written down — so the honest answer is to say nothing.
     */
    public function testSavingIsNotJournaled(): void
    {
        $journal = $this->createMock(JournalService::class);
        $journal->expects($this->never())->method('log');

        $service = new HealthSheetService($this->createStub(HealthSheetRepository::class), $journal);
        $service->save(self::MEMBER_ID, self::sheet(), new \DateTimeImmutable('2026-09-20'));
    }

    /**
     * « Tout effacer » IS journaled — it destroys data irreversibly, and a
     * site that cannot say *that it happened* cannot answer a family asking
     * why their sheet is gone.
     *
     * But with the member's numeric id and nothing else. This test reads
     * every argument the entry is built from and refuses to find a single
     * thing the family typed, a field name among them: a description that
     * said « allergies et traitement effacés » would be a leak with no
     * symptom.
     */
    public function testClearingIsJournaledWithTheMemberIdAndNothingElse(): void
    {
        $repository = $this->createStub(HealthSheetRepository::class);
        $repository->method('delete')->willReturn(true);

        $journal = $this->createMock(JournalService::class);
        $journal->expects($this->once())
            ->method('log')
            ->with(
                'official_documents',
                'health_sheet_cleared',
                'info',
                $this->callback(static fn(string $description): bool => self::saysNothingAboutHealth($description)),
                $this->callback(static function (array $context): bool {
                    // The id, and only the id.
                    return $context === ['member_id' => self::MEMBER_ID];
                }),
                7
            );

        (new HealthSheetService($repository, $journal))->clear(self::MEMBER_ID, 7);
    }

    /**
     * Neither a field name nor an answer, in any wording the description
     * might take. Checked against the sheet's own key list rather than a
     * list typed here, so a field added to the form is covered the day it
     * is added.
     */
    private static function saysNothingAboutHealth(string $description): bool
    {
        foreach (array_keys(HealthSheet::empty()->toArray()) as $key) {
            if (stripos($description, $key) !== false) {
                return false;
            }
        }

        foreach (['Arachides', 'Ventoline', 'asthma'] as $answer) {
            if (stripos($description, $answer) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Nothing removed, nothing journaled: an entry for a sheet that was not
     * there would say « this family cleared their data » about a family
     * that had none — which is itself a statement about their record.
     */
    public function testClearingASheetThatIsNotThereIsNotJournaled(): void
    {
        $repository = $this->createStub(HealthSheetRepository::class);
        $repository->method('delete')->willReturn(false);

        $journal = $this->createMock(JournalService::class);
        $journal->expects($this->never())->method('log');

        $this->assertFalse((new HealthSheetService($repository, $journal))->clear(self::MEMBER_ID));
    }

    /**
     * The journal is optional at the wiring level, so the service must work
     * without one rather than fataling on a null — an installation with
     * journaling off must still be able to clear a sheet.
     */
    public function testItWorksWithNoJournalAtAll(): void
    {
        $repository = $this->createStub(HealthSheetRepository::class);
        $repository->method('delete')->willReturn(true);

        $this->assertTrue((new HealthSheetService($repository))->clear(self::MEMBER_ID));
    }

    // --- the rest ---

    /**
     * A member with no sheet gets an empty one rather than null, so the
     * screen always has a form to draw and never has to branch.
     */
    public function testAMemberWithNoSheetGetsAnEmptyFormRatherThanNothing(): void
    {
        $repository = $this->createStub(HealthSheetRepository::class);
        $repository->method('findForMember')->willReturn(null);

        $sheet = (new HealthSheetService($repository))->forMember(self::MEMBER_ID);

        $this->assertTrue($sheet->isEmpty());
    }

    public function testAnExistingSheetComesBackAsItIs(): void
    {
        $repository = $this->createStub(HealthSheetRepository::class);
        $repository->method('findForMember')->willReturn(self::sheet());

        $this->assertSame('Arachides', (new HealthSheetService($repository))->forMember(self::MEMBER_ID)->allergies);
    }

    /**
     * Generating a document postpones the purge. Asserted at this layer
     * because IT-04 will call exactly this, and a `markUsed()` that quietly
     * did nothing would shorten every family's retention without a symptom.
     */
    public function testMarkingASheetUsedReachesTheRepository(): void
    {
        $now = new \DateTimeImmutable('2027-03-12 14:30:00');

        $repository = $this->createMock(HealthSheetRepository::class);
        $repository->expects($this->once())->method('touch')->with(self::MEMBER_ID, $now);

        (new HealthSheetService($repository))->markUsed(self::MEMBER_ID, $now);
    }
}
