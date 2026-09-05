<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ReferenceDataset\CampsBlueprint;
use Tests\Fixtures\ReferenceDataset\UnitBlueprint;

/**
 * `build.php`, run for real: a throwaway installation, a real MySQL/MariaDB
 * server, and the whole builder from the first argument to the last line of
 * its report.
 *
 * **Nothing else runs this script.** ReferenceDatasetBuilderTest next door
 * drives the pieces it orchestrates — the finance seeding, the demo
 * accounts, the reset — against SQLite, and says so in its own docblock:
 * « `build.php` itself is a script ». That left the orchestration itself,
 * and every seeder no piece-level test reaches (camps, campaign, calendar,
 * news, registrations, banners, gallery, rental), covered by nobody. The
 * cost of that showed the first time anybody ran it: two fatal errors, one
 * after the other, in code that had been committed, reviewed and released.
 *
 *   - `CampsSeeder` handed `CampService::create()` the id of the section
 *     this dataset deliberately empties in 2026-2027, which
 *     `MappingResolver::deactivateAllSections()` correctly leaves inactive.
 *     The service validates section ids against the sections that are
 *     ACTIVE, whatever the stay's dates, and refused — « Une des sections
 *     choisies n'existe pas ». See CampsBlueprint's own comment.
 *   - `CampaignSeeder::line()` re-parsed the `d/m/Y` string it had just
 *     formatted. `\DateTimeImmutable` reads that as `m/d/Y`: silently the
 *     wrong date in the bank reference for the first twelve days of a
 *     month, and a `DateMalformedStringException` for every other day.
 *
 * The build takes about twenty seconds against a local server, on top of
 * two for the provisioning. That is the price of the only test that proves
 * the reference dataset can still be built at all, and README.md §8.2 —
 * whose observed figures sat at « à constater » because the build could not
 * be run — is what it costs not to have it.
 *
 * The instance is provisioned by `scripts/e2e-support.php provision`, the
 * same production code path the end-to-end suite uses, into its OWN
 * database (never TEST_DB_NAME: the builder truncates every table it
 * finds). It is dropped, with the instance tree, in tearDownAfterClass().
 *
 * @see tests/fixtures/reference-dataset/README.md §8
 */
#[Group('database')]
final class ReferenceDatasetBuildTest extends TestCase
{
    /**
     * Deliberately not TEST_DB_NAME. The builder empties every table of the
     * database it is pointed at, and the rest of this suite is entitled to
     * its own.
     */
    private const DATABASE = 'scoutmagic_reference_dataset_build';

    /** Nothing ever listens on it — the instance only writes it into a base URL. */
    private const INSTANCE_PORT = 8099;

    /**
     * One fixed query per table this test counts. PDO cannot bind an
     * identifier, so a `rowCount($table)` helper can only build its SQL by
     * concatenation — which AGENTS.md § Security checklist rules out
     * without exception, and which a static analyser flags whether or not
     * the caller happens to pass a literal today. Written out, the SQL is
     * constant, and a table renamed out from under this test fails on a
     * missing key here rather than deep inside a PDO error.
     *
     * @var array<string, string>
     */
    private const COUNT_QUERIES = [
        'camp_places' => 'SELECT COUNT(*) FROM camp_places',
        'camp_camps' => 'SELECT COUNT(*) FROM camp_camps',
        'camp_camp_sections' => 'SELECT COUNT(*) FROM camp_camp_sections',
        'calendar_events' => 'SELECT COUNT(*) FROM calendar_events',
        'news_articles' => 'SELECT COUNT(*) FROM news_articles',
        'registration_requests' => 'SELECT COUNT(*) FROM registration_requests',
        'banners' => 'SELECT COUNT(*) FROM banners',
        'gallery_albums' => 'SELECT COUNT(*) FROM gallery_albums',
        'rental_assets' => 'SELECT COUNT(*) FROM rental_assets',
        'rental_bookings' => 'SELECT COUNT(*) FROM rental_bookings',
        'member_badges' => 'SELECT COUNT(*) FROM member_badges',
        'member_photos' => 'SELECT COUNT(*) FROM member_photos',
        'finance_campaign_rows' => 'SELECT COUNT(*) FROM finance_campaign_rows',
        'finance_expected_receivables' => 'SELECT COUNT(*) FROM finance_expected_receivables',
    ];

    private static string $instanceRoot = '';

    private static string $buildOutput = '';

    private static int $buildStatus = -1;

    private static ?\PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        if (self::serverPdo() === null) {
            self::markTestSkipped('No MySQL/MariaDB server reachable through TEST_DB_* — see CONTRIBUTING.md.');
        }

        self::$instanceRoot = sys_get_temp_dir() . '/scoutmagic_refdataset_build_' . uniqid() . '/instance';

        [$status, $output] = self::runProcess(
            [PHP_BINARY, self::repositoryRoot() . '/scripts/e2e-support.php', 'provision',
                self::$instanceRoot, (string) self::INSTANCE_PORT],
            self::provisioningEnvironment(),
        );
        self::assertSame(0, $status, "Provisioning the throwaway instance failed:\n" . $output);

        [self::$buildStatus, self::$buildOutput] = self::runProcess([
            PHP_BINARY,
            self::repositoryRoot() . '/tests/fixtures/reference-dataset/build.php',
            '--yes',
            // The provisioning leaves an installation that has served —
            // five accounts and their members — which the builder refuses
            // to build into, as it should. --reset answers that refusal;
            // --no-backup skips the safety dump (it needs mysqldump, and
            // there is nothing here worth restoring).
            '--reset',
            '--no-backup',
            '--root=' . self::$instanceRoot,
        ], []);

        self::$pdo = self::serverPdo(self::DATABASE);
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo = null;

        $server = self::serverPdo();
        $server?->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');

        if (self::$instanceRoot !== '') {
            self::removeTree(dirname(self::$instanceRoot));
        }
    }

    public function testTheBuilderRunsToCompletion(): void
    {
        self::assertSame(
            0,
            self::$buildStatus,
            "build.php did not finish. Its output:\n" . self::$buildOutput,
        );
        self::assertStringContainsString(
            'Terminé.',
            self::$buildOutput,
            'build.php exited 0 without reaching its closing line — a step ended the script quietly.',
        );
    }

    public function testTheThreeScoutYearsArePopulated(): void
    {
        foreach (UnitBlueprint::YEARS as $label) {
            $members = $this->countActiveMembersIn($label);

            self::assertGreaterThanOrEqual(170, $members, "L'unité de {$label} a perdu du monde en route.");
            self::assertLessThanOrEqual(190, $members, "L'unité de {$label} a gagné du monde en route.");
        }
    }

    public function testOnlyTheEmptiedSectionIsLeftInactive(): void
    {
        $states = [];
        foreach ($this->query('SELECT name, is_active FROM sections') as $row) {
            $states[(string) $row['name']] = (int) $row['is_active'] === 1;
        }

        foreach (UnitBlueprint::SECTIONS as $handle => $section) {
            self::assertArrayHasKey($section['name'], $states, "{$section['name']} n'a pas été créée.");
            self::assertSame(
                $handle !== 'iam1',
                $states[$section['name']],
                // iam1 is the section the dataset empties in 2026-2027
                // (UnitBlueprint::HEADCOUNT). Every other one stays open.
                "{$section['name']} n'est pas dans l'état que le blueprint décrit.",
            );
        }
    }

    /**
     * The camps regression. A stay whose section the service refuses does
     * not come back as a missing row — it throws, and the build stops
     * there — so the count that matters is the one that ties every stay to
     * the sections its blueprint names.
     */
    public function testEveryCampPlaceAndStayIsCreatedWithItsSections(): void
    {
        $expectedLinks = 0;
        foreach (CampsBlueprint::CAMPS as $camp) {
            $expectedLinks += count($camp['sections']);
        }

        self::assertSame(count(CampsBlueprint::PLACES), $this->rowCount('camp_places'));
        self::assertSame(count(CampsBlueprint::CAMPS), $this->rowCount('camp_camps'));
        self::assertSame(
            $expectedLinks,
            $this->rowCount('camp_camp_sections'),
            'Un séjour a perdu une section en route — CampService::create() ne les accepte que actives.',
        );
    }

    /**
     * The campaign regression, and the half of it that never threw: a
     * reference built from a date read as `m/d/Y` carries the wrong day for
     * the first twelve days of a month, and nothing anywhere would notice.
     */
    public function testEveryCampaignPaymentCarriesItsOwnDateInItsBankReference(): void
    {
        $checked = 0;
        foreach ($this->query('SELECT bank_reference, transaction_date FROM finance_transactions') as $row) {
            $reference = (string) $row['bank_reference'];
            // CampaignSeeder's own serial band: ymd, then '9', then nine
            // digits — well clear of the committed statements' references.
            if (preg_match('/^\d{6}9\d{9}$/', $reference) !== 1) {
                continue;
            }

            $checked++;
            self::assertSame(
                (new \DateTimeImmutable((string) $row['transaction_date']))->format('ymd'),
                substr($reference, 0, 6),
                'Une référence de paiement de campagne ne porte pas la date de son propre mouvement.',
            );
        }

        self::assertGreaterThan(0, $checked, "Aucun paiement de campagne n'a été importé.");
    }

    /**
     * Every seeder that build.php alone reaches wrote something. Not a
     * count — the blueprints own those, and half of them are computed — but
     * the difference between "the domain was seeded" and the silent skip
     * README.md §8.1 warns about.
     */
    public function testEverySeededDomainWroteSomething(): void
    {
        foreach ([
            'calendar_events' => 'le calendrier',
            'news_articles' => 'les actualités',
            'registration_requests' => 'les inscriptions',
            'banners' => 'les bannières',
            'gallery_albums' => 'la galerie',
            'rental_assets' => 'le bien en location',
            'rental_bookings' => 'les réservations',
            'member_badges' => 'les badges',
            'member_photos' => 'les photos de membres',
            'finance_campaign_rows' => 'la campagne de paiement',
            'finance_expected_receivables' => 'les créances',
        ] as $table => $domain) {
            self::assertGreaterThan(0, $this->rowCount($table), "Rien n'a été semé pour {$domain}.");
        }
    }

    // --- Plumbing ---------------------------------------------------------

    private function rowCount(string $table): int
    {
        $sql = self::COUNT_QUERIES[$table] ?? null;
        if ($sql === null) {
            self::fail('No counting query declared for ' . $table . '.');
        }

        return (int) $this->pdo()->query($sql)->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function query(string $sql): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->pdo()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    private function countActiveMembersIn(string $yearLabel): int
    {
        $statement = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM member_years my
             JOIN scout_years sy ON sy.id = my.scout_year_id
             WHERE sy.label = ? AND my.is_active = 1'
        );
        $statement->execute([$yearLabel]);

        return (int) $statement->fetchColumn();
    }

    private function pdo(): \PDO
    {
        if (self::$pdo === null) {
            self::fail('The throwaway instance was never provisioned.');
        }

        return self::$pdo;
    }

    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The credentials scripts/e2e-support.php reads, pointed at this test's
     * own database. The account passwords are throwaway and never leave
     * this process tree.
     *
     * @return array<string, string>
     */
    private static function provisioningEnvironment(): array
    {
        $password = 'Reference-Build-' . bin2hex(random_bytes(8)) . '!';

        $environment = [
            'E2E_DB_HOST' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'E2E_DB_PORT' => getenv('TEST_DB_PORT') ?: '3306',
            'E2E_DB_NAME' => self::DATABASE,
            'E2E_DB_USER' => getenv('TEST_DB_USER') ?: 'root',
            'E2E_DB_PASSWORD' => getenv('TEST_DB_PASSWORD') ?: '',
        ];

        foreach (['ADMIN', 'MEMBER', 'INTENDANT', 'CHIEF', 'UNIT_ADMIN'] as $role) {
            $environment['E2E_' . $role . '_EMAIL'] = strtolower($role) . '@example.invalid';
            $environment['E2E_' . $role . '_PASSWORD'] = $password;
        }

        return $environment;
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     * @return array{0: int, 1: string}
     */
    private static function runProcess(array $arguments, array $environment): array
    {
        // `2>&1` rather than a second pipe: draining one pipe to EOF and
        // only then the other deadlocks as soon as the child fills the
        // one nobody is reading — and a failing build.php writes a PHP
        // fatal to stderr while stdout is still open, which is precisely
        // the case this test exists to observe. One stream cannot fill
        // behind the other's back, and the two were being concatenated
        // anyway.
        $pipes = [];
        $process = proc_open(
            implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1',
            [1 => ['pipe', 'w']],
            $pipes,
            self::repositoryRoot(),
            $environment + getenv(),
        );

        if (!is_resource($process)) {
            return [-1, 'Could not start ' . ($arguments[1] ?? '')];
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    private static function serverPdo(?string $database = null): ?\PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=utf8mb4',
            getenv('TEST_DB_HOST') ?: '127.0.0.1',
            (int) (getenv('TEST_DB_PORT') ?: 3306),
        );

        try {
            $pdo = new \PDO(
                $database === null ? $dsn : $dsn . ';dbname=' . $database,
                getenv('TEST_DB_USER') ?: 'root',
                getenv('TEST_DB_PASSWORD') ?: '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\PDOException) {
            return null;
        }

        return $pdo;
    }

    private static function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) && !is_link($path) ? self::removeTree($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
