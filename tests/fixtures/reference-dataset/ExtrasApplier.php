<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Badge\MemberBadgeRepository;
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
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Service\ExpectedReceivableService;
use Modules\Finance\Service\ReceivableAllocationService;
use Modules\Finance\Service\TreasurerScope;
use Modules\Registration\Service\SlotService;

/**
 * Applies the extras through the application's own services, and orchestrates
 * the per-domain seeders.
 *
 * Nothing here writes to a table directly — not `member_photos`, not
 * `member_badges`, not `finance_expected_receivables`. That is the difference
 * between a dataset that stays restorable and one that freezes today's
 * schema: a column added tomorrow is absorbed by the service, and a signature
 * that changes is caught by `vendor/bin/phpstan analyse` before anybody runs
 * this.
 *
 * The photos in particular go through Core\Photo\PhotoIngestionService — the
 * same pipeline `/upload` uses — so every one of them is cropped for its
 * context, stripped of EXIF and given its derivative. Writing the rows by hand
 * would have skipped all three and produced photos the site cannot render
 * properly.
 *
 * **This class stayed the orchestrator when the extras were split up.** Each
 * module-shaped domain — calendar, news, camps, registrations, rentals,
 * banners, the gallery album, the section staffs — now lives in its own
 * `*Blueprint`/`*Seeder` pair, and this file calls them, counts what they
 * wrote and reports what it had to skip. What it still applies itself is the
 * member-level half: section addresses, year offsets, departures, photos and
 * the expected receivables, none of which belongs to a module.
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
        /**
         * Whose "may I manage this?" the gallery is asked on the builder's
         * behalf. The role handed with it is SUPERADMIN, which never consults
         * the address — but the parameter is not optional in the module's
         * API, and inventing one here would be a second identity nobody
         * declared.
         */
        private readonly string $actorEmail = DemoAccounts::SUPERADMIN_EMAIL,
    ) {
    }

    /**
     * The extras that belong to an optional module, and the table whose
     * presence says whether that module can be written to on the target.
     *
     * Since the builder now switches every module on before it gets here
     * (ModuleActivator, README §8.1), nothing should be skipped in practice.
     * The map is kept all the same: the builder can be pointed at an
     * installation whose schema predates a module, and a fixture that died
     * halfway through would be worse than one that names the page it could
     * not fill.
     *
     * @var array<string, array{table: string, module: string}>
     */
    private const MODULE_EXTRAS = [
        'créances attendues' => ['table' => 'finance_expected_receivables', 'module' => 'finance'],
        'évènements de calendrier' => ['table' => 'calendar_calendars', 'module' => 'calendar'],
        'articles d\'actualité' => ['table' => 'news_articles', 'module' => 'news'],
        'réponses de formulaire' => ['table' => 'news_form_responses', 'module' => 'news'],
        'lieux de camp' => ['table' => 'camp_places', 'module' => 'camps'],
        'séjours' => ['table' => 'camp_camps', 'module' => 'camps'],
        'demandes d\'inscription' => ['table' => 'registration_requests', 'module' => 'registration'],
        'bannières' => ['table' => 'banners', 'module' => 'banner'],
        'albums photo' => ['table' => 'gallery_albums', 'module' => 'gallery'],
        'biens en location' => ['table' => 'rental_assets', 'module' => 'rental'],
        'réservations' => ['table' => 'rental_bookings', 'module' => 'rental'],
        'responsables de section' => ['table' => 'trombinoscope_function_flags', 'module' => 'trombinoscope'],
    ];

    /**
     * @param array<string, int> $yearIds scout year label => id
     * @return array{counts: array<string, int>, skipped: array<string, string>, notes: list<string>}
     *         counters for the builder's report, the extras that were skipped
     *         mapped to the module that was not there, and anything a human
     *         should read rather than count
     */
    public function apply(array $yearIds, int $unitAccountId): array
    {
        $this->loadMembers();
        $this->loadSections();

        $counts = [
            'adresses de section' => $this->applySectionEmails(),
            'décalages d\'année' => $this->applyScoutYearOffsets($yearIds),
            'départs marqués' => $this->applyDepartures($yearIds),
            'badges attribués' => $this->staffSeeder($yearIds)->assignBadges(),
            'photos de membres' => $this->applyMemberPhotos($yearIds),
            'photos de groupe' => $this->applySectionPhotos($yearIds),
        ];

        // A module can legitimately be absent from the target instance, and
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
        $notes = [];
        $present = [];

        foreach (self::MODULE_EXTRAS as $label => $extra) {
            if ($this->tableExists($extra['table'])) {
                $present[$label] = true;
                continue;
            }
            $counts[$label] = 0;
            $skipped[$label] = $extra['module'];
        }

        if (isset($present['créances attendues'])) {
            $counts['créances attendues'] = $this->applyExpectedReceivables($unitAccountId);
        }

        if (isset($present['évènements de calendrier'])) {
            $counts['évènements de calendrier'] = (new CalendarSeeder(
                $this->pdo,
                $this->encryption,
                $this->sectionService(),
                $this->sectionIds,
                $this->actorId,
            ))->seed();
        }

        if (isset($present['articles d\'actualité']) && $this->actorId !== null) {
            $news = (new NewsSeeder(
                $this->pdo,
                $this->encryption,
                $this->storagePath,
                $this->datasetRoot,
                $this->actorId,
            ))->seed();
            $counts['articles d\'actualité'] = $news['articles'];
            if (isset($present['réponses de formulaire'])) {
                $counts['réponses de formulaire'] = $news['responses'];
            }
        }

        if (isset($present['lieux de camp'])) {
            $camps = (new CampsSeeder($this->pdo, $this->encryption, $this->sectionIds, $this->actorId))->seed();
            $counts['lieux de camp'] = $camps['places'];
            if (isset($present['séjours'])) {
                $counts['séjours'] = $camps['camps'];
            }
        }

        if (isset($present['demandes d\'inscription'])) {
            $registrations = (new RegistrationSeeder($this->pdo, $this->encryption, $this->sectionIds))->seed();
            $counts['demandes d\'inscription'] = $registrations['requests'];
            $notes[] = sprintf(
                'inscriptions : %d créneau(x) semé(s) à %d, %d capacité(s) réglée(s) à la main, '
                . '%d demande(s) acceptée(s), formulaire %s',
                $registrations['seededCapacities'],
                SlotService::DEFAULT_CAPACITY,
                $registrations['overrides'],
                $registrations['accepted'],
                $registrations['formOpen'] ? 'ouvert' : 'fermé',
            );
        }

        if (isset($present['bannières']) && $this->actorId !== null) {
            $counts['bannières'] = (new BannerSeeder($this->pdo, $this->actorId))->seed();
        }

        if (isset($present['albums photo']) && $this->actorId !== null) {
            $gallery = (new GallerySeeder(
                $this->pdo,
                $this->encryption,
                $this->sectionService(),
                $this->storagePath,
                $this->sectionIds,
                $this->actorId,
                $this->actorEmail,
            ))->seed();
            $counts['albums photo'] = $gallery['albums'];
            foreach ($gallery['failures'] as $failure) {
                $notes[] = 'album externe non créé — ' . $failure;
            }
        }

        if (isset($present['biens en location'])) {
            $rental = (new RentalSeeder($this->pdo, $this->encryption, $this->memberIds, $this->actorId))->seed();
            $counts['biens en location'] = $rental['asset'] !== null ? 1 : 0;
            if (isset($present['réservations'])) {
                $counts['réservations'] = $rental['bookings'];
            }
            if (!$rental['manager']) {
                $notes[] = 'le bien en location n\'a pas de gestionnaire : le Tiers '
                    . RentalBlueprint::MANAGER_TIERS . ' est-il toujours membre ?';
            }
            foreach ($rental['refusals'] as $refusal) {
                $notes[] = 'réservation refusée par le module — ' . $refusal;
            }
        }

        if (isset($present['responsables de section'])) {
            $lead = $this->staffSeeder($yearIds)->flagSectionLead();
            $counts['responsables de section'] = $lead['ledSections'];
            if (!$lead['flagged']) {
                $notes[] = 'aucune fonction « ' . UnitBlueprint::SECTION_LEAD_FUNCTION
                    . ' » en base : les sections n\'auront pas de responsable.';
            }
            foreach ($lead['headless'] as $headless) {
                $notes[] = 'section sans responsable — ' . $headless;
            }
        }

        return ['counts' => $counts, 'skipped' => $skipped, 'notes' => $notes];
    }

    /**
     * The Tiers-to-member-id map this applier built, for whoever runs after
     * it — the payment campaign needs one row per member and would otherwise
     * read the same table a second time.
     *
     * Empty until apply() has run: it is filled from the database, not
     * declared.
     *
     * @return array<string, int> Tiers => members.id
     */
    public function memberIds(): array
    {
        return $this->memberIds;
    }

    /**
     * Whether a table is present, used to tell "this module is not installed
     * here" from "something is broken".
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

    /**
     * Every section's email address, through SectionService::
     * updateSectionInfo() — the Config Desk call, which is also the one that
     * trims the value and nulls an empty one.
     */
    private function applySectionEmails(): int
    {
        $service = $this->sectionService();
        $applied = 0;

        foreach (ExtrasBlueprint::sectionEmails() as $handle => $email) {
            $sectionId = $this->sectionIds[$handle] ?? null;
            if ($sectionId === null) {
                continue;
            }
            $service->updateSectionInfo($sectionId, UnitBlueprint::SECTIONS[$handle]['name'], $email);
            $applied++;
        }

        // Staff d'U is a section like any other on that page, and the only one
        // no export ever names — UnitStaffSectionService synthesises it, and
        // the name it gave it is read back rather than restated here: the
        // service owns that string, and a copy of it in this directory is a
        // copy that would drift.
        $unitStaff = $service->findByDeskCode(UnitStaffSectionService::DESK_CODE);
        if ($unitStaff !== null) {
            $service->updateSectionInfo($unitStaff['id'], $unitStaff['name'], UnitBlueprint::UNIT_STAFF_EMAIL);
            $applied++;
        }

        return $applied;
    }

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
    private function staffSeeder(array $yearIds): StaffSeeder
    {
        return new StaffSeeder(
            $this->pdo,
            $this->sectionService(),
            $this->sectionIds,
            $yearIds,
            $this->memberIds,
            $this->actorId,
        );
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

    // ------------------------------------------------------------- finances

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
