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
