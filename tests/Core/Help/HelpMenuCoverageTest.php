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
        }

        return false;
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
