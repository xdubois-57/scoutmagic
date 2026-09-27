<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Modules\Rental\Document\ConditionsVersion;
use Modules\Rental\Repository\RentalConditionsVersionRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The conditions archive's insert-or-read-back, on the engine production
 * runs (issue #494).
 *
 * `archive()` reads by hash, inserts when nothing is there, and treats a
 * duplicate on the unique (asset, hash) index as the other visitor having
 * won the race: SQLSTATE 23000 is caught and their row read back. That is
 * the rule `AGENTS.md` § Database names for an upsert — the decision rests
 * on what the engine raises, so it is judged on the engine.
 */
#[Group('database')]
final class RentalConditionsVersionRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->pdo->prepare(
            "INSERT INTO rental_assets (asset_type, name, slug, quantity, is_public) VALUES ('Local', 'Local', 'local', 1, 1)"
        )->execute();
        $this->assetId = (int) $this->pdo->lastInsertId();
    }

    /**
     * The premise: a second row for the same asset and text is refused with
     * the SQLSTATE the repository catches. If this engine ever answered
     * otherwise, the race would surface as a server error on a public page.
     */
    public function testThisEngineRefusesASecondRowForTheSameTextWithSqlstate23000(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO rental_conditions_versions (asset_id, version, text_hash, body_html, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $row = [$this->assetId, str_repeat('a', 12), str_repeat('a', 64), '<p>x</p>', '2026-09-27 10:00:00'];
        $insert->execute($row);

        try {
            $insert->execute($row);
            $this->fail('the unique (asset_id, text_hash) index did not refuse a duplicate');
        } catch (\PDOException $e) {
            $this->assertSame('23000', $e->getCode());
        }
    }

    public function testAWordingIsArchivedOnceAndReadBack(): void
    {
        $repository = new RentalConditionsVersionRepository($this->pdo);
        $hash = hash('sha256', '<p>Balayé.</p>');

        $first = $repository->archive($this->assetId, $hash, '<p>Balayé.</p>', new \DateTimeImmutable('2026-09-27 10:00:00'), null);
        $again = $repository->archive($this->assetId, $hash, '<p>Balayé.</p>', new \DateTimeImmutable('2026-09-28 10:00:00'), null);

        $this->assertSame(substr($hash, 0, 12), $first->version);
        $this->assertSame('2026-09-27 10:00:00', $again->createdAt->format('Y-m-d H:i:s'), 'the first archive stands');
        $this->assertSame('<p>Balayé.</p>', $repository->findByVersion($this->assetId, $first->version)?->html);
    }

    /**
     * The race itself. Its window — both visitors' reads finding nothing,
     * then both inserting — cannot be interleaved from one test, so the
     * losing visitor is played by a repository whose first read misses, as
     * it would have a moment earlier; the winner's row is already there.
     * The duplicate INSERT then really goes to the engine, and the loser
     * must come back with the winner's version rather than an error.
     */
    public function testTheLoserOfTheRaceReadsTheWinnersRowBack(): void
    {
        $hash = hash('sha256', '<p>Lavé.</p>');
        (new RentalConditionsVersionRepository($this->pdo))
            ->archive($this->assetId, $hash, '<p>Lavé.</p>', new \DateTimeImmutable('2026-09-27 10:00:00'), null);

        $loser = new class ($this->pdo) extends RentalConditionsVersionRepository {
            private bool $missedOnce = false;

            public function findByHash(int $assetId, string $hash): ?ConditionsVersion
            {
                if (!$this->missedOnce) {
                    $this->missedOnce = true;

                    return null;
                }

                return parent::findByHash($assetId, $hash);
            }
        };

        $version = $loser->archive($this->assetId, $hash, '<p>Lavé.</p>', new \DateTimeImmutable('2026-09-27 10:00:01'), null);

        $this->assertSame('2026-09-27 10:00:00', $version->createdAt->format('Y-m-d H:i:s'), 'the winner\'s row');
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM rental_conditions_versions WHERE asset_id = ?');
        $count->execute([$this->assetId]);
        $this->assertSame(1, (int) $count->fetchColumn());
    }

    /** Only a duplicate is the race; any other failure is still a failure. */
    public function testAFailureThatIsNotADuplicateStillSurfaces(): void
    {
        $this->expectException(\PDOException::class);

        // No such asset: the foreign key refuses it, which is SQLSTATE 23000
        // too on this engine — but with nothing to read back, so the
        // repository must not pretend it archived anything.
        (new RentalConditionsVersionRepository($this->pdo))
            ->archive($this->assetId + 1000, hash('sha256', 'x'), 'x', new \DateTimeImmutable(), null);
    }
}
