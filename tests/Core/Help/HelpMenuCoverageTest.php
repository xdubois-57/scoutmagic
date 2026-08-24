<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help;

use Core\Help\HelpRegistry;
use Core\Help\HelpTopic;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;

/**
 * The help chantier's closing invariant: no menu page reachable at a
 * given role is without help FOR THAT ROLE — written as a test, not as a
 * review pass, so a page added later without a topic fails CI instead of
 * shipping undocumented (AGENTS.md checklists ask for the topic in the
 * same change).
 *
 * "Menu page" means exactly what the nav renders: core pages registered
 * via $menuBuilder->addPage() in public/index.php (parsed from source —
 * same approach as HelpInvariantsTest's route extraction) and module
 * routes carrying a non-empty `label`. Dynamic per-member entries are not
 * pages of their own (their target, /members/{id}, is covered by its own
 * topics). Modules gated by `visible_when` are skipped: they never exist
 * on a deploying unit's installation (ARCHITECTURE.md §8.49), which is
 * the audience the help is written for.
 *
 * Coverage means: at least one shipped topic whose `paths` match the
 * page's URL and whose role_min does not exceed the page's own — so the
 * lowest role that can open the page also sees its help.
 */
final class HelpMenuCoverageTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<string, HelpTopic>
     */
    private static function shippedTopics(): array
    {
        $registry = new HelpRegistry(self::root() . '/docs/help');
        foreach (glob(self::root() . '/modules/*/module.json') ?: [] as $manifestPath) {
            $moduleDir = dirname($manifestPath);
            $data = json_decode((string) file_get_contents($manifestPath), true);
            $helpDirName = is_array($data) && isset($data['help']['dir']) && is_string($data['help']['dir'])
                ? $data['help']['dir']
                : 'help';
            if (is_dir($moduleDir . '/' . $helpDirName)) {
                $registry->registerModuleTopics(basename($moduleDir), $moduleDir . '/' . $helpDirName);
            }
        }

        return $registry->all();
    }

    /**
     * Every (url, role_min) pair the menus offer.
     *
     * @return array<int, array{url: string, role: Role, origin: string}>
     */
    private static function menuPages(): array
    {
        $pages = [];

        $indexSource = (string) file_get_contents(self::root() . '/public/index.php');
        $count = preg_match_all(
            "/\\\$menuBuilder->addPage\\(\\s*MenuBuilder::MENU_[A-Z_]+,\\s*'((?:[^'\\\\]|\\\\.)*)',\\s*'([^']*)',\\s*'([^']*)'/",
            $indexSource,
            $m,
            PREG_SET_ORDER
        );
        self::assertGreaterThan(0, $count, 'No core menu page found in public/index.php — the extraction regex broke.');
        foreach ($m as $match) {
            $pages[] = [
                'url' => $match[2],
                'role' => Role::from($match[3]),
                'origin' => "core menu page '{$match[1]}' (public/index.php)",
            ];
        }

        foreach (glob(self::root() . '/modules/*/module.json') ?: [] as $manifestPath) {
            $data = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($data)) {
                continue;
            }
            // A visible_when module never exists on a deploying unit's
            // installation — its pages are out of the help's audience.
            if (!empty($data['visible_when'])) {
                continue;
            }
            foreach ($data['routes'] ?? [] as $route) {
                if (!is_array($route) || ($route['label'] ?? '') === '') {
                    continue;
                }
                $method = strtoupper((string) ($route['method'] ?? 'GET'));
                if ($method !== 'GET') {
                    continue;
                }
                $pages[] = [
                    'url' => (string) $route['path'],
                    'role' => Role::from((string) $route['role_min']),
                    'origin' => "module '" . basename(dirname($manifestPath)) . "' menu page '{$route['label']}'",
                ];
            }
        }

        return $pages;
    }

    /**
     * Core\Help\HelpService::bestMatch()'s three forms, re-stated over a
     * ROUTE pattern rather than a request path: a `{param}` segment in the
     * url stands for the one concrete segment a real request would carry,
     * which is exactly what a topic's own `*` segment stands for.
     */
    private static function topicCovers(HelpTopic $topic, string $url): bool
    {
        foreach ($topic->paths as $rule) {
            if ($rule['match'] === 'exact' && $rule['path'] === $url) {
                return true;
            }
            if ($rule['match'] === 'child' && str_starts_with($url, $rule['path'])) {
                $remainder = trim(substr($url, strlen($rule['path'])), '/');
                if ($remainder !== '' && !str_contains($remainder, '/')) {
                    return true;
                }
            }
            // A segment pattern ('/mes-locations/*/reglages'). Without this
            // branch every page a module hangs off an id would read as
            // uncovered here even though the panel shows its topic.
            if ($rule['match'] === 'pattern' && self::segmentsMatch($rule['path'], $url)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Segment for segment, a `*` in the rule and a `{param}` in the route
     * both standing for exactly one. Same count on both sides, like
     * HelpService: a rule for a page must not claim the pages under it.
     */
    private static function segmentsMatch(string $rule, string $url): bool
    {
        $ruleSegments = explode('/', trim($rule, '/'));
        $urlSegments = explode('/', trim($url, '/'));

        if (count($ruleSegments) !== count($urlSegments)) {
            return false;
        }

        foreach ($ruleSegments as $index => $segment) {
            $urlSegment = $urlSegments[$index];
            $urlIsParam = str_starts_with($urlSegment, '{') && str_ends_with($urlSegment, '}');
            if ($segment === '*' || $urlIsParam) {
                continue;
            }
            if ($segment !== $urlSegment) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pages that render, carry a help button, and legitimately have no
     * topic behind it. Each one is here for a reason, not because writing
     * a topic was inconvenient — a page missing from this list and from
     * the corpus fails the test below.
     *
     * `/offline` is deliberately absent: it is a standalone HTML document
     * rather than a base.html.twig page, so it has no breadcrumb bar and
     * therefore no help button to answer for.
     *
     * @var array<string, string> route pattern => why
     */
    private const PAGES_WITHOUT_A_TOPIC = [
        '/aide/{id}' => 'a help topic itself — its own help would be the page you are already reading',
        '/members/emails/confirm/{id}' => 'a one-shot landing after clicking a confirmation link — it says what happened and ends',
        '/inscriptions/suivi/emails/confirm/{id}' => 'idem, for a registration tracking address',
        '/mass-mail/unsubscribe/{id}' => 'a one-shot unsubscribe landing reached from an email, for somebody who wants less from this site, not more',
    ];

    /**
     * Every GET route whose action renders a real PAGE — a template that
     * extends base.html.twig, so it has a breadcrumb bar and therefore a
     * help button. A fragment rendered for "load more" (it extends
     * nothing, the JS drops it into a page that already has its own help)
     * is not a page and is skipped by that same rule.
     *
     * Read from the sources rather than from a booted application, like
     * everything else in this file and in tests/Security/.
     *
     * @return array<int, array{url: string, origin: string}>
     */
    private static function renderedPages(): array
    {
        $pages = [];

        $indexSource = (string) file_get_contents(self::root() . '/public/index.php');
        $count = preg_match_all(
            "/->addRoute\(\s*'GET'\s*,\s*'([^']+)'\s*,\s*([\\\\\w]+)::class\s*,\s*'(\w+)'/",
            $indexSource,
            $coreMatches,
            PREG_SET_ORDER
        );
        self::assertGreaterThan(0, $count, 'No core GET route found in public/index.php — the extraction regex broke.');
        // public/index.php names most controllers by their SHORT class
        // name and imports them at the top; resolving those imports is what
        // makes this see the core pages at all, not just the handful written
        // out in full.
        $imports = [];
        if (preg_match_all('/^use\s+([\\\\\w]+);/m', $indexSource, $useMatches) > 0) {
            foreach ($useMatches[1] as $fqn) {
                $imports[substr((string) strrchr('\\' . $fqn, '\\'), 1)] = $fqn;
            }
        }
        foreach ($coreMatches as $match) {
            $class = ltrim($match[2], '\\');
            $class = $imports[$class] ?? $class;
            if (!str_starts_with($class, 'Core\\')) {
                continue;
            }
            $file = self::root() . '/core/' . str_replace('\\', '/', substr($class, 5)) . '.php';
            if (self::actionRendersAPage($file, $match[3], null)) {
                $pages[] = ['url' => $match[1], 'origin' => "core route {$class}::{$match[3]}()"];
            }
        }

        foreach (glob(self::root() . '/modules/*/module.json') ?: [] as $manifestPath) {
            $data = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($data) || !empty($data['visible_when'])) {
                continue;
            }
            $moduleDir = dirname($manifestPath);
            $moduleId = basename($moduleDir);
            foreach ($data['routes'] ?? [] as $route) {
                if (!is_array($route) || strtoupper((string) ($route['method'] ?? 'GET')) !== 'GET') {
                    continue;
                }
                $class = (string) ($route['controller'] ?? '');
                $action = (string) ($route['action'] ?? '');
                if ($class === '' || $action === '') {
                    continue;
                }
                // Modules\<Name>\Controller\X → modules/<id>/src/Controller/X.php,
                // the PSR-4 mapping ARCHITECTURE.md §13 states.
                $relative = preg_replace('/^Modules\\\\[^\\\\]+\\\\/', '', $class) ?? $class;
                $file = $moduleDir . '/src/' . str_replace('\\', '/', $relative) . '.php';
                if (self::actionRendersAPage($file, $action, $moduleId)) {
                    $pages[] = ['url' => (string) $route['path'], 'origin' => "module '{$moduleId}' route {$action}()"];
                }
            }
        }

        return $pages;
    }

    /**
     * Whether $action in $controllerFile renders at least one template
     * that extends base.html.twig — the marker of a full page, as opposed
     * to a fragment or a JSON payload.
     */
    private static function actionRendersAPage(string $controllerFile, string $action, ?string $moduleId): bool
    {
        if (!is_file($controllerFile)) {
            return false;
        }
        $source = (string) file_get_contents($controllerFile);
        if (preg_match('/function\s+' . preg_quote($action, '/') . '\s*\(.*?\n    \}/s', $source, $body) !== 1) {
            return false;
        }
        if (preg_match_all("/->render\(\s*'([^']+\.twig)'/", $body[0], $templates) < 1) {
            return false;
        }

        foreach ($templates[1] as $template) {
            // errors/404 is every controller's other exit, never the page
            // the route is about.
            if (str_starts_with($template, 'errors/')) {
                continue;
            }
            if (self::templateExtendsBase($template, $moduleId)) {
                return true;
            }
        }

        return false;
    }

    private static function templateExtendsBase(string $template, ?string $moduleId): bool
    {
        if (str_starts_with($template, '@')) {
            // '@groups/x.html.twig' → modules/groups/views/x.html.twig,
            // the namespace public/index.php registers per enabled module.
            [$namespace, $relative] = explode('/', substr($template, 1), 2) + [1 => ''];
            $file = self::root() . '/modules/' . $namespace . '/views/' . $relative;
        } else {
            $file = self::root() . '/core/View/templates/' . $template;
            if (!is_file($file) && $moduleId !== null) {
                $file = self::root() . '/modules/' . $moduleId . '/views/' . $template;
            }
        }

        if (!is_file($file)) {
            return false;
        }

        return preg_match('/\{%\s*extends\s*[\'"]base\.html\.twig[\'"]/', (string) file_get_contents($file)) === 1;
    }

    public function testEveryRenderedPageIsCoveredByAHelpTopic(): void
    {
        $topics = self::shippedTopics();
        $pages = self::renderedPages();
        self::assertNotEmpty($pages, 'No rendered page found — the source extraction broke.');

        $missing = [];
        foreach ($pages as $page) {
            if (isset(self::PAGES_WITHOUT_A_TOPIC[$page['url']])) {
                continue;
            }
            $covered = false;
            foreach ($topics as $topic) {
                if (self::topicCovers($topic, $page['url'])) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $missing[] = "{$page['url']} ({$page['origin']})";
            }
        }
        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "Pages that render a help button with nothing behind it:\n  "
            . implode("\n  ", $missing)
            . "\nEvery page an end user can open needs a topic — extend an existing topic's `paths` when its"
            . " body already covers the page, write a new one when it does not, or, if the page genuinely"
            . " warrants no help, add it to self::PAGES_WITHOUT_A_TOPIC with the reason."
        );
    }

    /**
     * The allow-list must not outlive the pages it excuses: an entry for a
     * route that no longer exists is a hole waiting for a future route to
     * reuse the same path.
     */
    public function testEveryAllowListedPageStillExists(): void
    {
        $urls = array_column(self::renderedPages(), 'url');

        foreach (array_keys(self::PAGES_WITHOUT_A_TOPIC) as $url) {
            $this->assertContains(
                $url,
                $urls,
                "self::PAGES_WITHOUT_A_TOPIC excuses '{$url}', which no longer renders a page — drop the entry."
            );
        }
    }

    public function testEveryMenuPageHasHelpVisibleAtItsOwnRoleFloor(): void
    {
        $topics = self::shippedTopics();
        $missing = [];

        foreach (self::menuPages() as $page) {
            $covered = false;
            foreach ($topics as $topic) {
                if ($topic->roleMin->level() <= $page['role']->level() && self::topicCovers($topic, $page['url'])) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $missing[] = "{$page['url']} ({$page['origin']}, role_min {$page['role']->value})";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Menu pages without a help topic visible at their own role floor:\n  "
            . implode("\n  ", $missing)
            . "\nEvery end-user page needs a topic, existing or new (AGENTS.md checklists, design.md §7.11)."
        );
    }
}
