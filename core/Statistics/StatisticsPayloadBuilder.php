<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Statistics;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Mail\MailService;
use Core\Maintenance\VersionFile;
use Core\Member\UnitStaffSectionService;
use Core\Module\ModuleManager;
use Core\ScoutYear\ScoutYearResolver;
use Core\Service\DateInput;
use Modules\UsageStats\Api\ModuleUsageInterface;

/**
 * Builds the exact document an installation reports to the statistics
 * receiver (ARCHITECTURE.md §8.47) — and, unchanged, the `statistics.json`
 * of a support package (§8.48).
 *
 * Three rules govern everything here:
 *
 * 1. **Unavailable is `null`, never `0`, `false` or `""`.** The receiver
 *    has to be able to tell "this site has no members" from "this site
 *    could not tell us how many members it has", and a zero that really
 *    means "unknown" quietly poisons every aggregate built on top of it.
 * 2. **Nothing about a person, ever.** No member, no section name, no
 *    email, no contact, no hostname, no server IP, no credential, no module
 *    configuration value. The instance URL is the one identifying field,
 *    and it is deliberate (§8.47) — a report you cannot tie to the site you
 *    are supporting is close to useless.
 * 3. **Every collector is independent.** Each metric is gathered inside its
 *    own try/catch: one throwing sets its own field to `null` and changes
 *    nothing else. `build()` never throws — a report that cannot be built
 *    is worse than a report with holes in it.
 */
class StatisticsPayloadBuilder
{
    public const STATISTICS_SCHEMA_VERSION = 1;

    /**
     * A `cron_last_run` stamp older than this means no real crontab is
     * driving the scheduler any more, whatever it once did.
     */
    private const REAL_CRON_MAX_AGE_SECONDS = 172800;

    public function __construct(
        private SettingService $settingService,
        private \PDO $pdo,
        private InstallationIdentityService $identityService,
        private string $projectRoot,
        private ?ModuleManager $moduleManager = null,
        private ?MailService $mailService = null,
        // The usage_stats module's published capability (ARCHITECTURE.md
        // §7.5), consumed nullable like every other cross-boundary
        // capability: the module disabled means `module_usage` is null in
        // the report, which rule 1 above makes a different fact from an
        // empty list. Trailing and defaulted so no existing call site of
        // this constructor changes.
        private ?ModuleUsageInterface $moduleUsage = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $publicScoutYearId = $this->collect(fn(): ?int => $this->publicScoutYearId());

        return [
            'statistics_schema_version' => self::STATISTICS_SCHEMA_VERSION,
            'installation_id' => $this->collect(fn(): string => $this->identityService->getInstallationId()),
            'instance_url' => $this->collect(fn(): ?string => $this->settingValue('base_url')),
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format(\DateTimeInterface::ATOM),
            'scoutmagic' => [
                'version' => $this->collect(fn(): string => VersionFile::read($this->projectRoot)),
                'is_dev_build' =>
                    $this->collect(fn(): bool => VersionFile::isDevBuild(VersionFile::read($this->projectRoot))),
            ],
            'scout_year' => [
                'label' => $this->collect(fn(): ?string => $this->scoutYearLabel($publicScoutYearId)),
            ],
            'usage' => [
                'active_members' => $this->collect(fn(): ?int => $this->activeMembers($publicScoutYearId)),
                'active_sections' => $this->collect(fn(): ?int => $this->activeSections($publicScoutYearId)),
                'active_accounts_30d' => $this->collect(fn(): int => $this->activeAccounts()),
            ],
            'modules' => $this->collect(fn(): ?array => $this->modules()),
            'module_usage' => $this->collect(fn(): ?array => $this->moduleUsagePayload()),
            'installation' => [
                'method' => $this->collect(fn(): ?string => $this->installationMethod()),
            ],
            'runtime' => [
                'php_version' => PHP_VERSION,
            ],
            'database' => [
                'engine' => $this->collect(fn(): ?string => $this->pdoAttribute(\PDO::ATTR_DRIVER_NAME)),
                'version' => $this->collect(fn(): ?string => $this->pdoAttribute(\PDO::ATTR_SERVER_VERSION)),
            ],
            'host' => [
                'os_family' => PHP_OS_FAMILY,
                // php_uname('m') only — the bare php_uname() carries the
                // machine's hostname, which this payload must never contain.
                'cpu_architecture' => $this->collect(fn(): ?string => self::nonEmpty(php_uname('m'))),
            ],
            'security' => [
                'https_enabled' => $this->collect(fn(): ?bool => $this->httpsEnabled()),
            ],
            'email' => [
                'mode' => $this->collect(fn(): ?string => $this->mailService?->getDeliveryMode()),
                'configured' => $this->collect(fn(): ?bool => $this->mailService?->isDeliveryConfigured()),
            ],
            'scheduler' => [
                'mode' => $this->collect(fn(): string => $this->schedulerMode()),
            ],
            'updates' => [
                'auto_update_enabled' => $this->collect(fn(): ?bool => $this->booleanSetting('auto_update_enabled')),
                'auto_update_level' => $this->collect(fn(): ?string => $this->settingValue('auto_update_level')),
            ],
            'lifecycle' => [
                'installed_at' =>
                    $this->collect(fn(): ?string => $this->settingValue(InstallationDateService::SETTING_KEY)),
                'last_upgraded_at' => $this->collect(fn(): ?string => $this->lastUpgradedAt()),
            ],
            'storage' => [
                'free_disk_bytes' => $this->collect(fn(): ?int => $this->freeDiskBytes()),
            ],
        ];
    }

    /**
     * The payload as the JSON actually transmitted (and shown in the
     * preview, and written to a support package). Deterministic: modules
     * are sorted by id, so two builds of an unchanged installation differ
     * only by `generated_at`.
     */
    public function buildJson(): string
    {
        // JSON_INVALID_UTF8_SUBSTITUTE completes rule 3 above: every
        // collector is independent, so a single field that came back as an
        // invalid byte sequence — a database server banner, a `php_uname`
        // on an exotic host — must cost that field its readability and
        // nothing more. Without it json_encode() answers `false` for the
        // whole document, and the cast turns that into an empty string:
        // an empty POST body the receiver rejects as malformed, and an
        // empty preview on the Support page, from one bad byte.
        return (string) json_encode(
            $this->build(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * Run one metric collector, resolving any failure to `null`.
     *
     * @template T
     * @param callable(): T $collector
     * @return T|null
     */
    private function collect(callable $collector): mixed
    {
        try {
            return $collector();
        } catch (\Throwable) {
            return null;
        }
    }

    private function settingValue(string $key): ?string
    {
        $value = $this->settingService->get($key);

        return is_string($value) ? self::nonEmpty($value) : null;
    }

    private function booleanSetting(string $key): ?bool
    {
        $value = $this->settingValue($key);

        return $value === null ? null : $value === '1';
    }

    /**
     * The scout year the public site is on — never the preview year and
     * never the staff year, both of which are staff-side working state.
     */
    /**
     * The year every page of the site is showing — which is NOT simply
     * the `current_scout_year_id` setting.
     *
     * That setting ships at `0` and stays there unless somebody pins a
     * year by hand; `Core\ScoutYear\ScoutYearResolver` then falls back to
     * the year the date says we are in, and that fallback is the normal
     * case rather than the exception. Reading the setting alone made
     * « membres actifs », « sections actives » and the scout-year label
     * null on every installation that never pinned it — most of them — so
     * the two counts a maintainer reads while answering a ticket showed
     * « Non renseigné » on a site that knew both perfectly well.
     *
     * The fallback is a plain lookup, deliberately: `ScoutYearService::
     * getCurrentYear()` CREATES the row when it is missing, and building a
     * report is a read. A year that does not exist yet stays null, which
     * is this class's rule 1 — unavailable is null, never zero.
     */
    private function publicScoutYearId(): ?int
    {
        $pinned = (int) ($this->settingValue(ScoutYearResolver::SETTING_PUBLIC_YEAR) ?? '0');
        if ($pinned > 0) {
            return $pinned;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM scout_years WHERE label = ?');
        $stmt->execute([ScoutYearService::labelForDate(new \DateTimeImmutable('now'))]);
        $id = (int) $stmt->fetchColumn();

        return $id > 0 ? $id : null;
    }

    private function scoutYearLabel(?int $scoutYearId): ?string
    {
        if ($scoutYearId === null) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT label FROM scout_years WHERE id = ?');
        $stmt->execute([$scoutYearId]);
        $label = $stmt->fetchColumn();

        return is_string($label) ? self::nonEmpty($label) : null;
    }

    private function activeMembers(?int $scoutYearId): ?int
    {
        if ($scoutYearId === null) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM member_years WHERE scout_year_id = ? AND is_active = 1');
        $stmt->execute([$scoutYearId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Sections with at least one active member this year, Staff d'U
     * excluded: it is a synthetic container for the unit's own staff
     * (Core\Member\UnitStaffSectionService), not a section of animés, and
     * counting it would inflate every unit by exactly one.
     */
    private function activeSections(?int $scoutYearId): ?int
    {
        if ($scoutYearId === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT mf.section_id)
             FROM member_functions mf
             JOIN member_years my ON my.id = mf.member_year_id
             JOIN sections s ON s.id = mf.section_id
             WHERE my.scout_year_id = ? AND my.is_active = 1 AND s.desk_code <> ?'
        );
        $stmt->execute([$scoutYearId, UnitStaffSectionService::DESK_CODE]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Accounts that logged in over the last THIRTY DAYS — not « this
     * month », and the difference is the whole reason the field is named
     * for its window.
     *
     * The receiver keeps only the LATEST report of each installation, so a
     * calendar-month count would say something different depending on which
     * day of the month the report happened to be built: a unit last heard
     * from on the 2nd would look deserted beside one last heard from on the
     * 28th, and the column comparing them would be measuring the calendar.
     * A sliding window is comparable across installations by construction.
     *
     * The unit's own screen answers « ce mois » instead, on purpose: a
     * chief reads their own site against the month they are living in, and
     * that figure never travels (ARCHITECTURE.md §8.93).
     *
     * `last_login_at` holds the LAST login only, so this counts accounts
     * whose most recent visit falls in the window — for a window ending
     * now, that is exactly « who came recently », which is the question.
     */
    private function activeAccounts(): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM user_accounts WHERE is_active = 1 AND last_login_at >= ?'
        );
        $stmt->execute([(new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s')]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Every module present on disk, with the version its manifest declares
     * and whether it is enabled. The version matters as much as the flag:
     * without it a module bug report says nothing about which schema is
     * actually running over there.
     *
     * @return array<int, array{id: string, enabled: bool, version: string}>|null
     */
    private function modules(): ?array
    {
        if ($this->moduleManager === null) {
            return null;
        }

        $modules = [];
        foreach ($this->moduleManager->discoverModules() as $module) {
            $modules[] = [
                'id' => $module->manifest->id,
                'enabled' => $module->enabled,
                'version' => $module->manifest->version,
            ];
        }

        usort($modules, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $modules;
    }

    /**
     * How much each module was actually OPENED, over the window the
     * capability declares — the one thing `modules` above cannot say.
     *
     * `modules` lists what is installed and switched on; this lists what
     * anybody used. A module enabled everywhere and opened nowhere is a
     * candidate for retirement, and it is the single observation no unit
     * can make on its own (ARCHITECTURE.md §8.51bis).
     *
     * **Null when the module is absent or disabled**, per rule 1: « cette
     * installation ne mesure pas » and « personne n'a ouvert ce module »
     * are different facts, and a receiver that confounded them would drop
     * a module people use.
     *
     * The aggregate only. The detail per page stays on the unit's own
     * screens: the project's question is which modules serve, not how
     * often one unit opened its calendar.
     *
     * @return ?array{window_months: int, modules: array<int, array{id: string, views: int}>}
     */
    private function moduleUsagePayload(): ?array
    {
        if ($this->moduleUsage === null) {
            return null;
        }

        $modules = [];
        foreach ($this->moduleUsage->aggregatedByModule() as $usage) {
            $modules[] = ['id' => $usage->moduleId, 'views' => $usage->views];
        }

        return [
            'window_months' => ModuleUsageInterface::WINDOW_MONTHS,
            'modules' => $modules,
        ];
    }

    /**
     * Which of `bootstrap.php`'s two supported layouts (§9.1) this
     * installation runs in.
     *
     * `storage/config/install-report.json` is the canonical answer when it
     * exists — it carries the layout `bootstrap.php` actually chose, rather
     * than a guess reconstructed afterwards. Otherwise fall back to the one
     * unambiguous filesystem tell: Layout B puts an `index.php` stub next to
     * `public/index.php` at the project root, Layout A never does. Anything
     * else is `null`, which is the honest answer for a checkout or a hand-
     * assembled tree.
     */
    private function installationMethod(): ?string
    {
        $reportPath = $this->projectRoot . '/storage/config/install-report.json';
        if (is_readable($reportPath)) {
            $report = json_decode((string) file_get_contents($reportPath), true);
            $layout = is_array($report) ? ($report['layout'] ?? null) : null;
            if ($layout === 'A' || $layout === 'B') {
                return 'layout_' . strtolower($layout);
            }
        }

        $hasPublicEntryPoint = is_file($this->projectRoot . '/public/index.php');
        if (!$hasPublicEntryPoint) {
            return null;
        }

        return is_file($this->projectRoot . '/index.php') ? 'layout_b' : 'layout_a';
    }

    private function pdoAttribute(int $attribute): ?string
    {
        $value = $this->pdo->getAttribute($attribute);

        return is_string($value) ? self::nonEmpty($value) : null;
    }

    private function httpsEnabled(): ?bool
    {
        $baseUrl = $this->settingValue('base_url');

        return $baseUrl === null ? null : str_starts_with(strtolower($baseUrl), 'https://');
    }

    /**
     * `cron_last_run` is stamped only by public/cron.php (§8.24), and by
     * nothing a web request does — so a recent value is proof a real
     * crontab is driving the scheduler, and its absence or staleness is
     * proof that nothing is.
     */
    private function schedulerMode(): string
    {
        $lastRun = (int) ($this->settingValue('cron_last_run') ?? '0');

        return ($lastRun > 0 && (time() - $lastRun) < self::REAL_CRON_MAX_AGE_SECONDS)
            ? 'real_cron'
            : 'poor_mans_cron';
    }

    private function lastUpgradedAt(): ?string
    {
        $stmt = $this->pdo->query(
            "SELECT completed_at FROM update_history WHERE status = 'completed' AND completed_at IS NOT NULL ORDER BY "
                . "completed_at DESC LIMIT 1"
        );
        $value = $stmt !== false ? $stmt->fetchColumn() : false;

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return DateInput::fromStorage($value)
            ?->setTimezone(new \DateTimeZone('UTC'))
            ->format(\DateTimeInterface::ATOM);
    }

    /**
     * Free space where uploads and generated files land.
     *
     * Imprecise by nature and knowingly so: on shared hosting this is the
     * whole underlying volume, not this account's quota, so it says more
     * about the host than about the installation. `false` (open_basedir,
     * a disabled function, a missing directory) resolves to `null`.
     */
    private function freeDiskBytes(): ?int
    {
        $bytes = @disk_free_space($this->projectRoot . '/storage');

        return is_float($bytes) && $bytes >= 0 ? (int) $bytes : null;
    }

    private static function nonEmpty(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
