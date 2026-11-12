<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Importer;

use Core\Config\ScoutYearService;
use Core\File\ChunkedUploadStore;
use Core\Import\MemberRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\HtmlSanitizer;
use Modules\DataMigration\Importer\AnalysisSummary;
use Modules\DataMigration\Importer\EmailHistoryImporter;
use Modules\DataMigration\Manifest\ManifestReader;
use Modules\DataMigration\Repository\ItemMappingRepository;
use Modules\DataMigration\Repository\Run;
use Modules\DataMigration\Repository\RunRepository;
use Modules\DataMigration\Service\ImportEngine;
use Modules\DataMigration\Service\MissingScoutYears;
use Modules\DataMigration\Service\RunService;
use Modules\DataMigration\Service\UploadStorage;
use Modules\MassMail\Repository\Delivery;
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\ImportedEmailRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\DataMigration\Support\TestArchive;
use Tests\Modules\MassMail\ImportedEmailFixture;
use Tests\UsesProductionEngine;

/**
 * « Historique des courriels » end to end, on the engine production runs:
 * the sample archives of tests/fixtures/data-migration/email-history/
 * deposited, analysed, confirmed and run in slices through the module's
 * shared machinery, into mass_mail through its import interface — and what
 * that leaves behind, which is a history and nothing to send.
 *
 * On MariaDB/MySQL because the import writes, inside one transaction per
 * email, into tables whose ENUMs this lot widened and whose provenance is
 * compared byte for byte, and because the shared machinery's own premises
 * (named lock, VARBINARY correspondence) are the engine's.
 */
#[Group('database')]
final class EmailHistoryImporterOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private const FIXTURES = __DIR__ . '/../../../fixtures/data-migration/email-history/';

    private \PDO $pdo;
    private string $storage;
    private EmailHistoryImporter $importer;
    private RunService $service;
    private int $sectionId;
    private int $staffId;
    private int $alpha;
    private int $beta;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->storage = sys_get_temp_dir() . '/dm_mail_' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0700, true);

        $this->pdo->exec(
            "INSERT INTO age_branches (desk_code, label) VALUES ('ECL', 'Éclaireurs'), ('STAFF', 'Staff d''U')",
        );
        $this->pdo->exec(
            "INSERT INTO sections (age_branch_id, desk_code, name, email) VALUES "
                . "((SELECT id FROM age_branches WHERE desk_code = 'ECL'), 'ECL01', 'Éclaireurs', "
                . "'Eclaireurs@Exemple.test'), "
                . "((SELECT id FROM age_branches WHERE desk_code = 'STAFF'), 'STAFFDU', 'Staff d''U', NULL)"
        );
        $this->sectionId = (int) $this->pdo->query("SELECT id FROM sections WHERE desk_code = 'ECL01'")->fetchColumn();
        $this->staffId = (int) $this->pdo->query("SELECT id FROM sections WHERE desk_code = 'STAFFDU'")->fetchColumn();
        // 900001 and 900002 exist; 900003 does not, and must never be created.
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('900001'), ('900002')");
        $this->alpha = (int) $this->pdo->query("SELECT id FROM members WHERE desk_id = '900001'")->fetchColumn();
        $this->beta = (int) $this->pdo->query("SELECT id FROM members WHERE desk_id = '900002'")->fetchColumn();

        $encryption = ImportedEmailFixture::encryption();
        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->importer = new EmailHistoryImporter(
            ImportedEmailFixture::service($this->pdo, $this->storage),
            new MemberRepository($this->pdo),
            new HtmlSanitizer()
        );
        $runs = new RunRepository($this->pdo, $encryption);
        $uploads = new UploadStorage($this->storage);
        $years = new MissingScoutYears(new ScoutYearService($this->pdo));
        $this->service = new RunService(
            $runs,
            new ImportEngine(
                $this->pdo,
                $runs,
                new ItemMappingRepository($this->pdo),
                $years,
                $uploads,
                new ManifestReader(),
                $journal,
                0.0
            ),
            $years,
            $uploads,
            new ChunkedUploadStore($this->storage),
            $journal
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

    public function testTheSampleArchiveIsAnalysedWithoutWritingAnything(): void
    {
        $run = $this->analysed('exemple.zip');

        $this->assertSame(3, $run->analysis['total']);
        $this->assertSame(3, $run->analysis['to_import']);
        $this->assertSame([], $run->analysis['ignored']);
        $this->assertSame(1, $run->analysis['tags'][EmailHistoryImporter::TAG_SECTION_FROM_SENDER] ?? 0);
        $this->assertSame(2, $run->analysis['tags'][EmailHistoryImporter::TAG_SECTION_STAFF] ?? 0);
        $this->assertSame(1, $run->analysis['tags'][EmailHistoryImporter::TAG_MAIL_MERGE] ?? 0);
        $this->assertSame(1, $run->analysis['tags'][EmailHistoryImporter::TAG_MEMBER_UNKNOWN] ?? 0);
        $this->assertSame(0, $this->rows('mass_mail_emails'));

        $notes = array_map(
            static fn ($note): string => $note->text,
            $this->importer->previewNotes(self::summary($run))
        );
        $this->assertContains(
            '1 courriel est rattaché à une section d\'après son adresse d\'expédition, 2 au Staff d\'U. '
                . 'L\'adresse et le nom d\'origine restent affichés tels quels.',
            $notes
        );
        $this->assertContains(
            '1 publipostage est importé avec son texte modèle, sans les versions personnalisées.',
            $notes,
        );
    }

    public function testTheSampleArchiveBecomesAReadOnlyHistoryAndNothingToSend(): void
    {
        $run = $this->executed('exemple.zip');

        $this->assertSame(3, $run->report['imported']);
        $this->assertSame(0, $run->report['errors']);
        $this->assertSame(3, $this->rows('mass_mail_emails'));
        $this->assertSame(3, $this->rows('data_migration_items'));

        // D6: the section whose address sent it, else the Staff d'U.
        $sections = $this->pdo->query(
            'SELECT subject, section_id FROM mass_mail_emails',
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame($this->sectionId, (int) $sections["Infos camp d'été 2024"]);
        $this->assertSame($this->staffId, (int) $sections['Réunion de rentrée']);

        // The camp email: its known messages, statuses as the former site
        // said, its two valid attachments, two members linked — and the
        // unknown Desk id created nobody.
        $campId = (int) $this->pdo->query(
            "SELECT id FROM mass_mail_emails WHERE subject = 'Infos camp d''été 2024'",
        )->fetchColumn();
        $statuses = [];
        $repository = new DeliveryRepository($this->pdo, ImportedEmailFixture::encryption());
        foreach ($repository->findByEmailId($campId) as $delivery) {
            $statuses[$delivery->emailAddress] = $delivery->status;
        }
        $this->assertSame([
            'famille.alpha@exemple.test' => Delivery::STATUS_SENT,
            'famille.beta@exemple.test' => Delivery::STATUS_UNKNOWN,
            'ancien.chef@exemple.test' => Delivery::STATUS_ERROR,
        ], $statuses);
        $this->assertSame(
            2,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM mass_mail_attachments WHERE email_id = {$campId}",
            )->fetchColumn(),
        );
        $this->assertSame(
            [$this->alpha, $this->beta],
            array_map('intval', $this->pdo->query(
                "SELECT member_id FROM mass_mail_targets WHERE email_id = {$campId} ORDER BY member_id"
            )->fetchAll(\PDO::FETCH_COLUMN))
        );
        $this->assertSame(2, $this->rows('members'));

        $camp = (new EmailRepository($this->pdo))->findById($campId);
        $this->assertTrue($camp?->isImported());
        $this->assertStringNotContainsString('<script', (string) $camp?->bodyHtml);
        $this->assertSame('Parents des Éclaireurs', $camp?->originalAudienceName);
        $this->assertSame(
            ['address' => 'eclaireurs@exemple.test', 'name' => 'Éclaireurs 99e Exempleville'],
            (new ImportedEmailRepository($this->pdo, ImportedEmailFixture::encryption()))
                ->findOriginalSenders([$campId])[$campId]
        );

        // The mail merge is ONE email, its template text (D3).
        $merge = $this->pdo->query(
            "SELECT body_html FROM mass_mail_emails WHERE subject = 'Cotisation {{Annee}}'",
        )->fetchColumn();
        $this->assertStringContainsString('{{Prenom}}', (string) $merge);

        // Nothing to send, nothing scheduled, nobody notified.
        $this->assertSame(
            0,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM mass_mail_deliveries WHERE status = 'pending'",
            )->fetchColumn(),
        );
        $this->assertSame(
            0,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'mass_mail'",
            )->fetchColumn(),
        );
        $this->assertSame(0, $this->rows('notifications'));
        $this->assertSame(0, $this->rows('mass_mail_notified_accounts'));
    }

    public function testARerunImportsNothingTwice(): void
    {
        $this->executed('exemple.zip');

        $again = $this->executed('exemple.zip');

        $this->assertSame(0, $again->report['imported']);
        $this->assertSame(3, $again->report['present']);
        $this->assertSame(3, $this->rows('mass_mail_emails'));
    }

    /**
     * mass_mail's own provenance column is the heuristic behind the
     * correspondence table: with every row of that table gone, a re-run
     * still finds the emails already there.
     */
    public function testWithoutItsCorrespondenceRowsAnEmailIsStillRecognisedByItsProvenance(): void
    {
        $this->executed('exemple.zip');
        $this->pdo->exec('DELETE FROM data_migration_items');

        $run = $this->analysed('exemple.zip');

        $this->assertSame(3, $run->analysis['present']);
        $this->assertSame(0, $run->analysis['to_import']);
    }

    public function testEachEmailMissingWhatMakesItAMessageIsIgnoredWithItsReason(): void
    {
        $run = $this->executed('cas-ignores.zip');

        $this->assertSame([
            'Sans sujet' => 1,
            'Sans date d\'envoi' => 1,
            'Date d\'envoi illisible' => 1,
            'Sans expéditeur' => 1,
            'Adresse d\'expéditeur invalide' => 1,
            'Sans contenu' => 2,
            'Contenu introuvable dans l\'archive' => 1,
            'Identifiant d\'origine absent ou qui n\'est pas une chaîne de caractères' => 1,
            'Fichier illisible' => 1,
        ], $run->report['ignored']);
        // The three whose optional part only was invalid are imported without it.
        $this->assertSame(3, $run->report['imported']);
        $this->assertSame(0, $this->rows('mass_mail_attachments'));
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM mass_mail_deliveries WHERE status = 'sent'")->fetchColumn(),
        );
        // « delivered » is not a status this format knows: never « Remis ».
        $this->assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM mass_mail_deliveries WHERE status = 'unknown'",
            )->fetchColumn(),
        );
        $this->assertSame(2, $this->rows('members'), 'an unknown Desk id created nobody');

        // D8: the report names the former site's references, never a person.
        foreach ($run->report['samples'] as $sample) {
            $this->assertStringNotContainsString('@', (string) $sample['reference']);
        }
    }

    public function testTheAnalysisCountsTheInvalidPartsForThePreview(): void
    {
        $run = $this->analysed('cas-ignores.zip');

        $this->assertSame(2, $run->analysis['tags'][EmailHistoryImporter::TAG_ATTACHMENT_INVALID] ?? 0);
        $this->assertSame(1, $run->analysis['tags'][EmailHistoryImporter::TAG_RECIPIENT_INVALID] ?? 0);
        $this->assertSame(2, $run->analysis['tags'][EmailHistoryImporter::TAG_MEMBER_UNKNOWN] ?? 0);
        $notes = array_map(
            static fn ($note): string => $note->level . ' ' . $note->text,
            $this->importer->previewNotes(self::summary($run)),
        );
        $this->assertContains(
            'warning 2 pièces jointes sont invalides (empreinte, type ou taille). '
                . 'Leur courriel est importé sans elles.',
            $notes
        );
    }

    public function testAnotherMajorVersionIsRefusedBeforeAnyWrite(): void
    {
        $run = $this->drive($this->deposit(self::FIXTURES . 'version-majeure-2.zip'));

        $this->assertNotSame(Run::ANALYSED, $run->state);
        $this->assertStringContainsString('2.0.0', (string) $run->rejectionReason);
        $this->assertSame(0, $this->rows('mass_mail_emails'));
    }

    /**
     * D11, the decision this lot exists for: once the migration is over and
     * its module disabled, nothing of data_migration is built — and the
     * emails it wrote stay locked, because the lock is mass_mail's column.
     */
    public function testTheImportedEmailsStayLockedOnceTheMigrationModuleIsGone(): void
    {
        $this->executed('exemple.zip');
        $this->pdo->exec('DELETE FROM data_migration_items');
        $this->pdo->exec('DELETE FROM data_migration_runs');

        $emails = new EmailRepository($this->pdo);
        $deliveries = new DeliveryRepository($this->pdo, ImportedEmailFixture::encryption());
        foreach ($this->pdo->query('SELECT id FROM mass_mail_emails')->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $email = $emails->findById((int) $id);
            $this->assertTrue($email?->isImported());
            $this->assertFalse($email?->isEditable());
            $this->assertFalse($emails->deleteIfNotStarted((int) $id));
        }
        $this->assertSame(3, $this->rows('mass_mail_emails'));
        $this->assertSame([], $deliveries->findOldestPending(100));
    }

    public function testWithoutAStaffSectionTheEmailsNoSectionClaimsAreIgnored(): void
    {
        $this->pdo->exec("UPDATE sections SET desk_code = 'STAFFX' WHERE id = {$this->staffId}");

        $run = $this->analysed('exemple.zip');

        $this->assertSame(2, $run->analysis['ignored']['Section « Staff d\'U » absente du site'] ?? 0);
        $this->assertSame(1, $run->analysis['to_import']);
    }

    /**
     * A body is any HTML: one that opens on « ! » is imported like any
     * other, and its text never becomes a reason in the report.
     */
    public function testAnHtmlBodyStartingWithAnExclamationMarkIsImported(): void
    {
        $html = '!! Rappel : réunion chez Jean Dupont, rue des Exemples 1.';
        $content = 'content/' . hash('sha256', $html) . '.html';
        $zip = TestArchive::build(
            [
                'communications/c-1.json' => (string) json_encode([
                    'id' => 'c-1',
                    'sent_at' => '2023-09-04T20:00:00+02:00',
                    'subject' => 'Rappel',
                    'sender' => ['address' => 'tresorerie@exemple.test'],
                    'body' => ['html' => $content],
                ]),
                $content => $html,
            ],
            ['format' => EmailHistoryImporter::FORMAT, 'format_version' => '1.0.0']
        );

        $run = $this->service->confirm($this->drive($this->deposit($zip)), []);
        $this->assertNotNull($run);
        $run = $this->drive($run);
        @unlink($zip);

        $this->assertSame(Run::DONE, $run->state, (string) $run->rejectionReason);
        $this->assertSame(1, $run->report['imported']);
        $this->assertSame([], $run->report['ignored']);
        $this->assertSame(1, $this->rows('mass_mail_emails'));
    }

    public function testAnArchiveMissingFromItsOwnInventoryIsRefusedWhole(): void
    {
        $zip = TestArchive::build(
            ['communications/c-1.json' => '{"id":"c-1"}'],
            ['format' => EmailHistoryImporter::FORMAT, 'format_version' => '1.0.0'],
            ['communications/c-2.json' => '{"id":"c-2"}']
        );

        $run = $this->drive($this->deposit($zip));

        $this->assertNotSame(Run::ANALYSED, $run->state);
        $this->assertSame(0, $this->rows('mass_mail_emails'));
    }

    private function analysed(string $fixture): Run
    {
        $run = $this->drive($this->deposit(self::FIXTURES . $fixture));
        $this->assertSame(Run::ANALYSED, $run->state, (string) $run->rejectionReason);

        return $run;
    }

    private function executed(string $fixture): Run
    {
        $run = $this->service->confirm($this->analysed($fixture), []);
        $this->assertNotNull($run);
        $run = $this->drive($run);
        $this->assertSame(Run::DONE, $run->state, (string) $run->rejectionReason);

        return $run;
    }

    private function deposit(string $zip): Run
    {
        $chunk = sys_get_temp_dir() . '/dm_chunk_' . bin2hex(random_bytes(6));
        copy($zip, $chunk);
        $result = $this->service->receiveChunk(
            $this->importer,
            bin2hex(random_bytes(16)),
            'test-session',
            0,
            $chunk,
            true,
            'historique-emails.zip',
            null
        );
        @unlink($chunk);

        return $result['run'] ?? $this->fail('The last fragment created no run.');
    }

    private function drive(Run $run): Run
    {
        for ($i = 0; $i < 300 && in_array($run->state, [Run::UPLOADED, Run::CONFIRMED, Run::RUNNING], true); $i++) {
            $run = $this->service->advance($this->importer, $run);
        }

        return $run;
    }

    private function rows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    private static function summary(Run $run): AnalysisSummary
    {
        $analysis = $run->analysis;

        return new AnalysisSummary(
            (int) $analysis['total'],
            (int) $analysis['to_import'],
            (int) $analysis['present'],
            (int) $analysis['ambiguous'],
            (array) $analysis['ignored'],
            (array) $analysis['tags'],
            (array) $analysis['missing_years']
        );
    }
}
