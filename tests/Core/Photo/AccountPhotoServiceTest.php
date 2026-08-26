<?php

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\File\FileRepository;
use Core\Photo\AccountPhotoRepository;
use Core\Photo\AccountPhotoService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The photo of an identified login — the other half of this codebase's
 * identity model from MemberPhotoService, and deliberately simpler: no
 * scout year, one row per account, and replacing it deletes what it
 * replaced (Core\Photo\AccountPhotoService).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AccountPhotoServiceTest extends TestCase
{
    private \PDO $pdo;
    private AccountPhotoService $service;
    private FileRepository $files;
    private string $storagePath;
    private int $accountId;
    private int $otherAccountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->files = new FileRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic-account-photos-' . bin2hex(random_bytes(4));
        mkdir($this->storagePath . '/core/account_photos', 0777, true);

        $this->service = new AccountPhotoService(
            new AccountPhotoRepository($this->pdo),
            $this->files,
            $this->storagePath
        );

        $this->accountId = $this->createAccount('one');
        $this->otherAccountId = $this->createAccount('two');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/core/account_photos/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath . '/core/account_photos');
        @rmdir($this->storagePath . '/core');
        @rmdir($this->storagePath);
    }

    public function testAnAccountWithNoPhotoResolvesToNothing(): void
    {
        $this->assertNull($this->service->resolveFileId($this->accountId));
    }

    public function testSettingAPhotoMakesItResolve(): void
    {
        $fileId = $this->createStoredFile('face.webp');

        $this->service->setPhoto($this->accountId, $fileId);

        $this->assertSame($fileId, $this->service->resolveFileId($this->accountId));
        $this->assertNull($this->service->resolveFileId($this->otherAccountId));
    }

    /**
     * The reason the repository hands the replaced id back: an account
     * that changes its picture five times must not leave five files
     * behind, and nothing else ever points at the old one.
     */
    public function testReplacingAPhotoDeletesTheOneItReplaces(): void
    {
        $first = $this->createStoredFile('first.webp');
        $second = $this->createStoredFile('second.webp');
        $this->service->setPhoto($this->accountId, $first);

        $this->service->setPhoto($this->accountId, $second);

        $this->assertSame($second, $this->service->resolveFileId($this->accountId));
        $this->assertNull($this->files->findById($first));
        $this->assertFileDoesNotExist($this->storagePath . '/core/account_photos/first.webp');
        // …and its derivative, which is a sibling on disk rather than a
        // `files` row of its own and would otherwise be what accumulates.
        $this->assertFileDoesNotExist($this->storagePath . '/core/account_photos/first.thumb.webp');
        $this->assertFileExists($this->storagePath . '/core/account_photos/second.webp');
    }

    public function testRemovingAPhotoForgetsItAndDeletesTheFile(): void
    {
        $fileId = $this->createStoredFile('face.webp');
        $this->service->setPhoto($this->accountId, $fileId);

        $this->service->removePhoto($this->accountId);

        $this->assertNull($this->service->resolveFileId($this->accountId));
        $this->assertNull($this->files->findById($fileId));
        $this->assertFileDoesNotExist($this->storagePath . '/core/account_photos/face.webp');
    }

    public function testRemovingAPhotoAnAccountDoesNotHaveIsANoOp(): void
    {
        $this->service->removePhoto($this->accountId);

        $this->assertNull($this->service->resolveFileId($this->accountId));
    }

    /**
     * A page naming twenty authors resolves their avatars in one query,
     * not twenty.
     */
    public function testPhotosOfSeveralAccountsComeBackInOneLookup(): void
    {
        $mine = $this->createStoredFile('mine.webp');
        $theirs = $this->createStoredFile('theirs.webp');
        $this->service->setPhoto($this->accountId, $mine);
        $this->service->setPhoto($this->otherAccountId, $theirs);

        $resolved = $this->service->resolveFileIds([$this->accountId, $this->otherAccountId, 9999]);

        $this->assertSame(
            [$this->accountId => $mine, $this->otherAccountId => $theirs],
            $resolved
        );
    }

    /**
     * The nav asks for the connected account's avatar three times per
     * page — the second and third answers come from memory. Proven by
     * deleting the row behind the service's back: a fresh read would
     * notice, the memo must not.
     */
    public function testRepeatedResolutionsAreAnsweredFromMemory(): void
    {
        $fileId = $this->createStoredFile('face.webp');
        $this->service->setPhoto($this->accountId, $fileId);
        $this->assertSame($fileId, $this->service->resolveFileId($this->accountId));

        $this->pdo->exec('DELETE FROM user_account_photos');

        $this->assertSame($fileId, $this->service->resolveFileId($this->accountId));
    }

    public function testBatchResolutionPrimesTheMemoForSingleLookups(): void
    {
        $mine = $this->createStoredFile('mine.webp');
        $this->service->setPhoto($this->accountId, $mine);

        $this->service->resolveFileIds([$this->accountId, $this->otherAccountId]);
        $this->pdo->exec('DELETE FROM user_account_photos');

        $this->assertSame($mine, $this->service->resolveFileId($this->accountId));
        $this->assertNull($this->service->resolveFileId($this->otherAccountId));
    }

    private function createAccount(string $suffix): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc-' . $suffix, 'idx-' . $suffix]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createStoredFile(string $name): int
    {
        $relative = 'core/account_photos/' . $name;
        file_put_contents($this->storagePath . '/' . $relative, 'x');
        // The thumbnail the upload pipeline generates beside it.
        file_put_contents(
            $this->storagePath . '/core/account_photos/' . pathinfo($name, PATHINFO_FILENAME) . '.thumb.webp',
            'x'
        );

        $stmt = $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min)
             VALUES (?, ?, 'image/webp', 1, 'identified')"
        );
        $stmt->execute([$relative, $name]);

        return (int) $this->pdo->lastInsertId();
    }
}
