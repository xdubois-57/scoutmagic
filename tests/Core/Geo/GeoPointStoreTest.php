<?php

declare(strict_types=1);

namespace Tests\Core\Geo;

use Core\Geo\GeoPoint;
use Core\Geo\GeoPointStore;
use PHPUnit\Framework\TestCase;

/**
 * The manual lock, against a table of its own — the store knows nothing of
 * camps or carpools, only the four columns every table that carries a point
 * declares.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class GeoPointStoreTest extends TestCase
{
    private \PDO $pdo;
    private GeoPointStore $store;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE pins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            latitude REAL NULL,
            longitude REAL NULL,
            coordinates_are_manual INTEGER NOT NULL DEFAULT 0,
            geocoded_at TEXT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $this->pdo->exec('INSERT INTO pins (id) VALUES (1)');
        $this->store = new GeoPointStore($this->pdo, 'pins');
    }

    /**
     * @return array{latitude: mixed, longitude: mixed, coordinates_are_manual: mixed, geocoded_at: mixed}
     */
    private function row(): array
    {
        /** @var array{latitude: mixed, longitude: mixed, coordinates_are_manual: mixed, geocoded_at: mixed} $row */
        $row = $this->pdo->query('SELECT * FROM pins WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);

        return $row;
    }

    public function testAFoundPointIsRecordedAndStamped(): void
    {
        $this->store->recordGeocoding(1, new GeoPoint(50.44, 5.0), new \DateTimeImmutable('2026-09-24 10:00'));

        $this->assertEqualsWithDelta(50.44, (float) $this->row()['latitude'], 0.000001);
        $this->assertSame('2026-09-24 10:00:00', $this->row()['geocoded_at']);
    }

    public function testAFailedLookupIsStampedWithoutErasingAnExistingPoint(): void
    {
        $this->store->recordGeocoding(1, new GeoPoint(50.44, 5.0), new \DateTimeImmutable('2026-09-01'));

        $this->store->recordGeocoding(1, null, new \DateTimeImmutable('2026-09-24 10:00'));

        // Stamped: without it the row is retried on every run for ever
        // and blocks the one-at-a-time queue behind it.
        $this->assertSame('2026-09-24 10:00:00', $this->row()['geocoded_at']);
        // And no answer is not an answer of « nowhere ».
        $this->assertEqualsWithDelta(50.44, (float) $this->row()['latitude'], 0.000001);
        $this->assertEqualsWithDelta(5.0, (float) $this->row()['longitude'], 0.000001);
    }

    public function testForgettingDropsTheAutomaticPointFoundForTheOldAddress(): void
    {
        $this->store->recordGeocoding(1, new GeoPoint(50.44, 5.0), new \DateTimeImmutable('2026-09-01'));

        $this->store->forgetGeocoding(1);
        // The new address means nothing to the gazetteer.
        $this->store->recordGeocoding(1, null, new \DateTimeImmutable('2026-09-24 10:00'));

        // No pin left at a place the row no longer describes.
        $this->assertNull($this->row()['latitude']);
        $this->assertNull($this->row()['longitude']);
        $this->assertSame('2026-09-24 10:00:00', $this->row()['geocoded_at']);
    }

    public function testForgettingKeepsAManualPoint(): void
    {
        $this->store->setManual(1, new GeoPoint(50.7, 4.6), new \DateTimeImmutable());

        $this->store->forgetGeocoding(1);

        $this->assertEqualsWithDelta(50.7, (float) $this->row()['latitude'], 0.000001);
    }

    public function testAManualPointIsNeverOverwrittenByGeocoding(): void
    {
        $this->store->setManual(1, new GeoPoint(50.720100, 4.641200), new \DateTimeImmutable());

        $this->store->recordGeocoding(1, new GeoPoint(0.0, 0.0), new \DateTimeImmutable());

        $this->assertEqualsWithDelta(50.7201, (float) $this->row()['latitude'], 0.000001);
        $this->assertSame(1, (int) $this->row()['coordinates_are_manual']);
        $this->assertNull($this->row()['geocoded_at']);
    }

    public function testForgettingDoesNotRequeueAManualPoint(): void
    {
        $this->store->recordGeocoding(1, null, new \DateTimeImmutable('2026-09-01'));
        $this->store->setManual(1, new GeoPoint(50.7, 4.6), new \DateTimeImmutable());

        $this->store->forgetGeocoding(1);

        $this->assertSame('2026-09-01 00:00:00', $this->row()['geocoded_at']);
    }

    public function testRemovingAPointByHandStillLocksTheRow(): void
    {
        // Somebody who removed a wrong pin said « no point here »; the next
        // run must not put the wrong one back.
        $this->store->recordGeocoding(1, new GeoPoint(50.44, 5.0), new \DateTimeImmutable());
        $this->store->setManual(1, null, new \DateTimeImmutable());

        $this->assertNull($this->row()['latitude']);
        $this->assertSame(1, (int) $this->row()['coordinates_are_manual']);
    }

    public function testCopyingAnAutomaticPointNeverUnlocksAManualRow(): void
    {
        $this->store->setManual(1, null, new \DateTimeImmutable());

        $this->store->copy(1, new GeoPoint(50.1, 4.1), false, new \DateTimeImmutable());

        $this->assertSame(1, (int) $this->row()['coordinates_are_manual']);
        $this->assertEqualsWithDelta(50.1, (float) $this->row()['latitude'], 0.000001);
    }

    public function testCopyingAManualPointLocksTheRow(): void
    {
        $this->store->copy(1, new GeoPoint(50.1, 4.1), true, new \DateTimeImmutable());

        $this->assertSame(1, (int) $this->row()['coordinates_are_manual']);
    }

    public function testATableNameIsCheckedBeforeItReachesAStatement(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GeoPointStore($this->pdo, 'pins; DROP TABLE pins');
    }
}
