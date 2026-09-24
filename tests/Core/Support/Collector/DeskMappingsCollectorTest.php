<?php

declare(strict_types=1);

namespace Tests\Core\Support\Collector;

use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\AgeBranchRepository;
use Core\Import\DeskMappingGapService;
use Core\Import\FunctionRepository;
use Core\Support\Collector\DeskMappingsCollector;
use Core\Support\SupportCollectorContext;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * `desk-mappings.json` in the support package (issue #356).
 *
 * This is the half of the answer that survives an installation with
 * telemetry switched off (D10): a maintainer reading a ticket gets the
 * unresolved values from the archive whether or not a daily report ever
 * reached anywhere.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeskMappingsCollectorTest extends TestCase
{
    private \PDO $pdo;
    private string $archivePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->archivePath = tempnam(sys_get_temp_dir(), 'pkg') . '.zip';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->archivePath)) {
            unlink($this->archivePath);
        }
    }

    public function testTheArchiveNamesWhatTheSiteDoesNotRecogniseAndTheTableToComplete(): void
    {
        (new FunctionRepository($this->pdo))->create('Animateur Nutons', 'Animateur Nutons', 'identified', false);
        (new AgeBranchRepository($this->pdo))->create('Nutons', 'Nutons');

        $written = $this->collect();

        $this->assertCount(2, $written['unresolved']);
        $kinds = array_column($written['unresolved'], 'kind');
        $this->assertContains('function', $kinds);
        $this->assertContains('branch', $kinds);

        $branch = $written['unresolved'][array_search('branch', $kinds, true)];
        $this->assertSame('Nutons', $branch['value']);
        $this->assertSame('Core\Import\AgeBranchRepository::canonicalSortOrder()', $branch['code_table']);
    }

    /**
     * An empty list, never an absent file: « this installation recognises
     * everything » and « nobody collected this » are opposite answers, and
     * a package that omits the second reads as the first.
     */
    public function testAnInstallationWithNothingUnresolvedStillWritesTheFile(): void
    {
        $this->assertSame(['unresolved' => []], $this->collect());
    }

    /**
     * SECURITY.md §11, checked on the file that actually leaves the
     * installation: a federal label and a count, and no member.
     */
    public function testTheFileCarriesNothingButVocabularyAndCounts(): void
    {
        (new FunctionRepository($this->pdo))->create('Animateur Nutons', 'Animateur Nutons', 'identified', false);

        $written = $this->collect();

        $this->assertSame(['kind', 'value', 'affected', 'code_table'], array_keys($written['unresolved'][0]));
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(): array
    {
        $archive = new \ZipArchive();
        $archive->open($this->archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $collector = new DeskMappingsCollector(
            new DeskMappingGapService($this->pdo, new ScoutYearService($this->pdo))
        );
        $collector->collect(new SupportCollectorContext(
            $archive,
            Connection::withPdo($this->pdo),
            new SettingService(new SettingRepository($this->pdo)),
            dirname(__DIR__, 4),
            sys_get_temp_dir()
        ));
        $archive->close();

        $read = new \ZipArchive();
        $read->open($this->archivePath);
        $content = $read->getFromName('desk-mappings.json');
        $read->close();

        $this->assertIsString($content, 'desk-mappings.json must be in the archive.');
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
