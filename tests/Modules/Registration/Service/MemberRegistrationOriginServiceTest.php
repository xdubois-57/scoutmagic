<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Modules\Registration\Service\MemberRegistrationOriginService;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * Where a member came from, as the admin member page shows it.
 *
 * The cases worth pinning are the two that decide whether the block is
 * honest: it points, it never copies; and having no request at all is
 * the ordinary answer rather than a failure.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberRegistrationOriginServiceTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationRequestRepository $repository;
    private MemberRegistrationOriginService $service;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new RegistrationRequestRepository($this->pdo, $encryption);
        $this->service = new MemberRegistrationOriginService($this->repository);
        $this->scoutYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function fields(array $overrides = []): array
    {
        return array_merge([
            'parent_name' => 'Marie Dupont',
            'child_last_name' => 'Dupont',
            'child_first_name' => 'Léa',
            'gender' => 'F',
            'birth_date' => '2019-05-12',
            'street' => 'Rue de la Paix',
            'number' => '12',
            'postal_code' => '1000',
            'city' => 'Bruxelles',
            'email' => 'marie.dupont@example.com',
            'phone1' => '0470123456',
            'phone2' => null,
            'remarks' => null,
        ], $overrides);
    }

    private function encodedRequestFor(int $memberId): int
    {
        $created = $this->repository->create($this->scoutYearId, $this->fields(), null, []);
        $this->repository->linkToMemberAndEncode(
            $created['id'],
            $memberId,
            new \DateTimeImmutable('2026-04-02 11:00:00')
        );

        return $created['id'];
    }

    public function testAMemberBornOfARequestGetsALinkToIt(): void
    {
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'D1');
        $requestId = $this->encodedRequestFor($memberId);

        $origin = $this->service->getRegistrationOrigin($memberId);

        $this->assertNotNull($origin);
        $this->assertSame('/config/inscriptions/demandes/' . $requestId, $origin->path);
        $this->assertSame('Encodée dans Desk', $origin->statusLabel);
        $this->assertStringStartsWith('Demande du ', $origin->label);
    }

    /**
     * A pointer, never a copy: the request keeps its own page, and
     * recopying its fields here would create a second place to keep in
     * step with Desk. Nothing the parent typed may travel with the link.
     */
    public function testTheOriginCarriesNothingOfTheRequestsContent(): void
    {
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'D1');
        $this->encodedRequestFor($memberId);

        $origin = $this->service->getRegistrationOrigin($memberId);
        $this->assertNotNull($origin);

        $serialized = json_encode($origin, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($serialized);
        foreach (['Marie Dupont', 'Léa', 'marie.dupont@example.com', '0470123456', 'Rue de la Paix'] as $personal) {
            $this->assertStringNotContainsString($personal, $serialized);
        }
    }

    /**
     * The ordinary case, not an anomaly: every member imported from Desk
     * before this module existed, and everyone who joined another way.
     */
    public function testAMemberWithNoRequestGetsNothingRatherThanAnEmptyBlock(): void
    {
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'D1');

        $this->assertNull($this->service->getRegistrationOrigin($memberId));
    }

    /**
     * A request that has not been linked yet belongs to nobody: it is
     * still a request, and pointing a member at it would claim a
     * migration that never happened.
     */
    public function testAnUnlinkedRequestIsNotAnybodysOrigin(): void
    {
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'D1');
        $this->repository->create($this->scoutYearId, $this->fields(), null, []);

        $this->assertNull($this->service->getRegistrationOrigin($memberId));
    }

    public function testOneMembersRequestIsNeverAnothersOrigin(): void
    {
        $first = RegistrationTestHelper::insertMember($this->pdo, 'D1');
        $second = RegistrationTestHelper::insertMember($this->pdo, 'D2');
        $this->encodedRequestFor($first);

        $this->assertNotNull($this->service->getRegistrationOrigin($first));
        $this->assertNull($this->service->getRegistrationOrigin($second));
    }
}
