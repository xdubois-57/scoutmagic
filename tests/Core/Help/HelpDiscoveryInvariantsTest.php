<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help;

use Core\Help\DiscoveryPriority;
use Core\Help\HelpRegistry;
use Core\Help\HelpTopic;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;

/**
 * The editorial half of « Le saviez-vous ? » (ARCHITECTURE.md §8.95),
 * over the SHIPPED corpus — same scan-the-real-sources shape as
 * Tests\Core\Help\HelpInvariantsTest, whose structural invariants this
 * file deliberately does not repeat.
 *
 * What is checked here is the running order itself, and it is checked
 * because nothing else can see it go wrong. A corpus where every topic
 * sits at the default renders a perfectly good dialog; it just opens on
 * whatever the seed happened to pick, which is the one thing the
 * `discovery:` key exists to prevent.
 */
final class HelpDiscoveryInvariantsTest extends TestCase
{
    /**
     * Every role floor must lead with something.
     *
     * Without this, a role whose topics are all at the default opens on a
     * card drawn at random from everything it may read — and an animé,
     * whose corpus is the smallest, is exactly who that happens to first.
     * Three is the floor a charter can be held to; the aim written in
     * design.md §7.11 is about twenty priority-1 topics in total, which
     * over the six floors this corpus uses is four apiece.
     */
    private const MIN_HIGH_PER_ROLE = 3;

    /**
     * And a ceiling, because the aim has an upper half too. A priority-1
     * topic is worth what it is only by being among the first cards
     * somebody sees; fifty of them are just the default under another
     * name. Deliberately loose — this refuses a drift, not a judgement
     * call about one topic.
     */
    private const MAX_HIGH_TOTAL = 30;

    /** @return string repo root */
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * The whole shipped corpus through the real registry and parser —
     * core topics plus every module's, enabled or not.
     *
     * @return HelpTopic[]
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
            $helpDir = $moduleDir . '/' . $helpDirName;
            if (is_dir($helpDir)) {
                $registry->registerModuleTopics(basename($moduleDir), $helpDir);
            }
        }

        self::assertSame([], $registry->loadErrors(), 'The shipped corpus must load without a single error.');

        return array_values($registry->all());
    }

    /**
     * @return string[] one absolute path per shipped topic file
     */
    private static function shippedFiles(): array
    {
        $files = glob(self::root() . '/docs/help/*.md') ?: [];
        foreach (glob(self::root() . '/modules/*/help/*.md') ?: [] as $moduleFile) {
            $files[] = $moduleFile;
        }
        sort($files);

        return $files;
    }

    /**
     * The parser already refuses an unknown value at load, so this reads
     * the raw lines instead: it is the only way to tell a topic that
     * declared `2` from one that declared nothing, and the charter has a
     * rule about exactly that.
     */
    public function testEveryDeclaredDiscoveryValueIsOneTheCharterAllows(): void
    {
        $offenders = [];
        foreach (self::shippedFiles() as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                if (str_starts_with($line, 'discovery:')) {
                    $value = trim(substr($line, strlen('discovery:')));
                    if (DiscoveryPriority::tryFrom($value) === null) {
                        $offenders[] = basename($file) . ' declares discovery: ' . $value;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Only 1, 2, 3 and off exist (Core\\Help\\DiscoveryPriority).");
    }

    /**
     * `2` is the default and the charter says not to write it: an absent
     * key already means it, and a hundred and twenty `discovery: 2` lines
     * would be noise in every file for no information at all.
     */
    public function testNoTopicSpellsOutTheDefault(): void
    {
        $offenders = [];
        foreach (self::shippedFiles() as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                if (trim($line) === 'discovery: 2') {
                    $offenders[] = basename($file);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "The default is written by leaving the key out (design.md §7.11):\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * The invariant this file exists for.
     */
    public function testEveryRoleFloorHasAtLeastThreeTopicsAtPriorityOne(): void
    {
        $highPerRole = [];
        $floors = [];
        foreach (self::shippedTopics() as $topic) {
            $floors[$topic->roleMin->value] = true;
            if ($topic->discovery === DiscoveryPriority::High) {
                $highPerRole[$topic->roleMin->value] = ($highPerRole[$topic->roleMin->value] ?? 0) + 1;
            }
        }

        $this->assertNotEmpty($floors, 'No topic at all — did the corpus move?');

        $short = [];
        foreach (array_keys($floors) as $floor) {
            $count = $highPerRole[$floor] ?? 0;
            if ($count < self::MIN_HIGH_PER_ROLE) {
                $short[] = sprintf('%s: %d (needs %d)', $floor, $count, self::MIN_HIGH_PER_ROLE);
            }
        }

        $this->assertSame(
            [],
            $short,
            "A role floor with no priority-1 topic opens its dialog on a card drawn at random, which is what\n"
            . "the editorial order exists to prevent. Tag one more `discovery: 1` at each of these floors:\n  "
            . implode("\n  ", $short)
        );
    }

    public function testPriorityOneStaysASmallSet(): void
    {
        $high = array_filter(
            self::shippedTopics(),
            static fn (HelpTopic $t): bool => $t->discovery === DiscoveryPriority::High
        );

        $this->assertLessThanOrEqual(
            self::MAX_HIGH_TOTAL,
            count($high),
            'A priority-1 topic is worth what it is by being among the first cards somebody sees. '
            . 'Past a few dozen, it is the default under another name — retag some to 2 or 3 '
            . 'rather than raising this ceiling.'
        );
    }

    /**
     * The four ids §8.95 names as the kind of subject that must never be
     * discovered — account hygiene, a legal obligation, a technical
     * operation — plus the four the roadmap of this feature listed
     * alongside them. Each is consulted at the moment it is needed; a
     * card spent on one is a card wasted.
     *
     * @return array<string, array{0: string}>
     */
    public static function topicsThatMustNeverBeOffered(): array
    {
        return array_map(
            static fn (string $id): array => [$id],
            array_combine(
                $ids = [
                    'cookies', 'donnees-personnelles', 'se-connecter', 'mon-compte',
                    'reinitialisation', 'installation-serveur', 'sauvegardes', 'mises-a-jour',
                ],
                $ids
            )
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('topicsThatMustNeverBeOffered')]
    public function testHygieneAndOperationsTopicsAreNeverOffered(string $id): void
    {
        $topics = [];
        foreach (self::shippedTopics() as $topic) {
            $topics[$topic->id] = $topic;
        }

        $this->assertArrayHasKey($id, $topics, "The corpus no longer ships '{$id}' — this list needs revisiting.");
        $this->assertSame(
            DiscoveryPriority::Off,
            $topics[$id]->discovery,
            "'{$id}' is consulted at the moment it is needed, never discovered (design.md §7.11)."
        );
    }

    /**
     * A sanity check on the roles themselves: every floor the corpus uses
     * is a real Role, so a typo cannot quietly create a seventh floor
     * that the count above then finds empty.
     */
    public function testEveryRoleFloorTheCorpusUsesIsARealRole(): void
    {
        foreach (self::shippedTopics() as $topic) {
            $this->assertInstanceOf(Role::class, $topic->roleMin);
        }
    }
}
