<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations\Service;

use Core\File\FileAccessGuard;
use Core\File\FileRepository;
use Core\Security\Role;
use Modules\Attestations\Service\BatchDepositService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Who can actually open a certificate, asked of the guard itself.
 *
 * This is the property the whole module rests on, and it is easy to get
 * backwards: `FileAccessGuard::check()` wants the `role_min` floor AND the
 * ownership match, independently. A floor set to `admin` on an owned
 * certificate would lock out the family the document is for — and grant the
 * staff nothing, since they are not linked to that member either. So the
 * floor is `identified` while the file has an owner, and the strict one
 * while it has none.
 */
#[Group('database')]
class CertificateAccessTest extends TestCase
{
    private \PDO $pdo;
    private FileRepository $files;
    private int $familyMemberId = 0;
    private int $otherMemberId = 0;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->files = new FileRepository($this->pdo);

        foreach (['famille', 'autre'] as $key) {
            $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
            $stmt->execute(['D-' . $key]);
            $id = (int) $this->pdo->lastInsertId();
            $key === 'famille' ? $this->familyMemberId = $id : $this->otherMemberId = $id;
        }
    }

    private function storeCertificate(?int $ownerMemberId): int
    {
        return $this->files->create(
            BatchDepositService::STORAGE_SUBDIRECTORY . '/' . bin2hex(random_bytes(8)),
            'attestation.pdf',
            'application/pdf',
            1024,
            $ownerMemberId === null
                ? BatchDepositService::FILE_ROLE_MIN_UNOWNED
                : BatchDepositService::FILE_ROLE_MIN_OWNED,
            'attestations',
            null,
            true,
            $ownerMemberId
        );
    }

    /**
     * @param list<int> $linkedMemberIds
     */
    private function guard(string $role, array $linkedMemberIds): FileAccessGuard
    {
        return new FileAccessGuard($this->files, Role::fromString($role), $linkedMemberIds);
    }

    /** The point of the whole feature. */
    public function testTheFamilyOpensItsOwnCertificate(): void
    {
        $fileId = $this->storeCertificate($this->familyMemberId);

        $this->assertNotNull(
            $this->guard('identified', [$this->familyMemberId])->check($fileId)
        );
    }

    public function testAnotherFamilyDoesNot(): void
    {
        $fileId = $this->storeCertificate($this->familyMemberId);

        $this->assertNull(
            $this->guard('identified', [$this->otherMemberId])->check($fileId)
        );
    }

    /**
     * The Staff d'Unité opens it, and an animateur de section does not.
     *
     * That first half is a guarantee this project used to make the other
     * way round (ARCHITECTURE.md §8.3, SECURITY.md §6): an owner-scoped file
     * was unreachable by anybody but its owner. It was withdrawn on purpose
     * so a chef d'unité can answer « nous n'avons rien reçu » from the
     * member's own sheet. The bound that remains is this second assertion —
     * a `chief` is refused, because an animateur has no reason to read an
     * animé's tax certificate — plus the `security`-level journal entry
     * FileController writes for every such opening.
     */
    public function testTheStaffOpensItAndAnAnimateurDoesNot(): void
    {
        $fileId = $this->storeCertificate($this->familyMemberId);

        $this->assertNotNull($this->guard('admin', [])->check($fileId));
        $this->assertNull($this->guard('chief', [])->check($fileId));
    }

    public function testAVisitorWithNoAccountReadsNothing(): void
    {
        $fileId = $this->storeCertificate($this->familyMemberId);

        $this->assertNull($this->guard('guest', [$this->familyMemberId])->check($fileId));
    }

    /**
     * A certificate waiting for a human decision has no owner, so the
     * ownership half of the guard has nothing to compare and the role floor
     * is the only thing left — which is why it is the strict one. An
     * identified account that guessed the id must find nothing.
     */
    public function testACertificateStillWaitingForADecisionIsStaffOnly(): void
    {
        $fileId = $this->storeCertificate(null);

        $this->assertNull($this->guard('identified', [$this->familyMemberId])->check($fileId));
        $this->assertNull($this->guard('chief', [])->check($fileId));
        $this->assertNotNull($this->guard('admin', [])->check($fileId));
    }
}
