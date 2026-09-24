<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\AgeBranchRepository;
use Core\Import\FeeCategoryRepository;
use Core\Import\FunctionRepository;
use Core\Import\ImportSectionRepository;
use Core\Import\MappingResolver;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Modules\Fees\Api\HouseholdTariffRecognitionInterface;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MappingResolverTest extends TestCase
{
    private \PDO $pdo;
    private MappingResolver $resolver;
    private FunctionRepository $functionRepo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->functionRepo = new FunctionRepository($this->pdo);
        $ageBranchRepo = new AgeBranchRepository($this->pdo);
        $sectionRepo = new ImportSectionRepository($this->pdo);
        $feeRepo = new FeeCategoryRepository($this->pdo);

        $this->resolver = new MappingResolver($this->functionRepo, $ageBranchRepo, $sectionRepo, $feeRepo);
    }

    public function testResolveFunctionWithKnownFunctionReturnsExistingId(): void
    {
        $id = $this->functionRepo->create('Animateur', 'Animateur', 'chief', true);
        $resolved = $this->resolver->resolveFunction('Animateur');

        $this->assertSame($id, $resolved);
        $this->assertSame(0, $this->resolver->getNewFunctionsCount());
    }

    public function testResolveFunctionWithUnknownCreatesNew(): void
    {
        $id = $this->resolver->resolveFunction('Inconnu');

        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, $this->resolver->getNewFunctionsCount());
    }

    public function testResolveFunctionNewEntryHasIdentifiedRoleAndUnconfirmed(): void
    {
        $this->resolver->resolveFunction('Nouvelle Fonction');

        $fn = $this->functionRepo->findByDeskCode('Nouvelle Fonction');
        $this->assertNotNull($fn);
        $this->assertSame('identified', $fn['role']);
        $this->assertFalse($fn['confirmed']);
    }

    public function testResolveFunctionRoleNeverElevatedAutomatically(): void
    {
        // Create multiple unknown functions — none should get elevated.
        // The first is a real Desk label whose confirmed role IS `admin`,
        // which is exactly the point: the CSV never carries a role, so the
        // import cannot know that and must not guess it.
        $this->resolver->resolveFunction('Animateur d\'unité');
        $this->resolver->resolveFunction('Administrateur');
        $this->resolver->resolveFunction('Directeur');

        foreach (['Animateur d\'unité', 'Administrateur', 'Directeur'] as $code) {
            $fn = $this->functionRepo->findByDeskCode($code);
            $this->assertNotNull($fn);
            $this->assertSame('identified', $fn['role'], "Function '$code' should not be auto-elevated.");
            $this->assertFalse($fn['confirmed']);
        }
    }

    public function testResolveBranchAutoCreates(): void
    {
        $id = $this->resolver->resolveBranch('Louveteaux');
        $this->assertGreaterThan(0, $id);

        // Second call returns same ID
        $id2 = $this->resolver->resolveBranch('Louveteaux');
        $this->assertSame($id, $id2);
    }

    public function testResolveSectionAutoCreatesWithBranchLink(): void
    {
        $branchId = $this->resolver->resolveBranch('Baladins');
        $sectionId = $this->resolver->resolveSection('SV025B1', $branchId, 'Ribambelle');

        $this->assertGreaterThan(0, $sectionId);
    }

    public function testResolveSectionUsesDeskNameAsInitialName(): void
    {
        $branchId = $this->resolver->resolveBranch('Louveteaux');
        $this->resolver->resolveSection('SV025L1', $branchId, 'Meute Akela');

        $sectionRepo = new ImportSectionRepository($this->pdo);
        $section = $sectionRepo->findByDeskCode('SV025L1');
        $this->assertNotNull($section);
        $this->assertSame('Meute Akela', $section['name']);
    }

    public function testResolveSectionNewSectionIsActive(): void
    {
        $branchId = $this->resolver->resolveBranch('Baladins');
        $sectionId = $this->resolver->resolveSection('SV025B1', $branchId, 'Ribambelle');

        $this->assertSame(1, (int) $this->pdo->query("SELECT is_active FROM sections WHERE id = {$sectionId}")->fetchColumn());
    }

    public function testDeactivateAllSectionsThenResolveReactivatesReferencedOnes(): void
    {
        $branchId = $this->resolver->resolveBranch('Baladins');
        $sectionId = $this->resolver->resolveSection('SV025B1', $branchId, 'Ribambelle');

        $this->resolver->deactivateAllSections();
        $this->assertSame(0, (int) $this->pdo->query("SELECT is_active FROM sections WHERE id = {$sectionId}")->fetchColumn());

        // Resolving it again (as a later import would) reactivates it.
        $this->resolver->resolveSection('SV025B1', $branchId, 'Ribambelle');
        $this->assertSame(1, (int) $this->pdo->query("SELECT is_active FROM sections WHERE id = {$sectionId}")->fetchColumn());
    }

    public function testDeactivateAllSectionsLeavesUnreferencedSectionsInactive(): void
    {
        $branchId = $this->resolver->resolveBranch('Baladins');
        $sectionId = $this->resolver->resolveSection('SV025B1', $branchId, 'Ribambelle');

        $this->resolver->deactivateAllSections();
        // A different import run that never references SV025B1 again.
        $this->resolver->resolveSection('SV025B2', $branchId, 'Renards');

        $this->assertSame(0, (int) $this->pdo->query("SELECT is_active FROM sections WHERE id = {$sectionId}")->fetchColumn());
    }

    public function testResolveFeeAutoCreates(): void
    {
        $id = $this->resolver->resolveFee('N_COTISATION_NORMALE');
        $this->assertGreaterThan(0, $id);

        $id2 = $this->resolver->resolveFee('N_COTISATION_NORMALE');
        $this->assertSame($id, $id2);
    }

    /**
     * A cotisation type the federation has not invented yet.
     *
     * Desk offers a unit three today, and `Modules\Fees\Service\
     * FeeCategoryClassifier` recognises those three — but the import layer
     * has no opinion at all about which values are legitimate, and that is
     * the property this pins. A fourth type arriving in a future export is
     * created like any other and the import carries on; refusing it, or
     * folding it into one of the three, would either block a unit's whole
     * roster or silently bill their members on a tariff nobody chose.
     * Where it becomes visible is the « Justesse des tarifs » screen, which
     * leaves its holder out of the household comparison rather than
     * reporting them as wrong (`Tests\Modules\Fees\Service\
     * FeeAccuracyServiceTest`), and the daily usage report, which names it
     * so the maintainer knows it exists (`Core\Statistics\
     * StatisticsPayloadBuilder`'s `desk_vocabulary`).
     */
    public function testAnUnknownFeeCategoryIsImportedRatherThanRefused(): void
    {
        $id = $this->resolver->resolveFee('X_COTISATION_INEDITE');

        $this->assertGreaterThan(0, $id);
        $stmt = $this->pdo->prepare('SELECT desk_code, label FROM fee_categories WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $this->assertSame('X_COTISATION_INEDITE', $row['desk_code']);
        $this->assertSame('X_COTISATION_INEDITE', $row['label']);
    }

    public function testGetNewFunctionsCountTracksCorrectly(): void
    {
        $this->assertSame(0, $this->resolver->getNewFunctionsCount());

        $this->resolver->resolveFunction('Fn1');
        $this->resolver->resolveFunction('Fn2');
        $this->assertSame(2, $this->resolver->getNewFunctionsCount());

        // Resolving same again should not increase count
        $this->resolver->resolveFunction('Fn1');
        $this->assertSame(2, $this->resolver->getNewFunctionsCount());
    }

    /**
     * Issue #356: a value this code does not recognise is created anyway,
     * and until now nothing said so anywhere. These tests are about the
     * saying, which is the whole feature — the creating already worked.
     */
    public function testAnUnknownFunctionIsJournalledOncePerImport(): void
    {
        $resolver = $this->journallingResolver();

        // Two members holding the same unknown function, which is the
        // ordinary case: an import calls resolve*() once per CSV row.
        $resolver->resolveFunction('Animateur Nutons');
        $resolver->resolveFunction('Animateur Nutons');

        $this->assertSame(1, $this->journalCount('desk_function_unknown'));
    }

    public function testTheNextImportSaysItAgain(): void
    {
        $resolver = $this->journallingResolver();
        $resolver->resolveFunction('Animateur Nutons');

        // A second import through the same object, which is what
        // resetImportState() marks. Still unqualified, still worth saying:
        // "once per import" is the promise, never "once ever".
        $resolver->resetImportState();
        $resolver->resolveFunction('Animateur Nutons');

        $this->assertSame(2, $this->journalCount('desk_function_unknown'));
    }

    public function testAFunctionThisSiteAlreadyKnowsIsNotJournalled(): void
    {
        $this->functionRepo->create('Animateur', 'Animateur', 'chief', true);
        $this->journallingResolver()->resolveFunction('Animateur');

        $this->assertSame(0, $this->journalCount('desk_function_unknown'));
    }

    public function testABranchTheSortOrderDoesNotRecogniseIsJournalled(): void
    {
        $this->journallingResolver()->resolveBranch('Nutons');

        $this->assertSame(1, $this->journalCount('desk_branch_not_canonical'));
    }

    public function testACanonicalBranchIsNotJournalled(): void
    {
        $this->journallingResolver()->resolveBranch('Baladins');

        $this->assertSame(0, $this->journalCount('desk_branch_not_canonical'));
    }

    /**
     * The case D1 of issue #356 calls the failure with no signal at all:
     * the row was created by an import months ago and has sorted last ever
     * since. Nothing creates it today, so a report that only watched
     * creations would never mention it.
     */
    public function testABranchLeftOnNinetyNineByAnEarlierImportIsJournalledToo(): void
    {
        $ageBranchRepo = new AgeBranchRepository($this->pdo);
        $ageBranchRepo->create('Nutons', 'Nutons');

        $this->journallingResolver()->resolveBranch('Nutons');

        $this->assertSame(1, $this->journalCount('desk_branch_not_canonical'));
    }

    /**
     * SECURITY.md §11. The context is a federal label and the name of the
     * table to complete — never a member, and never anything a reader
     * could narrow down to one.
     */
    public function testNothingPersonalReachesTheJournal(): void
    {
        $resolver = $this->journallingResolver();
        $resolver->resolveFunction('Animateur Nutons');

        $stmt = $this->pdo->query("SELECT description, context FROM event_log WHERE event_type = 'desk_function_unknown'");
        $row = $stmt === false ? null : $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        $context = json_decode((string) $row['context'], true);
        $this->assertIsArray($context);
        $this->assertSame(['value', 'code_table'], array_keys($context));
        $this->assertSame('Animateur Nutons', $context['value']);
        $this->assertStringContainsString('Animateur Nutons', (string) $row['description']);
    }

    public function testAFeeIsJournalledOnlyWhenTheCotisationsModuleRecognisesNothing(): void
    {
        $resolver = $this->journallingResolver($this->recognition(recognises: false));
        $resolver->resolveFee('reduit_fratrie');

        $this->assertSame(1, $this->journalCount('desk_fee_without_scale'));
    }

    public function testAFeeTheModuleRecognisesIsNotJournalled(): void
    {
        $resolver = $this->journallingResolver($this->recognition(recognises: true));
        $resolver->resolveFee('N_N_COTISATION NORMALE');

        $this->assertSame(0, $this->journalCount('desk_fee_without_scale'));
    }

    /**
     * No cotisations module, no barème — so no tariff can be reported as
     * missing from one. The alternative, reporting every category, would
     * fill a maintainer's list with installations that simply do not use
     * the feature.
     */
    public function testWithoutTheCotisationsModuleNoFeeIsEverJournalled(): void
    {
        $this->journallingResolver()->resolveFee('reduit_fratrie');

        $this->assertSame(0, $this->journalCount('desk_fee_without_scale'));
    }

    private function journallingResolver(?HouseholdTariffRecognitionInterface $recognition = null): MappingResolver
    {
        return new MappingResolver(
            $this->functionRepo,
            new AgeBranchRepository($this->pdo),
            new ImportSectionRepository($this->pdo),
            new FeeCategoryRepository($this->pdo),
            new JournalService(new JournalRepository($this->pdo)),
            $recognition
        );
    }

    private function recognition(bool $recognises): HouseholdTariffRecognitionInterface
    {
        return new class ($recognises) implements HouseholdTariffRecognitionInterface {
            public function __construct(private bool $recognises)
            {
            }

            public function recognisesWording(string $deskCode, string $label): bool
            {
                return $this->recognises;
            }

            /** @return list<int> */
            public function unmappedFeeCategoryIds(): array
            {
                return [];
            }
        };
    }

    private function journalCount(string $eventType): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM event_log WHERE event_type = ?');
        $stmt->execute([$eventType]);

        return (int) $stmt->fetchColumn();
    }
}
