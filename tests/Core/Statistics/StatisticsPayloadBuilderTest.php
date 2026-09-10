<?php

declare(strict_types=1);

namespace Tests\Core\Statistics;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Database\MigrationRunner;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailService;
use Core\Module\ModuleManager;
use Core\Module\ModuleRegistryRepository;
use Core\Security\Role;
use Core\Security\SecretManager;
use Core\Statistics\InstallationDateService;
use Core\Maintenance\Portable\PortableRestore;
use Core\Statistics\InstallationIdentityService;
use Core\Statistics\StatisticsPayloadBuilder;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class StatisticsPayloadBuilderTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SettingRepository $settingRepository;
    private string $projectRoot;
    private SecretManager $secretManager;
    private InstallationIdentityService $identityService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingRepository = new SettingRepository($this->pdo);
        $this->settings = new SettingService($this->settingRepository);

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-payload-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/storage/keys', 0700, true);
        mkdir($this->projectRoot . '/storage/config', 0700, true);
        file_put_contents($this->projectRoot . '/VERSION', "1.0.33\n");

        $this->secretManager = new SecretManager(
            $this->projectRoot . '/storage/keys/master.key',
            $this->projectRoot . '/storage/config/secrets.enc'
        );
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets([]);

        $this->registerSettings();
        $this->identityService = new InstallationIdentityService($this->settings, $this->secretManager);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->projectRoot);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private function registerSettings(): void
    {
        $this->settings->register('base_url', '', 'url', 'L', 'D');
        $this->settings->register('current_scout_year_id', '0', 'number', 'L', 'D');
        $this->settings->register('auto_update_enabled', '1', 'boolean', 'L', 'D');
        $this->settings->register('auto_update_level', 'minor', 'text', 'L', 'D');
        $this->settings->register(InstallationIdentityService::INSTALLATION_ID_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(PortableRestore::RESTORED_FROM_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        InstallationDateService::register($this->settings);
        $this->settings->clearCache();
    }

    private function builder(
        ?ModuleManager $moduleManager = null,
        ?MailService $mailService = null,
        ?\Modules\UsageStats\Api\ModuleUsageInterface $moduleUsage = null
    ): StatisticsPayloadBuilder {
        return new StatisticsPayloadBuilder(
            $this->settings,
            $this->pdo,
            $this->identityService,
            $this->projectRoot,
            $moduleManager,
            $mailService,
            $moduleUsage
        );
    }

    /**
     * The distinction the whole `module_usage` field exists to preserve:
     * « cette installation ne mesure pas » is null, « personne n'a ouvert
     * ce module » is an entry missing from a list that is itself present.
     * A receiver that confounded the two would retire a module people use.
     */
    public function testModuleUsageIsNullWhenTheUsageStatsModuleIsNotWired(): void
    {
        $payload = $this->builder()->build();

        $this->assertArrayHasKey('module_usage', $payload);
        $this->assertNull($payload['module_usage']);
    }

    public function testModuleUsageCarriesTheAggregatePerModuleAndItsWindow(): void
    {
        $usage = new class () implements \Modules\UsageStats\Api\ModuleUsageInterface {
            /** @return list<\Modules\UsageStats\Api\ModuleUsage> */
            public function aggregatedByModule(): array
            {
                return [
                    new \Modules\UsageStats\Api\ModuleUsage('calendar', 412),
                    new \Modules\UsageStats\Api\ModuleUsage('news', 244),
                ];
            }
        };

        $payload = $this->builder(null, null, $usage)->build();

        $this->assertSame(
            [
                'window_months' => \Modules\UsageStats\Api\ModuleUsageInterface::WINDOW_MONTHS,
                'modules' => [
                    ['id' => 'calendar', 'views' => 412],
                    ['id' => 'news', 'views' => 244],
                ],
            ],
            $payload['module_usage']
        );
    }

    /**
     * The aggregate, never the detail. The payload has no page, no route
     * and no audience anywhere — the project's question is which modules
     * serve, not how often one unit opened its calendar.
     */
    public function testTheReportCarriesNoPageLevelDetail(): void
    {
        $usage = new class () implements \Modules\UsageStats\Api\ModuleUsageInterface {
            /** @return list<\Modules\UsageStats\Api\ModuleUsage> */
            public function aggregatedByModule(): array
            {
                return [new \Modules\UsageStats\Api\ModuleUsage('calendar', 412)];
            }
        };

        $json = $this->builder(null, null, $usage)->buildJson();

        foreach (['route', 'page', 'audience', '{id}'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    private function moduleManager(): ModuleManager
    {
        return new ModuleManager(
            dirname(__DIR__, 2) . '/fixtures/modules',
            $this->settings,
            new CookieConsentService([]),
            new MenuBuilder(Role::fromString('admin')),
            new ModuleRegistryRepository($this->pdo),
            new JournalService(new JournalRepository($this->pdo)),
            new Router()
        );
    }

    private function seedScoutYear(string $label = '2026-2027'): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 1)');
        $stmt->execute([$label, '2026-09-01', '2027-08-31']);
        $id = (int) $this->pdo->lastInsertId();

        $this->settingRepository->updateValue(null, 'current_scout_year_id', (string) $id);
        $this->settings->clearCache();

        return $id;
    }

    private function seedSection(string $deskCode): int
    {
        $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, 1)')
            ->execute(['BR-' . $deskCode, 'Branche']);
        $branchId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)')
            ->execute([$branchId, $deskCode, 'Section ' . $deskCode]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedMember(int $scoutYearId, ?int $sectionId, bool $active = true): void
    {
        static $counter = 0;
        $counter++;

        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute(['desk-' . $counter]);
        $memberId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active) VALUES (?, ?, ?, ?, ?)'
        )->execute([$memberId, $scoutYearId, 'x', 'y', $active ? 1 : 0]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        if ($sectionId !== null) {
            $this->pdo->prepare('INSERT INTO functions (desk_code, label) VALUES (?, ?)')
                ->execute(['fn-' . $counter, 'Fonction']);
            $functionId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)')
                ->execute([$memberYearId, $functionId, $sectionId]);
        }
    }

    public function testPayloadHasTheExpectedShape(): void
    {
        $this->settingRepository->updateValue(null, 'base_url', 'https://unite-exemple.be');
        $this->settings->clearCache();
        $this->seedScoutYear();

        $payload = $this->builder($this->moduleManager())->build();

        $this->assertSame(1, $payload['statistics_schema_version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $payload['installation_id']);
        $this->assertSame('https://unite-exemple.be', $payload['instance_url']);
        // Null, not '' — an installation that was never restored from an
        // archive has no origin, and "not reported" is a different fact
        // from "reported as nothing" everywhere in this payload.
        $this->assertNull($payload['restored_from']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', (string) $payload['generated_at']);
        $this->assertSame('1.0.33', $payload['scoutmagic']['version']);
        $this->assertFalse($payload['scoutmagic']['is_dev_build']);
        $this->assertSame('2026-2027', $payload['scout_year']['label']);
        $this->assertSame(PHP_VERSION, $payload['runtime']['php_version']);
        $this->assertSame(PHP_OS_FAMILY, $payload['host']['os_family']);
        $this->assertTrue($payload['security']['https_enabled']);
        $this->assertSame('poor_mans_cron', $payload['scheduler']['mode']);
        $this->assertTrue($payload['updates']['auto_update_enabled']);
        $this->assertSame('minor', $payload['updates']['auto_update_level']);
        $this->assertIsArray($payload['modules']);

        $this->assertSame(
            [
                'statistics_schema_version', 'installation_id', 'restored_from', 'instance_url', 'generated_at',
                'scoutmagic', 'scout_year', 'usage', 'modules', 'module_usage', 'desk_vocabulary',
                'installation', 'runtime', 'database', 'host', 'security', 'email', 'scheduler',
                'updates', 'lifecycle', 'storage',
            ],
            array_keys($payload)
        );
    }

    /**
     * A restored installation names the one it came from.
     *
     * The receiver otherwise sees one installation go quiet and another
     * appear, with no way to tell a move from an abandonment — which is
     * the difference between a unit that changed host and a unit that
     * needs somebody to call it.
     */
    public function testARestoredInstallationReportsWhereItCameFrom(): void
    {
        $this->settingRepository->updateValue(null, PortableRestore::RESTORED_FROM_SETTING, 'aaaabbbbccccddddeeeeffff00001111');
        $this->settings->clearCache();
        $this->seedScoutYear();

        $payload = $this->builder($this->moduleManager())->build();

        $this->assertSame('aaaabbbbccccddddeeeeffff00001111', $payload['restored_from']);
        $this->assertNotSame(
            $payload['restored_from'],
            $payload['installation_id'],
            'the restored installation is reporting under the identity of the one it replaced'
        );
    }

    public function testADevBuildIsFlagged(): void
    {
        file_put_contents($this->projectRoot . '/VERSION', "dev-a1b2c3d\n");

        $payload = $this->builder()->build();

        $this->assertSame('dev-a1b2c3d', $payload['scoutmagic']['version']);
        $this->assertTrue($payload['scoutmagic']['is_dev_build']);
    }

    public function testUnavailableValuesAreNullNeverZeroOrFalse(): void
    {
        $payload = $this->builder()->build();

        $this->assertNull($payload['instance_url']);
        $this->assertNull($payload['scout_year']['label']);
        $this->assertNull($payload['usage']['active_members']);
        $this->assertNull($payload['usage']['active_sections']);
        $this->assertNull($payload['security']['https_enabled']);
        $this->assertNull($payload['email']['mode']);
        $this->assertNull($payload['email']['configured']);
        $this->assertNull($payload['modules']);
        $this->assertNull($payload['lifecycle']['installed_at']);
        $this->assertNull($payload['lifecycle']['last_upgraded_at']);
    }

    public function testMemberAndSectionCountsAreExact(): void
    {
        $scoutYearId = $this->seedScoutYear();
        $sectionA = $this->seedSection('BALA');
        $sectionB = $this->seedSection('LOUV');

        $this->seedMember($scoutYearId, $sectionA);
        $this->seedMember($scoutYearId, $sectionA);
        $this->seedMember($scoutYearId, $sectionB);
        $this->seedMember($scoutYearId, null, false);

        $payload = $this->builder()->build();

        $this->assertSame(3, $payload['usage']['active_members']);
        $this->assertSame(2, $payload['usage']['active_sections']);
    }

    /**
     * The counts a maintainer reads while answering a ticket used to be
     * « Non renseigné » on almost every installation, and the payload was
     * where it went wrong: `current_scout_year_id` ships at 0 and stays
     * there unless somebody pins a year by hand, while every page of the
     * site falls back to the year the date is in
     * (Core\ScoutYear\ScoutYearResolver). Reading only the setting meant
     * the report said « I don't know » about a site that knew.
     *
     * The label is computed rather than written down: a year hard-coded
     * here is a test that breaks by itself on the 1st of September.
     */
    public function testTheCountsFallBackToTheYearTheDateIsInLikeTheRestOfTheSite(): void
    {
        $label = ScoutYearService::labelForDate(new \DateTimeImmutable('now'));

        $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 1)');
        $stmt->execute([$label, substr($label, 0, 4) . '-09-01', substr($label, 5, 4) . '-08-31']);
        $scoutYearId = (int) $this->pdo->lastInsertId();

        // Deliberately NOT pinned — this is the shipped default.
        $this->assertSame('0', $this->settings->get('current_scout_year_id'));

        $this->seedMember($scoutYearId, $this->seedSection('BALA'));
        $this->seedMember($scoutYearId, $this->seedSection('LOUV'));

        $payload = $this->builder()->build();

        $this->assertSame(2, $payload['usage']['active_members']);
        $this->assertSame(2, $payload['usage']['active_sections']);
        $this->assertSame($label, $payload['scout_year']['label']);
    }

    public function testActiveSectionsExcludesStaffDu(): void
    {
        $scoutYearId = $this->seedScoutYear();
        $section = $this->seedSection('BALA');
        $staffDu = $this->seedSection('STAFFDU');

        $this->seedMember($scoutYearId, $section);
        $this->seedMember($scoutYearId, $staffDu);

        $payload = $this->builder()->build();

        $this->assertSame(2, $payload['usage']['active_members']);
        $this->assertSame(1, $payload['usage']['active_sections']);
    }

    public function testModulesAreSortedByIdAndCarryTheirVersion(): void
    {
        $payload = $this->builder($this->moduleManager())->build();

        $modules = $payload['modules'];
        $this->assertIsArray($modules);
        $this->assertNotEmpty($modules);

        $ids = array_column($modules, 'id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);

        foreach ($modules as $module) {
            $this->assertArrayHasKey('enabled', $module);
            $this->assertIsBool($module['enabled']);
            $this->assertArrayHasKey('version', $module);
        }
    }

    public function testEmailModeAndConfigurationComeFromTheMailService(): void
    {
        $dkim = new DkimManager($this->projectRoot . '/storage/keys');
        $configured = new MailService('smtp', 'unite@exemple.be', 'Unité', 'EX', $dkim, 's1', 'smtp.exemple.be', 587, 'user', 'password');
        $unconfigured = new MailService('smtp', '', '', 'EX', $dkim, 's1');

        $this->assertSame('smtp', $this->builder(null, $configured)->build()['email']['mode']);
        $this->assertTrue($this->builder(null, $configured)->build()['email']['configured']);
        $this->assertFalse($this->builder(null, $unconfigured)->build()['email']['configured']);
    }

    public function testEmailBlockNeverCarriesCredentials(): void
    {
        $dkim = new DkimManager($this->projectRoot . '/storage/keys');
        $mail = new MailService('smtp', 'unite@exemple.be', 'Unité', 'EX', $dkim, 's1', 'smtp.exemple.be', 587, 'smtp-user', 'smtp-password');

        $json = $this->builder(null, $mail)->buildJson();

        $this->assertStringNotContainsString('smtp-password', $json);
        $this->assertStringNotContainsString('smtp-user', $json);
        $this->assertStringNotContainsString('smtp.exemple.be', $json);
        $this->assertStringNotContainsString('unite@exemple.be', $json);
    }

    public function testSchedulerModeIsRealCronWhenCronRanRecently(): void
    {
        $this->settings->register('cron_last_run', '0', 'number', 'L', 'D', null, null, null, false);
        $this->settingRepository->updateValue(null, 'cron_last_run', (string) (time() - 3600));
        $this->settings->clearCache();

        $this->assertSame('real_cron', $this->builder()->build()['scheduler']['mode']);
    }

    public function testSchedulerModeIsPoorMansCronWhenTheStampIsStale(): void
    {
        $this->settings->register('cron_last_run', '0', 'number', 'L', 'D', null, null, null, false);
        $this->settingRepository->updateValue(null, 'cron_last_run', (string) (time() - 300000));
        $this->settings->clearCache();

        $this->assertSame('poor_mans_cron', $this->builder()->build()['scheduler']['mode']);
    }

    public function testLastUpgradedAtUsesTheMostRecentCompletedUpdate(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO update_history (version_from, version_to, status, completed_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['1.0.30', '1.0.31', 'completed', '2026-07-01 02:00:00']);
        $stmt->execute(['1.0.31', '1.0.32', 'completed', '2026-08-01 02:00:00']);
        $stmt->execute(['1.0.32', '1.0.33', 'failed', '2026-08-10 02:00:00']);

        $payload = $this->builder()->build();

        $this->assertStringStartsWith('2026-08-01T', (string) $payload['lifecycle']['last_upgraded_at']);
        $this->assertStringEndsWith('+00:00', (string) $payload['lifecycle']['last_upgraded_at']);
    }

    public function testInstallationMethodComesFromTheInstallReportWhenPresent(): void
    {
        file_put_contents(
            $this->projectRoot . '/storage/config/install-report.json',
            json_encode(['layout' => 'B'])
        );

        $this->assertSame('layout_b', $this->builder()->build()['installation']['method']);
    }

    public function testInstallationMethodIsNullWithoutAnyTell(): void
    {
        $this->assertNull($this->builder()->build()['installation']['method']);
    }

    public function testInstallationMethodFallsBackToTheFilesystemLayout(): void
    {
        mkdir($this->projectRoot . '/public');
        file_put_contents($this->projectRoot . '/public/index.php', '<?php');
        $this->assertSame('layout_a', $this->builder()->build()['installation']['method']);

        file_put_contents($this->projectRoot . '/index.php', '<?php');
        $this->assertSame('layout_b', $this->builder()->build()['installation']['method']);
    }

    public function testTheSecretNeverAppearsAnywhereInTheJson(): void
    {
        $secret = $this->identityService->getSecret();
        $this->assertNotNull($secret);

        $json = $this->builder($this->moduleManager())->buildJson();

        $this->assertStringNotContainsString($secret, $json);
        $this->assertStringNotContainsString('statistics_secret', $json);
    }

    public function testForbiddenFieldsAreAbsent(): void
    {
        $this->settingRepository->updateValue(null, 'base_url', 'https://unite-exemple.be');
        $this->settings->clearCache();
        $scoutYearId = $this->seedScoutYear();
        $this->seedMember($scoutYearId, $this->seedSection('BALA'));

        $json = $this->builder($this->moduleManager())->buildJson();
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);

        // No key, at any depth, naming something the spec forbids.
        $forbiddenKeys = [
            'secret', 'contact_name', 'contact_email', 'email_address', 'language', 'timezone',
            'admin_count', 'environment', 'database_size', 'upload_bytes', 'hostname', 'kernel',
            'server_ip', 'members', 'sections', 'member_names', 'section_names', 'activity',
            'file_content', 'credential', 'module_settings',
        ];
        $keys = self::collectKeys($decoded);
        foreach ($forbiddenKeys as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "Payload must not carry a '{$forbidden}' field");
        }

        // No value carrying a section name, a hostname or kernel details.
        $this->assertStringNotContainsString('Section BALA', $json);
        $this->assertStringNotContainsString(php_uname(), $json);
        $this->assertStringNotContainsString(gethostname() ?: '__no_hostname__', $json);
        $this->assertStringNotContainsString(php_uname('n'), $json);
    }

    /**
     * @param array<mixed> $data
     * @return array<int, string>
     */
    private static function collectKeys(array $data): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            if (is_array($value)) {
                $keys = array_merge($keys, self::collectKeys($value));
            }
        }

        return $keys;
    }

    public function testAThrowingCollectorDoesNotInterruptTheBuild(): void
    {
        // A dropped table is the bluntest possible collector failure: the
        // member/section counters throw, everything else must still be built.
        $this->pdo->exec('DROP TABLE member_years');
        $this->seedScoutYearWithoutMembers();

        $payload = $this->builder()->build();

        $this->assertNull($payload['usage']['active_members']);
        $this->assertNull($payload['usage']['active_sections']);
        $this->assertSame('2026-2027', $payload['scout_year']['label']);
        $this->assertSame(PHP_VERSION, $payload['runtime']['php_version']);
    }

    public function testBuildNeverThrowsWithoutAnyTable(): void
    {
        foreach (['member_functions', 'member_years', 'scout_years', 'update_history', 'settings'] as $table) {
            $this->pdo->exec('DROP TABLE ' . $table);
        }
        $this->settings->clearCache();

        $payload = $this->builder()->build();

        $this->assertSame(1, $payload['statistics_schema_version']);
        $this->assertNull($payload['instance_url']);
    }

    public function testJsonIsPrettyPrintedWithUnescapedSlashesAndUnicode(): void
    {
        $this->settingRepository->updateValue(null, 'base_url', 'https://unité-exemple.be');
        $this->settings->clearCache();

        $json = $this->builder()->buildJson();

        $this->assertStringContainsString("\n", $json);
        $this->assertStringContainsString('https://unité-exemple.be', $json);
        $this->assertStringNotContainsString('\\/', $json);
        $this->assertStringNotContainsString('\\u00e9', $json);
    }

    public function testTwoBuildsOfAnUnchangedInstallationDifferOnlyByTheTimestamp(): void
    {
        $this->seedScoutYear();
        $builder = $this->builder($this->moduleManager());

        $first = $builder->build();
        $second = $builder->build();

        unset($first['generated_at'], $second['generated_at']);
        $this->assertSame($first, $second);
    }

    /**
     * The unit's Desk vocabulary — the FONCTION labels and the cotisation
     * types its exports actually carry. It travels because nothing on this
     * site breaks when the federation invents a new one (the import
     * creates it, the screens that cannot classify it say so), and so
     * nobody reports it: seeing it here is the only way the maintainer
     * learns a new value is in the wild.
     */
    public function testTheReportCarriesTheUnitsDeskFunctionsAndFeeCategories(): void
    {
        $this->seedScoutYear();
        $this->seedVocabulary();

        $payload = $this->builder()->build();

        $this->assertSame(2, $payload['desk_vocabulary']['fee_categories']['total']);
        $this->assertSame(
            [
                ['desk_code' => 'F_COTISATION_FAMILLE', 'label' => 'F_COTISATION_FAMILLE'],
                ['desk_code' => 'N_COTISATION_NORMALE', 'label' => 'N_COTISATION_NORMALE'],
            ],
            $payload['desk_vocabulary']['fee_categories']['listed'],
        );

        $this->assertSame(2, $payload['desk_vocabulary']['functions']['total']);
        $this->assertSame(
            [
                ['desk_code' => 'Animateur', 'label' => 'Animateur', 'role' => 'chief', 'confirmed' => true],
                [
                    'desk_code' => 'Délégué de branche',
                    'label' => 'Délégué de branche',
                    'role' => 'identified',
                    'confirmed' => false,
                ],
            ],
            $payload['desk_vocabulary']['functions']['listed'],
        );
    }

    /**
     * An empty list is a real answer — a site that has never imported
     * anything — and must not read as a failure to measure.
     */
    public function testAnInstallationThatNeverImportedReportsEmptyVocabularyRatherThanNull(): void
    {
        $this->seedScoutYear();

        $payload = $this->builder()->build();

        $this->assertSame(
            ['total' => 0, 'listed' => []],
            $payload['desk_vocabulary']['fee_categories'],
        );
    }

    /**
     * The bound that actually matters, and the one an entry count does not
     * give you.
     *
     * `desk_code` and `label` are `VARCHAR(100)` under `utf8mb4` in both
     * tables, so one entry can be four hundred-odd bytes. A hundred of
     * each at that width serialised to 134 632 bytes — twice
     * `StatisticsIntakeService::MAX_BODY_BYTES`, which is checked on the
     * raw body before parsing, so the receiver answered 413 and the WHOLE
     * report was lost. A unit with a verbose Desk vocabulary would have
     * silently stopped reporting anything at all.
     */
    public function testMaximumLengthMultibyteVocabularyStaysUnderTheReceiversBodyLimit(): void
    {
        $this->seedScoutYear();

        $fees = $this->pdo->prepare('INSERT INTO fee_categories (desk_code, label) VALUES (?, ?)');
        $functions = $this->pdo->prepare(
            'INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, ?)'
        );
        for ($i = 0; $i < 150; $i++) {
            // 100 characters, the column's declared maximum, in the widest
            // encoding utf8mb4 allows — four bytes each.
            $suffix = sprintf('%03d', $i);
            $fees->execute([str_repeat('界', 97) . $suffix, str_repeat('é', 97) . $suffix]);
            $functions->execute([
                str_repeat('界', 97) . $suffix,
                str_repeat('é', 97) . $suffix,
                str_repeat('役', 20),
                0,
            ]);
        }

        $json = $this->builder()->buildJson();

        $this->assertLessThan(65536, strlen($json));

        // Truncated, and saying so: `total` still counts every row, so a
        // reader can tell a short list from a complete one.
        $payload = $this->builder()->build();
        $this->assertSame(150, $payload['desk_vocabulary']['fee_categories']['total']);
        $this->assertSame(150, $payload['desk_vocabulary']['functions']['total']);
        $this->assertLessThan(100, count($payload['desk_vocabulary']['fee_categories']['listed']));
        $this->assertNotSame([], $payload['desk_vocabulary']['fee_categories']['listed']);
    }

    /**
     * The entry cap is still the second bound: ordinary short labels are
     * limited by count long before they are limited by bytes.
     */
    public function testAnAbsurdNumberOfCategoriesIsTruncatedAndSaysSo(): void
    {
        $this->seedScoutYear();
        $stmt = $this->pdo->prepare('INSERT INTO fee_categories (desk_code, label) VALUES (?, ?)');
        for ($i = 0; $i < 150; $i++) {
            $code = sprintf('TARIF_%03d', $i);
            $stmt->execute([$code, $code]);
        }

        $payload = $this->builder()->build();

        $this->assertSame(150, $payload['desk_vocabulary']['fee_categories']['total']);
        $this->assertCount(100, $payload['desk_vocabulary']['fee_categories']['listed']);
        $this->assertLessThan(65536, strlen($this->builder()->buildJson()));
    }

    /** Rule 2 of the builder: the vocabulary names labels, never people. */
    public function testTheVocabularyCarriesNoMemberData(): void
    {
        $this->seedScoutYear();
        $this->seedVocabulary();

        $json = $this->builder()->buildJson();

        $this->assertStringContainsString('N_COTISATION_NORMALE', $json);
        $this->assertStringNotContainsString('member_id', $json);
        $this->assertStringNotContainsString('@', substr($json, (int) strpos($json, '"desk_vocabulary"')));
    }

    private function seedVocabulary(): void
    {
        $fees = $this->pdo->prepare('INSERT INTO fee_categories (desk_code, label) VALUES (?, ?)');
        foreach (['N_COTISATION_NORMALE', 'F_COTISATION_FAMILLE'] as $code) {
            $fees->execute([$code, $code]);
        }

        $functions = $this->pdo->prepare(
            'INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, ?)'
        );
        $functions->execute(['Animateur', 'Animateur', 'chief', 1]);
        // The interesting half of the list: a function nobody has confirmed
        // in Config Desk yet, which is what a brand-new federation label
        // looks like on the day it appears.
        $functions->execute(['Délégué de branche', 'Délégué de branche', 'identified', 0]);
    }

    private function seedScoutYearWithoutMembers(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 1)');
        $stmt->execute(['2026-2027', '2026-09-01', '2027-08-31']);
        $this->settingRepository->updateValue(null, 'current_scout_year_id', (string) $this->pdo->lastInsertId());
        $this->settings->clearCache();
    }
}
