<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Importer;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\ChunkedUploadStore;
use Core\Import\FeeCategoryRepository;
use Core\Import\MemberRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\DataMigration\Importer\AnalysisSummary;
use Modules\DataMigration\Importer\PreviewNote;
use Modules\DataMigration\Importer\RegistrationRequestsImporter;
use Modules\DataMigration\Manifest\ManifestReader;
use Modules\DataMigration\Repository\ItemMappingRepository;
use Modules\DataMigration\Repository\Run;
use Modules\DataMigration\Repository\RunRepository;
use Modules\DataMigration\Service\ImportEngine;
use Modules\DataMigration\Service\MissingScoutYears;
use Modules\DataMigration\Service\RunService;
use Modules\DataMigration\Service\SectionByDeskCode;
use Modules\DataMigration\Service\UploadStorage;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;
use Modules\Registration\Service\RequestImportService;
use Modules\Registration\Service\RequestStatusService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Registration\RegistrationTestHelper;
use Tests\UsesProductionEngine;

/**
 * « Inscriptions de l'année suivante » (chantier #1142, lot H), end to end
 * through the data migration module's own run, on the engine production
 * runs: the fixture archives of `tests/fixtures/data-migration/
 * scoutmagic-next-year-registrations/` are deposited, analysed, previewed,
 * confirmed and imported, then imported again.
 *
 * On MariaDB/MySQL because every item is written inside the module's own
 * transaction (the registration repository must join it, not open its
 * own), and the decision line is an INSERT … SELECT the engine decides.
 *
 * « Nothing is sent » is held by what the site would send FROM: no
 * decision e-mail scheduled, due or in play, no secondary address with a
 * confirmation token, and no journal line naming anybody.
 */
#[Group('database')]
final class RegistrationRequestsImporterOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private const FIXTURES = __DIR__ . '/../../../fixtures/data-migration/scoutmagic-next-year-registrations/';

    private \PDO $pdo;
    private string $storage;
    private RegistrationRequestsImporter $importer;
    private RunService $service;
    private RegistrationRequestRepository $requests;
    private DecisionEmailRepository $decisionEmails;
    private RegistrationSecondaryEmailRepository $secondaryEmails;
    private JournalService $journal;
    private int $yearId;
    private int $sectionId;
    private int $siblingId;
    private int $encodedMemberId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->storage = sys_get_temp_dir() . '/dm_reg_' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0700, true);

        $encryption = new EncryptionService(random_bytes(32), random_bytes(32));
        $this->journal = new JournalService(new JournalRepository($this->pdo));
        $this->decisionEmails = new DecisionEmailRepository($this->pdo);
        $this->requests = new RegistrationRequestRepository($this->pdo, $encryption, null, $this->decisionEmails);
        $this->secondaryEmails = new RegistrationSecondaryEmailRepository($this->pdo, $encryption);
        $connection = Connection::withPdo($this->pdo);
        $scoutYears = new ScoutYearService($this->pdo);

        $this->importer = new RegistrationRequestsImporter(
            new RequestImportService(
                $this->requests,
                $this->decisionEmails,
                $this->secondaryEmails,
                new SettingService(new SettingRepository($this->pdo)),
                $this->journal
            ),
            $scoutYears,
            new SectionByDeskCode(new SectionService(
                new SectionRepository($connection),
                new MemberProfileRepository($connection, $encryption, new \Core\Badge\MemberBadgeRepository($this->pdo))
            )),
            new MemberRepository($this->pdo),
            new FeeCategoryRepository($this->pdo)
        );

        $this->yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $branch = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOU', 'Louveteaux', 2);
        $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code) VALUES (?, ?)')->execute([$branch, 'LOU-A']);
        $this->sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO fee_categories (desk_code, label) VALUES ('COT-1', 'Cotisation 1')");
        $this->siblingId = RegistrationTestHelper::insertMember($this->pdo, '0001234');
        $this->encodedMemberId = RegistrationTestHelper::insertMember($this->pdo, '0005678');

        $runs = new RunRepository($this->pdo, $encryption);
        $uploads = new UploadStorage($this->storage);
        $years = new MissingScoutYears($scoutYears);
        $this->service = new RunService(
            $runs,
            new ImportEngine(
                $this->pdo,
                $runs,
                new ItemMappingRepository($this->pdo),
                $years,
                $uploads,
                new ManifestReader(),
                $this->journal,
                0.0
            ),
            $years,
            $uploads,
            new ChunkedUploadStore($this->storage),
            $this->journal
        );
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->storage);
    }

    public function testTheExampleArchiveIsPreviewedThenImportedAsOrdinaryRequests(): void
    {
        $run = $this->analysed('exemple-valide.zip');

        $this->assertSame(5, $run->analysis['to_import']);
        $this->assertSame([], $run->analysis['ignored']);
        $this->assertSame(0, $this->rowCount('registration_requests'), 'The analysis wrote something.');
        $this->assertSame([
            'Année visée par le fichier : 2027-2028. 1 en attente, 1 acceptée, 1 refusée, 1 retirée, '
                . '1 encodée dans Desk.',
            'Les 3 décisions déjà prises sont marquées « Communiquée par l\'ancien site ». '
                . 'Les familles les voient tout de suite, sans nouvel e-mail.',
        ], $this->notes($run));

        $run = $this->drive($this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(Run::DONE, $run->state);
        $this->assertSame(5, $run->report['imported']);
        $status = new RequestStatusService($this->requests, $this->journal);
        $byChild = [];
        foreach ($this->requests->findAllForYear($this->yearId) as $request) {
            $byChild[$request->childFirstName] = [$request->status, $status->visibleStatus($request)];
        }
        ksort($byChild);
        $this->assertSame([
            'Camille' => ['pending', 'pending'],
            'Eden' => ['encoded', 'encoded'],
            'Lou' => ['refused', 'refused'],
            'Noé' => ['accepted', 'accepted'],
            'Sam' => ['withdrawn', 'withdrawn'],
        ], $byChild);

        $camille = $this->requestOf('Camille');
        $this->assertSame('2026-10-12 17:04', $camille->receivedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
        $this->assertSame($this->sectionId, $camille->desiredSectionId);
        $this->assertSame('12e Unité d\'essai', $camille->previousUnitName);
        $this->assertSame([$this->siblingId], $this->requests->findSiblingMemberIds($camille->id));
        $this->assertCount(2, $this->secondaryEmails->findByRequest($camille->id));
        $noe = $this->requestOf('Noé');
        $this->assertSame($this->sectionId, $noe->intendedSectionId);
        $this->assertNotNull($noe->feeCategoryId);
        $this->assertSame('Dossier complet.', $noe->internalNotes);
        $this->assertSame($this->encodedMemberId, $this->requestOf('Eden')->linkedMemberId);
        $this->assertSame('no_room', $this->requestOf('Lou')->refusalReason);

        $this->assertNothingWasOrWillBeSent();
    }

    public function testARerunFindsEveryRequestAlreadyPresentAndAddsNothing(): void
    {
        $run = $this->drive($this->service->confirm($this->analysed('exemple-valide.zip'), []) ?? $this->fail('No.'));
        $this->assertSame(5, $run->report['imported']);

        $again = $this->drive($this->service->restart($this->importer, $run, null) ?? $this->fail('No restart.'));
        $this->assertSame(5, $again->analysis['present']);
        $again = $this->drive($this->service->confirm($again, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(0, $again->report['imported']);
        $this->assertSame(5, $again->report['present']);
        $this->assertSame(5, $this->rowCount('registration_requests'));
        $this->assertSame(3, $this->rowCount('registration_decision_emails'));
    }

    public function testEveryRequestThatCannotBeRepresentedIsIgnoredWithItsReasonAndNoName(): void
    {
        $run = $this->analysed('elements-ignores.zip');

        $this->assertSame(0, $run->analysis['to_import']);
        $this->assertSame([
            'Champ obligatoire manquant : téléphone' => 1,
            'Valeur non reconnue : date de naissance' => 1,
            'Statut non reconnu' => 1,
            'Année scoute absente de ce site' => 1,
            'Date de réception illisible' => 1,
            'Demande encodée sans membre Desk reconnu' => 1,
            'Motif de refus non reconnu' => 1,
            'Valeur non reconnue : code postal' => 1,
            'Identifiant d\'origine absent ou qui n\'est pas une chaîne de caractères' => 1,
            'Ligne illisible' => 1,
        ], $run->analysis['ignored']);

        $shown = (string) json_encode($run->analysis, JSON_UNESCAPED_UNICODE);
        foreach (['Camille', 'Exemple', 'alex.exemple@example.org', 'Namur'] as $personal) {
            $this->assertStringNotContainsString($personal, $shown);
        }
        $this->assertStringContainsString('r-bad-01', $shown);

        $run = $this->drive($this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));
        $this->assertSame(0, $run->report['imported']);
        $this->assertSame(0, $this->rowCount('registration_requests'));
    }

    public function testALookAlikeOfAnExistingRequestIsImportedAndAnnounced(): void
    {
        $this->requests->create($this->yearId, [
            'parent_name' => 'Autre parent', 'child_last_name' => 'Exemple', 'child_first_name' => 'Camille',
            'gender' => 'F', 'birth_date' => '2019-05-06', 'street' => 'Rue', 'number' => '1', 'postal_code' => '5000',
            'city' => 'Namur', 'email' => 'autre@example.org', 'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);

        $run = $this->analysed('exemple-valide.zip');

        $this->assertSame(5, $run->analysis['to_import']);
        $this->assertContains(
            '1 demande ressemble à une demande déjà présente. Elle est importée et signalée « doublon possible », '
                . 'comme une demande normale.',
            $this->notes($run)
        );
    }

    public function testAYearThisSiteDoesNotHaveIsNeverCreated(): void
    {
        $this->pdo->exec('DELETE FROM scout_years');

        $run = $this->analysed('exemple-valide.zip');

        $this->assertSame(['Année scoute absente de ce site' => 5], $run->analysis['ignored']);
        $this->assertSame([], $run->analysis['missing_years'], 'A year to register for is not offered for creation.');
    }

    public function testAMalformedArchiveIsRejectedBeforeAnyWrite(): void
    {
        foreach (['version-majeure-inconnue.zip', 'empreinte-fausse.zip', 'fichier-en-trop.zip'] as $fixture) {
            $run = $this->drive($this->deposit($fixture));
            $this->assertSame(Run::ABANDONED, $run->state, $fixture);
            $this->assertNotNull($run->rejectionReason, $fixture);
        }
        $this->assertSame(0, $this->rowCount('registration_requests'));
    }

    private function assertNothingWasOrWillBeSent(): void
    {
        $this->assertSame(0, $this->decisionEmails->countScheduled());
        $this->assertSame([], $this->decisionEmails->findDue(new \DateTimeImmutable('+10 years'), 100));
        $this->assertSame(0, $this->rowCount('registration_decision_emails WHERE active_request_id IS NOT NULL'));
        $this->assertSame(0, $this->rowCount("registration_decision_emails WHERE state <> 'communicated_elsewhere'"));
        $this->assertSame(0, $this->rowCount('registration_decision_emails WHERE sent_at IS NOT NULL'));
        $this->assertSame(0, $this->rowCount(
            'registration_secondary_emails WHERE confirmation_token_hash IS NOT NULL OR last_confirmation_sent_at IS NOT NULL'
        ));
        $this->assertSame(0, $this->requests->countDecisionEmailsToSendForYears([$this->yearId]));

        $journal = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));
        foreach (['Camille', 'Exemple', 'alex.exemple@example.org', 'Namur', '+32'] as $personal) {
            $this->assertStringNotContainsString($personal, $journal);
        }
        $this->assertStringNotContainsString('email', strtolower(
            (string) json_encode($this->pdo->query("SELECT event_type FROM event_log WHERE category = 'registration'")
                ->fetchAll(\PDO::FETCH_COLUMN))
        ));
    }

    private function requestOf(string $firstName): \Modules\Registration\Repository\RegistrationRequest
    {
        foreach ($this->requests->findAllForYear($this->yearId) as $request) {
            if ($request->childFirstName === $firstName) {
                return $request;
            }
        }

        $this->fail('No request of ' . $firstName . '.');
    }

    /**
     * @return list<string>
     */
    private function notes(Run $run): array
    {
        $a = $run->analysis;

        return array_map(
            static fn (PreviewNote $note): string => $note->text,
            $this->importer->previewNotes(new AnalysisSummary(
                (int) $a['total'],
                (int) $a['to_import'],
                (int) $a['present'],
                (int) $a['ambiguous'],
                (array) $a['ignored'],
                (array) $a['tags'],
                (array) $a['missing_years']
            ))
        );
    }

    private function rowCount(string $from): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $from)->fetchColumn();
    }

    private function deposit(string $fixture): Run
    {
        $chunk = sys_get_temp_dir() . '/dm_reg_chunk_' . bin2hex(random_bytes(6));
        copy(self::FIXTURES . $fixture, $chunk);

        $result = $this->service->receiveChunk(
            $this->importer,
            bin2hex(random_bytes(16)),
            'test-session',
            0,
            $chunk,
            true,
            $fixture,
            null
        );
        @unlink($chunk);

        return $result['run'] ?? $this->fail('The last fragment created no run.');
    }

    private function analysed(string $fixture): Run
    {
        $run = $this->drive($this->deposit($fixture));
        $this->assertSame(Run::ANALYSED, $run->state, (string) $run->rejectionReason);

        return $run;
    }

    private function drive(Run $run): Run
    {
        for ($i = 0; $i < 200 && in_array($run->state, [Run::UPLOADED, Run::CONFIRMED, Run::RUNNING], true); $i++) {
            $run = $this->service->advance($this->importer, $run);
        }

        return $run;
    }
}
