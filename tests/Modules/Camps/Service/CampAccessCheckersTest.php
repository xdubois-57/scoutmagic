<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Security\Role;
use Modules\Camps\Service\CampAlbumAccessChecker;
use Modules\Camps\Service\CampFileOwnershipChecker;
use PHPUnit\Framework\TestCase;

/**
 * The module's two file gates, which have to agree with each other.
 *
 * They guard different routes — `CampFileOwnershipChecker` gates
 * `/files/{id}` (a stay's documents, a link's preview image),
 * `CampAlbumAccessChecker` gates `/gallery/media/{id}` (the photos) — and
 * a module registering only one leaves its media reachable through the
 * other. That is why they are tested together and why the last test below
 * compares their answers rather than restating them.
 */
class CampAccessCheckersTest extends TestCase
{
    /**
     * @return array<string, array{Role, bool}>
     */
    public static function roleProvider(): array
    {
        return [
            'public' => [Role::PUBLIC, false],
            'identified' => [Role::IDENTIFIED, false],
            // An intendant is one level below the module's floor: the whole
            // module is chief-and-above, so a camp contract is not theirs.
            'intendant' => [Role::INTENDANT, false],
            'chief' => [Role::CHIEF, true],
            'admin' => [Role::ADMIN, true],
            'superadmin' => [Role::SUPERADMIN, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roleProvider')]
    public function testTheFileGateAnswersOnTheRoleAlone(Role $role, bool $allowed): void
    {
        $this->assertSame($allowed, (new CampFileOwnershipChecker())->isAllowed(7, $role, []));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roleProvider')]
    public function testTheAlbumGateAnswersOnTheRoleAlone(Role $role, bool $allowed): void
    {
        $this->assertSame($allowed, (new CampAlbumAccessChecker())->isAllowed(7, $role, []));
    }

    /**
     * Both claim `camp_camp` and nothing else. A checker that answered for
     * another module's owner type would decide about files it knows
     * nothing of.
     */
    public function testBothClaimOnlyTheirOwnOwnerType(): void
    {
        $file = new CampFileOwnershipChecker();
        $album = new CampAlbumAccessChecker();

        $this->assertSame('camp_camp', CampFileOwnershipChecker::OWNER_TYPE);
        $this->assertSame('camp_camp', CampAlbumAccessChecker::OWNER_TYPE);

        foreach ([$file, $album] as $checker) {
            $this->assertTrue($checker->supports('camp_camp'));
            $this->assertFalse($checker->supports('rental_document'));
            $this->assertFalse($checker->supports('discussion_group'));
            $this->assertFalse($checker->supports(''));
        }
    }

    /**
     * The invariant that matters: whatever the rule becomes, one route
     * must never open what the other closes. A per-camp visibility rule
     * added to one of them and forgotten on the other fails here.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('roleProvider')]
    public function testTheTwoGatesNeverDisagree(Role $role, bool $allowed): void
    {
        $this->assertSame(
            (new CampFileOwnershipChecker())->isAllowed(7, $role, []),
            (new CampAlbumAccessChecker())->isAllowed(7, $role, []),
            'the documents route and the photos route must let the same people through'
        );
    }

    /**
     * The camp id is not consulted, and the linked members are not either
     * — this module has no per-camp visibility, and pretending otherwise
     * in a test would pin a rule that does not exist.
     */
    public function testNeitherGateLooksAtTheCampOrTheViewersMemberships(): void
    {
        $file = new CampFileOwnershipChecker();

        $this->assertTrue($file->isAllowed(1, Role::CHIEF, []));
        $this->assertTrue($file->isAllowed(999_999, Role::CHIEF, [42]));
        $this->assertFalse($file->isAllowed(1, Role::INTENDANT, [42]));
    }
}
