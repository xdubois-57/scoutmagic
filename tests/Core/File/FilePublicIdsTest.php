<?php

declare(strict_types=1);

namespace Tests\Core\File;

use Core\File\FilePublicIds;
use Core\File\FileRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Issue #1109: the opaque identifier every file is served under, and the
 * address builder every page goes through.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FilePublicIdsTest extends TestCase
{
    private \PDO $pdo;
    private FileRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new FileRepository($this->pdo);
        FilePublicIds::useRepository($this->repository);
    }

    protected function tearDown(): void
    {
        FilePublicIds::useRepository(null);
    }

    public function testAGeneratedIdIs128RandomBitsInBase64Url(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $id = FilePublicIds::generate();
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $id);
            $this->assertTrue(FilePublicIds::isWellFormed($id));
            $seen[$id] = true;
        }
        $this->assertCount(200, $seen);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformed(): iterable
    {
        yield 'a number' => ['123'];
        yield 'too short' => [str_repeat('a', 21)];
        yield 'too long' => [str_repeat('a', 23)];
        yield 'padding' => [str_repeat('a', 20) . '=='];
        yield 'a slash' => [str_repeat('a', 21) . '/'];
        yield 'a dot' => [str_repeat('a', 21) . '.'];
        yield 'empty' => [''];
        yield 'a newline after' => [str_repeat('a', 22) . "\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function testMalformedValuesAreNeverWellFormed(string $value): void
    {
        $this->assertFalse(FilePublicIds::isWellFormed($value));
        $this->assertNull($this->repository->findByPublicId($value));
    }

    public function testEveryNewFileGetsItsOwnPublicIdNeverDerivedFromItsId(): void
    {
        $a = $this->repository->create('a.jpg', 'a.jpg', 'image/jpeg', 1, 'public', null, null);
        $b = $this->repository->create('b.jpg', 'b.jpg', 'image/jpeg', 1, 'public', null, null);

        $publicA = $this->repository->findById($a)?->publicId;
        $publicB = $this->repository->findById($b)?->publicId;

        $this->assertNotNull($publicA);
        $this->assertNotNull($publicB);
        $this->assertNotSame($publicA, $publicB);
        $this->assertSame($a, $this->repository->findByPublicId($publicA)?->id);
    }

    /**
     * The lookup is exact: a public id differing only by case is another id.
     */
    public function testTheLookupIsCaseExact(): void
    {
        $id = $this->repository->create('c.jpg', 'c.jpg', 'image/jpeg', 1, 'public', null, null);
        $public = (string) $this->repository->findById($id)?->publicId;
        $other = strtolower($public) === $public ? strtoupper($public) : strtolower($public);

        $this->assertNull($this->repository->findByPublicId($other));
    }

    public function testUrlForBuildsTheOpaqueAddressWithItsVariant(): void
    {
        $id = $this->repository->create('d.jpg', 'd.jpg', 'image/jpeg', 1, 'public', null, null);
        $public = (string) $this->repository->findById($id)?->publicId;

        $this->assertSame('/files/' . $public, FilePublicIds::urlFor($id));
        $this->assertSame('/files/' . $public . '/md', FilePublicIds::urlFor((string) $id, 'md'));
        $this->assertSame('', FilePublicIds::urlFor(null));
        $this->assertSame('', FilePublicIds::urlFor(0));
        $this->assertSame('', FilePublicIds::urlFor(''));
    }

    /**
     * Transition only: a file not yet reached by the backfill keeps its
     * numeric address, which the route still answers.
     */
    public function testAFileWithoutAPublicIdYetKeepsItsNumericAddress(): void
    {
        $id = $this->insertLegacyFile('legacy.jpg');

        $this->assertSame('/files/' . $id . '/thumb', FilePublicIds::urlFor($id, 'thumb'));
    }

    public function testPrimeResolvesAPageInOneQueryAndRemembersMisses(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->repository->create("p$i.jpg", "p$i.jpg", 'image/jpeg', 1, 'public', null, null);
        }
        FilePublicIds::prime([...$ids, 9999]);

        // The rows are gone; what was resolved is still answered.
        $this->pdo->exec('DELETE FROM files');

        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('#^/files/[A-Za-z0-9_-]{22}$#', FilePublicIds::urlFor($id));
        }
        $this->assertSame('/files/9999', FilePublicIds::urlFor(9999));
    }

    public function testWithoutARepositoryAddressesStayNumeric(): void
    {
        FilePublicIds::useRepository(null);

        $this->assertSame('/files/12/md', FilePublicIds::urlFor(12, 'md'));
        $this->assertNull(FilePublicIds::forFileId(12));
    }

    public function testTheBackfillGivesEveryOlderFileAnIdInBatches(): void
    {
        $ids = [];
        for ($i = 0; $i < 7; $i++) {
            $ids[] = $this->insertLegacyFile("old$i.jpg");
        }

        $this->assertSame(3, $this->repository->backfillPublicIds(3));
        $this->assertSame(3, $this->repository->backfillPublicIds(3));
        $this->assertSame(1, $this->repository->backfillPublicIds(3));
        $this->assertSame(0, $this->repository->backfillPublicIds(3));

        $publicIds = $this->repository->publicIdsFor($ids);
        $this->assertCount(7, $publicIds);
        $this->assertCount(7, array_unique($publicIds));
        foreach ($publicIds as $public) {
            $this->assertTrue(FilePublicIds::isWellFormed($public));
        }
    }

    public function testTheBackfillNeverReplacesAnExistingId(): void
    {
        $id = $this->repository->create('kept.jpg', 'kept.jpg', 'image/jpeg', 1, 'public', null, null);
        $before = $this->repository->findById($id)?->publicId;

        $this->repository->backfillPublicIds();

        $this->assertSame($before, $this->repository->findById($id)?->publicId);
    }

    public function testPublicIdsForIgnoresNonPositiveIdsAndFilesWithoutOne(): void
    {
        $withId = $this->repository->create('w.jpg', 'w.jpg', 'image/jpeg', 1, 'public', null, null);
        $without = $this->insertLegacyFile('wo.jpg');

        $found = $this->repository->publicIdsFor([$withId, $without, 0, -3]);

        $this->assertSame([$withId], array_keys($found));
        $this->assertSame([], $this->repository->publicIdsFor([]));
    }

    /**
     * Every template goes through file_url(): it must print the opaque
     * address, never the sequential id it was handed.
     */
    public function testFileUrlInTwigPrintsTheOpaqueAddress(): void
    {
        $id = $this->repository->create('t.jpg', 't.jpg', 'image/jpeg', 1, 'public', null, null);
        $public = (string) $this->repository->findById($id)?->publicId;
        $html = \Tests\TestTwig::withTemplates([
            'urls.twig' => "{{ file_url(id) }}|{{ file_url(id, 'thumb') }}|{{ file_url(null) }}",
        ])->render('urls.twig', ['id' => $id]);

        $this->assertSame('/files/' . $public . '|/files/' . $public . '/thumb|', $html);
    }

    private function insertLegacyFile(string $path): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$path, $path, 'image/jpeg', 1, 'public']);

        return (int) $this->pdo->lastInsertId();
    }
}
