<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The optional message of a PHPUnit assertion is code. It never reaches a
 * visitor: it surfaces in `vendor/bin/phpunit`'s output and in the JUnit
 * report CI publishes, and it is read by whoever is repairing a red test.
 * `AGENTS.md` § Language therefore puts it on the English side, the one
 * written exception being text the user sees.
 *
 * Issue #388 counted forty-three French messages across nineteen files on
 * `aec6aae`. This detector, which is stricter than the issue's, found
 * **eighty-two across thirty** — the suite having grown since. All of them
 * are translated in the change that adds this class, so the list below is
 * empty and the rule is fully enforced: the same shape as
 * Tests\Architecture\TwigCommentsAreEnglishTest, minus the allowlist it
 * still needs.
 *
 * **Why no allowlist at all.** The Twig sibling carries one because
 * translating forty-five templates in a single commit would cost the
 * `git blame` of prose written in the maintainer's own voice. An assertion
 * message is not prose: it is one line, it has no author to preserve, and
 * eighty-two of them fit in one reviewable diff. Nothing is left to ratchet
 * down, so the assertion is simply « none ».
 *
 * **The sentinel is the load-bearing half.** An empty list matched against
 * a scan that finds nothing anywhere looks exactly like a rule being
 * enforced. If the reader below stops recognising assertion calls — a
 * PHPUnit release renaming them, a refactor moving the messages into
 * constants — this class would go on passing while judging nothing. So it
 * also asserts that the scan still SEES the corpus it is meant to police.
 *
 * **What this does NOT promise.** It is a floor, not a proof. Only the
 * LAST argument of each call is read, because that is where PHPUnit puts
 * the message and because the earlier ones legitimately hold French
 * interface text under test. A message shorter than four words carries too
 * little signal for any detector and is left alone. Read the rule, do not
 * test against the detector.
 */
final class AssertionMessagesAreEnglishTest extends TestCase
{
    /**
     * Messages shorter than this are left alone: a three-word note carries
     * too little signal, and chasing it would turn a mechanical rule into
     * an argument.
     */
    private const MINIMUM_WORDS = 4;

    /**
     * What share of a message's words must be French function words.
     *
     * A DENSITY, not a count, for the reason the Twig sibling gives:
     * counting hits makes length the real test, and these messages are
     * short. 0.13 comes from the corpus rather than from taste. Measured
     * over all 2221 messages the scan below reads, the eighty-two French
     * ones scored 0.143 and up, and the highest-scoring English one —
     * « De, À, Sujet — the order every mail client uses. », which names
     * French header labels — reaches 0.111. The threshold sits in that
     * gap.
     */
    private const MINIMUM_FRENCH_DENSITY = 0.13;

    /**
     * The corpus is 2221 messages today. A scan that suddenly reads far
     * fewer is a scan that has stopped working, and this is the number
     * that says so out loud rather than letting an empty result read as
     * an enforced rule. Deliberately slack — assertions are added every
     * week and removed sometimes — because its job is catching a reader
     * that broke, not tracking a count.
     */
    private const MINIMUM_MESSAGES_SCANNED = 1800;

    /**
     * Unambiguous French function words only, as in the Twig sibling:
     * `a`, `on`, `en` and `plus` are ordinary English words too and have
     * no place here.
     */
    private const FRENCH_MARKERS = [
        'le', 'la', 'les', 'des', 'une', 'un', 'qui', 'que', 'pour', 'dans',
        'est', 'pas', 'ce', 'cette', 'sur', 'du', 'au', 'aux', 'et', 'ne',
        'de', 'elle', 'il', 'se', 'par', 'avec', 'ou', 'sa', 'ses', 'leur',
        'mais', 'donc', 'sont', 'être', 'été',
    ];

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Quoted interface text is stripped before scoring, both the French
     * guillemets and straight double quotes, and that is a rule rather
     * than a convenience: `AGENTS.md` says user-facing text IS French, so
     * an English message quoting the label it is about — « an emptied box
     * is "pas de limite" » — is correct on both counts. Scoring the
     * quotation would punish the message for obeying the other half of the
     * same rule.
     */
    private static function frenchDensity(string $message): float
    {
        $prose = preg_replace('/«.*?»|"[^"]*"/su', ' ', $message) ?? $message;
        $prose = mb_strtolower($prose, 'UTF-8');

        // An elided article — l', d', qu', n' — is French and has no
        // English equivalent, so it counts as a marker in its own right.
        $hits = preg_match_all("/\b(?:l|d|n|j|m|s|t|c|qu)['’]/u", $prose);

        $words = preg_split('/[^a-zà-ÿ]+/u', $prose, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < self::MINIMUM_WORDS) {
            return 0.0;
        }

        foreach ($words as $word) {
            if (in_array($word, self::FRENCH_MARKERS, true)) {
                $hits++;
            }
        }

        return $hits / count($words);
    }

    private static function looksFrench(string $message): bool
    {
        return self::frenchDensity($message) >= self::MINIMUM_FRENCH_DENSITY;
    }

    /**
     * @return string[] repo-relative paths, sorted
     */
    private static function testFiles(): array
    {
        $paths = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::repoRoot() . '/tests')
        );
        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $paths[] = substr($file->getPathname(), strlen(self::repoRoot()) + 1);
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * Assertion helpers the suite declares for itself, harvested rather
     * than listed.
     *
     * They matter because PHPUnit's convention — the message goes last —
     * is PHPUnit's, not theirs: `assertTextIsNotInsideALabel($html, 'Année
     * dans la branche')` ends on the French heading it looks for, which is
     * interface text and correct. Reading them would report four such
     * needles as violations, and a hand-written exclusion list would go
     * stale the day somebody adds a sixth helper.
     *
     * @return list<string>
     */
    private static function locallyDeclaredAssertions(): array
    {
        $names = [];
        foreach (self::testFiles() as $path) {
            $source = (string) file_get_contents(self::repoRoot() . '/' . $path);
            if (preg_match_all('/function\s+(assert\w+)\s*\(/', $source, $matches) > 0) {
                foreach ($matches[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * Every assertion message a file passes, read from the tokens rather
     * than from a regular expression: an assertion call spans lines, its
     * message is often a concatenation, and the arguments before it hold
     * parentheses, arrays and closures of their own.
     *
     * Only the last argument is returned, and only when it is made of
     * string literals alone — `assertSame('Année scoute', $label)` ends on
     * a variable and yields nothing, which is how the French EXPECTED
     * values stay out of this.
     *
     * @param list<string> $localHelpers
     *
     * @return list<array{int, string}> line number, message
     */
    private static function messagesIn(string $path, array $localHelpers): array
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $count = count($tokens);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            if (preg_match('/^(assert|fail$|markTestSkipped$|markTestIncomplete$)/', $token[1]) !== 1) {
                continue;
            }
            if (in_array($token[1], $localHelpers, true)) {
                continue;
            }

            $open = $i + 1;
            while (
                $open < $count
                && is_array($tokens[$open])
                && in_array($tokens[$open][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ) {
                $open++;
            }
            if ($open >= $count || $tokens[$open] !== '(') {
                continue;
            }

            $message = self::lastArgumentString(array_slice($tokens, $open));
            if ($message !== null) {
                $found[] = [$token[2], $message];
            }

            $i = $open;
        }

        return $found;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens starting at the call's opening parenthesis
     */
    private static function lastArgumentString(array $tokens): ?string
    {
        $depth = 0;
        /** @var list<list<array{int, string, int}|string>> $arguments */
        $arguments = [[]];

        foreach ($tokens as $token) {
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($token === ',' && $depth === 1) {
                $arguments[] = [];
                continue;
            }

            $arguments[count($arguments) - 1][] = $token;
        }

        $last = end($arguments);
        if ($last === false) {
            return null;
        }

        $message = '';
        foreach ($last as $token) {
            if (is_array($token)) {
                if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $message .= substr($token[1], 1, -1);
                    continue;
                }
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT], true)) {
                    continue;
                }

                return null;
            }
            if ($token === '.') {
                continue;
            }

            return null;
        }

        return $message === '' ? null : $message;
    }

    public function testNoAssertionMessageIsWrittenInFrench(): void
    {
        $helpers = self::locallyDeclaredAssertions();
        $found = [];
        $scanned = 0;

        foreach (self::testFiles() as $path) {
            foreach (self::messagesIn(self::repoRoot() . '/' . $path, $helpers) as [$line, $message]) {
                $scanned++;
                if (self::looksFrench($message)) {
                    $found[] = $path . ':' . $line . ' — ' . $message;
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_MESSAGES_SCANNED,
            $scanned,
            'The reader found only ' . $scanned . ' assertion messages, where the suite holds thousands. '
            . 'It has stopped seeing what it is meant to police, so the empty result below would mean '
            . 'nothing — repair the reader rather than lowering this number.'
        );

        $this->assertSame(
            [],
            $found,
            'A PHPUnit assertion message is code: it is read in a CI report by somebody repairing a '
            . 'red test, never by a visitor, and AGENTS.md § Language puts code in English. Write the '
            . 'message in English. If it has to name interface text, quote it — « … » or "…" — and the '
            . 'quotation is left out of the reckoning.'
        );
    }

    /**
     * The detector has to be shown working, or the assertion above could
     * pass for the wrong reason.
     */
    public function testTheDetectorTellsTheTwoLanguagesApart(): void
    {
        // Two real messages this change translated, at opposite ends of
        // the length range.
        $this->assertTrue(self::looksFrench('Le décalage a été écrit malgré le refus.'));
        $this->assertTrue(self::looksFrench("l'extraction n'a pas été mise en file"));

        // The lowest-scoring French message in the corpus (0.143) and the
        // highest-scoring English one (0.111) — the pair the threshold
        // sits between.
        $this->assertTrue(self::looksFrench('La référence seule a suffi à rattacher.'));
        $this->assertFalse(self::looksFrench('De, À, Sujet — the order every mail client uses.'));

        $this->assertFalse(self::looksFrench('the offset was written despite the refusal'));

        // The false positive worth guarding: an English message quoting the
        // French interface value it is about. Both quotation marks count.
        $this->assertFalse(self::looksFrench('an emptied box is "pas de limite"'));
        $this->assertFalse(self::looksFrench('the site answers « non » in place of the family'));

        // And the floor: three words carry too little signal to judge, so
        // they are left alone either way — French included.
        $this->assertFalse(self::looksFrench('Le décalage manque.'));
    }
}
