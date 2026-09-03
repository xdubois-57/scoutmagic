<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\StoredFileReader;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportMailProbeRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\Service\TicketDossierBuilder;
use Modules\SupportDashboard\TicketCategory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * One zip holding everything this receiver knows about one installation.
 *
 * The complaint: « Les informations sur les sondes email reçues ne sont
 * pas ajoutées dans le zip de support dans la page d'un ticket. Je veux
 * aussi ajouter dedans les statistiques reçues au moment de la création
 * du ticket et les dernières. »
 *
 * They were not missing — they were in three other places. The probes
 * were a second download, and the two readings of the statistics were on
 * the screen and nowhere else, so a maintainer who took the archive away
 * to read it offline had a third of the file.
 *
 * What must NOT happen is the obvious implementation: adding entries to
 * the archive the instance uploaded. That file was built and encrypted on
 * the other side of the wire, and one partly written by its recipient is
 * no longer the thing anybody was relying on. It goes in whole.
 */
class TicketDossierBuilderTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private SupportTicketRepository $tickets;
    private SupportInstallationRepository $installations;
    private SupportMailProbeRepository $probes;
    private int $installationId;
    private int $ticketId;

    private const LATEST_PAYLOAD = [
        'statistics_schema_version' => 1,
        'installation_id' => 'unite-de-test',
        'instance_url' => 'https://unite-de-test.example.be',
        'scoutmagic' => ['version' => '1.0.40', 'is_dev_build' => false],
        'runtime' => ['php_version' => '8.4.1'],
        'usage' => ['active_members' => 130, 'active_sections' => 6],
    ];

    private const SNAPSHOT_PAYLOAD = [
        'statistics_schema_version' => 1,
        'scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => false],
        'runtime' => ['php_version' => '8.4.0'],
        'usage' => ['active_members' => 118, 'active_sections' => 6],
    ];

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->tickets = new SupportTicketRepository($this->pdo, $this->encryption);
        $this->installations = new SupportInstallationRepository($this->pdo);
        $this->probes = new SupportMailProbeRepository($this->pdo, $this->encryption);

        $this->installationId = $this->installations->register(
            'unite-de-test',
            password_hash('secret', PASSWORD_DEFAULT),
            (string) json_encode(self::LATEST_PAYLOAD),
            \Modules\SupportDashboard\Service\StatisticsIntakeService::denormalize(self::LATEST_PAYLOAD)
        );

        $reference = $this->tickets->create(
            $this->installationId,
            TicketCategory::of('desk_import'),
            "L'import Desk s'arrête à mi-parcours.",
            'chef@unite.be',
            '1.0.33',
            '8.4.0',
            (string) json_encode(self::SNAPSHOT_PAYLOAD)
        );
        $this->ticketId = (int) $this->tickets->findByReference($reference)['id'];
    }

    private function seedReceivedProbe(): void
    {
        $this->probes->issue(
            $this->installationId,
            'SMP-ABCDE12345',
            ['locations@scoutmagic.be'],
            new \DateTimeImmutable('2026-09-01 14:00:00'),
            new \DateTimeImmutable('2026-09-02 14:00:00')
        );
        $id = (int) $this->pdo->query('SELECT id FROM support_mail_probes LIMIT 1')->fetchColumn();
        $this->probes->markReceived(
            $id,
            new \DateTimeImmutable('2026-09-01 14:02:58'),
            178,
            ['spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'pass', 'relays' => ['mx.example.be']],
            "Authentication-Results: mx.example.be; spf=pass\nDKIM-Signature: v=1; a=rsa-sha256;"
        );
    }

    /**
     * @return array<string, mixed> the ticket as the page reads it
     */
    private function ticket(): array
    {
        $ticket = $this->tickets->findWithInstallation($this->ticketId);
        self::assertIsArray($ticket);
        $ticket['probes'] = $this->probes->findForInstallation($this->installationId);

        return $ticket;
    }

    private function builder(?StoredFileReader $files = null): TicketDossierBuilder
    {
        return new TicketDossierBuilder($this->installations, $files);
    }

    /**
     * @return array<string, string> entry name => contents
     */
    private function entriesOf(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sm-test-dossier-');
        self::assertIsString($path);
        file_put_contents($path, $bytes);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true, 'the dossier is not a readable zip');

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $entries[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($path);

        return $entries;
    }

    private function dossier(?StoredFileReader $files = null): array
    {
        return $this->entriesOf(
            $this->builder($files)->build($this->ticket(), new \DateTimeImmutable('2026-09-03 09:00:00'))
        );
    }

    // ── What the zip must hold ──────────────────────────────────────────

    public function testTheProbesAndTheirHeadersAreInTheZip(): void
    {
        // The first half of the complaint: they existed, as a second
        // download, and the one file a maintainer takes away did not have
        // them.
        $this->seedReceivedProbe();

        $probes = $this->dossier()[TicketDossierBuilder::PROBES_ENTRY];

        $this->assertStringContainsString('locations@scoutmagic.be', $probes);
        $this->assertStringContainsString('DKIM-Signature', $probes);
    }

    public function testBothReadingsOfTheStatisticsAreInTheZip(): void
    {
        // The second half: what the instance reported WITH the ticket, and
        // what it has reported since. « Quelle version avaient-ils quand
        // ça a cassé » is answerable from the file alone now.
        $entries = $this->dossier();

        $this->assertStringContainsString('1.0.33', $entries[TicketDossierBuilder::SNAPSHOT_ENTRY]);
        $this->assertStringContainsString('118', $entries[TicketDossierBuilder::SNAPSHOT_ENTRY]);

        $this->assertStringContainsString('1.0.40', $entries[TicketDossierBuilder::LATEST_ENTRY]);
        $this->assertStringContainsString('130', $entries[TicketDossierBuilder::LATEST_ENTRY]);
    }

    public function testTheComparisonNamesWhatMovedBetweenTheTwo(): void
    {
        $comparison = $this->dossier()[TicketDossierBuilder::COMPARISON_ENTRY];

        $this->assertStringContainsString('Version du site', $comparison);
        $this->assertStringContainsString('1.0.33', $comparison);
        $this->assertStringContainsString('1.0.40', $comparison);
        $this->assertStringContainsString('a changé', $comparison);
    }

    public function testTheTicketItselfIsInTheZip(): void
    {
        $ticket = $this->dossier()[TicketDossierBuilder::TICKET_ENTRY];

        $this->assertStringContainsString("L'import Desk s'arrête à mi-parcours.", $ticket);
        $this->assertStringContainsString('chef@unite.be', $ticket);
    }

    public function testTheInstallationsOwnFactsAreInTheZip(): void
    {
        $installation = $this->dossier()[TicketDossierBuilder::INSTALLATION_ENTRY];

        $this->assertStringContainsString('unite-de-test', $installation);
        $this->assertStringContainsString('https://unite-de-test.example.be', $installation);
    }

    // ── The uploaded archive goes in whole ──────────────────────────────

    public function testTheUploadedArchiveIsCarriedVerbatimAndNotRewritten(): void
    {
        $bytes = "PK\x03\x04 pretend this is the instance's own zip";
        $fileId = $this->seedArchive($bytes);

        $entries = $this->dossier($this->reader());

        $this->assertSame(
            $bytes,
            $entries[TicketDossierBuilder::ARCHIVE_ENTRY],
            'the archive the instance uploaded must go in byte for byte: it was built and encrypted '
            . 'on the other side of the wire, and one partly written by its recipient is no longer '
            . 'the thing anybody was relying on'
        );
        $this->assertSame($fileId, $this->ticket()['archive_file_id']);
    }

    public function testADossierIsStillProducedWithNoArchiveAtAll(): void
    {
        // Retention deletes an uploaded archive long before the ticket, and
        // the probes and statistics outlive it. A ticket read after that
        // used to offer nothing at all.
        $this->seedReceivedProbe();

        $entries = $this->dossier($this->reader());

        $this->assertArrayNotHasKey(TicketDossierBuilder::ARCHIVE_ENTRY, $entries);
        $this->assertArrayHasKey(TicketDossierBuilder::PROBES_ENTRY, $entries);
        // And it says so rather than leaving a maintainer hunting for a
        // file that was never going to be there.
        $this->assertStringContainsString(
            'rétention',
            $entries[TicketDossierBuilder::README_ENTRY]
        );
    }

    public function testWhatIsMissingIsNamedAndWhyRatherThanSilentlyLeftOut(): void
    {
        // A ticket older than the snapshot's own existence.
        $reference = $this->tickets->create(
            $this->installationId,
            TicketCategory::of('desk_import'),
            'Un ticket sans instantané.',
            'chef@unite.be',
            null,
            null
        );
        $id = (int) $this->tickets->findByReference($reference)['id'];
        $ticket = $this->tickets->findWithInstallation($id);
        $ticket['probes'] = [];

        $entries = $this->entriesOf(
            $this->builder()->build($ticket, new \DateTimeImmutable('2026-09-03 09:00:00'))
        );

        $this->assertArrayNotHasKey(TicketDossierBuilder::SNAPSHOT_ENTRY, $entries);
        $this->assertStringContainsString(
            'Ce qui manque',
            $entries[TicketDossierBuilder::README_ENTRY]
        );
        $this->assertStringContainsString(
            'figé au moment du ticket',
            $entries[TicketDossierBuilder::README_ENTRY]
        );
    }

    public function testWithoutAFileReaderTheRestIsStillProduced(): void
    {
        // §7.5's degradation: a caller that built no reader gets the
        // receiver's own knowledge, not a failed download.
        $this->seedArchive('PK bytes');

        $entries = $this->dossier(null);

        $this->assertArrayNotHasKey(TicketDossierBuilder::ARCHIVE_ENTRY, $entries);
        $this->assertArrayHasKey(TicketDossierBuilder::TICKET_ENTRY, $entries);
    }

    public function testTheFilenameCarriesTheReferenceSoTwoTicketsNeverCollide(): void
    {
        $name = TicketDossierBuilder::filename($this->ticket());

        $this->assertStringStartsWith('dossier-support-', $name);
        $this->assertStringEndsWith('.zip', $name);
        $this->assertStringContainsString((string) $this->ticket()['reference'], $name);
    }

    private function reader(): StoredFileReader
    {
        $storage = sys_get_temp_dir() . '/sm-dossier-storage';
        @mkdir($storage, 0777, true);
        $fileRepository = new FileRepository($this->pdo);

        return new StoredFileReader(
            $fileRepository,
            new EncryptedFileStorageService($fileRepository, $this->encryption, $storage),
            $storage
        );
    }

    private function seedArchive(string $bytes): int
    {
        $storage = sys_get_temp_dir() . '/sm-dossier-storage';
        @mkdir($storage . '/support', 0777, true);
        file_put_contents($storage . '/support/archive.zip', $bytes);

        $fileId = (new FileRepository($this->pdo))->create(
            'support/archive.zip',
            'archive.zip',
            'application/zip',
            strlen($bytes),
            'superadmin',
            'support_dashboard',
            null
        );
        $this->tickets->attachArchive($this->ticketId, $fileId, new \DateTimeImmutable('2026-09-01 15:00:00'));

        return $fileId;
    }
}
