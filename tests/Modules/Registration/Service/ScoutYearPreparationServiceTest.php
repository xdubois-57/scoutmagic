<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Service\PassageService;
use Modules\Registration\Service\ScoutYearPreparationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * Api\ScoutYearPreparationProvider — the two numbers the "Année scoute"
 * workflow reads to know whether the Passage page still has work waiting.
 *
 * The counts must be the Passage page's own, on the year Passage itself
 * targets (public + 1, §18.2) — this test pins both, because a second,
 * divergent notion of "who changes branch" is exactly the failure this
 * provider exists to avoid.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ScoutYearPreparationServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ScoutYearPreparationService $service;
    private SectionTransferRepository $transferRepository;
    private int $currentYearId;
    private int $targetYearId;
    private int $louveteauxSectionId;
    private int $eclaireursSectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $louveteauxBranchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $eclaireursBranchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'ECLA', 'Éclaireurs', 30);

        $this->louveteauxSectionId = $this->createSection('LOUV1', $louveteauxBranchId, 'Louveteaux A');
        $this->eclaireursSectionId = $this->createSection('ECLA1', $eclaireursBranchId, 'Éclaireurs A');

        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo));
        $this->transferRepository = new SectionTransferRepository($this->pdo);

        $passageService = new PassageService(
            $this->pdo,
            $this->encryption,
            $sectionService,
            $this->transferRepository,
            new RegistrationRequestRepository($this->pdo, $this->encryption),
            new AgeBracketRepository($this->pdo)
        );

        $scoutYearService = new ScoutYearService($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'Public year id', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'Staff year id', null, '^[0-9]+$', null, false);
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->currentYearId);

        $resolver = new ScoutYearResolver($scoutYearService, $settingService, new MemberYearRepository($this->pdo));

        $this->service = new ScoutYearPreparationService($passageService, $resolver, $scoutYearService);
    }

    private function createSection(string $deskCode, int $branchId, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A last-rank Louveteau — the only shape that counts as a passage.
     * Anything else (mid-branch, leaving) is PassageServiceTest's subject,
     * not this one's.
     */
    private function createPassageCandidate(string $firstName, bool $leaving = false): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted, leaving)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->currentYearId,
            // The AAD context each column is bound to — same values
            // PassageServiceTest seeds with, and the ones PassageService
            // decrypts against.
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $this->encryption->encrypt('2015-06-01', 'member_years.birth_date'),
            $leaving ? 1 : 0,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('identified', 'Animé', 'identified')");
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'identified'")->fetchColumn();
        $branchId = (int) $this->pdo->query('SELECT age_branch_id FROM sections WHERE id = ' . $this->louveteauxSectionId)->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $this->louveteauxSectionId, $branchId]);

        return $memberId;
    }

    /**
     * A unit where nobody changes branch reports zero of both — which the
     * workflow reads as "nothing left to decide", not as "not started".
     */
    public function testReportsNothingWhenNobodyChangesBranch(): void
    {
        $this->assertSame(0, $this->service->countPassages());
        $this->assertSame(0, $this->service->countUnassignedPassages());
    }

    public function testCountsEveryPassageAndThoseStillWithoutADestination(): void
    {
        $first = $this->createPassageCandidate('Sacha');
        $this->createPassageCandidate('Camille');

        $this->assertSame(2, $this->service->countPassages());
        $this->assertSame(2, $this->service->countUnassignedPassages());

        $this->transferRepository->setDestination($first, $this->targetYearId, $this->eclaireursSectionId);

        // A fresh instance: the counts are memoised per request on purpose.
        $service = $this->freshService();
        $this->assertSame(2, $service->countPassages());
        $this->assertSame(1, $service->countUnassignedPassages());
    }

    /**
     * An animé marked leaving in Départs has no passage to decide — the
     * same exclusion the Passage page itself applies.
     */
    public function testALeavingAnimeIsNotAPassage(): void
    {
        $this->createPassageCandidate('Partant', leaving: true);

        $this->assertSame(0, $this->service->countPassages());
    }

    /**
     * Destinations are keyed on the year Passage targets. One recorded
     * against any other year leaves the passage unassigned, which is what
     * stops a stale decision from silently ticking the step off.
     */
    public function testADestinationOnAnotherYearDoesNotCount(): void
    {
        $memberId = $this->createPassageCandidate('Sacha');
        $this->transferRepository->setDestination($memberId, $this->currentYearId, $this->eclaireursSectionId);

        $this->assertSame(1, $this->service->countUnassignedPassages());
    }

    private function freshService(): ScoutYearPreparationService
    {
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo));

        $passageService = new PassageService(
            $this->pdo,
            $this->encryption,
            $sectionService,
            $this->transferRepository,
            new RegistrationRequestRepository($this->pdo, $this->encryption),
            new AgeBracketRepository($this->pdo)
        );

        $scoutYearService = new ScoutYearService($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $resolver = new ScoutYearResolver($scoutYearService, $settingService, new MemberYearRepository($this->pdo));

        return new ScoutYearPreparationService($passageService, $resolver, $scoutYearService);
    }
}
