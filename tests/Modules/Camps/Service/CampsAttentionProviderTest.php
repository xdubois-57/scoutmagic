<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Security\EncryptionService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Service\CampsAttentionProvider;
use Modules\InboundMail\Api\InboundMailInterface;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

#[\PHPUnit\Framework\Attributes\Group('database')]
class CampsAttentionProviderTest extends TestCase
{
    private CampRepository $camps;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($pdo);
        $this->camps = new CampRepository($pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $pdo->exec("INSERT INTO camp_places (name) VALUES ('Domaine de Mozet')");
    }

    private function inboundMailWith(int $waiting): InboundMailInterface
    {
        return new class ($waiting) implements InboundMailInterface {
            use \Tests\Modules\InboundMail\InertInboundMail;

            public function __construct(private int $waiting)
            {
            }

            public function countCandidatesFor(string $consumerId): int
            {
                return $consumerId === 'camps' ? $this->waiting : 99;
            }
        };
    }

    private function stay(string $status): void
    {
        $this->camps->create(1, Camp::STAY_GRAND_CAMP, '2026-07-12', '2026-07-19', null, $status, null, null, null, null, []);
    }

    public function testNothingToDecideIsNoPoint(): void
    {
        $this->stay(Camp::STATUS_CONFIRMED);

        $this->assertSame([], (new CampsAttentionProvider($this->camps, $this->inboundMailWith(0)))->collect(1));
    }

    public function testPropositionsAndStaysToConfirmAreTwoSeparatePoints(): void
    {
        $this->stay(Camp::STATUS_TO_CONFIRM);
        $this->stay(Camp::STATUS_TO_CONFIRM);
        $this->stay(Camp::STATUS_CANCELLED);

        $points = (new CampsAttentionProvider($this->camps, $this->inboundMailWith(1)))->collect(1);

        $this->assertCount(2, $points);
        $this->assertSame('1 message reçu attend une décision sur un séjour', $points[0]->title);
        $this->assertSame('/chefs/camps/courrier', $points[0]->actionUrl);
        $this->assertSame('2 séjours « à confirmer »', $points[1]->title);
        $this->assertSame('/chefs/camps', $points[1]->actionUrl);
    }

    public function testWithoutInboundMailOnlyTheStaysAreCounted(): void
    {
        $this->stay(Camp::STATUS_TO_CONFIRM);

        $points = (new CampsAttentionProvider($this->camps))->collect(1);

        $this->assertCount(1, $points);
        $this->assertSame('1 séjour « à confirmer »', $points[0]->title);
    }
}
