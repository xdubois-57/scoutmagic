<?php

declare(strict_types=1);

namespace Tests\Core\File;

use Core\File\FileAccessGuard;
use Core\File\FileOwnershipCheckerInterface;
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
            owner_type TEXT,
            owner_id INTEGER,
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
     * An owner-scoped file (a member's private document) is denied to a
     * chief who is not linked to its owner, and READ by the Staff d'Unité.
     *
     * The second half reverses what this test used to assert. The no-bypass
     * rule was withdrawn on purpose so a chef d'unité can answer « nous
     * n'avons rien reçu » from the member's own sheet (ARCHITECTURE.md §8.3
     * and SECURITY.md §6, both rewritten in the same change). What survives
     * is the bound: it stops at `admin`, so an animateur de section still
     * reads none of it — and `FileController` journals every staff opening
     * at `security` level.
     */
    public function testOwnerScopedFileDeniedToAChiefAndReadableByTheStaff(): void
    {
        $id = $this->repo->create('f.pdf', 'f.pdf', 'application/pdf', 100, 'identified', null, null, false, 42);

        $chiefGuard = new FileAccessGuard($this->repo, Role::CHIEF, []);
        $this->assertNull($chiefGuard->check($id));

        $adminGuard = new FileAccessGuard($this->repo, Role::ADMIN, []);
        $this->assertNotNull($adminGuard->check($id));
    }

    /**
     * The role floor is still checked first and independently: the bypass
     * lets the Staff d'Unité past the OWNERSHIP half, never past a floor
     * their role does not reach.
     */
    public function testTheStaffBypassDoesNotLiftTheRoleFloor(): void
    {
        $id = $this->repo->create('f.pdf', 'f.pdf', 'application/pdf', 100, 'superadmin', null, null, false, 42);

        $guard = new FileAccessGuard($this->repo, Role::ADMIN, []);
        $this->assertNull($guard->check($id));
    }

    /**
     * Which of the two ways in was used — the question FileController asks
     * to decide whether to write an ordinary `info` entry or the
     * `security`-level one that is the whole compensation for the wall this
     * bypass removed.
     */
    public function testTheGuardSaysWhetherAnAccessWasTheStaffBypass(): void
    {
        $id = $this->repo->create('f.pdf', 'f.pdf', 'application/pdf', 100, 'identified', null, null, false, 42);

        $staffGuard = new FileAccessGuard($this->repo, Role::ADMIN, []);
        $file = $staffGuard->check($id);
        $this->assertNotNull($file);
        $this->assertTrue($staffGuard->isStaffBypass($file));

        // The owner reading their own document is not a bypass, whatever
        // their role happens to be.
        $ownerGuard = new FileAccessGuard($this->repo, Role::ADMIN, [42]);
        $this->assertFalse($ownerGuard->isStaffBypass($file));

        $unowned = $this->repo->create('g.jpg', 'g.jpg', 'image/jpeg', 100, 'public', null, null);
        $unownedFile = $staffGuard->check($unowned);
        $this->assertNotNull($unownedFile);
        $this->assertFalse($staffGuard->isStaffBypass($unownedFile));
    }

    public function testFileWithNoOwnerIsUnaffectedByLinkedMemberIds(): void
    {
        $id = $this->repo->create('f.jpg', 'f.jpg', 'image/jpeg', 100, 'public', null, null);

        $guard = new FileAccessGuard($this->repo, Role::PUBLIC, []);
        $this->assertNotNull($guard->check($id));
    }

    // --- Generic owner_type/owner_id registry (Core\File\FileOwnershipCheckerInterface) ---

    private function fakeChecker(string $ownerType, bool $allowed): FileOwnershipCheckerInterface
    {
        return new class ($ownerType, $allowed) implements FileOwnershipCheckerInterface {
            public function __construct(private string $ownerType, private bool $allowed)
            {
            }

            public function supports(string $ownerType): bool
            {
                return $ownerType === $this->ownerType;
            }

            public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
            {
                return $this->allowed;
            }
        };
    }

    public function testOwnerTypeFileGrantedByAMatchingChecker(): void
    {
        $id = $this->repo->create('f.pdf', 'f.pdf', 'application/pdf', 100, 'identified', null, null, false, null, 'section_document', 5);

        $guard = new FileAccessGuard($this->repo, Role::IDENTIFIED, [], [$this->fakeChecker('section_document', true)]);
        $this->assertNotNull($guard->check($id));
    }

    public function testOwnerTypeFileDeniedByAMatchingChecker(): void
    {
        $id = $this->repo->create('f.pdf', 'f.pdf', 'application/pdf', 100, 'identified', null, null, false, null, 'section_document', 5);

        $guard = new FileAccessGuard($this->repo, Role::IDENTIFIED, [], [$this->fakeChecker('section_document', false)]);
        $this->assertNull($guard->check($id));
    }

    /**
     * Fail-safe: an owner_type with no registered checker at all must
     * deny, not silently fall through to "allowed" — same posture as
     * RBAC's own "no role_min = no access" default.
     */
    public function testOwnerTypeFileWithNoMatchingCheckerIsDenied(): void
    {
        $id = $this->repo->create('f.pdf', 'f.pdf', 'application/pdf', 100, 'identified', null, null, false, null, 'section_document', 5);

        $guard = new FileAccessGuard($this->repo, Role::CHIEF, [], [$this->fakeChecker('some_other_type', true)]);
        $this->assertNull($guard->check($id));
    }

    public function testOwnerTypeCheckStillRequiresRoleMinFirst(): void
    {
        $id = $this->repo->create('f.pdf', 'f.pdf', 'application/pdf', 100, 'chief', null, null, false, null, 'section_document', 5);

        $guard = new FileAccessGuard($this->repo, Role::IDENTIFIED, [], [$this->fakeChecker('section_document', true)]);
        $this->assertNull($guard->check($id));
    }

    /**
     * The registry is fail-closed with no bypass at any role: a file whose
     * owner_type has no checker registered at all — the exact state when
     * the module that provides it is disabled, uninstalled, or simply not
     * wired — is denied even to a superadmin. An empty registry must never
     * degrade to "nothing to check, therefore allowed".
     */
    public function testOwnerTypeFileWithNoCheckerRegisteredAtAllIsDeniedEvenForSuperadmin(): void
    {
        $id = $this->repo->create('f.pdf', 'f.pdf', 'application/pdf', 100, 'public', null, null, false, null, 'group_document', 7);

        $guard = new FileAccessGuard($this->repo, Role::SUPERADMIN, [], []);
        $this->assertNull($guard->check($id));
    }

    /**
     * isAllowedByRegistry() returns on the first checker whose supports()
     * is true, so a checker must be consulted for its own owner_type and
     * for no other — two owner_types claimed by the same checker, or one
     * checker answering for another's type, would silently hand a file to
     * the wrong access rule.
     */
    public function testEachCheckerIsConsultedOnlyForItsOwnOwnerType(): void
    {
        $sectionFileId = $this->repo->create('s.pdf', 's.pdf', 'application/pdf', 100, 'public', null, null, false, null, 'section_document', 5);
        $otherFileId = $this->repo->create('o.pdf', 'o.pdf', 'application/pdf', 100, 'public', null, null, false, null, 'other_document', 6);

        $sectionChecker = $this->recordingChecker('section_document', true);
        $otherChecker = $this->recordingChecker('other_document', false);
        $guard = new FileAccessGuard($this->repo, Role::IDENTIFIED, [], [$sectionChecker, $otherChecker]);

        $this->assertNotNull($guard->check($sectionFileId));
        $this->assertSame([5], $sectionChecker->consultedOwnerIds);
        $this->assertSame([], $otherChecker->consultedOwnerIds, 'the other type\'s checker must not be consulted');

        $this->assertNull($guard->check($otherFileId));
        $this->assertSame([5], $sectionChecker->consultedOwnerIds, 'section checker must not be consulted for another type');
        $this->assertSame([6], $otherChecker->consultedOwnerIds);
    }

    /**
     * Mirrors what the composition root now does: core registers its own
     * checker early (public/index.php, where $linkedMembers exists), a
     * module appends its own later from inside its own enabled-module
     * block, and only then is the guard constructed. A checker added to
     * the array after the first one must be consulted just the same —
     * FileAccessGuard is immutable, so the array is what carries it.
     */
    public function testACheckerAppendedAfterTheFirstOneIsStillConsulted(): void
    {
        $id = $this->repo->create('m.pdf', 'm.pdf', 'application/pdf', 100, 'public', null, null, false, null, 'module_document', 9);

        $checkers = [$this->recordingChecker('section_document', true)];
        $moduleChecker = $this->recordingChecker('module_document', true);
        $checkers[] = $moduleChecker;

        $guard = new FileAccessGuard($this->repo, Role::IDENTIFIED, [], $checkers);

        $this->assertNotNull($guard->check($id));
        $this->assertSame([9], $moduleChecker->consultedOwnerIds);
    }

    /**
     * @return FileOwnershipCheckerInterface&object{consultedOwnerIds: int[]}
     */
    private function recordingChecker(string $ownerType, bool $allowed): FileOwnershipCheckerInterface
    {
        return new class ($ownerType, $allowed) implements FileOwnershipCheckerInterface {
            /** @var int[] */
            public array $consultedOwnerIds = [];

            public function __construct(private string $ownerType, private bool $allowed)
            {
            }

            public function supports(string $ownerType): bool
            {
                return $ownerType === $this->ownerType;
            }

            public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
            {
                $this->consultedOwnerIds[] = $ownerId;

                return $this->allowed;
            }
        };
    }
}
