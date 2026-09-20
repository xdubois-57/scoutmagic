<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Repository;

use Core\Security\EncryptionService;
use Modules\OfficialDocuments\Repository\HealthSheetRepository;
use Modules\OfficialDocuments\Value\HealthSheet;
use PHPUnit\Framework\TestCase;

/**
 * The one table holding health data about children, and the guarantees that
 * are worth nothing unless a test says them out loud.
 *
 * The first is that what lands in the column is genuinely unreadable —
 * « encrypted at rest » is a claim about bytes, and the way it fails is
 * that somebody stores the JSON and only *means* to encrypt it. So this
 * suite reads the raw column back and looks for the plaintext.
 *
 * The second is that a family's own words survive the round trip through
 * cipher and JSON unchanged, accents and apostrophes included.
 *
 * **Against the REAL engine**, like
 * `Tests\Core\Storage\Location\StorageLocationRepositoryOnMysqlTest`, and
 * for the same reason: `save()` is one `INSERT … ON DUPLICATE KEY UPDATE`
 * so that two households saving the same child's sheet at once cannot race
 * into a duplicate-key error, and that statement is MySQL's. SQLite would
 * refuse to parse it — so a suite that ran this on SQLite would either fail
 * for the wrong reason or, worse, push the repository towards a
 * read-then-write that has the race back.
 *
 * The table is created here from `modules/official_documents/schema.sql`
 * itself, which also means that file is parsed by the engine that will run
 * it in production rather than only read by a human.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class HealthSheetRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private HealthSheetRepository $repository;
    private int $memberId;

    protected function setUp(): void
    {
        $this->pdo = $this->connect();
        $this->pdo->exec('DROP TABLE IF EXISTS official_documents_health_sheets');
        $this->pdo->exec(self::createTableStatement());

        $this->repository = new HealthSheetRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );

        // A member id that belongs to nobody else in the shared database:
        // the foreign key is stripped below, so this only has to be
        // unique to this file.
        $this->memberId = random_int(1_000_000, 9_999_999);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS official_documents_health_sheets');
    }

    private function connect(): \PDO
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';

        try {
            return new \PDO(
                "mysql:host={$host};port={$port};dbname={$dbName}",
                getenv('TEST_DB_USER') ?: 'root',
                getenv('TEST_DB_PASSWORD') ?: '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            $this->markTestSkipped('Database connection not available: ' . $e->getMessage());
        }
    }

    /**
     * The module's own `schema.sql`, with the foreign key stripped —
     * `members` is not migrated into this shared database, and the
     * constraint is not what this file is about.
     */
    private static function createTableStatement(): string
    {
        $sql = (string) file_get_contents(
            dirname(__DIR__, 4) . '/modules/official_documents/schema.sql'
        );

        // Drop the FK line and the comma that precedes it, so the real
        // column and index declarations are the ones the engine parses.
        return (string) preg_replace('/,\s*\n\s*CONSTRAINT fk_odhs_member[^\n]*\n/', "\n", $sql);
    }

    private static function now(string $when = '2026-09-20 10:00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when);
    }

    private static function sheet(): HealthSheet
    {
        return HealthSheet::fromArray([
            'contact1_name' => 'Xavier Dubois',
            'contact1_phone' => '0470 00 00 00',
            'doctor_last_name' => 'Lhoëst',
            'allergies' => "Arachides, œufs, et l'iode",
            'conditions' => ['asthma' => true],
            'swimming_level' => '25m',
        ]);
    }

    // --- the guarantee that is about bytes ---

    /**
     * What is in the column must not be readable. Stored plaintext is the
     * failure this catches, and nothing at any other layer would notice it:
     * every read goes back through the Repository, which would hand the
     * same answers back either way.
     */
    public function testWhatLandsInTheColumnIsNotReadable(): void
    {
        $this->repository->save($this->memberId, self::sheet(), self::now());

        $raw = (string) $this->pdo
            ->query('SELECT content_encrypted FROM official_documents_health_sheets')
            ->fetchColumn();

        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('Arachides', $raw);
        $this->assertStringNotContainsString('Xavier Dubois', $raw);
        $this->assertStringNotContainsString('asthma', $raw);
        // Nor the JSON scaffolding, which would give away the shape even
        // if the values themselves were somehow hidden.
        $this->assertStringNotContainsString('contact1_name', $raw);
    }

    /**
     * And the family's own words come back exactly as they typed them —
     * through the cipher and through JSON, accents and apostrophe included.
     */
    public function testTheFamilysOwnWordsComeBackUnchanged(): void
    {
        $this->repository->save($this->memberId, self::sheet(), self::now());

        $back = $this->repository->findForMember($this->memberId);

        $this->assertNotNull($back);
        $this->assertSame("Arachides, œufs, et l'iode", $back->allergies);
        $this->assertSame('Lhoëst', $back->doctorLastName);
        $this->assertTrue($back->conditions['asthma']);
        $this->assertSame('25m', $back->swimmingLevel);
    }

    // --- one row per member, replaced in place ---

    public function testAMemberWithNoSheetHasNothingRatherThanAnEmptyOne(): void
    {
        $this->assertNull($this->repository->findForMember($this->memberId));
        $this->assertNull($this->repository->lastUsedAt($this->memberId));
    }

    /**
     * Saving twice replaces, never accumulates: a second row would be a
     * second truth about somebody's health and no screen could choose.
     */
    public function testSavingTwiceReplacesTheSheetRatherThanAddingOne(): void
    {
        $this->repository->save($this->memberId, self::sheet(), self::now());
        $this->repository->save(
            $this->memberId,
            HealthSheet::fromArray(['allergies' => 'Plus aucune']),
            self::now('2026-10-01 09:00:00')
        );

        $count = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM official_documents_health_sheets')
            ->fetchColumn();

        $this->assertSame(1, $count);
        $this->assertSame('Plus aucune', $this->repository->findForMember($this->memberId)?->allergies);
    }

    /**
     * A replacement genuinely drops what it replaces: a merge would leave
     * an allergy on file that the family had removed on purpose, which is
     * the one direction this must never fail in.
     */
    public function testAReplacementDropsWhatTheFamilyRemoved(): void
    {
        $this->repository->save($this->memberId, self::sheet(), self::now());
        $this->repository->save($this->memberId, HealthSheet::empty(), self::now('2026-10-01 09:00:00'));

        $back = $this->repository->findForMember($this->memberId);

        $this->assertNotNull($back);
        $this->assertTrue($back->isEmpty());
        $this->assertSame('', $back->allergies);
        $this->assertFalse($back->conditions['asthma']);
    }

    /**
     * An empty sheet is a stored sheet, not the absence of one. The family
     * chose to clear it; the difference from « never filled in » only shows
     * up here, and the purge (IT-05) reads this row like any other.
     */
    public function testAnEmptySheetIsStillARow(): void
    {
        $this->repository->save($this->memberId, HealthSheet::empty(), self::now());

        $this->assertNotNull($this->repository->findForMember($this->memberId));
        $this->assertNotNull($this->repository->lastUsedAt($this->memberId));
    }

    // --- last_used_at ---

    public function testSavingIsUsingTheSheet(): void
    {
        $this->repository->save($this->memberId, self::sheet(), self::now());

        $this->assertSame(
            '2026-09-20 10:00:00',
            $this->repository->lastUsedAt($this->memberId)?->format('Y-m-d H:i:s')
        );
    }

    /**
     * Generating a document from a sheet is a family relying on it, so it
     * postpones the purge exactly as retyping it would — WITHOUT touching
     * what the sheet says. A `touch()` that rewrote the content would put a
     * child's health data through a cipher for the sake of a date.
     */
    public function testGeneratingADocumentPostponesThePurgeWithoutChangingTheSheet(): void
    {
        $this->repository->save($this->memberId, self::sheet(), self::now());

        $this->repository->touch($this->memberId, self::now('2027-03-12 14:30:00'));

        $this->assertSame(
            '2027-03-12 14:30:00',
            $this->repository->lastUsedAt($this->memberId)?->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            "Arachides, œufs, et l'iode",
            $this->repository->findForMember($this->memberId)?->allergies
        );
    }

    public function testTouchingAMemberWithNoSheetDoesNothingAndDoesNotFail(): void
    {
        $this->repository->touch($this->memberId, self::now());

        $this->assertNull($this->repository->lastUsedAt($this->memberId));
    }

    // --- « Tout effacer » ---

    /**
     * A delete, not a blanking. What the family asked for is that the site
     * stop holding their child's health data, and a row full of empty
     * strings is still a row holding something.
     */
    public function testClearingEverythingRemovesTheRowRatherThanEmptyingIt(): void
    {
        $this->repository->save($this->memberId, self::sheet(), self::now());

        $this->assertTrue($this->repository->delete($this->memberId));

        $count = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM official_documents_health_sheets')
            ->fetchColumn();
        $this->assertSame(0, $count);
        $this->assertNull($this->repository->findForMember($this->memberId));
    }

    /**
     * Answers whether there was anything to remove, so the caller can
     * journal the fact — with the member id and nothing else.
     */
    public function testClearingASheetThatIsNotThereSaysSo(): void
    {
        $this->assertFalse($this->repository->delete($this->memberId));
    }

    // --- the rest ---

    /**
     * Two members never see each other's sheet. Obvious, and the reason to
     * assert it is that the table is keyed on a member id passed in by a
     * caller: the day that key is wrong, this is what says so.
     */
    public function testTwoMembersSheetsStayApart(): void
    {
        $other = $this->memberId + 1;

        $this->repository->save($this->memberId, self::sheet(), self::now());
        $this->repository->save($other, HealthSheet::fromArray(['allergies' => 'Aucune']), self::now());

        $this->assertSame(
            "Arachides, œufs, et l'iode",
            $this->repository->findForMember($this->memberId)?->allergies
        );
        $this->assertSame('Aucune', $this->repository->findForMember($other)?->allergies);
    }

    /**
     * A row whose content cannot be decrypted — a rotated key, a restore
     * from elsewhere — answers null rather than throwing. The family then
     * gets an empty form they can fill in again, and the next save replaces
     * the unreadable row; an exception would give them an error page and no
     * way forward.
     */
    public function testAnUnreadableRowIsAnEmptyFormRatherThanAnErrorPage(): void
    {
        $this->repository->save($this->memberId, self::sheet(), self::now());
        $this->pdo->exec(
            "UPDATE official_documents_health_sheets SET content_encrypted = 'ceci n''est pas du chiffré'"
        );

        $this->assertNull($this->repository->findForMember($this->memberId));

        $this->repository->save($this->memberId, HealthSheet::fromArray(['allergies' => 'Arachides']), self::now());
        $this->assertSame('Arachides', $this->repository->findForMember($this->memberId)?->allergies);
    }
}
