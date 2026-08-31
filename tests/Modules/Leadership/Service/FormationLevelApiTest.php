<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Service;

use Core\Database\Connection;
use Modules\Leadership\FormationStep;
use Modules\Leadership\Repository\FormationLevelMappingRepository;
use Modules\Leadership\Service\FormationLevelApi;
use Modules\Leadership\Service\FormationLevelResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Leadership\LeadershipTestHelper;

/**
 * The module's public reading of a Desk formation wording (roadmap IT-21,
 * ARCHITECTURE.md §7.5) — what `fees` consumes to decide whether the
 * federation's reduction applies.
 *
 * @group database
 */
#[Group('database')]
class FormationLevelApiTest extends TestCase
{
    private \PDO $pdo;
    private FormationLevelMappingRepository $mappingRepository;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        LeadershipTestHelper::createTables($this->pdo);

        $this->mappingRepository = new FormationLevelMappingRepository(Connection::withPdo($this->pdo));
    }

    public function testItAnswersTheTwoQuestionsSeparately(): void
    {
        $api = $this->api();

        $bacv = $api->resolve('BACV');
        $this->assertSame('bacv', $bacv->code);
        $this->assertSame('BACV', $bacv->label);
        $this->assertTrue($bacv->recognised);
        $this->assertTrue($bacv->countsForOneRatio);
        $this->assertTrue($bacv->countsForFederationDiscount);

        $woodbadge = $api->resolve('Woodbadge');
        $this->assertFalse($woodbadge->countsForOneRatio, 'the ONE does not recognise it');
        $this->assertTrue($woodbadge->countsForFederationDiscount, 'the federation reduction does');
    }

    /**
     * The unit's own decision wins over the heuristic — that is the whole
     * reason a consumer asks this module rather than reading the string
     * itself.
     */
    public function testTheAdminMappingReachesTheConsumer(): void
    {
        $this->mappingRepository->save('Wording maison', FormationStep::BACV);

        $level = $this->api()->resolve('Wording maison');

        $this->assertSame('bacv', $level->code);
        $this->assertTrue($level->countsForFederationDiscount);
    }

    /**
     * « recognised » is the field that keeps an unreadable wording from
     * being reported as "no brevet": both are false on the discount, and
     * only this tells them apart.
     */
    public function testAnUnreadableWordingSaysSoRatherThanAnsweringNo(): void
    {
        $level = $this->api()->resolve('Zorglub');

        $this->assertFalse($level->recognised);
        $this->assertFalse($level->countsForFederationDiscount);
        $this->assertSame('unknown', $level->code);

        // Nothing encoded is a different answer again: the site is not
        // unsure, Desk simply says the person has not started.
        $nothing = $this->api()->resolve(null);
        $this->assertTrue($nothing->recognised);
        $this->assertSame('none', $nothing->code);
    }

    /**
     * A consumer walks a whole roster, so the mapping is read once — not
     * once per member, and not on every request that merely builds the
     * composition root.
     */
    public function testTheMappingIsReadOnceAndOnlyWhenSomethingIsAsked(): void
    {
        $counting = new class (Connection::withPdo($this->pdo)) extends FormationLevelMappingRepository {
            public int $calls = 0;

            /** @return array<string, string> */
            public function findAll(): array
            {
                $this->calls++;

                return parent::findAll();
            }
        };

        $api = new FormationLevelApi($counting, new FormationLevelResolver());
        $this->assertSame(0, $counting->calls, 'building it must cost no query');

        $api->resolve('T1');
        $api->resolve('BACV');
        $api->resolve('Zorglub');

        $this->assertSame(1, $counting->calls);
    }

    private function api(): FormationLevelApi
    {
        return new FormationLevelApi($this->mappingRepository, new FormationLevelResolver());
    }
}
