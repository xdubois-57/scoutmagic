<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Finance\Api\ReceivableDestination;
use Modules\Finance\Api\ReceivableViewer;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ExpectedReceivableService;
use Modules\Finance\Service\ReceivablesOverviewService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReceivablesOverviewServiceTest extends TestCase
{
    private \PDO $pdo;
    private ReceivablesOverviewService $service;
    private ExpectedReceivableService $receivableService;
    private ExpectedReceivableRepository $repository;
    private AccountRepository $accountRepository;
    private \Modules\Finance\Service\AccountVisibility $visibility;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new ExpectedReceivableRepository($this->pdo, $encryption);
        $transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->receivableService = FinanceTestHelper::receivableService($this->pdo, $encryption, $this->repository);
        $this->accountRepository = new AccountRepository($this->pdo, $encryption);
        $this->visibility = new \Modules\Finance\Service\AccountVisibility(
            // No badge assigned in these fixtures, so the treasurer rule is
            // off and the module behaves exactly as it did before it
            // existed — which is what these tests assert.
            \Modules\Finance\Service\TreasurerScope::systemCaller()
        );

        // No describer: the shape a module nobody anticipated produces.
        // The tests about naming build their own.
        $this->service = $this->serviceWith();

        $stmt = $this->pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        $this->accountId = (int) $this->pdo->lastInsertId();
    }

    public function testBuildOverviewReturnsEmptyArrayWithNoReceivables(): void
    {
        $this->assertSame([], $this->service->buildOverview(Role::INTENDANT));
    }

    public function testBuildOverviewGroupsBySourceModuleThenBySourceReferenceId(): void
    {
        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', 'Alice');
        $this->receivableService->createReceivable('news', 1, $this->accountId, 3000, '+++200/0000/00068+++', 'Bob');
        $this->receivableService->createReceivable('news', 2, $this->accountId, 1000, '+++300/0000/00005+++', 'Carla');

        $overview = $this->service->buildOverview(Role::INTENDANT);

        $this->assertCount(1, $overview);
        $this->assertSame('news', $overview[0]['source_module']);
        // No describer wired in this fixture — the naming tests below own
        // that question.
        $this->assertSame('News', $overview[0]['source_label']);
        $this->assertCount(2, $overview[0]['instances']);

        $instance1 = current(array_filter($overview[0]['instances'], fn($i) => $i['source_reference_id'] === 1));
        $this->assertCount(2, $instance1['receivables']);
        $this->assertSame(5500, $instance1['amount_due']);
    }

    // ── « Nom/Contact » : qui doit cet argent ───────────────────────────

    /**
     * `member_id` has always said WHO owes a receivable — the schema says
     * so in as many words — and the column headed « Nom/Contact » printed
     * « — » for one that carried a debtor and no free text.
     */
    public function testAReceivableWithNoLabelIsNamedAfterItsMember(): void
    {
        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', null, 42);

        $overview = $this->serviceNaming([42 => 'Jean Dupont'])->buildOverview(Role::INTENDANT);

        $this->assertSame('Jean Dupont', $overview[0]['receivables'][0]['label']);
    }

    public function testTheSourceModulesOwnTextWins(): void
    {
        // « Caution LOC-D4E5F6 — Jean Dupont » says more than a name:
        // the module wrote it because it knew something this page does not.
        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', 'Caution — Jean Dupont', 42);

        $overview = $this->serviceNaming([42 => 'Jean Dupont'])->buildOverview(Role::INTENDANT);

        $this->assertSame('Caution — Jean Dupont', $overview[0]['receivables'][0]['label']);
    }

    public function testAMemberNobodyCanNameStaysUnnamed(): void
    {
        // "We do not know" is not "their name is blank": the template
        // prints its own dash.
        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', null, 42);

        $overview = $this->serviceNaming([])->buildOverview(Role::INTENDANT);

        $this->assertNull($overview[0]['receivables'][0]['label']);
    }

    public function testAReceivableWithNoDebtorAtAllIsUnaffected(): void
    {
        // An outside renter is nobody's member — the column is empty and
        // that is the truth.
        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', null);

        $overview = $this->serviceNaming([42 => 'Jean Dupont'])->buildOverview(Role::INTENDANT);

        $this->assertNull($overview[0]['receivables'][0]['label']);
    }

    public function testTheNamesAreResolvedInOneCallForTheWholePage(): void
    {
        // A lookup per row decrypts a name per row. Two hundred rows is
        // two hundred round trips through the encryption service.
        foreach ([1, 2, 3] as $i) {
            $this->receivableService->createReceivable(
                'news',
                $i,
                $this->accountId,
                2500,
                \Modules\Finance\Service\StructuredCommunicationService::format('10' . $i . '0000000'),
                null,
                40 + $i
            );
        }

        $calls = 0;
        $service = $this->serviceWith(memberNames: function (array $ids) use (&$calls): array {
            $calls++;

            return [41 => 'Anna', 42 => 'Bruno', 43 => 'Carla'];
        });

        $labels = array_column($service->buildOverview(Role::INTENDANT)[0]['receivables'], 'label');

        $this->assertSame(1, $calls);
        $this->assertSame(['Anna', 'Bruno', 'Carla'], $labels);
    }

    public function testAResolverThatThrowsLeavesTheRowsUnnamedRatherThanThePageBroken(): void
    {
        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', null, 42);

        $overview = $this->serviceWith(memberNames: static function (array $ids): array {
            throw new \RuntimeException('boom');
        })->buildOverview(Role::INTENDANT);

        $this->assertNull($overview[0]['receivables'][0]['label']);
    }

    public function testTheViewModelNeverCarriesAMemberIdToTheTemplate(): void
    {
        // A template has no use for one, and one it can reach is one it
        // will eventually print.
        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', null, 42);

        $overview = $this->serviceNaming([42 => 'Jean Dupont'])->buildOverview(Role::INTENDANT);

        $this->assertArrayNotHasKey('member_id', $overview[0]['receivables'][0]);
        $this->assertArrayNotHasKey('member_id', $overview[0]['instances'][0]['receivables'][0]);
    }

    /**
     * @param array<int, string> $names
     */
    private function serviceNaming(array $names): ReceivablesOverviewService
    {
        return $this->serviceWith(memberNames: static fn(array $ids): array => $names);
    }

    // ── Le module source nomme ses propres créances ─────────────────────

    /**
     * Finance knows a source instance only as a numeric id — that is what
     * lets this page work for any future module — so the group used to be
     * headed « Rental #45 »: a primary key, in English, above rows already
     * reading « LOC-D4E5F6 — Jean Dupont ».
     */
    public function testTheSourceModuleNamesItsOwnGroupAndItsOwnInstances(): void
    {
        $this->givenARentalBookingWithItsDeposit();

        $overview = $this->serviceWith([new FakeReceivableSourceDescriber(
            'rental',
            'Locations',
            static fn(int $id): ?string => $id === 45 ? 'LOC-D4E5F6 — Jean Dupont' : null
        )])->buildOverview(Role::INTENDANT);

        $this->assertSame('Locations', $overview[0]['source_label']);
        // A booking and its deposit share a source_reference_id, so the
        // middle level does group here — and now says what it groups.
        $this->assertTrue($overview[0]['groups_instances']);
        $this->assertSame('LOC-D4E5F6 — Jean Dupont', $overview[0]['instances'][0]['instance_label']);
    }

    public function testASourceWithNoDescriberKeepsTheOldNames(): void
    {
        // A module nobody anticipated must still appear, rather than
        // disappear for want of a describer.
        $this->givenARentalBookingWithItsDeposit();

        $overview = $this->service->buildOverview(Role::INTENDANT);

        $this->assertSame('Rental', $overview[0]['source_label']);
        $this->assertSame('Rental #45', $overview[0]['instances'][0]['instance_label']);
    }

    public function testAReferenceItsModuleNoLongerRecognisesFallsBackToTheId(): void
    {
        // The booking was deleted. Naming the group by its id is honest;
        // an invented name would not be.
        $this->givenARentalBookingWithItsDeposit();

        $overview = $this->serviceWith([new FakeReceivableSourceDescriber(
            'rental',
            'Locations',
            static fn(int $id): ?string => null
        )])->buildOverview(Role::INTENDANT);

        $this->assertSame('Locations', $overview[0]['source_label']);
        $this->assertSame('Rental #45', $overview[0]['instances'][0]['instance_label']);
    }

    public function testADescriberThatThrowsDoesNotTakeThePageDown(): void
    {
        // One module unable to name its own object must not cost the
        // treasurer the list of everybody's.
        $this->givenARentalBookingWithItsDeposit();

        $overview = $this->serviceWith([new FakeReceivableSourceDescriber(
            'rental',
            'Locations',
            static function (int $id): ?string {
                throw new \RuntimeException('boom');
            },
            throwOnSourceLabel: true
        )])->buildOverview(Role::INTENDANT);

        $this->assertSame('Rental', $overview[0]['source_label']);
        $this->assertSame('Rental #45', $overview[0]['instances'][0]['instance_label']);
    }

    public function testADescriberOnlyEverSpeaksForItsOwnModule(): void
    {
        $this->givenARentalBookingWithItsDeposit();
        $this->receivableService->createReceivable('news', 12, $this->accountId, 1000, '+++600/0000/00010+++', 'Alice');

        $overview = $this->serviceWith([new FakeReceivableSourceDescriber(
            'rental',
            'Locations',
            static fn(int $id): ?string => 'LOC-D4E5F6 — Jean Dupont'
        )])->buildOverview(Role::INTENDANT);

        $byModule = array_column($overview, 'source_label', 'source_module');
        $this->assertSame('Locations', $byModule['rental']);
        $this->assertSame('News', $byModule['news'], 'the rental describer must not name another module');
    }

    /**
     * The reference dataset's membership fees have no module behind them,
     * so their group is named by finance's fallback — which used to print
     * « Reference_dataset » (issue #836). The fixture's source now reads
     * right through that fallback, with no special case in production.
     */
    public function testTheReferenceDatasetsFeesAreGroupedUnderCotisations(): void
    {
        $this->receivableService->createReceivable(
            \Tests\Fixtures\ReferenceDataset\ExtrasBlueprint::RECEIVABLE_SOURCE_MODULE,
            1,
            $this->accountId,
            6500,
            '+++600/0000/00010+++',
            'Cotisation 2026-2027'
        );

        $this->assertSame('Cotisations', $this->service->buildOverview(Role::INTENDANT)[0]['source_label']);
    }

    // ── « Ouvrir … » : l'écran où la créance se gère (issue #836) ───────

    public function testAGroupLinksToTheScreenItsModuleNamesForThisViewer(): void
    {
        $this->givenARentalBookingWithItsDeposit();
        $viewer = new ReceivableViewer('tresorier@example.org', Role::INTENDANT);

        $overview = $this->serviceWith([new FakeReceivableSourceDestination(
            'rental',
            static fn(int $id, ReceivableViewer $who): ?ReceivableDestination => $id === 45 && $who === $viewer
                ? new ReceivableDestination('Ouvrir la réservation', '/mes-locations/chalet/reservations/45/finances')
                : null
        )])->buildOverview(Role::INTENDANT, $viewer);

        $expected = ['label' => 'Ouvrir la réservation', 'url' => '/mes-locations/chalet/reservations/45/finances'];
        $this->assertSame($expected, $overview[0]['instances'][0]['destination']);
        // Each row carries it too, for when the middle level is dropped.
        $this->assertSame([$expected, $expected], array_column($overview[0]['receivables'], 'destination'));
    }

    public function testNoViewerMeansNoLinkIsEvenAskedFor(): void
    {
        $this->givenARentalBookingWithItsDeposit();
        $asked = false;

        $overview = $this->serviceWith([new FakeReceivableSourceDestination(
            'rental',
            static function () use (&$asked): ?ReceivableDestination {
                $asked = true;

                return new ReceivableDestination('Ouvrir', '/x');
            }
        )])->buildOverview(Role::INTENDANT);

        $this->assertFalse($asked);
        $this->assertNull($overview[0]['instances'][0]['destination']);
    }

    public function testASourceWithNoDestinationStillShowsItsRowsWithoutALink(): void
    {
        // A describer that names its groups but has no screen to send
        // anybody to — the shape a plain ReceivableSourceDescriberInterface has.
        $this->givenARentalBookingWithItsDeposit();

        $overview = $this->serviceWith([new FakeReceivableSourceDescriber(
            'rental',
            'Locations',
            static fn(int $id): ?string => 'LOC-D4E5F6'
        )])->buildOverview(Role::INTENDANT, new ReceivableViewer('a@example.org', Role::ADMIN));

        $this->assertCount(2, $overview[0]['receivables']);
        $this->assertNull($overview[0]['instances'][0]['destination']);
    }

    public function testADestinationThatThrowsIsSimplyNotShown(): void
    {
        $this->givenARentalBookingWithItsDeposit();

        $overview = $this->serviceWith([new FakeReceivableSourceDestination(
            'rental',
            static function (): ?ReceivableDestination {
                throw new \RuntimeException('boom');
            }
        )])->buildOverview(Role::INTENDANT, new ReceivableViewer('a@example.org', Role::ADMIN));

        $this->assertNull($overview[0]['instances'][0]['destination']);
        $this->assertSame('Locations', $overview[0]['source_label'], 'the page itself survives');
    }

    private function givenARentalBookingWithItsDeposit(): void
    {
        $this->receivableService->createReceivable('rental', 45, $this->accountId, 30000, '+++400/0000/00039+++', 'LOC-D4E5F6 — Jean Dupont');
        $this->receivableService->createReceivable('rental', 45, $this->accountId, 15000, '+++500/0000/00073+++', 'Caution LOC-D4E5F6 — Jean Dupont');
    }

    /**
     * @param \Modules\Finance\Api\ReceivableSourceDescriberInterface[] $describers
     */
    private function serviceWith(array $describers = [], ?\Closure $memberNames = null): ReceivablesOverviewService
    {
        return new ReceivablesOverviewService(
            $this->repository,
            $this->receivableService,
            $this->accountRepository,
            $this->visibility,
            $describers,
            $memberNames
        );
    }

    // ── Le niveau intermédiaire ne s'affiche que s'il groupe ────────────

    /**
     * The middle level of the page is a collapsible group per
     * `source_reference_id`. It is worth a heading and a click when it
     * gathers several receivables — one form answered by three families.
     */
    public function testAnInstanceHoldingSeveralReceivablesIsWorthGrouping(): void
    {
        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', 'Alice');
        $this->receivableService->createReceivable('news', 1, $this->accountId, 3000, '+++200/0000/00068+++', 'Bob');

        $overview = $this->service->buildOverview(Role::INTENDANT);

        $this->assertTrue($overview[0]['groups_instances']);
    }

    /**
     * And it is worth nothing when every instance holds exactly one: the
     * reader gets N collapsed headers, each named after a database id
     * nobody recognises, each hiding a single line whose subtotal is that
     * line. A module registering one receivable per payer produces exactly
     * that, and it turned the page into sixteen accordions to read sixteen
     * rows.
     */
    public function testInstancesOfOneReceivableEachAreNotWorthGrouping(): void
    {
        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', 'Alice');
        $this->receivableService->createReceivable('news', 2, $this->accountId, 3000, '+++200/0000/00068+++', 'Bob');
        $this->receivableService->createReceivable('news', 3, $this->accountId, 1000, '+++300/0000/00005+++', 'Carla');

        $overview = $this->service->buildOverview(Role::INTENDANT);

        $this->assertFalse($overview[0]['groups_instances']);
        $this->assertCount(3, $overview[0]['instances'], 'the instances are still built — only the page hides them');
    }

    public function testTheFlatListCarriesEveryReceivableOfTheSourceInOrder(): void
    {
        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', 'Alice');
        $this->receivableService->createReceivable('news', 2, $this->accountId, 3000, '+++200/0000/00068+++', 'Bob');

        $overview = $this->service->buildOverview(Role::INTENDANT);

        $this->assertSame(
            ['Alice', 'Bob'],
            array_column($overview[0]['receivables'], 'label')
        );
    }

    public function testEveryFlattenedRowKnowsWhichInstanceItCameFrom(): void
    {
        // Without it a « voir cette créance » link has nothing to point at
        // once the instance headings are gone.
        $this->receivableService->createReceivable('news', 7, $this->accountId, 2500, '+++100/0000/00034+++', 'Alice');

        $overview = $this->service->buildOverview(Role::INTENDANT);

        $this->assertSame(7, $overview[0]['receivables'][0]['source_reference_id']);
        $this->assertSame(7, $overview[0]['instances'][0]['receivables'][0]['source_reference_id']);
    }

    public function testTheFlatListAndTheGroupedOneHoldTheSameRows(): void
    {
        // The page renders one or the other; they must not be able to show
        // different money.
        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', 'Alice');
        $this->receivableService->createReceivable('news', 1, $this->accountId, 3000, '+++200/0000/00068+++', 'Bob');
        $this->receivableService->createReceivable('news', 2, $this->accountId, 1000, '+++300/0000/00005+++', 'Carla');

        $overview = $this->service->buildOverview(Role::INTENDANT);

        $grouped = [];
        foreach ($overview[0]['instances'] as $instance) {
            foreach ($instance['receivables'] as $row) {
                $grouped[] = $row;
            }
        }

        $this->assertEquals($grouped, $overview[0]['receivables']);
        $this->assertSame(
            $overview[0]['amount_due'],
            array_sum(array_column($overview[0]['receivables'], 'amount_due'))
        );
    }

    /**
     * The page is role_min: intendant, but WHICH accounts' receivables it
     * shows is a per-account decision (role_min_view) — the same boundary
     * every other finance page applies through
     * FinanceService::getAccountsForUser(). This page used to skip it
     * entirely and render every row in the table, handing an intendant the
     * label, payer communication and reconciled amounts of accounts they
     * cannot otherwise see anywhere in the module.
     */
    public function testReceivablesOnAnAccountAboveTheViewersRoleAreNotShown(): void
    {
        $adminOnlyAccountId = $this->createAccount('Compte direction', 'admin');

        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', 'Alice');
        $this->receivableService->createReceivable('news', 2, $adminOnlyAccountId, 9900, '+++900/0000/00015+++', 'Secret Donor');

        $asIntendant = $this->service->buildOverview(Role::INTENDANT);

        $this->assertCount(1, $asIntendant);
        $this->assertCount(1, $asIntendant[0]['instances'], 'the admin-only account\'s instance must not appear');
        $this->assertSame(2500, $asIntendant[0]['amount_due'], 'its amount must not be folded into the totals either');

        $communications = array_column($asIntendant[0]['instances'][0]['receivables'], 'communication');
        $this->assertSame(['+++100/0000/00034+++'], $communications);

        $serialized = json_encode($asIntendant);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('Secret Donor', $serialized);
        $this->assertStringNotContainsString('+++900/0000/00015+++', $serialized);
    }

    public function testAViewerWhoClearsTheAccountsFloorSeesItsReceivables(): void
    {
        $adminOnlyAccountId = $this->createAccount('Compte direction', 'admin');
        $this->receivableService->createReceivable('news', 2, $adminOnlyAccountId, 9900, '+++900/0000/00015+++', 'Donor');

        $asAdmin = $this->service->buildOverview(Role::ADMIN);

        $this->assertCount(1, $asAdmin);
        $this->assertSame(9900, $asAdmin[0]['amount_due']);
    }

    /**
     * A source module whose every receivable is invisible must drop out
     * altogether rather than render as an empty accordion — an empty
     * "Formulaires" section still tells an intendant that receivables they
     * may not see exist.
     */
    public function testASourceModuleWithNoVisibleReceivablesDisappearsEntirely(): void
    {
        $adminOnlyAccountId = $this->createAccount('Compte direction', 'admin');
        $this->receivableService->createReceivable('news', 1, $adminOnlyAccountId, 500, '+++111/0000/00094+++', 'Donor');

        $this->assertSame([], $this->service->buildOverview(Role::INTENDANT));
    }

    /**
     * Visibility keys on role_min_view alone, never on the account's status:
     * a receivable booked against an account that has since been archived
     * must still reconcile for someone allowed to see that account, or money
     * silently vanishes from the totals.
     */
    public function testAnArchivedAccountsReceivablesStillReconcile(): void
    {
        $archivedId = $this->createAccount('Ancien compte', 'intendant', 'archived');
        $this->receivableService->createReceivable('news', 1, $archivedId, 750, '+++222/0000/00091+++', 'Alice');

        $overview = $this->service->buildOverview(Role::INTENDANT);

        $this->assertCount(1, $overview);
        $this->assertSame(750, $overview[0]['amount_due']);
    }

    private function createAccount(string $name, string $roleMinView, string $status = 'active'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_accounts (name, account_type, role_min_view, status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, 'bank', $roleMinView, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testBuildOverviewComputesTotalsAtEveryLevel(): void
    {
        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', 'Alice');

        $overview = $this->service->buildOverview(Role::INTENDANT);

        $this->assertSame(2500, $overview[0]['amount_due']);
        $this->assertSame(0, $overview[0]['amount_received']);
        $this->assertSame(2500, $overview[0]['instances'][0]['amount_due']);
    }
}

/**
 * A source module naming its own receivables, without dragging that
 * module's repositories into this test.
 *
 * @internal
 */
final class FakeReceivableSourceDescriber implements \Modules\Finance\Api\ReceivableSourceDescriberInterface
{
    public function __construct(
        private string $sourceModule,
        private string $sourceLabel,
        private \Closure $onDescribeInstance,
        private bool $throwOnSourceLabel = false
    ) {
    }

    public function sourceModule(): string
    {
        return $this->sourceModule;
    }

    public function sourceLabel(): string
    {
        if ($this->throwOnSourceLabel) {
            throw new \RuntimeException('boom');
        }

        return $this->sourceLabel;
    }

    public function describeInstance(int $sourceReferenceId): ?string
    {
        return ($this->onDescribeInstance)($sourceReferenceId);
    }
}

/**
 * A describer that also says where its receivables are managed (issue
 * #836).
 *
 * @internal
 */
final class FakeReceivableSourceDestination implements
    \Modules\Finance\Api\ReceivableSourceDescriberInterface,
    \Modules\Finance\Api\ReceivableSourceDestinationInterface
{
    /**
     * @param \Closure(int, ReceivableViewer): ?ReceivableDestination $onDestination
     */
    public function __construct(private string $sourceModule, private \Closure $onDestination)
    {
    }

    public function sourceModule(): string
    {
        return $this->sourceModule;
    }

    public function sourceLabel(): string
    {
        return 'Locations';
    }

    public function describeInstance(int $sourceReferenceId): ?string
    {
        return null;
    }

    public function destinationFor(int $sourceReferenceId, ReceivableViewer $viewer): ?ReceivableDestination
    {
        return ($this->onDestination)($sourceReferenceId, $viewer);
    }
}
