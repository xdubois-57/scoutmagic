<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\UsageStats\Service;

use Core\Http\Router;
use Core\Module\ModuleManager;
use Core\Security\Role;
use Modules\UsageStats\Audience;
use Modules\UsageStats\Month;
use Modules\UsageStats\Repository\AccountActivityRepository;
use Modules\UsageStats\Repository\PageViewRepository;

/**
 * Everything the three screens read, turned into view models here so the
 * templates hold no arithmetic.
 *
 * Two dependencies deserve their explanation:
 *
 * - **`Router`**, for one question only: what a page is CALLED. A route's
 *   breadcrumb label already says « Calendrier » where the pattern says
 *   `/calendar`, and `Router::pageAtPath()` is the narrow accessor that
 *   answers it. Inventing a second table of French names for routes would
 *   be a second thing to keep in step with the route table, and it would
 *   drift.
 * - **`ModuleManager`**, for which modules exist, which are enabled, what
 *   they are called and whether they are staff-only. That last one is
 *   read off the routes' own `role_min`: « Cotisations : 3 comptes » reads
 *   as a failure of adoption until you know three people is exactly the
 *   expected number.
 */
class UsageStatsService
{
    /** How far back « personne ne l'a jamais ouvert » looks. */
    public const UNUSED_WINDOW_MONTHS = 12;

    /** How many months the overview's curve draws. */
    public const CURVE_MONTHS = 12;

    public const TOP_PAGES = 5;

    /** What the pseudo-module holding the application's own routes is called. */
    private const CORE_MODULE_NAME = 'Cœur du site';

    /**
     * `ModuleManager::discoverModules()` scans the modules directory and
     * reads every manifest off disk; this class asks it about a module
     * once per rendered row, so the scan is done once per request and
     * kept. Read-only, like every use of it here.
     *
     * @var ?array<string, \Core\Module\ModuleInfo>
     */
    private ?array $modulesById = null;

    public function __construct(
        private PageViewRepository $pageViews,
        private AccountActivityRepository $accounts,
        private ModuleManager $moduleManager,
        private Router $router
    ) {
    }

    /**
     * The months the picker offers, newest first — every month with at
     * least one counter, plus the current one even when nothing has been
     * counted in it yet (so the page opens on « ce mois-ci » on the first
     * day of a month rather than on the last one).
     *
     * @return list<array{value: string, label: string}>
     */
    public function availableMonths(?\DateTimeImmutable $now = null): array
    {
        $months = $this->pageViews->months();
        $current = Month::current($now);
        if (!in_array($current, $months, true)) {
            array_unshift($months, $current);
        }
        rsort($months);

        return array_map(
            static fn(string $month): array => ['value' => $month, 'label' => Month::capitalisedLabel($month)],
            $months
        );
    }

    /**
     * The month a request asked for, or the current one — never a value
     * off the query string unchecked. Anything malformed falls back rather
     * than erroring: a mistyped URL should show this month, not a 500.
     */
    public function resolveMonth(?string $requested, ?\DateTimeImmutable $now = null): string
    {
        return $requested !== null && Month::isValid($requested) ? $requested : Month::current($now);
    }

    /**
     * @return array{
     *     month: string,
     *     month_label: string,
     *     is_current_month: bool,
     *     total_views: int,
     *     active_accounts: int,
     *     total_accounts: int,
     *     adoption_percent: ?int,
     *     curve: list<array{month: string, label: string, views: int, height_percent: int, is_last: bool}>,
     *     audiences: list<array{label: string, views: int, share_percent: int}>,
     *     top_pages: list<array{label: string, route: string, views: int}>
     * }
     */
    public function overview(string $month, ?\DateTimeImmutable $now = null): array
    {
        $curveMonths = Month::windowEndingAt($month, self::CURVE_MONTHS);
        $perMonth = $this->pageViews->viewsPerMonth($curveMonths[0], $month);
        $peak = max([0, ...array_values($perMonth)]);

        $curve = [];
        foreach ($curveMonths as $index => $curveMonth) {
            $views = $perMonth[$curveMonth] ?? 0;
            $curve[] = [
                'month' => $curveMonth,
                'label' => Month::shortLabel($curveMonth),
                'views' => $views,
                'height_percent' => $peak > 0 ? (int) round($views / $peak * 100) : 0,
                'is_last' => $index === count($curveMonths) - 1,
            ];
        }

        $perAudience = $this->pageViews->viewsPerAudience($month);
        $audiencePeak = max([0, ...array_values($perAudience)]);
        $audiences = [];
        foreach (Audience::cases() as $audience) {
            $views = $perAudience[$audience->value] ?? 0;
            $audiences[] = [
                'label' => $audience->label(),
                'views' => $views,
                'share_percent' => $audiencePeak > 0 ? (int) round($views / $audiencePeak * 100) : 0,
            ];
        }

        $topPages = [];
        foreach ($this->pageViews->pages($month, null, self::TOP_PAGES) as $page) {
            $topPages[] = [
                'label' => $this->pageLabel($page['route_pattern']),
                'route' => $page['route_pattern'],
                'views' => $page['views'],
            ];
        }

        // Adoption is answered for the current month and only for it — see
        // AccountActivityRepository. A past month shows zero accounts
        // rather than a number that would read as one thing and mean
        // another, and the template says why.
        $isCurrentMonth = $month === Month::current($now);
        $activeAccounts = $isCurrentMonth ? $this->accounts->activeAccountsIn($month) : 0;
        $totalAccounts = $this->accounts->activeAccountCount();

        return [
            'month' => $month,
            'month_label' => Month::capitalisedLabel($month),
            'is_current_month' => $isCurrentMonth,
            'total_views' => $this->pageViews->totalViews($month),
            'active_accounts' => $activeAccounts,
            'total_accounts' => $totalAccounts,
            'adoption_percent' => $isCurrentMonth && $totalAccounts > 0
                ? (int) round($activeAccounts / $totalAccounts * 100)
                : null,
            'curve' => $curve,
            'audiences' => $audiences,
            'top_pages' => $topPages,
        ];
    }

    /**
     * @return array{
     *     month: string,
     *     month_label: string,
     *     used: list<array{id: string, name: string, views: int, width_percent: int, staff_only: bool, trend_percent: ?int}>,
     *     unused: list<array{id: string, name: string}>,
     *     unused_window_months: int
     * }
     */
    public function modules(string $month): array
    {
        $thisMonth = $this->pageViews->viewsPerModule($month, $month);
        $previousMonth = $this->pageViews->viewsPerModule(Month::shift($month, -1), Month::shift($month, -1));

        $peak = max([0, ...array_values($thisMonth)]);
        $used = [];
        foreach ($thisMonth as $moduleId => $views) {
            if ($views <= 0) {
                continue;
            }
            $used[] = [
                'id' => $moduleId,
                'name' => $this->moduleName($moduleId),
                'views' => $views,
                'width_percent' => $peak > 0 ? (int) round($views / $peak * 100) : 0,
                'staff_only' => $this->isStaffOnly($moduleId),
                'trend_percent' => $this->trend($previousMonth[$moduleId] ?? 0, $views),
            ];
        }
        usort($used, static fn(array $a, array $b): int => $b['views'] <=> $a['views'] ?: strcmp($a['name'], $b['name']));

        return [
            'month' => $month,
            'month_label' => Month::capitalisedLabel($month),
            'used' => $used,
            'unused' => $this->unusedModules($month),
            'unused_window_months' => self::UNUSED_WINDOW_MONTHS,
        ];
    }

    /**
     * @return array{
     *     month: string,
     *     month_label: string,
     *     audience: ?string,
     *     filters: list<array{value: string, label: string, selected: bool}>,
     *     pages: list<array{label: string, route: string, module_name: string, views: int, width_percent: int}>
     * }
     */
    public function pages(string $month, ?string $audience): array
    {
        $audience = $audience !== null ? Audience::tryFrom($audience)?->value : null;

        $rows = $this->pageViews->pages($month, $audience);
        $peak = max([0, ...array_map(static fn(array $row): int => $row['views'], $rows)]);

        $pages = [];
        foreach ($rows as $row) {
            $pages[] = [
                'label' => $this->pageLabel($row['route_pattern']),
                'route' => $row['route_pattern'],
                'module_name' => $this->moduleName($row['module_id']),
                'views' => $row['views'],
                'width_percent' => $peak > 0 ? (int) round($row['views'] / $peak * 100) : 0,
            ];
        }

        $filters = [['value' => '', 'label' => 'Tous', 'selected' => $audience === null]];
        foreach (Audience::cases() as $case) {
            $filters[] = [
                'value' => $case->value,
                'label' => $case->shortLabel(),
                'selected' => $audience === $case->value,
            ];
        }

        return [
            'month' => $month,
            'month_label' => Month::capitalisedLabel($month),
            'audience' => $audience,
            'filters' => $filters,
            'pages' => $pages,
        ];
    }

    /**
     * The one constat this whole screen exists for: a module the unit
     * switched on and nobody has opened in a year.
     *
     * A module with no page of its own — one that only publishes API
     * endpoints, or that works entirely through another module's screens —
     * is left out rather than listed as unused: it has nothing to open,
     * so « personne ne l'a ouvert » says nothing about it, and telling a
     * chief to switch it off would be advice based on a measurement that
     * does not apply.
     *
     * @return list<array{id: string, name: string}>
     */
    private function unusedModules(string $month): array
    {
        $windowStart = Month::shift($month, -(self::UNUSED_WINDOW_MONTHS - 1));
        $seen = $this->pageViews->viewsPerModule($windowStart, $month);

        $unused = [];
        foreach ($this->modulesById() as $id => $module) {
            if (!$module->enabled || ($seen[$id] ?? 0) > 0 || !$this->hasAPageOfItsOwn($id)) {
                continue;
            }
            $unused[] = ['id' => $id, 'name' => $module->manifest->name];
        }
        usort($unused, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $unused;
    }

    /**
     * Rounded percentage change, or null when there is nothing to compare
     * against — a module that had no views last month has no trend, and
     * « +∞ % » is not a figure.
     */
    private function trend(int $previous, int $current): ?int
    {
        if ($previous <= 0) {
            return null;
        }

        return (int) round(($current - $previous) / $previous * 100);
    }

    private function moduleName(string $moduleId): string
    {
        if ($moduleId === PageViewRecorder::CORE_MODULE_ID) {
            return self::CORE_MODULE_NAME;
        }

        // A module whose files are gone still has counters. Its id is the
        // honest answer — inventing a name for it would be worse.
        return $this->modulesById()[$moduleId]->manifest->name ?? $moduleId;
    }

    /**
     * A module none of whose pages a family can reach. Read off the
     * routes' own `role_min` rather than declared by hand, so a module
     * that opens a page to members stops being labelled staff-only the
     * day it does.
     */
    private function isStaffOnly(string $moduleId): bool
    {
        $pageRoutes = $this->pageRoutesOf($moduleId);
        if ($pageRoutes === []) {
            return false;
        }

        foreach ($pageRoutes as $route) {
            if (!Role::fromString($route['role_min'])->hasAccess(Role::INTENDANT)) {
                return false;
            }
        }

        return true;
    }

    private function hasAPageOfItsOwn(string $moduleId): bool
    {
        return $this->pageRoutesOf($moduleId) !== [];
    }

    /**
     * The module's GET routes that are pages — the same definition
     * Service\PageViewPolicy counts on, so the two can never disagree
     * about what « ouvert » means.
     *
     * @return list<array{path: string, role_min: string}>
     */
    private function pageRoutesOf(string $moduleId): array
    {
        $module = $this->modulesById()[$moduleId] ?? null;
        if ($module === null) {
            return [];
        }

        $routes = [];
        foreach ($module->manifest->routes as $route) {
            if ($route['method'] !== 'GET' || str_starts_with($route['path'], '/api/')) {
                continue;
            }
            $routes[] = ['path' => $route['path'], 'role_min' => $route['role_min']];
        }

        return $routes;
    }

    /** @return array<string, \Core\Module\ModuleInfo> */
    private function modulesById(): array
    {
        if ($this->modulesById === null) {
            $this->modulesById = [];
            foreach ($this->moduleManager->discoverModules() as $module) {
                $this->modulesById[$module->manifest->id] = $module;
            }
        }

        return $this->modulesById;
    }

    /**
     * « Calendrier » rather than `/calendar`, taken from the route's own
     * breadcrumb label. A route that declares none — and in this
     * application that is an endpoint rather than a page — keeps its
     * pattern, which is still readable and still true.
     */
    private function pageLabel(string $routePattern): string
    {
        return $this->router->pageAtPath($routePattern)['label'] ?? $routePattern;
    }
}
