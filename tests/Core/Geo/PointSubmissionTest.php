<?php

declare(strict_types=1);

namespace Tests\Core\Geo;

use Core\Geo\GeoPoint;
use Core\Geo\GeoPointException;
use Core\Geo\PointSubmission;
use PHPUnit\Framework\TestCase;

/**
 * What a form carrying the core's point picker meant (#1353): the carpool's
 * rules (#642, #692), now the core's so that every module reads its point
 * the same way.
 */
final class PointSubmissionTest extends TestCase
{
    private const ADDRESS = 'Rue des Grottes 12, Han';

    public function testAPointTypedWithoutTheScriptIsAHumansPoint(): void
    {
        $submission = PointSubmission::fromInput(['latitude' => '50,125', 'longitude' => '5.187'], self::ADDRESS);

        $this->assertSame('50.125000, 5.187000', $submission->point?->line());
        $this->assertTrue($submission->given);
        $this->assertFalse($submission->automatic);
        $this->assertFalse($submission->removed);
    }

    public function testAFormWithoutTheFieldsCarriesNoPoint(): void
    {
        $submission = PointSubmission::fromInput(['address' => self::ADDRESS], self::ADDRESS);

        $this->assertNull($submission->point);
        $this->assertFalse($submission->given);
        $this->assertFalse($submission->changes(new GeoPoint(50.0, 4.0), false, true));
    }

    public function testAnAutomaticPinFoundForThisAddressIsKeptAsAutomatic(): void
    {
        $submission = PointSubmission::fromInput([
            'latitude' => '50.125', 'longitude' => '5.187',
            'point_automatic' => '1', 'point_address' => 'rue des grottes 12, HAN',
        ], self::ADDRESS);

        $this->assertNotNull($submission->point);
        $this->assertTrue($submission->given);
        $this->assertTrue($submission->automatic);
    }

    /** Sent before the lookup of the new address answered: not this address's point. */
    public function testAnAutomaticPinFoundForAnotherAddressIsDropped(): void
    {
        $submission = PointSubmission::fromInput([
            'latitude' => '50.125', 'longitude' => '5.187',
            'point_automatic' => '1', 'point_address' => 'Plaine de Basse-Wavre',
        ], self::ADDRESS);

        $this->assertNull($submission->point);
        $this->assertFalse($submission->given);
        $this->assertFalse($submission->removed);
    }

    public function testRemovingThePinIsAHumansNoPoint(): void
    {
        $submission = PointSubmission::fromInput([
            'latitude' => '', 'longitude' => '', 'point_automatic' => '0', 'point_manual' => '1',
        ], self::ADDRESS);

        $this->assertNull($submission->point);
        $this->assertTrue($submission->removed);
        $this->assertTrue($submission->changes(null, false, false), 'a « no point » the task has not found yet');
        $this->assertFalse($submission->changes(null, true, false), 'already a human\'s « no point »');
        $this->assertTrue($submission->changes(new GeoPoint(50.0, 4.0), true, false));
    }

    /** An automatic pin dropped for a stale address posts empty fields: not a removal. */
    public function testEmptyAutomaticFieldsAreNotARemoval(): void
    {
        $submission = PointSubmission::fromInput([
            'latitude' => '', 'longitude' => '', 'point_automatic' => '1', 'point_manual' => '0',
        ], self::ADDRESS);

        $this->assertFalse($submission->removed);
    }

    public function testThePointPostedBackUntouchedChangesNothing(): void
    {
        $saved = new GeoPoint(50.125, 5.187);
        $submission = PointSubmission::fromInput(['latitude' => '50.125000', 'longitude' => '5.187000'], self::ADDRESS);

        $this->assertFalse($submission->changes($saved, false, false));
        $this->assertTrue(
            PointSubmission::fromInput(['latitude' => '50.2', 'longitude' => '5.187'], self::ADDRESS)
                ->changes($saved, false, false)
        );
    }

    /** After a change of address, the module forgot the old point: an automatic one is written back. */
    public function testAnAutomaticPointForANewAddressIsWrittenEvenOnTheSameSpot(): void
    {
        $saved = new GeoPoint(50.125, 5.187);
        $submission = PointSubmission::fromInput([
            'latitude' => '50.125', 'longitude' => '5.187',
            'point_automatic' => '1', 'point_address' => self::ADDRESS,
        ], self::ADDRESS);

        $this->assertTrue($submission->changes($saved, false, true));
        $this->assertFalse($submission->changes($saved, false, false));
    }

    public function testRefusedCoordinatesAreSaidByGeoPoint(): void
    {
        $this->expectException(GeoPointException::class);

        PointSubmission::fromInput(['latitude' => '50.1', 'longitude' => ''], self::ADDRESS);
    }

    public function testShowsManual(): void
    {
        $saved = new GeoPoint(50.125, 5.187);

        $this->assertTrue(PointSubmission::showsManual([], null, true), 'a saved human\'s point');
        $this->assertFalse(PointSubmission::showsManual([], $saved, false), 'a first display of an automatic point');
        $this->assertTrue(PointSubmission::showsManual(['point_manual' => '1'], null, false), 'the script said so');
        $this->assertFalse(
            PointSubmission::showsManual(['latitude' => '50.2', 'longitude' => '5.1', 'point_automatic' => '1'], $saved, false),
            'an automatic pin'
        );
        $this->assertFalse(PointSubmission::showsManual(['latitude' => ''], $saved, false), 'no point posted');
        $this->assertFalse(
            PointSubmission::showsManual(['latitude' => '50.125000', 'longitude' => '5.187000'], $saved, false),
            'the saved automatic point posted back without the script'
        );
        $this->assertTrue(
            PointSubmission::showsManual(['latitude' => '50.2', 'longitude' => '5.1'], $saved, false),
            'typed without the script'
        );
        $this->assertTrue(
            PointSubmission::showsManual(['latitude' => '50.2', 'longitude' => 'x'], $saved, false),
            'unreadable, and not the saved point'
        );
    }
}
