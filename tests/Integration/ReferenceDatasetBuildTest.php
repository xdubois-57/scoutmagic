<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Config\AppClock;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\File\FileRepository;
use Core\Photo\ImageVariantProcessor;
use Core\Photo\ImageVariantService;
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

    /**
     * #212. `--reset` preserves `settings`, which is right for the unit's
     * name and wrong for the flags a run leaves behind. The one that showed
     * is `current_scout_year_id`: the provisioning pins it on the single
     * year it creates, the wipe restarts the id counters, and the setting
     * then designates 2024-2025 — the site served its oldest year as the
     * public one.
     */
    public function testTheBuiltInstanceServesTheDateComputedYearAsItsPublicYear(): void
    {
        // README §5: "Le builder ne pose pas le réglage
        // `current_scout_year_id` : le site retombe donc sur son année
        // date-calculée". The provisioning DID pin it, and the reset used to
        // preserve it — pointing it, after the id counters restarted, at
        // whichever year the builder wrote first.
        self::assertSame(
            0,
            (int) $this->pdo()->query(
                "SELECT COUNT(*) FROM settings WHERE setting_key = 'current_scout_year_id'"
            )->fetchColumn(),
            "L'instance construite épingle une année publique — le site n'affiche plus l'année du jour.",
        );

        $expected = ScoutYearService::labelForDate(new \DateTimeImmutable('now', new \DateTimeZone(AppClock::TIMEZONE)));
        $resolver = new ScoutYearResolver(
            new ScoutYearService($this->pdo()),
            new SettingService(new SettingRepository($this->pdo())),
            new MemberYearRepository($this->pdo()),
        );

        self::assertSame(
            $expected,
            (string) ($resolver->getCurrentPublicYear()['label'] ?? ''),
            "L'année publique de l'instance construite n'est pas celle du jour.",
        );
    }

    /**
     * #213. The modules gated by `visible_when` are filtered out of
     * discovery when the installation profile resolves from an empty base
     * URL — which is what reading it from `settings` did, since
     * public/index.php only copies it there on the first web request.
     */
    public function testEveryModuleOnDiskIsActivatedAndAnySkipIsNamed(): void
    {
        $onDisk = count(glob(self::repositoryRoot() . '/modules/*/module.json') ?: []);

        self::assertStringContainsString(
            'Modules activés : ' . $onDisk,
            self::$buildOutput,
            "Le builder n'a pas activé tous les modules présents sur le disque. Sa sortie :\n" . self::$buildOutput,
        );
        self::assertStringNotContainsString('non activé', self::$buildOutput);
        self::assertSame(
            $onDisk,
            (int) $this->pdo()->query(
                "SELECT COUNT(*) FROM event_log WHERE event_type = 'module_activated'"
            )->fetchColumn(),
        );
    }

    /**
     * #214. The builder ran on php.ini's timezone (UTC here) while the site
     * it writes into runs on Europe/Brussels, so everything it wrote was
     * dated two hours before the site that serves it.
     */
    public function testTheBuilderWritesOnTheApplicationClock(): void
    {
        $loggedAt = (string) $this->pdo()->query(
            "SELECT logged_at FROM event_log WHERE event_type = 'module_activated' ORDER BY id LIMIT 1"
        )->fetchColumn();

        $written = new \DateTimeImmutable($loggedAt, new \DateTimeZone(AppClock::TIMEZONE));
        $now = new \DateTimeImmutable('now', new \DateTimeZone(AppClock::TIMEZONE));

        self::assertLessThan(
            1800,
            abs($now->getTimestamp() - $written->getTimestamp()),
            'Le builder date ce qu\'il écrit sur une autre horloge que celle de l\'application '
            . "(écrit : {$loggedAt}, application : " . $now->format('Y-m-d H:i:s') . ').',
        );
    }

    /**
     * #216. A seeder that calls the repository instead of the service skips
     * the journal line the controller writes — and a reference instance
     * whose journal says nothing about its own data cannot be used to read
     * what the journal is supposed to contain.
     */
    public function testEveryGestureTheControllersJournalIsInTheJournal(): void
    {
        $counts = [];
        foreach ($this->query('SELECT event_type, COUNT(*) AS total FROM event_log GROUP BY event_type') as $row) {
            $counts[(string) $row['event_type']] = (int) $row['total'];
        }

        foreach ([
            'article_created' => 'les articles',
            'form_response_submitted' => 'les réponses de formulaire',
            'registration_request_received' => 'les demandes d\'inscription',
            'badge_assigned' => 'les badges',
            'member_scout_year_offset_changed' => 'les décalages d\'année',
            'event_created' => 'les évènements du calendrier',
            'banner_created' => 'les bannières',
        ] as $eventType => $domain) {
            self::assertGreaterThan(
                0,
                $counts[$eventType] ?? 0,
                "Le journal ne dit rien de {$domain} ({$eventType}).",
            );
        }
    }

    /**
     * #216, the other half: `ResponseService::submit()` refuses a response
     * with no account on an `identified` form, so a seeded row carrying
     * `user_account_id = NULL` there is a state the application cannot
     * reach.
     */
    public function testEveryResponseOnAnIdentifiedFormHasAnAccountBehindIt(): void
    {
        $orphans = (int) $this->pdo()->query(
            "SELECT COUNT(*)
             FROM news_form_responses r
             JOIN news_forms f ON f.id = r.form_id
             WHERE f.access = 'identified' AND r.user_account_id IS NULL"
        )->fetchColumn();

        self::assertSame(0, $orphans, 'Une réponse sans compte sur un formulaire réservé aux connectés.');
    }

    /**
     * #210. The real upload path generates every derivative right after
     * storing the original; FileController::variant() answers 404 rather
     * than falling back, so a missing one is a broken image on every card.
     */
    public function testEveryNewsImageCarriesItsDerivatives(): void
    {
        $paths = $this->query(
            "SELECT relative_path FROM files WHERE relative_path LIKE 'news/images/%'"
        );
        self::assertNotSame([], $paths, "Aucune image d'article n'a été stockée.");

        // Asked of the service itself rather than recomputed here: the
        // naming rule of a derivative is its business, and a test that
        // restates it stops testing the day the rule changes.
        $variants = new ImageVariantService(
            new FileRepository($this->pdo()),
            new ImageVariantProcessor(),
            self::$instanceRoot . '/storage',
        );

        foreach ($paths as $row) {
            $relative = (string) $row['relative_path'];
            foreach (ImageVariantService::VARIANTS as $variant) {
                self::assertNotNull(
                    $variants->resolvePath($relative, $variant),
                    "Le dérivé « {$variant} » de {$relative} n'a pas été produit.",
                );
            }
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
