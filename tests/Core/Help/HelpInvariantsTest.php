<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help;

use Core\Help\HelpFrontMatterParser;
use Core\Help\HelpRegistry;
use Core\Help\HelpTopic;
use PHPUnit\Framework\TestCase;

/**
 * Mechanical guards over the SHIPPED help corpus — docs/help/ plus every
 * module's help directory, enabled or not (same scan-the-real-sources
 * philosophy as tests/Core/View/UxConventionsTest.php).
 *
 * The two structural invariants come straight from the feature's design
 * (ARCHITECTURE.md §8.64):
 *
 * - Every declared `paths` entry corresponds to a route actually
 *   registered in the Router (core + all modules). Without this, one typo
 *   makes a topic silently invisible on the page it documents — the
 *   close-to-undebuggable failure mode this whole test file exists for.
 * - Every topic id is unique across core + modules (loading via
 *   HelpRegistry also re-validates every file through the real parser).
 *
 * The rest pins the editorial charter (design.md §7.11) where a rule is
 * mechanically checkable: topic length, at most one warning callout, no
 * external link except the federation's site.
 */
final class HelpInvariantsTest extends TestCase
{
    private const FEDERATION_HOST_SUFFIX = 'lesscouts.be';
    private const MAX_BODY_WORDS = 500; // charter says ~400 — hard stop with headroom

    /** @return string repo root */
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * The whole shipped corpus through the real registry/parser.
     */
    private static function shippedRegistry(): HelpRegistry
    {
        $registry = new HelpRegistry(self::root() . '/docs/help');

        foreach (glob(self::root() . '/modules/*/module.json') ?: [] as $manifestPath) {
            $moduleDir = dirname($manifestPath);
            $moduleId = basename($moduleDir);
            $data = json_decode((string) file_get_contents($manifestPath), true);
            $helpDirName = is_array($data) && isset($data['help']['dir']) && is_string($data['help']['dir'])
                ? $data['help']['dir']
                : 'help';
            $helpDir = $moduleDir . '/' . $helpDirName;
            if (is_dir($helpDir)) {
                $registry->registerModuleTopics($moduleId, $helpDir);
            }
        }

        return $registry;
    }

    /**
     * @return array<string, HelpTopic>
     */
    private static function shippedTopics(): array
    {
        return self::shippedRegistry()->all();
    }

    /**
     * Every GET route pattern the application registers: core ones read
     * from public/index.php (the Router keeps its table private, so this
     * reads the source the way tests/Security/FileAccessAuditTest.php
     * already does), module ones from every module.json.
     *
     * @return string[]
     */
    private static function registeredGetRoutePatterns(): array
    {
        $patterns = [];

        $indexSource = (string) file_get_contents(self::root() . '/public/index.php');
        if (preg_match_all("/->addRoute\\(\\s*'GET'\\s*,\\s*'([^']+)'/", $indexSource, $m) > 0) {
            $patterns = $m[1];
        }
        self::assertNotEmpty($patterns, 'No core GET route found in public/index.php — the extraction regex broke.');

        foreach (glob(self::root() . '/modules/*/module.json') ?: [] as $manifestPath) {
            $data = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($data) || !isset($data['routes']) || !is_array($data['routes'])) {
                continue;
            }
            foreach ($data['routes'] as $route) {
                if (!is_array($route) || !isset($route['path']) || !is_string($route['path'])) {
                    continue;
                }
                $method = strtoupper((string) ($route['method'] ?? 'GET'));
                if ($method === 'GET') {
                    $patterns[] = $route['path'];
                }
            }
        }

        return $patterns;
    }

    /**
     * Core\Http\Router::matchPath()'s semantics, re-stated: a {param}
     * segment matches any one concrete segment.
     */
    private static function patternMatches(string $pattern, string $path): bool
    {
        $regex = preg_replace('/\{[a-zA-Z_]+\}/', '[^/]+', $pattern);

        return preg_match('#^' . $regex . '$#', $path) === 1;
    }

    public function testTheShippedCorpusLoadsAndEveryIdIsUnique(): void
    {
        // The registry no longer throws on a bad topic — it drops that one
        // and keeps the site up (HelpRegistry::load()). So the invariant is
        // now stated directly, and says which file broke rather than
        // failing on the first one: every shipped topic must load, and no
        // two may claim the same id.
        $registry = self::shippedRegistry();

        $this->assertSame(
            [],
            $registry->loadErrors(),
            'Every shipped help topic must parse and carry a unique id.'
        );
        $this->assertNotEmpty($registry->all(), 'docs/help/ must ship at least the /aide topic.');
    }

    public function testEveryDeclaredPathCorrespondsToARegisteredGetRoute(): void
    {
        $patterns = self::registeredGetRoutePatterns();

        foreach (self::shippedTopics() as $topic) {
            foreach ($topic->paths as $rule) {
                // An exact rule must match a registered pattern as a real
                // request path would; a child rule ('/x/*', stored as
                // '/x/') must have a route serving its parent plus exactly
                // one segment — probe with a synthetic child.
                $candidate = match ($rule['match']) {
                    'child' => $rule['path'] . 'x-probe',
                    // Each `*` stands for one segment; a probe value in
                    // each is what a real request path looks like.
                    'pattern' => str_replace('/*', '/x-probe', $rule['path']),
                    default => $rule['path'],
                };

                $matched = false;
                foreach ($patterns as $pattern) {
                    if (self::patternMatches($pattern, $candidate)) {
                        $matched = true;
                        break;
                    }
                }

                $declared = $rule['match'] === 'child' ? $rule['path'] . '*' : $rule['path'];
                $this->assertTrue(
                    $matched,
                    "Help topic '{$topic->id}' ({$topic->filePath}) declares path '{$declared}', which no registered GET route serves — a typo here makes the topic silently invisible."
                );
            }
        }
    }

    public function testEveryTopicRespectsTheMechanicallyCheckableCharterRules(): void
    {
        foreach (self::shippedTopics() as $topic) {
            $body = HelpFrontMatterParser::extractBody($topic->filePath);

            // ~400 words per topic, two topics beyond that (design.md
            // §7.11) — hard stop with headroom so a normal edit never
            // trips it but a runaway topic does.
            $wordCount = str_word_count(strip_tags($body), 0, 'àâäéèêëîïôöùûüçœæÀÂÄÉÈÊËÎÏÔÖÙÛÜÇŒÆ-\'’');
            $this->assertLessThanOrEqual(
                self::MAX_BODY_WORDS,
                $wordCount,
                "Help topic '{$topic->id}' is {$wordCount} words long — past ~400 words it should be two topics (design.md §7.11)."
            );

            // At most one warning callout per topic — counted as blocks
            // of consecutive '>' lines.
            $lines = preg_split('/\R/', $body) ?: [];
            $blocks = 0;
            $inQuote = false;
            foreach ($lines as $line) {
                $isQuote = str_starts_with(ltrim($line), '>');
                if ($isQuote && !$inQuote) {
                    $blocks++;
                }
                $inQuote = $isQuote;
            }
            $this->assertLessThanOrEqual(
                1,
                $blocks,
                "Help topic '{$topic->id}' has {$blocks} warning callouts — the charter allows at most one (design.md §7.11)."
            );

            // Never a level-1 heading: the topic's <h1> is its title
            // (page_header / the panel header), and the body renders with
            // heading_base_level 1 (HelpController::RENDER_OPTIONS), so a
            // lone '#' would put a second <h1> on the page — design.md
            // §7.6's one-<h1> rule. Sections start at '##'.
            $this->assertDoesNotMatchRegularExpression(
                '/^#\s/m',
                $body,
                "Help topic '{$topic->id}' uses a level-1 '#' heading — start sections at '##' (the title already is the <h1>)."
            );

            // No external link except the federation's own site.
            if (preg_match_all('#https?://([^/\s)\]]+)#i', $body, $m) > 0) {
                foreach ($m[1] as $host) {
                    $this->assertTrue(
                        $host === self::FEDERATION_HOST_SUFFIX || str_ends_with($host, '.' . self::FEDERATION_HOST_SUFFIX),
                        "Help topic '{$topic->id}' links to external host '{$host}' — only the federation's site is allowed (design.md §7.11)."
                    );
                }
            }
        }
    }
}
