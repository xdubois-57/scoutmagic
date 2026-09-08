<?php

declare(strict_types=1);

namespace Tests\Modules\Presences\Service;

use Core\Config\ScoutYearService;
use Core\Security\EncryptionService;
use Modules\Presences\Repository\PresenceRepository;
use Modules\Presences\Service\PresenceExportService;
use Modules\Presences\Service\PresenceRegisterService;
use Modules\Presences\Value\PresenceStatus;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Presences\PresencesTestHelper;

/**
 * The section's whole year as a spreadsheet — where somebody goes for the
 * detail the screens summarise on purpose.
 *
 * The assertion that matters most is the formula one: a comment is free
 * text somebody typed, and « =SOMME(A1:A9) » or « +32 475 … » opening as
 * a live formula is the CSV/XLSX injection class SECURITY.md §23 names.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PresenceExportServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private PresenceRepository $repository;
    private PresenceExportService $service;
    private int $scoutYearId;
    private int $sectionId;
    private int $calendarId;
    private string $year;
    /** @var array<string, int> */
    private array $animes = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        PresencesTestHelper::createTables($this->pdo);
        $this->encryption = PresencesTestHelper::encryption();
        $this->repository = new PresenceRepository($this->pdo, $this->encryption);

        [$label, $start, $end] = DatabaseTestHelper::scoutYear();
        $this->scoutYearId = PresencesTestHelper::createScoutYear($this->pdo, $label, $start, $end);
        $this->year = substr($start, 0, 4);

        $branch = PresencesTestHelper::createBranch($this->pdo, 'LOU', 'Louveteaux', 20);
        $this->sectionId = PresencesTestHelper::createSection($this->pdo, $branch, 'LOU1', 'Louveteaux 1');
        $this->calendarId = PresencesTestHelper::createSectionCalendar($this->pdo, $this->sectionId);

        $calendar = PresencesTestHelper::calendarService($this->pdo, $this->encryption);
        $sectionService = PresencesTestHelper::sectionService($this->pdo, $this->encryption);

        $this->service = new PresenceExportService(
            new PresenceRegisterService(
                $calendar,
                new ScoutYearService($this->pdo),
                $sectionService,
                $this->repository
            ),
            $sectionService,
            $this->repository
        );
    }

    public function testItWritesOneRowPerAnimeAndEvening(): void
    {
        $this->event('09-13');
        $this->event('09-20');
        $this->anime('Basile', 'Hargot');
        $this->anime('Dounia', 'Ayoute');

        [, , $counters] = $this->service->build($this->sectionId, $this->scoutYearId, '2026-2027');

        $this->assertSame(2, $counters['animes']);
        $this->assertSame(2, $counters['events']);
        $this->assertSame(4, $counters['rows']);
    }

    public function testTheStateAndTheCommentAreBothInTheFile(): void
    {
        $event = $this->event('09-13');
        $this->anime('Basile', 'Hargot');
        $this->repository->saveStatus($event, $this->animes['Hargot'], PresenceStatus::EXCUSED, null);
        $this->repository->saveComment($event, $this->animes['Hargot'], 'Malade.', null);

        $rows = $this->rowsOf($this->service->build($this->sectionId, $this->scoutYearId, '2026-2027')[0]);

        $this->assertSame(
            ['Nom', 'Prénom', 'Totem', 'Date', 'Évènement', 'État', 'Commentaire'],
            $rows[0]
        );
        $this->assertSame('Hargot', $rows[1][0]);
        $this->assertSame('13/09/' . $this->year, $rows[1][3]);
        $this->assertSame('Excusé', $rows[1][5]);
        $this->assertSame('Malade.', $rows[1][6]);
    }

    public function testAnAnimeNobodyPointedIsWrittenAsNonRenseigne(): void
    {
        $this->event('09-13');
        $this->anime('Basile', 'Hargot');

        $rows = $this->rowsOf($this->service->build($this->sectionId, $this->scoutYearId, '2026-2027')[0]);

        $this->assertSame('Non renseigné', $rows[1][5]);
        $this->assertSame('', $rows[1][6]);
    }

    /**
     * SECURITY.md §23 — a comment beginning with `=`, `+`, `-` or `@`
     * must stay text. Core\Export\TabularSpreadsheet writes every cell
     * with an explicit string type, which is exactly why the export goes
     * through it.
     */
    public function testACommentThatLooksLikeAFormulaStaysText(): void
    {
        $event = $this->event('09-13');
        $this->anime('Basile', 'Hargot');
        $this->repository->saveStatus($event, $this->animes['Hargot'], PresenceStatus::ABSENT, null);
        $this->repository->saveComment($event, $this->animes['Hargot'], '=SOMME(A1:A9)', null);

        $sheet = $this->service->build($this->sectionId, $this->scoutYearId, '2026-2027')[0]->getActiveSheet();
        $cell = $sheet->getCell([7, 2]);

        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame('=SOMME(A1:A9)', $cell->getValue());
    }

    public function testTheCountersHoldNoName(): void
    {
        $event = $this->event('09-13');
        $this->anime('Basile', 'Hargot');
        $this->repository->saveStatus($event, $this->animes['Hargot'], PresenceStatus::ABSENT, null);
        $this->repository->saveComment($event, $this->animes['Hargot'], 'Malade.', null);

        [, , $counters] = $this->service->build($this->sectionId, $this->scoutYearId, '2026-2027');

        $this->assertSame(['animes', 'events', 'rows', 'comments'], array_keys($counters));
        $this->assertSame(1, $counters['comments']);
        foreach ($counters as $value) {
            $this->assertIsInt($value);
        }
    }

    public function testTheFileNameSurvivesASectionNameWithASlashInIt(): void
    {
        PresencesTestHelper::createSection(
            $this->pdo,
            (int) $this->pdo->query('SELECT id FROM age_branches LIMIT 1')->fetchColumn(),
            'LOU9',
            'Louveteaux 1 / Meute'
        );
        $sectionId = (int) $this->pdo->lastInsertId();

        [, $fileName] = $this->service->build($sectionId, $this->scoutYearId, '2026-2027');

        $this->assertSame('presences-Louveteaux-1-Meute-2026-2027.xlsx', $fileName);
    }

    /**
     * @return list<list<string|null>>
     */
    private function rowsOf(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): array
    {
        return array_values(array_map(
            static fn (array $row): array => array_values(array_map(
                static fn ($value): ?string => $value === null ? null : (string) $value,
                $row
            )),
            $spreadsheet->getActiveSheet()->toArray()
        ));
    }

    private function event(string $monthDay, string $title = 'Réunion'): int
    {
        return PresencesTestHelper::createEvent(
            $this->pdo, $this->calendarId, $title, $this->year . '-' . $monthDay
        );
    }

    private function anime(string $firstName, string $lastName): void
    {
        $this->animes[$lastName] = PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId, $firstName, $lastName, 'animated', $this->sectionId
        )['memberId'];
    }
}
