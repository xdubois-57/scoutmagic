<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Statistics;

use Core\Import\DeskMappingGapService;
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
    /**
     * **Unchanged when a field is added, and the Desk branches and the
     * `desk_unresolved` block (issue #356) are added fields.**
     *
     * The version travels from sender to receiver, and the supported list
     * lives on the RECEIVER. So a bump does not protect an old sender from
     * a new receiver — it breaks a NEW sender against a receiver that has
     * not upgraded yet: every unit that installs this release before
     * `scoutmagic.be` does would have its report refused outright, and the
     * very data this feature collects lost until the receiver catches up.
     *
     * An added field needs no bump because the receiver already tolerates
     * one: an unrecognised top-level field is kept verbatim in the stored
     * payload and warned about, never rejected. ARCHITECTURE.md §8.49
     * states the rule for the `desk_vocabulary` addition in as many words —
     * « the schema version is unchanged, since an added field is what that
     * list's tolerance exists for and a bump would make every receiver
     * still on the previous release reject the report outright ».
     *
     * What a bump is for is a change that would make an old receiver read
     * the document WRONGLY: a field whose meaning or type changed, a
     * removal something depends on. Nothing here does that.
     */
    public const STATISTICS_SCHEMA_VERSION = 1;

    /**
     * A `cron_last_run` stamp older than this means no real crontab is
     * driving the scheduler any more, whatever it once did.
     */
    private const REAL_CRON_MAX_AGE_SECONDS = 172800;

    /**
     * How many Desk vocabulary entries of each kind travel at the very
     * most. A unit has a couple of dozen functions and three cotisation
     * types; a hundred is room for every plausible one plus the years of
     * federation renamings that accumulate under them.
     *
     * This is the OUTER guard, not the operative bound: it is the `LIMIT`
     * that keeps a runaway table from being fetched at all, while
     * {@see self::MAX_VOCABULARY_LIST_BYTES} is what a list actually runs
     * into — even for labels as short as `TARIF_000`, once the budget is
     * counted in the encoding the receiver measures. Bytes are the bound
     * that can lose a report; a row count never was one.
     */
    private const MAX_VOCABULARY_ENTRIES = 100;

    /**
     * And how many BYTES of them, which is the bound that actually
     * matters — counting entries does not bound a payload.
     *
     * `functions` and `fee_categories` both declare `desk_code` and
     * `label` as `VARCHAR(100)` under `utf8mb4`, so one entry can be four
     * hundred-odd bytes rather than the twenty a real Desk label takes.
     * A hundred of each at that width serialises to **134 632 bytes** —
     * against `StatisticsIntakeService::MAX_BODY_BYTES`, which is 65 536
     * and is checked on the raw body before anything is parsed. The
     * receiver would answer 413 and the WHOLE report would be lost, which
     * is the exact outcome this cap exists to prevent: a unit whose Desk
     * vocabulary is unusually verbose would silently stop reporting
     * anything at all.
     *
     * ## Why there is a TOTAL as well as a per-list cap
     *
     * There was only the per-list one, which bounded a payload with two
     * lists and stopped bounding one with four: the arithmetic lived in
     * this comment (« the two of them under 16 KB ») rather than in the
     * code, so adding `branches` and `desk_unresolved` doubled the total
     * while every per-list test kept passing. Measured, four lists at
     * their own cap with labels around twenty characters: a body of
     * **63 033 bytes**, inside the limit by 2 503 — on an installation
     * with no modules wired, where `modules` and `module_usage` on the
     * twenty-five real ones cost about **5 500** more. The report would
     * have been refused whole, which is the failure this cap exists to
     * prevent and had stopped preventing.
     *
     * So this is the bound on the SUM, held by the code rather than by a
     * comment: a fifth list added tomorrow spends from the same purse
     * instead of raising the ceiling, and cannot reopen the hole without
     * a test going red. {@see self::MAX_VOCABULARY_LIST_BYTES} then keeps
     * one list from eating the purse the others need.
     *
     * 32 KB against the 8 the rest of the payload needs leaves half the
     * body spare.
     */
    private const MAX_VOCABULARY_BYTES = 32768;

    /**
     * And what any ONE of those lists may spend of it.
     *
     * The total above is what protects the report; this is what keeps the
     * lists honest with each other. Sharing a single purse in reading
     * order let `functions` — capped at a hundred entries, so up to 25 KB
     * of it — leave nothing for `branches`, whose `listed` came back
     * empty while `total` said a hundred and fifty. An empty list is the
     * worst of the outcomes available: `branches` is in this payload
     * because a rank of 99 is the costliest mapping failure and the only
     * one silent on the unit's side, and a receiver cannot read what it
     * was not sent.
     *
     * Deliberately no rolling-over of what a list does not spend. A unit
     * with three cotisation types would hand its unspent share to
     * whichever list happens to be built next, which makes a list's
     * contents depend on its position in {@see self::build()} — an order
     * nothing else about this payload depends on.
     *
     * Four lists times this is exactly the total, so today the total
     * never binds. That is the point: it binds the moment a fifth list
     * arrives, which is the moment it is needed.
     */
    private const MAX_VOCABULARY_LIST_BYTES = 8192;

    /**
     * How deep the lists sit in the transmitted document, in
     * `JSON_PRETTY_PRINT` levels — `desk_vocabulary` › `functions` ›
     * `listed` › the entry.
     *
     * The budget above is spent in the encoding the RECEIVER measures,
     * which is the pretty-printed one ({@see self::buildJson()}), and
     * indentation is most of what that costs: a hundred four-line entries
     * indented sixteen spaces are 6 400 bytes of spaces alone. Counting
     * the compact encoding instead — which is what this class did —
     * understates a full list by about half.
     *
     * `desk_unresolved` sits one level shallower and is charged at this
     * depth anyway: over-charging a list narrows the budget, which is the
     * safe direction to be wrong in.
     */
    private const VOCABULARY_NESTING_DEPTH = 4;

    /**
     * What is left of the two budgets — the whole document's, and the
     * list being built. Both reset by {@see self::build()}, so two builds
     * of one installation produce the same document.
     */
    private int $vocabularyBytesLeft = self::MAX_VOCABULARY_BYTES;
    private int $vocabularyListBytesLeft = self::MAX_VOCABULARY_LIST_BYTES;

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
        private ?ModuleUsageInterface $moduleUsage = null,
        /**
         * What this installation currently fails to recognise in its Desk
         * data (issue #356). Trailing and defaulted like every dependency
         * added to this constructor: null makes `desk_unresolved` a null
         * FIELD, which rule 1 of this class makes a different fact from an
         * empty list — « nobody measured » against « nothing to report ».
         */
        private ?DeskMappingGapService $mappingGaps = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        // The vocabulary budget belongs to ONE document: without this, a
        // second build on the same instance would find the purse already
        // spent and report an empty vocabulary — and the Support page
        // builds a preview beside the report it sends.
        $this->vocabularyBytesLeft = self::MAX_VOCABULARY_BYTES;

        $publicScoutYearId = $this->collect(fn(): ?int => $this->publicScoutYearId());

        return [
            'statistics_schema_version' => self::STATISTICS_SCHEMA_VERSION,
            'installation_id' => $this->collect(fn(): string => $this->identityService->getInstallationId()),
            // Where this installation came from, when it came from
            // somewhere: a portable restore mints a new identifier (D6) and
            // records the old one here. Without it the receiver sees one
            // installation fall silent and another appear, and has no way to
            // tell a move from an abandonment — which is the difference
            // between a unit that left and a unit that needs help. Null on
            // every installation that was not restored from an archive,
            // which is nearly all of them.
            'restored_from' => $this->collect(
                fn(): ?string => $this->settingValue(InstallationIdentityService::RESTORED_FROM_SETTING)
            ),
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
            'desk_vocabulary' => [
                'functions' => $this->collect(fn(): array => $this->deskFunctions()),
                'fee_categories' => $this->collect(fn(): array => $this->deskFeeCategories()),
                'branches' => $this->collect(fn(): array => $this->deskBranches()),
            ],
            // What this installation KNOWS it could not match (D4 of issue
            // #356). The vocabulary above lets a receiver guess; this says
            // it outright, because the sender is the one who can: it holds
            // `functions.confirmed`, it knows what `canonicalSortOrder()`
            // answered, and it can ask the cotisations module a question
            // core has no business answering itself.
            'desk_unresolved' => $this->collect(fn(): ?array => $this->deskUnresolved()),
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
     * The FONCTION labels this unit's Desk exports actually contain, and
     * the cotisation types they actually contain — the unit's Desk
     * vocabulary, which is the one thing about an installation that the
     * maintainer cannot guess and has to support.
     *
     * **Why it is here rather than only in a support package.** Both lists
     * grow when the federation invents something: a new function, a fourth
     * cotisation type. The site never refuses an unknown value — the
     * import creates it (`Core\Import\MappingResolver`) and the screens
     * that cannot classify it say so instead of guessing — so nothing
     * breaks, and precisely because nothing breaks nobody finds out. A
     * unit only reports what visibly goes wrong; a value quietly sitting
     * outside every heuristic is exactly what never gets reported. Seeing
     * it in the daily report is what turns "somebody will open a ticket in
     * eighteen months" into "this appeared this week, on four units".
     *
     * This is unit VOCABULARY, never a person: a label, and how many rows
     * carry it in total — never who. `functions.label` and
     * `fee_categories.label` are the federation's own words, copied
     * verbatim out of an export; no member, no section name, no count that
     * could single anybody out. Rule 2 of this class holds.
     *
     * Bounded twice — {@see self::MAX_VOCABULARY_ENTRIES} and {@see
     * self::MAX_VOCABULARY_BYTES} — with `total` saying what the list left
     * out. These two tables are the only part of this payload whose size a
     * unit's own data decides, so a verbose or botched vocabulary must
     * cost this field its completeness, never the whole report.
     *
     * @return array{total: int, listed: array<int, array{desk_code: string, label: string, role: string,
     *     confirmed: bool}>}
     */
    private function deskFunctions(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT desk_code, label, role, confirmed FROM functions ORDER BY desk_code LIMIT ?'
        );
        $stmt->bindValue(1, self::MAX_VOCABULARY_ENTRIES, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->openVocabularyList();
        $listed = [];
        foreach ($rows as $row) {
            $entry = [
                'desk_code' => (string) $row['desk_code'],
                'label' => (string) $row['label'],
                'role' => (string) $row['role'],
                // `confirmed` false is « nobody has seen this in Correspondances Desk
                // yet », which on this list is the interesting half: it is
                // where a function the federation just invented shows up.
                'confirmed' => (bool) $row['confirmed'],
            ];
            if (!$this->fitsInVocabularyBudget($entry)) {
                break;
            }
            $listed[] = $entry;
        }

        $count = $this->pdo->query('SELECT COUNT(*) FROM functions');

        return ['total' => $count !== false ? (int) $count->fetchColumn() : count($listed), 'listed' => $listed];
    }

    /**
     * What one vocabulary entry will cost in the transmitted document —
     * measured on its own encoding rather than estimated from string
     * lengths, since the budget exists to keep the encoded body under the
     * receiver's limit and the encoding is what the receiver measures.
     *
     * Which means the PRETTY-printed encoding, flags and all, plus the
     * indentation its lines carry once the entry is nested where it
     * really goes ({@see self::VOCABULARY_NESTING_DEPTH}) and the two
     * bytes of `,\n` that separate it from the next one. Measuring the
     * compact form understated a full list by roughly half.
     *
     * @param array<string, mixed> $entry
     */
    private static function entryBytes(array $entry): int
    {
        $encoded = (string) json_encode(
            $entry,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        $lines = substr_count($encoded, "\n") + 1;

        return strlen($encoded) + $lines * 4 * self::VOCABULARY_NESTING_DEPTH + 2;
    }

    /**
     * Open a vocabulary list: hand it its own share, never more than the
     * document has left.
     */
    private function openVocabularyList(): void
    {
        $this->vocabularyListBytesLeft = min(self::MAX_VOCABULARY_LIST_BYTES, $this->vocabularyBytesLeft);
    }

    /**
     * Charge one entry to both budgets, and say whether it fitted.
     *
     * Charged before the answer, so the entry that overruns is both
     * refused and paid for: without that, a single oversized entry would
     * be skipped and the next, smaller one let through — a list whose
     * contents depended on the order the rows came back in.
     *
     * @param array<string, mixed> $entry
     */
    private function fitsInVocabularyBudget(array $entry): bool
    {
        $cost = self::entryBytes($entry);
        $this->vocabularyBytesLeft -= $cost;
        $this->vocabularyListBytesLeft -= $cost;

        return $this->vocabularyBytesLeft >= 0 && $this->vocabularyListBytesLeft >= 0;
    }

    /**
     * The cotisation types the unit's Desk export carries. A unit
     * configures three (`N_COTISATION_NORMALE`, `C_COTISATION_COUPLE`,
     * `F_COTISATION_FAMILLE`) and `Modules\Fees\Service\
     * FeeCategoryClassifier` recognises those three; a fourth arriving one
     * day is imported like any other value and simply left out of the
     * household comparison until somebody maps it by hand. Which is fine
     * for the unit, and is exactly what the maintainer needs to see here.
     *
     * Deliberately NOT classified before being sent. Doing that would mean
     * naming `Modules\Fees` from core, which the `Api\` contract forbids
     * (ARCHITECTURE.md §7.5) — and it would send a verdict where the whole
     * value of the field is the raw label. The receiver reads the words.
     *
     * @return array{total: int, listed: array<int, array{desk_code: string, label: string}>}
     */
    private function deskFeeCategories(): array
    {
        $stmt = $this->pdo->prepare('SELECT desk_code, label FROM fee_categories ORDER BY desk_code LIMIT ?');
        $stmt->bindValue(1, self::MAX_VOCABULARY_ENTRIES, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->openVocabularyList();
        $listed = [];
        foreach ($rows as $row) {
            $entry = [
                'desk_code' => (string) $row['desk_code'],
                'label' => (string) $row['label'],
            ];
            if (!$this->fitsInVocabularyBudget($entry)) {
                break;
            }
            $listed[] = $entry;
        }

        $count = $this->pdo->query('SELECT COUNT(*) FROM fee_categories');

        return ['total' => $count !== false ? (int) $count->fetchColumn() : count($listed), 'listed' => $listed];
    }

    /**
     * The age branches this unit's Desk export carries, with the rank
     * {@see AgeBranchRepository::canonicalSortOrder()} gave each one.
     *
     * The rank is the interesting column, and it is why branches were
     * worth adding to a vocabulary block that had done without them: 99
     * means none of the seven needles matched, which costs the branch its
     * logo on every member page and its place in every picker — the
     * costliest mapping to get wrong, and the only one that fails with no
     * signal of any kind on the unit's side.
     *
     * Unclassified, like the fee categories and for the same reason: the
     * receiver reads the words. The rank is not a verdict, it is what this
     * installation's own code answered.
     *
     * @return array{total: int, listed: array<int, array{desk_code: string, label: string, sort_order: int}>}
     */
    private function deskBranches(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT desk_code, label, sort_order FROM age_branches ORDER BY desk_code LIMIT ?'
        );
        $stmt->bindValue(1, self::MAX_VOCABULARY_ENTRIES, \PDO::PARAM_INT);
        $stmt->execute();

        $this->openVocabularyList();
        $listed = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $entry = [
                'desk_code' => (string) $row['desk_code'],
                'label' => (string) $row['label'],
                'sort_order' => (int) $row['sort_order'],
            ];
            if (!$this->fitsInVocabularyBudget($entry)) {
                break;
            }
            $listed[] = $entry;
        }

        $count = $this->pdo->query('SELECT COUNT(*) FROM age_branches');

        return ['total' => $count !== false ? (int) $count->fetchColumn() : count($listed), 'listed' => $listed];
    }

    /**
     * The values this installation knows it did not recognise.
     *
     * A KIND and a RAW VALUE, and deliberately nothing else. Not how many
     * members carry it: the receiver's question is « on how many
     * installations does this appear », which it answers by counting
     * reports, and a headcount per unit would be data nobody needs for a
     * table of words to complete (D9).
     *
     * Bounded like the vocabulary lists, and for the same reason — these
     * are the only parts of this payload whose size a unit's own data
     * decides. `total` declares what was left out, so a truncated list
     * never reads as a complete one.
     *
     * @return array{total: int, listed: array<int, array{kind: string, value: string}>}|null
     */
    private function deskUnresolved(): ?array
    {
        if ($this->mappingGaps === null) {
            return null;
        }

        $gaps = $this->mappingGaps->gaps();

        $this->openVocabularyList();
        $listed = [];
        foreach ($gaps as $gap) {
            $entry = ['kind' => $gap->kind->value, 'value' => $gap->rawValue];
            if (count($listed) >= self::MAX_VOCABULARY_ENTRIES || !$this->fitsInVocabularyBudget($entry)) {
                break;
            }
            $listed[] = $entry;
        }

        return ['total' => count($gaps), 'listed' => $listed];
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
