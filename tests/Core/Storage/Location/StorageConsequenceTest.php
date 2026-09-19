<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location;

use Core\Storage\Location\StorageCapability;
use Core\Storage\Location\StorageConsequence;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;

/**
 * The four sentences an administrator reads, and the comparison IT-07
 * lays them out in.
 *
 * These assert the SHAPE and the AGREEMENT rather than the wording: the
 * words are allowed to improve, but a verdict that contradicts what the
 * code does is the defect this file exists to catch.
 */
final class StorageConsequenceTest extends TestCase
{
    /**
     * **The verdict and the refusal must say the same thing.**
     *
     * `RemoteBackupController::choose()` refuses a destination that
     * cannot resume an upload, and `MaintenanceController` does not offer
     * one in the picker. This sentence used to answer « oui, sans
     * reprise » for exactly those types — telling an administrator that
     * backups would work there, only slower, about a destination the next
     * screen would not accept at all.
     *
     * So the test is not « it says non for S3 »: it is that the verdict
     * is a refusal precisely when the capability the controller requires
     * is absent, for every type there is and every type there will be.
     */
    public function testTheBackupsVerdictRefusesExactlyWhatTheBackupDestinationRefuses(): void
    {
        foreach (StorageLocationType::cases() as $type) {
            $backups = self::areaOf($type, StorageConsequence::AREA_BACKUPS);
            $canResume = $type->supports(StorageCapability::ResumableUpload);

            $this->assertSame(
                $canResume,
                $backups->verdict !== 'non',
                $type->value . ': the verdict must refuse exactly when RemoteBackupController does'
            );
        }
    }

    /**
     * And the refusal names the reason rather than the capability, which
     * is D3's whole point: an administrator is not choosing a storage on
     * resumable uploads.
     */
    public function testTheBackupsRefusalExplainsItselfWithoutNamingTheCapability(): void
    {
        $type = self::firstTypeWithout(StorageCapability::ResumableUpload);
        if ($type === null) {
            self::markTestSkipped('every type can resume an upload — nothing to refuse');
        }

        $backups = self::areaOf($type, StorageConsequence::AREA_BACKUPS);

        $this->assertSame('non', $backups->verdict);
        $this->assertStringNotContainsStringIgnoringCase('capacité', $backups->detail);
        $this->assertNotSame('', trim($backups->detail), 'a refusal must say why');
    }

    /**
     * The video verdict is the other real refusal, and the gallery blocks
     * the upload on the same condition — see
     * `MediaService::assertTheAlbumCanServeAVideo()`.
     */
    public function testTheVideoVerdictRefusesExactlyWhatTheGalleryRefuses(): void
    {
        foreach (StorageLocationType::cases() as $type) {
            $videos = self::areaOf($type, StorageConsequence::AREA_VIDEOS);

            $this->assertSame(
                $type->supports(StorageCapability::RangeRead),
                $videos->verdict !== 'non',
                $type->value . ': the verdict must refuse exactly when the upload does'
            );
        }
    }

    /**
     * **The comparison covers every type the enum knows**, which is what
     * keeps a fifth kind of storage from arriving invisibly: it is built
     * from `cases()` rather than from a list somebody has to remember.
     */
    public function testTheComparisonHasOneRowPerQuestionAndOneCellPerType(): void
    {
        $rows = StorageConsequence::comparison();

        $this->assertCount(4, $rows, 'four questions, the ones the mockup found');
        $this->assertSame(
            [
                StorageConsequence::AREA_PHOTOS,
                StorageConsequence::AREA_VIDEOS,
                StorageConsequence::AREA_BACKUPS,
                StorageConsequence::AREA_SPACE,
            ],
            array_column($rows, 'area')
        );

        $types = StorageLocationType::cases();
        foreach ($rows as $row) {
            $this->assertCount(count($types), $row['cells'], $row['area'] . ': one cell per type');
            $this->assertSame(
                $types,
                array_column($row['cells'], 'type'),
                $row['area'] . ': the columns follow the enum\'s own order'
            );
        }
    }

    /**
     * And every cell is the per-location reading of the same question, so
     * the table cannot drift from the sentence the location's own card
     * prints.
     */
    public function testEveryCellMatchesThePerTypeReadingOfTheSameQuestion(): void
    {
        foreach (StorageConsequence::comparison() as $row) {
            foreach ($row['cells'] as $cell) {
                $consequence = self::areaOf($cell['type'], $row['area']);

                $this->assertSame($consequence->verdict, $cell['verdict']);
                $this->assertSame($consequence->detail, $cell['detail']);
            }
        }
    }

    /** No cell is ever blank — an empty column reads as « unknown ». */
    public function testNoCellIsEmpty(): void
    {
        foreach (StorageConsequence::comparison() as $row) {
            $this->assertNotSame('', trim($row['label']));
            foreach ($row['cells'] as $cell) {
                $this->assertNotSame('', trim($cell['verdict']), $row['area']);
                $this->assertNotSame('', trim($cell['detail']), $row['area']);
            }
        }
    }

    private static function areaOf(StorageLocationType $type, string $area): StorageConsequence
    {
        foreach (StorageConsequence::forType($type) as $consequence) {
            if ($consequence->area === $area) {
                return $consequence;
            }
        }

        throw new \LogicException($type->value . ' has no reading for ' . $area);
    }

    private static function firstTypeWithout(StorageCapability $capability): ?StorageLocationType
    {
        foreach (StorageLocationType::cases() as $type) {
            if (!$type->supports($capability)) {
                return $type;
            }
        }

        return null;
    }
}
