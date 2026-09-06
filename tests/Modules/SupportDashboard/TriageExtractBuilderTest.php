<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Export\TabularSpreadsheet;
use Modules\SupportDashboard\Service\TriageExtractBuilder;
use Modules\SupportDashboard\TicketCategory;
use PHPUnit\Framework\TestCase;

/**
 * The reduced, anonymised copy of an archive (ARCHITECTURE.md
 * §8.49sexies): what is copied, what is converted, what is left out —
 * and that the README says which is which.
 */
class TriageExtractBuilderTest extends TestCase
{
    private const CONTACT = 'chef@unite.be';

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
    }

    public function testTheExtractCopiesTheAllowedEntriesScrubbed(): void
    {
        $entries = $this->extract($this->archive());

        $this->assertArrayHasKey('logs/error.log', $entries);
        $this->assertStringNotContainsString('203.0.113.7', $entries['logs/error.log']);
        $this->assertStringNotContainsString('parent@example.org', $entries['logs/error.log']);
        $this->assertStringContainsString('PHP Fatal error', $entries['logs/error.log']);
        $this->assertStringContainsString('ip-1', $entries['logs/error.log']);

        $this->assertArrayHasKey('statistics.json', $entries);
        $this->assertArrayHasKey('collection-status.json', $entries);
        $this->assertArrayHasKey('webserver/summary.txt', $entries);
    }

    public function testTheExcludedAndUnknownEntriesAreLeftOutAndNamed(): void
    {
        $entries = $this->extract($this->archive());

        $this->assertArrayNotHasKey('phpinfo.html', $entries);
        $this->assertArrayNotHasKey('configuration-parameters.xlsx', $entries);
        $this->assertArrayNotHasKey('new-collector.txt', $entries);
        $this->assertArrayNotHasKey('event-journal.xlsx', $entries);

        $readme = $entries[TriageExtractBuilder::README_ENTRY];
        $this->assertStringContainsString('phpinfo.html', $readme);
        $this->assertStringContainsString('configuration-parameters.xlsx', $readme);
        $this->assertStringContainsString('new-collector.txt : rubrique inconnue', $readme);
        $this->assertStringContainsString('issue GitHub n°181', $readme);
    }

    public function testTheEventJournalBecomesACsvWithItsPersonalColumnsTokenised(): void
    {
        $entries = $this->extract($this->archive());

        $this->assertArrayHasKey('event-journal.csv', $entries);
        $csv = $entries['event-journal.csv'];

        $this->assertStringContainsString('"Compte utilisateur","Adresse IP"', $csv);
        $this->assertStringNotContainsString('203.0.113.9', $csv);
        $this->assertStringNotContainsString('198.51.100.4', $csv);
        // The user-account column is tokenised whatever it holds: a bare
        // integer would otherwise pass every pattern.
        $this->assertMatchesRegularExpression('/,user-1,ip-\d+,auth,login_failed,/', $csv);
        $this->assertMatchesRegularExpression('/,user-2,ip-\d+,auth,login_ok,/', $csv);
        // The context column carried an address in JSON; scrubbed like
        // any other text.
        $this->assertStringNotContainsString('198.51.100.4', $csv);
        $this->assertStringContainsString('source_ip', $csv);
    }

    public function testTheTicketFileCarriesTheDescriptionButNeverTheContactAddress(): void
    {
        $entries = $this->extract($this->archive());

        $ticket = $entries[TriageExtractBuilder::TICKET_ENTRY];
        $this->assertStringContainsString('Ticket de support SUP-ABC234', $ticket);
        $this->assertStringContainsString('Import Desk', $ticket);
        $this->assertStringContainsString("L'import Desk s'arrête", $ticket);
        $this->assertStringNotContainsString(self::CONTACT, $ticket);
        // The description mentioned an address; scrubbed there too.
        $this->assertStringNotContainsString('203.0.113.50', $ticket);

        $whole = implode("\n", $entries);
        $this->assertStringNotContainsString(self::CONTACT, $whole);
        $this->assertStringNotContainsString('unite-de-test.example.be', $whole);
    }

    public function testALongDescriptionIsClamped(): void
    {
        $ticket = $this->ticket(['description' => str_repeat('x', 3000)]);
        $entries = $this->extract($this->archive(), $ticket);

        $this->assertStringContainsString(str_repeat('x', TriageExtractBuilder::DESCRIPTION_MAX_CHARS) . ' […]', $entries[TriageExtractBuilder::TICKET_ENTRY]);
        $this->assertStringNotContainsString(str_repeat('x', TriageExtractBuilder::DESCRIPTION_MAX_CHARS + 1), $entries[TriageExtractBuilder::TICKET_ENTRY]);
    }

    public function testAnEntryOverTheCeilingIsOmittedAndNamed(): void
    {
        $archive = $this->archive([
            'logs/huge.log' => str_repeat('a', TriageExtractBuilder::MAX_ENTRY_BYTES + 1),
        ]);
        $entries = $this->extract($archive);

        $this->assertArrayNotHasKey('logs/huge.log', $entries);
        $this->assertStringContainsString('logs/huge.log : entrée trop volumineuse', $entries[TriageExtractBuilder::README_ENTRY]);
        $this->assertArrayHasKey('logs/error.log', $entries, 'the other entries still travel');
    }

    public function testBytesThatAreNotAZipAreRefused(): void
    {
        $this->expectException(\RuntimeException::class);

        (new TriageExtractBuilder())->build($this->ticket(), 'not a zip at all', 181, new \DateTimeImmutable());
    }

    /**
     * @return array<string, mixed>
     */
    private function ticket(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'reference' => 'SUP-ABC234',
            'installation_id' => 1,
            'category' => TicketCategory::of('desk_import'),
            'description' => "L'import Desk s'arrête à mi-parcours depuis 203.0.113.50.",
            'contact_email' => self::CONTACT,
            'site_version' => '1.0.41',
            'php_version' => '8.4.0',
            'status' => 'open',
            'created_at' => '2026-09-06 08:00:00',
            'closed_at' => null,
            'resolution_note' => null,
            'archive_file_id' => 12,
            'archive_received_at' => '2026-09-06 08:01:00',
            'github_issue_number' => null,
            'github_issue_linked_at' => null,
            'statistics_snapshot' => ['instance_url' => 'https://unite-de-test.example.be'],
        ], $overrides);
    }

    /**
     * A diagnostic archive as the sending installation builds one, with
     * the entries this builder has to decide about.
     *
     * @param array<string, string> $extra
     */
    private function archive(array $extra = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sm-triage-test-');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);

        $zip->addEmptyDir('logs');
        $zip->addFromString('logs/error.log', "[06-Sep-2026 12:30:45] PHP Fatal error: Uncaught Error at 203.0.113.7 for parent@example.org\n");
        $zip->addFromString('logs/summary.txt', "# Journaux serveur\n");
        $zip->addFromString('webserver/summary.txt', "Apache/2.4\n");
        $zip->addFromString('statistics.json', '{"scoutmagic":{"version":"1.0.41"}}');
        $zip->addFromString('collection-status.json', '{"collectors":[]}');
        $zip->addFromString('phpinfo.html', '<html>PHP Version 8.4.0 DOCUMENT_ROOT=/var/www</html>');
        $zip->addFromString('configuration-parameters.xlsx', TabularSpreadsheet::build(['Clé', 'Valeur'], [['site_name', 'Unité de test']], 'Paramètres'));
        $zip->addFromString('new-collector.txt', "something a newer version writes\n");
        $zip->addFromString('event-journal.xlsx', TabularSpreadsheet::build(
            ['Horodatage (heure locale du serveur)', 'Compte utilisateur', 'Adresse IP', 'Catégorie', 'Type', 'Niveau', 'Description', 'Contexte'],
            [
                ['2026-09-06 08:00:00+02:00', '42', '203.0.113.9', 'auth', 'login_failed', 'warning', 'Échec de connexion', '{"source_ip":"198.51.100.4"}'],
                ['2026-09-06 08:01:00+02:00', '7', '203.0.113.9', 'auth', 'login_ok', 'info', 'Connexion', ''],
            ],
            'Journal 48h'
        ));

        foreach ($extra as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * @return array<string, string> entry name => content
     */
    private function extract(string $archive, ?array $ticket = null): array
    {
        $bytes = (new TriageExtractBuilder())->build(
            $ticket ?? $this->ticket(),
            $archive,
            181,
            new \DateTimeImmutable('2026-09-06 10:00:00')
        );

        $path = tempnam(sys_get_temp_dir(), 'sm-triage-out-test-');
        file_put_contents($path, $bytes);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true, 'the extract is not a readable zip');

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($path);

        return $entries;
    }
}
