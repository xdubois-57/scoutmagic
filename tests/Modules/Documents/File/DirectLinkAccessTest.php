<?php

declare(strict_types=1);

namespace Tests\Modules\Documents\File;

use Core\File\FileAccessGuard;
use Core\File\FileRepository;
use Core\Security\Role;
use Modules\Documents\File\DirectLinkGrants;
use Modules\Documents\File\DocumentFileOwnershipChecker;
use Modules\Documents\Repository\DocumentRepository;
use Modules\Documents\Service\DocumentService;
use Modules\Documents\Service\DocumentVisibility;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Documents\DocumentsTestHelper;

/**
 * An unlisted document is reachable by its address and by nothing else:
 * its file is `role_min: public`, and /files/{id} ids are sequential, so
 * without the ownership check anybody counting ids would find it.
 */
final class DirectLinkAccessTest extends TestCase
{
    private \PDO $pdo;
    private string $storage;
    private DocumentService $service;
    private DirectLinkGrants $grants;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        DocumentsTestHelper::createTables($this->pdo);
        $this->storage = DocumentsTestHelper::storage();
        $this->service = DocumentsTestHelper::service($this->pdo, $this->storage);
        $this->grants = new DirectLinkGrants();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        DocumentsTestHelper::removeTree($this->storage);
    }

    public function testEveryDocumentFileIsOwnedByItsDocument(): void
    {
        $document = $this->service->create('ROI', null, 'public', DocumentsTestHelper::upload(), null);
        $file = (new FileRepository($this->pdo))->findById($document->fileId);

        $this->assertSame(DocumentFileOwnershipChecker::OWNER_TYPE, $file?->ownerType);
        $this->assertSame($document->id, $file->ownerId);
    }

    /**
     * A new document's file is stored closed and opened only once owned:
     * between the upload (and its PDF compression) and the ownership,
     * an unlisted document's file must not be a plain public file.
     */
    public function testANewFileIsStoredClosedUntilOwned(): void
    {
        $roles = [];
        $spy = new class ($this->pdo, $roles) extends FileRepository {
            /** @param list<array{string, ?string}> $seen */
            public function __construct(\PDO $pdo, private array &$seen)
            {
                parent::__construct($pdo);
            }

            public function updateOwner(int $id, string $ownerType, int $ownerId): void
            {
                $file = $this->findById($id);
                $this->seen[] = [(string) $file?->roleMin, $file?->ownerType];
                parent::updateOwner($id, $ownerType, $ownerId);
            }
        };
        $service = DocumentsTestHelper::service($this->pdo, $this->storage, $spy);

        $document = $service->create('PV AG', null, 'direct_link', DocumentsTestHelper::upload(), null);

        $this->assertSame([['admin', null]], $roles, 'The file was reachable before it had an owner.');
        $this->assertSame('public', DocumentsTestHelper::fileRoleMin($this->pdo, $document->fileId));
    }

    /**
     * The same on replacement: the new file must not open before the
     * document row carries the visibility it is opened for — a document
     * switched to « Lien direct » with a new file would otherwise have
     * that file judged, for a while, by its old listed visibility.
     */
    public function testAReplacementFileIsStoredClosedUntilTheDocumentSaysWhatItIs(): void
    {
        $document = $this->service->create('ROI', null, 'public', DocumentsTestHelper::upload(), null);
        $seen = [];
        $spy = new class ($this->pdo, $seen) extends DocumentRepository {
            /** @param list<list<string>> $seen */
            public function __construct(private \PDO $db, private array &$seen)
            {
                parent::__construct($db);
            }

            public function applyEdit(
                int $id,
                string $title,
                ?string $description,
                DocumentVisibility $visibility,
                ?int $newFileId,
                ?int $updatedBy,
                string $now
            ): void {
                // Both files as the row is about to switch: the new one and
                // the outgoing one must both be closed at this instant.
                $query = $this->db->prepare(
                    'SELECT f.role_min FROM files f WHERE f.id = ? OR f.id = (SELECT file_id FROM documents WHERE id = ?)'
                    . ' ORDER BY f.id'
                );
                $query->execute([$newFileId, $id]);
                $this->seen[] = array_map('strval', $query->fetchAll(\PDO::FETCH_COLUMN));
                parent::applyEdit($id, $title, $description, $visibility, $newFileId, $updatedBy, $now);
            }
        };
        $service = DocumentsTestHelper::service($this->pdo, $this->storage, null, $spy);

        $updated = $service->update($document->id, 'ROI', null, 'direct_link', DocumentsTestHelper::upload('v2.pdf'), null);

        $this->assertSame([['admin', 'admin']], $seen, 'A file was open while the row still carried the old visibility.');
        $this->assertSame('public', DocumentsTestHelper::fileRoleMin($this->pdo, $updated->fileId));
    }

    /**
     * An edit that fails leaves the document AND its file as they were:
     * the row keeps its visibility, the file its role, the new upload
     * does not linger.
     */
    public function testAFailedEditLeavesTheDocumentAndItsFileAsTheyWere(): void
    {
        $document = $this->service->create('PV AG', null, 'direct_link', DocumentsTestHelper::upload(), null);
        $failing = new class ($this->pdo) extends DocumentRepository {
            public function applyEdit(
                int $id,
                string $title,
                ?string $description,
                DocumentVisibility $visibility,
                ?int $newFileId,
                ?int $updatedBy,
                string $now
            ): void {
                throw new \RuntimeException('lost connection');
            }
        };
        $service = DocumentsTestHelper::service($this->pdo, $this->storage, null, $failing);

        try {
            $service->update($document->id, 'PV AG', null, 'public', DocumentsTestHelper::upload('v2.pdf'), null);
            $this->fail('The failing write was swallowed.');
        } catch (\RuntimeException) {
        }

        $this->assertSame('public', DocumentsTestHelper::fileRoleMin($this->pdo, $document->fileId));
        $this->assertSame(DocumentVisibility::DIRECT_LINK, $this->service->findById($document->id)?->visibility);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testAReplacementFileIsOwnedByItsDocumentToo(): void
    {
        $document = $this->service->create('ROI', null, 'direct_link', DocumentsTestHelper::upload(), null);
        $updated = $this->service->update($document->id, 'ROI', null, 'direct_link', DocumentsTestHelper::upload('v2.pdf'), null);
        $file = (new FileRepository($this->pdo))->findById($updated->fileId);

        $this->assertSame(DocumentFileOwnershipChecker::OWNER_TYPE, $file?->ownerType);
        $this->assertSame($document->id, $file->ownerId);
    }

    public function testAnUnlistedFileIsRefusedToAnyoneWhoDidNotComeThroughItsAddress(): void
    {
        $document = $this->service->create('PV AG', null, 'direct_link', DocumentsTestHelper::upload(), null);

        foreach ([Role::PUBLIC, Role::IDENTIFIED, Role::INTENDANT, Role::CHIEF] as $role) {
            $this->assertNull($this->guard($role)->check($document->fileId), $role->value);
        }
    }

    public function testTheAddressOpensTheUnlistedFileForThatSession(): void
    {
        $document = $this->service->create('PV AG', null, 'direct_link', DocumentsTestHelper::upload(), null);

        $this->grants->grant($document->id);

        $this->assertNotNull($this->guard(Role::PUBLIC)->check($document->fileId));
    }

    public function testAGrantOpensThatDocumentOnly(): void
    {
        $first = $this->service->create('PV 1', null, 'direct_link', DocumentsTestHelper::upload(), null);
        $second = $this->service->create('PV 2', null, 'direct_link', DocumentsTestHelper::upload(), null);

        $this->grants->grant($first->id);

        $this->assertNull($this->guard(Role::PUBLIC)->check($second->fileId));
    }

    public function testTheStaffReadsUnlistedFilesWithoutTheAddress(): void
    {
        $document = $this->service->create('PV AG', null, 'direct_link', DocumentsTestHelper::upload(), null);

        $this->assertNotNull($this->guard(Role::ADMIN)->check($document->fileId));
    }

    public function testAListedFileIsDecidedByItsRoleAlone(): void
    {
        $public = $this->service->create('ROI', null, 'public', DocumentsTestHelper::upload(), null);
        $members = $this->service->create('Liste', null, 'identified', DocumentsTestHelper::upload(), null);

        $this->assertNotNull($this->guard(Role::PUBLIC)->check($public->fileId));
        $this->assertNull($this->guard(Role::PUBLIC)->check($members->fileId));
        $this->assertNotNull($this->guard(Role::IDENTIFIED)->check($members->fileId));
    }

    public function testSwitchingToDirectLinkClosesTheFileToCounting(): void
    {
        $document = $this->service->create('ROI', null, 'public', DocumentsTestHelper::upload(), null);
        $this->assertNotNull($this->guard(Role::PUBLIC)->check($document->fileId));

        $this->service->update($document->id, 'ROI', null, DocumentVisibility::DIRECT_LINK->value, null, null);

        $this->assertNull($this->guard(Role::PUBLIC)->check($document->fileId));
    }

    public function testAFileWhoseDocumentIsGoneIsRefused(): void
    {
        $checker = new DocumentFileOwnershipChecker(new DocumentRepository($this->pdo), $this->grants);

        $this->assertFalse($checker->isAllowed(999, Role::CHIEF, []));
    }

    private function guard(Role $role): FileAccessGuard
    {
        return new FileAccessGuard(
            new FileRepository($this->pdo),
            $role,
            [],
            [new DocumentFileOwnershipChecker(new DocumentRepository($this->pdo), $this->grants)]
        );
    }
}
