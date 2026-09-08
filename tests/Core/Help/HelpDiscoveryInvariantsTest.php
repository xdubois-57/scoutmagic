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
        foreach (self::declaredDiscoveryValues() as [$file, $value]) {
            if (DiscoveryPriority::tryFrom($value) === null) {
                $offenders[] = $file . ' declares discovery: ' . $value;
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
        foreach (self::declaredDiscoveryValues() as [$file, $value]) {
            if ($value === DiscoveryPriority::Normal->value) {
                $offenders[] = $file;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "The default is written by leaving the key out (design.md §7.11):\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * Every `discovery` value the corpus declares, read the way
     * Core\Help\HelpFrontMatterParser reads it: the line trimmed, split
     * on its FIRST colon, key and value trimmed in turn.
     *
     * One reader for both tests above, and that is the point rather than
     * tidiness. They were two near-copies, and each had drifted its own
     * way: one matched `discovery:` only at column zero, the other
     * compared the whole line against the single-space spelling, so
     * `discovery:2` and `discovery:  2` parsed as the default and escaped
     * the test forbidding it. A check that reads the file differently
     * from the parser is a check about a file nobody ships.
     *
     * @return array<int, array{0: string, 1: string}> file name, declared value
     */
    private static function declaredDiscoveryValues(): array
    {
        $declared = [];
        foreach (self::shippedFiles() as $file) {
            foreach (self::frontMatterValues($file, 'discovery') as $value) {
                $declared[] = [basename($file), $value];
            }
        }

        return $declared;
    }

    /**
     * Every value one file's FRONT MATTER declares for one key, read the
     * way Core\Help\HelpFrontMatterParser::parse() reads it: the block
     * between the opening `---` and the closing one, each line trimmed and
     * split on its first colon.
     *
     * **The bound at the closing `---` is the whole point**, and leaving it
     * out was the same mistake twice. The parser breaks there; a scan that
     * does not walks the Markdown body too, and a body is prose — it may
     * quote front matter, and docs/module-development.md's own example
     * already carries a column-zero `role_min: public`. Unbounded, the two
     * checks above fail in opposite directions: the discovery-value one
     * would refuse a corpus over a value written in a sentence, and the
     * role-floor one would keep a floor alive on the strength of a line in
     * a body long after the last real declaration was deleted — silently
     * defeating the one gap it exists to close.
     *
     * A file whose front matter never closes has none the parser would
     * use, so it yields nothing; `shippedTopics()` already refuses such a
     * corpus outright through an empty `loadErrors()`.
     *
     * @return string[]
     */
    private static function frontMatterValues(string $file, string $key): array
    {
        $lines = explode("\n", (string) file_get_contents($file));
        $values = [];

        // Line 0 is the opening delimiter — the parser requires it before
        // reading anything, so the block starts at line 1.
        foreach (array_slice($lines, 1) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '---') {
                return $values;
            }

            $colon = strpos($trimmed, ':');
            if ($colon !== false && trim(substr($trimmed, 0, $colon)) === $key) {
                $values[] = trim(substr($trimmed, $colon + 1));
            }
        }

        return [];
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
     * The set of role floors the corpus actually uses, held against the
     * six it is written for.
     *
     * The per-floor count above derives its floors FROM the corpus, which
     * makes it blind in one direction: a floor that disappears entirely —
     * the last `intendant` topic deleted, say — is simply no longer
     * checked, and the suite stays green over a role whose help has gone.
     * A floor appearing is caught there (it would carry no priority-1
     * topic); a floor vanishing is caught only here.
     *
     * Read off the raw `role_min:` lines rather than through the parser,
     * like the discovery-value check above: the parser refuses an unknown
     * role before a HelpTopic is ever built, so anything asked of the
     * parsed object is answered by PHP's own type system rather than by
     * the corpus.
     */
    public function testTheCorpusCoversExactlyTheSixRoleFloorsItIsWrittenFor(): void
    {
        $expected = ['admin', 'chief', 'identified', 'intendant', 'public', 'superadmin'];

        $found = [];
        foreach (self::shippedFiles() as $file) {
            foreach (self::frontMatterValues($file, 'role_min') as $value) {
                $found[$value] = true;
            }
        }

        $found = array_keys($found);
        sort($found);

        $this->assertSame(
            $expected,
            $found,
            "A role floor appearing or disappearing changes what the per-floor rule above has to cover:\n"
            . "a new one needs three priority-1 topics of its own, and one that vanished means a whole\n"
            . "role's help has gone. Either is a deliberate change; update this list with it."
        );
    }
}
