<?php

declare(strict_types=1);

namespace Tests\Core\File;

use Core\File\FileAccessGuard;
use Core\File\FileRepository;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;

class FileAccessGuardTest extends TestCase
{
    private \PDO $pdo;
    private FileRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            relative_path TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            module_id TEXT,
            role_min TEXT NOT NULL DEFAULT "public",
            custom_resolver TEXT,
            encrypted INTEGER NOT NULL DEFAULT 0,
            owner_member_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER
        )');

        $this->repo = new FileRepository($this->pdo);
    }

    public function testPublicFileAccessibleByAnyRole(): void
    {
        $id = $this->repo->create('f.jpg', 'f.jpg', 'image/jpeg', 100, 'public', null, null);

        $guard = new FileAccessGuard($this->repo, Role::PUBLIC);
        $result = $guard->check($id);
        $this->assertNotNull($result);
        $this->assertSame($id, $result->id);
    }

    public function testChiefFileFailsForIdentifiedRole(): void
    {
        $id = $this->repo->create('f.jpg', 'f.jpg', 'image/jpeg', 100, 'chief', null, null);

        $guard = new FileAccessGuard($this->repo, Role::IDENTIFIED);
        $this->assertNull($guard->check($id));
    }

    public function testChiefFileSucceedsForChiefRole(): void
    {
        $id = $this->repo->create('f.jpg', 'f.jpg', 'image/jpeg', 100, 'chief', null, null);

        $guard = new FileAccessGuard($this->repo, Role::CHIEF);
        $this->assertNotNull($guard->check($id));
    }

    public function testAdminFileSucceedsForAdminRole(): void
    {
        $id = $this->repo->create('f.jpg', 'f.jpg', 'image/jpeg', 100, 'admin', null, null);

        $guard = new FileAccessGuard($this->repo, Role::ADMIN);
        $this->assertNotNull($guard->check($id));
    }

    public function testNonExistentFileReturnsNull(): void
    {
        $guard = new FileAccessGuard($this->repo, Role::ADMIN);
        $this->assertNull($guard->check(999));
    }

    public function testOwnerScopedFileAccessibleToALinkedMember(): void
    {
        $id = $this->repo->create('f.pdf', 'f.pdf', 'application/pdf', 100, 'identified', null, null, false, 42);

        $guard = new FileAccessGuard($this->repo, Role::IDENTIFIED, [42]);
        $this->assertNotNull($guard->check($id));
    }

    public function testOwnerScopedFileDeniedToANonLinkedMember(): void
    {
        $id = $this->repo->create('f.pdf', 'f.pdf', 'application/pdf', 100, 'identified', null, null, false, 42);

        $guard = new FileAccessGuard($this->repo, Role::IDENTIFIED, [43]);
        $this->assertNull($guard->check($id));
    }

    /**
     * Deliberate no-bypass rule (member page spec, §5): an owner-scoped
     * file (e.g. a member's private document) must be denied to a chief or
     * admin account too, unless that account is itself linked to the
     * file's owner — a higher role_min never substitutes for ownership.
     */
    public function testOwnerScopedFileDeniedToAChiefOrAdminNotLinkedToTheOwner(): void
    {
        $id = $this->repo->create('f.pdf', 'f.pdf', 'application/pdf', 100, 'identified', null, null, false, 42);

        $chiefGuard = new FileAccessGuard($this->repo, Role::CHIEF, []);
        $this->assertNull($chiefGuard->check($id));

        $adminGuard = new FileAccessGuard($this->repo, Role::ADMIN, []);
        $this->assertNull($adminGuard->check($id));
    }

    public function testFileWithNoOwnerIsUnaffectedByLinkedMemberIds(): void
    {
        $id = $this->repo->create('f.jpg', 'f.jpg', 'image/jpeg', 100, 'public', null, null);

        $guard = new FileAccessGuard($this->repo, Role::PUBLIC, []);
        $this->assertNotNull($guard->check($id));
    }
}
