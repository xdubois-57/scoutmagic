<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Import\AgeBranchRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\DepartureRepository;
use Core\Member\DepartureService;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Photo\AccountPhotoRepository;
use Core\Photo\AccountPhotoService;
use Core\Photo\ImageVariantProcessor;
use Core\Photo\ImageVariantService;
use Core\Photo\LandscapeImageProcessor;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\MemberPhotoService;
use Core\Photo\PhotoIngestionService;
use Core\Photo\SectionPhotoProcessor;
use Core\Photo\SectionPhotoRepository;
use Core\Photo\SectionPhotoService;
use Core\Photo\UnitLogoProcessor;
use Core\Photo\UnitLogoService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use Modules\Calendar\Service\CalendarEventService;
use Modules\Calendar\Service\CalendarNotificationService;
use Modules\Calendar\Service\CalendarService;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Service\ExpectedReceivableService;
use Modules\Finance\Service\ReceivableAllocationService;
use Modules\Finance\Service\TreasurerScope;

/**
 * Applies the extras through the application's own services.
 *
 * Nothing here writes to a table directly — not `member_photos`, not
 * `member_badges`, not `finance_expected_receivables`. That is the difference
 * between a dataset that stays restorable and one that freezes today's schema:
 * a column added tomorrow is absorbed by the service, and a signature that
 * changes is caught by `vendor/bin/phpstan analyse` before anybody runs this.
 *
 * The photos in particular go through Core\Photo\PhotoIngestionService — the
 * same pipeline `/upload` uses — so every one of them is cropped for its
 * context, stripped of EXIF and given its derivative. Writing the rows by hand
 * would have skipped all three and produced photos the site cannot render
 * properly.
 */
final class ExtrasApplier
{
    /** @var array<string, int> Tiers => members.id */
    private array $memberIds = [];

    /** @var array<string, int> section handle => sections.id */
    private array $sectionIds = [];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly EncryptionService $encryption,
        private readonly string $storagePath,
        private readonly string $datasetRoot,
        private readonly ?int $actorId,
    ) {
    }

    /**
     * The extras that belong to an optional module, and the table whose
     * presence says whether that module is enabled on the target.
     *
     * @var array<string, array{table: string, module: string}>
     */
    private const MODULE_EXTRAS = [
        'évènements de calendrier' => ['table' => 'calendar_calendars', 'module' => 'calendar'],
        'créances attendues' => ['table' => 'finance_expected_receivables', 'module' => 'finance'],
    ];

    /**
     * @param array<string, int> $yearIds scout year label => id
     * @return array{counts: array<string, int>, skipped: array<string, string>}
     *         counters for the builder's report, and the extras that were
     *         skipped mapped to the module that was not there
     */
    public function apply(array $yearIds, int $unitAccountId): array
    {
        $this->loadMembers();
        $this->loadSections();

        $counts = [
            'décalages d\'année' => $this->applyScoutYearOffsets($yearIds),
            'départs marqués' => $this->applyDepartures($yearIds),
            'badges attribués' => $this->applyBadges($yearIds),
            'photos de membres' => $this->applyMemberPhotos($yearIds),
            'photos de groupe' => $this->applySectionPhotos($yearIds),
        ];

        // A module can legitimately be disabled on the target instance, and
        // its tables then do not exist. That is not a failure of the build —
        // it is the instance being configured differently — so those extras
        // are skipped rather than crashing halfway and leaving the dataset
        // half-applied.
        //
        // **The skip is reported, never silent.** A counter of zero reads the
        // same whether the module is off or the table name in this map is
        // wrong — and the table name in this map WAS wrong once (`calendars`
        // instead of `calendar_calendars`), which quietly dropped nine events
        // on an instance where the calendar was enabled the whole time.
        $skipped = [];
        foreach (self::MODULE_EXTRAS as $label => $extra) {
            if (!$this->tableExists($extra['table'])) {
                $counts[$label] = 0;
                $skipped[$label] = $extra['module'];
                continue;
            }

            $counts[$label] = $label === 'évènements de calendrier'
                ? $this->applyCalendarEvents($yearIds)
                : $this->applyExpectedReceivables($unitAccountId);
        }

        return ['counts' => $counts, 'skipped' => $skipped];
    }

    /**
     * Whether a table is present, used to tell "this module is disabled here"
     * from "something is broken". A disabled module's tables are simply
     * absent: ModuleManager only runs a module's schema.sql when it is
     * activated.
     */
    private function tableExists(string $table): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    // ------------------------------------------------------------- membres

    /** @param array<string, int> $yearIds */
    private function applyScoutYearOffsets(array $yearIds): int
    {
        $repository = new MemberYearRepository($this->pdo);
        $applied = 0;

        foreach (ExtrasBlueprint::SCOUT_YEAR_OFFSETS as $tiers => $byYear) {
            foreach ($byYear as $label => $offset) {
                $memberYearId = $this->memberYearIdOf($tiers, $yearIds[$label] ?? 0);
                if ($memberYearId === null) {
                    continue;
                }
                $repository->updateScoutYearOffset($memberYearId, $offset);
                $applied++;
            }
        }

        return $applied;
    }

    /** @param array<string, int> $yearIds */
    private function applyDepartures(array $yearIds): int
    {
        $service = new DepartureService(
            new DepartureRepository($this->pdo, $this->encryption),
            new JournalService(new JournalRepository($this->pdo)),
        );

        $applied = 0;
        foreach (ExtrasBlueprint::DEPARTURES as $departure) {
            $memberYearId = $this->memberYearIdOf($departure['tiers'], $yearIds[$departure['year']] ?? 0);
            if ($memberYearId === null) {
                continue;
            }
            $service->markLeaving($memberYearId, $departure['comment'], $this->actorId);
            $applied++;
        }

        return $applied;
    }

    /** @param array<string, int> $yearIds */
    private function applyBadges(array $yearIds): int
    {
        $badgeRepository = new BadgeRepository($this->pdo);
        $memberBadgeRepository = new MemberBadgeRepository($this->pdo);

        $service = new BadgeService($badgeRepository, $memberBadgeRepository, $this->sectionService());
        // Seeds "Infirmier" and "Trésorier", then creates one referent badge
        // per section — both idempotent, both what a first page load does.
        $service->ensureDefaults();
        $service->syncSectionReferentBadges();

        $applied = 0;
        foreach (ExtrasBlueprint::BADGES as $assignment) {
            $badge = $badgeRepository->findByName($assignment['badge']);
            $memberYearId = $this->memberYearIdOf($assignment['tiers'], $yearIds[$assignment['year']] ?? 0);
            if ($badge === null || $memberYearId === null) {
                continue;
            }
            $memberBadgeRepository->assign($memberYearId, $badge->id, $this->actorId);
            $applied++;
        }

        return $applied;
    }

    // -------------------------------------------------------------- photos

    /**
     * Individual portraits, through the real upload pipeline.
     *
     * The manifest names a Tiers and the year the photo belongs to; almost
     * every cadre gets exactly one row, and MemberPhotoService::resolveFileId()
     * produces their photo in the years after by falling back to it.
     *
     * @param array<string, int> $yearIds
     */
    private function applyMemberPhotos(array $yearIds): int
    {
        $ingestion = $this->photoIngestionService();
        $applied = 0;

        foreach ($this->photoManifest() as $row) {
            if ($row['kind'] !== 'individual') {
                continue;
            }

            $memberId = $this->memberIds[$row['target']] ?? null;
            $yearId = $yearIds[$row['year']] ?? null;
            if ($memberId === null || $yearId === null) {
                continue;
            }

            $result = $ingestion->ingest(
                $this->uploadedFileFor($row['file']),
                PhotoIngestionService::CONTEXT_MEMBER_PHOTO,
                $memberId . ':' . $yearId,
                $this->actorId,
            );
            $applied += $result->linked ? 1 : 0;
        }

        return $applied;
    }

    /**
     * Section group photos. The Staff d'U handle resolves through
     * UnitStaffSectionService::DESK_CODE — that section never appears in a
     * Desk export's Section column, because the import synthesises it.
     *
     * @param array<string, int> $yearIds
     */
    private function applySectionPhotos(array $yearIds): int
    {
        $ingestion = $this->photoIngestionService();
        $applied = 0;

        foreach ($this->photoManifest() as $row) {
            if ($row['kind'] !== 'group') {
                continue;
            }

            $sectionId = $row['target'] === PhotoLot::UNIT_STAFF_HANDLE
                ? $this->sectionIdByDeskCode(UnitStaffSectionService::DESK_CODE)
                : ($this->sectionIds[$row['target']] ?? null);
            $yearId = $yearIds[$row['year']] ?? null;
            if ($sectionId === null || $yearId === null) {
                continue;
            }

            $result = $ingestion->ingest(
                $this->uploadedFileFor($row['file']),
                PhotoIngestionService::CONTEXT_SECTION_PHOTO,
                $sectionId . ':' . $yearId,
                $this->actorId,
            );
            $applied += $result->linked ? 1 : 0;
        }

        return $applied;
    }

    /**
     * A `$_FILES`-shaped entry pointing at a COPY of the committed photo.
     *
     * The pipeline moves or re-encodes what it is handed; pointing it at the
     * versioned file would consume the lot. Same precaution, same reason, as
     * the CSV copies.
     *
     * @return array<string, mixed>
     */
    private function uploadedFileFor(string $filename): array
    {
        $source = $this->datasetRoot . '/' . PhotoLot::DIRECTORY . '/' . $filename;
        if (!is_file($source)) {
            throw new \RuntimeException("Photo introuvable : {$source}");
        }

        $copy = (string) tempnam(sys_get_temp_dir(), 'refdataset-photo');
        copy($source, $copy);

        return [
            'name' => $filename,
            'type' => 'image/jpeg',
            'tmp_name' => $copy,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($copy),
        ];
    }

    // ------------------------------------------------------------- modules

    /** @param array<string, int> $yearIds */
    private function applyCalendarEvents(array $yearIds): int
    {
        $calendarRepository = new CalendarRepository($this->pdo, $this->encryption);
        $eventRepository = new CalendarEventRepository($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));

        $calendarService = new CalendarService(
            $calendarRepository,
            $eventRepository,
            $this->sectionService(),
            new CalendarUnitFeedTokenRepository($this->pdo, $this->encryption),
        );
        // Both idempotent, and both what the calendar page does on first load.
        $calendarService->ensureDefaultCalendar();
        $calendarService->ensureSectionCalendars();

        $eventService = new CalendarEventService(
            $eventRepository,
            $calendarService,
            // Notifications and account lookup are optional collaborators, and
            // a build has nobody to notify: the same degradation the site
            // applies when Web Push is not configured.
            new CalendarNotificationService(
                new SchedulerService(new SchedulerRepository($this->pdo)),
                $settingService,
                $calendarService,
                $eventRepository,
            ),
        );

        $applied = 0;
        foreach (ExtrasBlueprint::CALENDAR_EVENTS as $event) {
            $calendarId = $this->calendarIdFor($calendarRepository, $event['section']);
            if ($calendarId === null || !isset($yearIds[$event['year']])) {
                continue;
            }

            $start = ExtrasBlueprint::dateIn($event['year'], $event['day']);
            $end = $event['duration'] > 0
                ? ExtrasBlueprint::dateIn($event['year'], $event['day'] + $event['duration'])
                : null;

            $eventService->createEvent(
                $calendarId,
                $event['title'],
                $start,
                $end,
                null,
                null,
                $event['location'],
                null,
                $this->actorId,
                false,
                // The seeder writes as the unit's chef d'unité across every
                // seeded section — the write check has no role-less path
                // anymore (there is no "system caller" bypass to lean on).
                \Core\Security\Role::SUPERADMIN,
                array_values($this->sectionIds),
            );
            $applied++;
        }

        return $applied;
    }

    /**
     * One expected receivable per structured communication on the statements.
     *
     * This is what turns the twenty uncategorised membership payments into a
     * reconciliation: ReceivablesOverviewService matches a receivable to the
     * movements carrying the same communication, and the amount due is
     * deliberately NOT the amount paid — some households are square, some
     * short, some over. A page where every line is settled shows nothing.
     */
    private function applyExpectedReceivables(int $unitAccountId): int
    {
        $receivableRepository = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $service = new ExpectedReceivableService(
            $receivableRepository,
            new ReceivableAllocationService(
                $receivableRepository,
                new ReceivableAllocationRepository($this->pdo),
                new TransactionRepository($this->pdo, $this->encryption),
                new AccountRepository($this->pdo, $this->encryption),
                // A builder acts for the installation, not for a person:
                // the treasurer partition has nobody to narrow against.
                new AccountVisibility(TreasurerScope::systemCaller()),
            ),
        );

        $applied = 0;
        $reference = 1;

        foreach (UnitBlueprint::YEARS as $label) {
            foreach (BankBlueprint::communicationsFor($label) as $communication) {
                $service->createReceivable(
                    ExtrasBlueprint::RECEIVABLE_SOURCE_MODULE,
                    $reference++,
                    $unitAccountId,
                    ExtrasBlueprint::RECEIVABLE_AMOUNT_CENTS,
                    $communication,
                    ExtrasBlueprint::RECEIVABLE_LABELS[$label],
                );
                $applied++;
            }
        }

        return $applied;
    }

    // -------------------------------------------------------------- outils

    private function photoIngestionService(): PhotoIngestionService
    {
        $fileRepository = new FileRepository($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));

        return new PhotoIngestionService(
            new UploadHandler($fileRepository, $this->storagePath),
            new EditableContentService(new EditableContentRepository($this->pdo)),
            new MemberPhotoService(new MemberPhotoRepository($this->pdo)),
            new SectionPhotoService(new SectionPhotoRepository($this->pdo)),
            new SectionPhotoProcessor(),
            new LandscapeImageProcessor(),
            new AgeBranchRepository($this->pdo),
            new UnitLogoService(
                new UnitLogoProcessor(),
                $settingService,
                $this->storagePath . '/core/logo',
                $this->storagePath . '/core/logo_defaults',
            ),
            new ImageVariantService($fileRepository, new ImageVariantProcessor(), $this->storagePath),
            new AccountPhotoService(new AccountPhotoRepository($this->pdo), $fileRepository, $this->storagePath),
        );
    }

    private function sectionService(): SectionService
    {
        return new SectionService(
            Connection::withPdo($this->pdo),
            $this->encryption,
            new MemberBadgeRepository($this->pdo),
        );
    }

    /**
     * @return list<array{file: string, kind: string, target: string, year: string, note: string}>
     */
    private function photoManifest(): array
    {
        return (new DatasetGenerator($this->datasetRoot))->photoRows();
    }

    private function calendarIdFor(CalendarRepository $repository, ?string $sectionHandle): ?int
    {
        if ($sectionHandle === null) {
            return $repository->findDefaultCalendar()?->id;
        }

        $sectionId = $this->sectionIds[$sectionHandle] ?? null;

        return $sectionId !== null ? $repository->findBySectionId($sectionId)?->id : null;
    }

    private function loadMembers(): void
    {
        $statement = $this->pdo->query('SELECT id, desk_id FROM members');
        foreach ($statement !== false ? $statement->fetchAll(\PDO::FETCH_ASSOC) : [] as $row) {
            $this->memberIds[(string) $row['desk_id']] = (int) $row['id'];
        }
    }

    private function loadSections(): void
    {
        foreach (UnitBlueprint::SECTIONS as $handle => $section) {
            $id = $this->sectionIdByDeskCode($section['name']);
            if ($id !== null) {
                $this->sectionIds[$handle] = $id;
            }
        }
    }

    private function sectionIdByDeskCode(string $deskCode): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM sections WHERE desk_code = ?');
        $statement->execute([$deskCode]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : (int) $row['id'];
    }

    private function memberYearIdOf(string $tiers, int $yearId): ?int
    {
        $memberId = $this->memberIds[$tiers] ?? null;
        if ($memberId === null || $yearId === 0) {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT id FROM member_years WHERE member_id = ? AND scout_year_id = ?');
        $statement->execute([$memberId, $yearId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : (int) $row['id'];
    }
}
