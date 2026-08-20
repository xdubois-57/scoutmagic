<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Member\TemporaryMemberProviderInterface;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The temporary-member override (ARCHITECTURE.md §8.42) as MemberService
 * sees it: the one injection point every other surface inherits from.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TemporaryMemberInjectionTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $enc;
    private int $yearId;
    private int $otherYearId;

    private const ADMIN_EMAIL = 'admin@example.com';
    private const ANIME_EMAIL = 'anime@example.com';

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->enc = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->yearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2024-2025', '2024-09-01', '2025-08-31', 0)");
        $this->otherYearId = (int) $this->pdo->lastInsertId();
    }

    /**
     * A service whose provider always answers $memberYearId for $forEmail,
     * standing in for the session-backed one.
     */
    private function serviceWithOverride(?int $memberYearId, string $forEmail = self::ADMIN_EMAIL): MemberService
    {
        $provider = new class ($memberYearId, $forEmail) implements TemporaryMemberProviderInterface {
            public function __construct(private ?int $memberYearId, private string $forEmail)
            {
            }

            public function resolveMemberYearId(string $email): ?int
            {
                return $email === $this->forEmail ? $this->memberYearId : null;
            }
        };

        return new MemberService(
            new MemberYearRepository($this->pdo),
            $this->enc,
            Connection::withPdo($this->pdo),
            $provider
        );
    }

    private function plainService(): MemberService
    {
        return new MemberService(
            new MemberYearRepository($this->pdo),
            $this->enc,
            Connection::withPdo($this->pdo)
        );
    }

    /** @return array{memberId: int, memberYearId: int} */
    private function seedMember(string $email, string $totem, ?int $yearId = null, bool $isActive = true): array
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, totem_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $yearId ?? $this->yearId,
            $this->enc->encrypt('Jean', 'member_years.first_name'),
            $this->enc->encrypt('Dupont', 'member_years.last_name'),
            $this->enc->encrypt($email, 'member_years.email'),
            $this->enc->blindIndex(strtolower($email), 'email'),
            $this->enc->encrypt($totem, 'member_years.totem'),
            $isActive ? 1 : 0,
        ]);

        return ['memberId' => $memberId, 'memberYearId' => (int) $this->pdo->lastInsertId()];
    }

    public function testTemporaryMemberIsAppendedToTheLinkedList(): void
    {
        $this->seedMember(self::ADMIN_EMAIL, 'Baloo');
        $anime = $this->seedMember(self::ANIME_EMAIL, 'Mowgli');

        $members = $this->serviceWithOverride($anime['memberYearId'])
            ->getLinkedMembers(self::ADMIN_EMAIL, $this->yearId);

        $names = array_map(static fn($m) => $m->getDisplayName(), $members);
        $this->assertSame(['Baloo', 'Mowgli'], $names);
    }

    public function testWithoutAProviderNothingIsInjected(): void
    {
        $this->seedMember(self::ADMIN_EMAIL, 'Baloo');
        $this->seedMember(self::ANIME_EMAIL, 'Mowgli');

        $members = $this->plainService()->getLinkedMembers(self::ADMIN_EMAIL, $this->yearId);

        $this->assertCount(1, $members);
        $this->assertSame('Baloo', $members[0]->getDisplayName());
    }

    public function testTemporaryMemberNeverLeaksIntoAnotherAccountsList(): void
    {
        $anime = $this->seedMember(self::ANIME_EMAIL, 'Mowgli');
        $this->seedMember('someone.else@example.com', 'Kaa');

        // The override belongs to ADMIN_EMAIL; asking about a third party
        // must not surface it — several callers pass a non-session email.
        $members = $this->serviceWithOverride($anime['memberYearId'])
            ->getLinkedMembers('someone.else@example.com', $this->yearId);

        $this->assertCount(1, $members);
        $this->assertSame('Kaa', $members[0]->getDisplayName());
    }

    public function testTemporaryMemberIsNotInjectedIntoADifferentScoutYear(): void
    {
        $anime = $this->seedMember(self::ANIME_EMAIL, 'Mowgli', $this->yearId);

        $members = $this->serviceWithOverride($anime['memberYearId'])
            ->getLinkedMembers(self::ADMIN_EMAIL, $this->otherYearId);

        $this->assertSame([], $members);
    }

    public function testTemporaryMemberIsNotDuplicatedWhenAlreadyLinkedByEmail(): void
    {
        $own = $this->seedMember(self::ADMIN_EMAIL, 'Baloo');

        $members = $this->serviceWithOverride($own['memberYearId'])
            ->getLinkedMembers(self::ADMIN_EMAIL, $this->yearId);

        $this->assertCount(1, $members);
    }

    public function testAnUnknownMemberYearIdIsIgnored(): void
    {
        $this->seedMember(self::ADMIN_EMAIL, 'Baloo');

        $members = $this->serviceWithOverride(999999)
            ->getLinkedMembers(self::ADMIN_EMAIL, $this->yearId);

        $this->assertCount(1, $members);
        $this->assertSame('Baloo', $members[0]->getDisplayName());
    }

    public function testAnInactiveMemberYearIsNeverInjected(): void
    {
        $this->seedMember(self::ADMIN_EMAIL, 'Baloo');
        $inactive = $this->seedMember(self::ANIME_EMAIL, 'Mowgli', null, false);

        // The search page lists inactive members, so an override can name
        // one; getLinkedMembers() only ever returns is_active = 1 rows and
        // the injected member has to match that contract.
        $members = $this->serviceWithOverride($inactive['memberYearId'])
            ->getLinkedMembers(self::ADMIN_EMAIL, $this->yearId);

        $this->assertCount(1, $members);
        $this->assertSame('Baloo', $members[0]->getDisplayName());
    }

    public function testIsLinkedToMemberRejectsAnInactiveTemporaryMember(): void
    {
        $inactive = $this->seedMember(self::ANIME_EMAIL, 'Mowgli', null, false);

        $this->assertFalse(
            $this->serviceWithOverride($inactive['memberYearId'])
                ->isLinkedToMember(self::ADMIN_EMAIL, $inactive['memberId'], $this->yearId)
        );
    }

    public function testIsLinkedToMemberAcceptsTheTemporaryMember(): void
    {
        $anime = $this->seedMember(self::ANIME_EMAIL, 'Mowgli');

        // The deliberate widening: "this is really you" now also answers yes
        // for a temporary member, which is what makes the member page's own
        // photo upload reachable while the override is active.
        $this->assertTrue(
            $this->serviceWithOverride($anime['memberYearId'])
                ->isLinkedToMember(self::ADMIN_EMAIL, $anime['memberId'], $this->yearId)
        );

        $this->assertFalse(
            $this->plainService()->isLinkedToMember(self::ADMIN_EMAIL, $anime['memberId'], $this->yearId)
        );
    }

    public function testIsLinkedToMemberStillRejectsAnUnrelatedMember(): void
    {
        $anime = $this->seedMember(self::ANIME_EMAIL, 'Mowgli');
        $other = $this->seedMember('someone.else@example.com', 'Kaa');

        $this->assertFalse(
            $this->serviceWithOverride($anime['memberYearId'])
                ->isLinkedToMember(self::ADMIN_EMAIL, $other['memberId'], $this->yearId)
        );
    }
}
