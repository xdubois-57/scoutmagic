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
 * **two hundred and four across fifty** — the suite having grown since,
 * and four call shapes having hidden most of them until the reader was
 * taught to read them. All are translated in the change that adds this
 * class, so the list below is empty and the rule is fully enforced: the
 * same shape as
 * Tests\Architecture\TwigCommentsAreEnglishTest, minus the allowlist it
 * still needs.
 *
 * **Why no allowlist at all.** The Twig sibling carries one because
 * translating forty-five templates in a single commit would cost the
 * `git blame` of prose written in the maintainer's own voice. An assertion
 * message is not prose: it is one line, it has no author to preserve, and
 * two hundred and four of them fit in one reviewable diff. Nothing is
 * left to ratchet down, so the assertion is simply « none ».
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
     * over all 2791 messages the scan below reads, the two hundred and
     * four French ones scored 0.143 and up, and the highest-scoring
     * English one —
     * « De, À, Sujet — the order every mail client uses. », which names
     * French header labels — reaches 0.111. The threshold sits in that
     * gap.
     *
     * That gap is kept by the convention this class's own failure message
     * states rather than by luck: an English message naming French
     * interface text — a role, a section, a page — quotes it, and the
     * quotation is left out of the reckoning. Seven messages naming
     * « Chef d'Unité » or « Espace chefs d'U » reached 0.125 without
     * their guillemets.
     */
    private const MINIMUM_FRENCH_DENSITY = 0.13;

    /**
     * The corpus is 2791 messages today. A scan that suddenly reads far
     * fewer is a scan that has stopped working, and this is the number
     * that says so out loud rather than letting an empty result read as
     * an enforced rule. Deliberately slack — assertions are added every
     * week and removed sometimes — because its job is catching a reader
     * that broke, not tracking a count.
     *
     * **What it cannot catch**, and the reason the reader has tests of its
     * own below: a reader that NARROWS rather than breaks. Two call
     * shapes were silently dropped in the making of this class — a
     * trailing comma before `)`, and any interpolated message — and
     * between them they hid 570 readings and ninety-five French messages
     * while this number sat comfortably above its floor. A number cannot
     * see a shape; only a test naming the shape can.
     */
    private const MINIMUM_MESSAGES_SCANNED = 2000;

    /**
     * The tokens an interpolation is made of, inside a double-quoted or
     * heredoc message.
     *
     * Refusing the whole call on sight of one of them — which reading only
     * T_CONSTANT_ENCAPSED_STRING amounts to — dropped every interpolated
     * message in the suite: not scored, and not counted either, so the
     * sentinel could not see the hole. Several were French, in files this
     * very change had already been through.
     *
     * @var list<int>
     */
    private const INTERPOLATION_TOKENS = [
        T_VARIABLE,
        T_CURLY_OPEN,
        T_DOLLAR_OPEN_CURLY_BRACES,
        T_STRING_VARNAME,
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_STRING,
        T_NUM_STRING,
        T_LNUMBER,
    ];

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
            // `{$x}` inside a double-quoted message opens with a TOKEN
            // (T_CURLY_OPEN) and closes with a bare `}`. Counting only the
            // close ended the argument on the first interpolation: the
            // reader stopped at « Le compte de démonstration « » and never
            // saw the prose after it. Matching the open restores the pair.
            if (
                is_array($token)
                && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)
            ) {
                $depth++;
                $arguments[count($arguments) - 1][] = $token;
                continue;
            }
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

        // A trailing comma before the closing bracket — the house style on
        // every multi-line assertion here — opens one more bucket, and the
        // whitespace ahead of `)` lands in it. Reading that bucket as the
        // last argument returned null for the whole call, so the message
        // was never scored and the call never counted: the detector was
        // blind to the very shape it meets most often.
        while ($arguments !== [] && self::isBlank(end($arguments))) {
            array_pop($arguments);
        }

        $last = end($arguments);
        if ($last === false) {
            return null;
        }

        $message = '';
        $literals = 0;
        // Whether the walk is currently INSIDE an interpolated string.
        // The distinction is the whole correctness of this: a `$user`
        // between two `"` is a hole in a message, and the same token as
        // the whole argument is not a message at all. Accepting it in both
        // places took the corpus from 2 358 readings to 15 039, every
        // `assertSame($a, $b)` in the suite suddenly counting as a
        // message made of one space.
        $inString = false;

        foreach ($last as $token) {
            if (is_array($token)) {
                if ($token[0] === T_CONSTANT_ENCAPSED_STRING && !$inString) {
                    $message .= self::literalBody($token[1]);
                    $literals++;
                    continue;
                }
                // The prose BETWEEN the interpolations of a double-quoted
                // or heredoc message. Reading only the whole-literal token
                // above dropped every such call outright — see
                // INTERPOLATION_TOKENS for what that cost.
                if ($token[0] === T_ENCAPSED_AND_WHITESPACE && $inString) {
                    $message .= $token[1];
                    $literals++;
                    continue;
                }
                if ($token[0] === T_START_HEREDOC) {
                    $inString = true;
                    continue;
                }
                if ($token[0] === T_END_HEREDOC) {
                    $inString = false;
                    continue;
                }
                // An array key or a method argument INSIDE an
                // interpolation — `{$row['file']}` — is a whole literal
                // token arriving while the walk is inside a string. It is
                // part of the hole, not part of the message, and reading
                // it as a message literal dropped the call outright.
                if ($inString && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $message .= ' ';
                    continue;
                }
                if ($inString && in_array($token[0], self::INTERPOLATION_TOKENS, true)) {
                    // A space, not nothing: `{$a}` sitting between two
                    // words must not glue them into one, which would
                    // change the word count the density divides by.
                    $message .= ' ';
                    continue;
                }
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT], true)) {
                    continue;
                }

                return null;
            }

            // The `"` of an interpolated string is a bare token of its
            // own, unlike the quotes of a whole literal, which arrive
            // inside T_CONSTANT_ENCAPSED_STRING.
            if ($token === '"') {
                $inString = !$inString;
                continue;
            }
            if ($token === '.' && !$inString) {
                continue;
            }
            if ($inString && ($token === '}' || $token === '[' || $token === ']')) {
                continue;
            }

            return null;
        }

        return $literals === 0 ? null : $message;
    }

    /**
     * A string literal's text, with the escapes a SINGLE-quoted literal
     * actually has undone.
     *
     * PHP single-quoted strings escape exactly two things, `\'` and `\\`,
     * and `token_get_all()` hands back the source spelling — so
     * « aucun échec d\'envoi journalisé » reaches the detector with a
     * backslash wedged between the `d` and the apostrophe. The elision
     * pattern wants them adjacent, so that hit is lost and a French
     * message can score 0.0. That is a real one, measured:
     * `SetupControllerTest` held exactly it.
     *
     * `stripcslashes()` would be the wrong tool: it also decodes `\n`,
     * `\t` and `\x41`, none of which a single-quoted literal means, so it
     * would rewrite text the author wrote literally. A double-quoted body
     * is left exactly as written, for the same reason — its `\n` is a
     * newline the scoring does not care about, and touching it would only
     * invent characters.
     */
    private static function literalBody(string $literal): string
    {
        $body = substr($literal, 1, -1);

        return $literal[0] === "'" ? strtr($body, ["\\'" => "'", '\\\\' => '\\']) : $body;
    }

    /**
     * @param list<array{int, string, int}|string> $argument
     */
    private static function isBlank(array $argument): bool
    {
        foreach ($argument as $token) {
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return false;
            }
        }

        return true;
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
     * The call shape the reader was blind to, shown being read.
     *
     * A trailing comma before the closing bracket — the house style on
     * every multi-line assertion in this suite — opened one more argument
     * bucket holding nothing but the whitespace ahead of `)`. Reading that
     * bucket as the last argument returned null for the whole call, so the
     * message was never scored AND the call was never counted: the
     * detector reported a clean corpus it had not finished reading, which
     * is the one outcome this class exists to prevent. It cost
     * twenty-seven French messages, found only once this was fixed.
     */
    public function testACallWrittenWithATrailingCommaIsStillRead(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'assertion_messages_') . '.php';
        file_put_contents($file, <<<'PHP'
            <?php
            $this->assertSame(
                [],
                $rows,
                'une ligne `files` orpheline est restée',
            );
            PHP);

        try {
            $messages = self::messagesIn($file, []);

            $this->assertCount(1, $messages);
            $this->assertSame('une ligne `files` orpheline est restée', $messages[0][1]);
            $this->assertTrue(self::looksFrench($messages[0][1]));
        } finally {
            unlink($file);
        }
    }

    /**
     * A message assembled around an interpolation, read.
     *
     * `lastArgumentString()` accepted only whole string literals, so
     * `"Le compte {$handle} n'a pas de mot de passe."` — which tokenises
     * as delimiter, prose, variable, prose, delimiter — was refused
     * outright: not scored, and not counted either, so the sentinel above
     * saw nothing missing. Twenty-four French messages lived in that
     * blind spot, several in files this very change had already been
     * through.
     *
     * The interpolation itself becomes a space rather than nothing, so
     * the words either side of it stay two words: the density divides by
     * that count, and gluing them would move the score.
     */
    public function testAMessageBuiltAroundAnInterpolationIsRead(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'assertion_messages_') . '.php';
        file_put_contents($file, <<<'PHP'
            <?php
            $this->assertNotNull($account, "Le compte de démonstration « {$handle} » n'a pas de membre.");
            PHP);

        try {
            $messages = self::messagesIn($file, []);

            $this->assertCount(1, $messages);
            $this->assertStringContainsString('Le compte de démonstration', $messages[0][1]);
            $this->assertStringContainsString("n'a pas de membre.", $messages[0][1]);
            $this->assertTrue(self::looksFrench($messages[0][1]));
        } finally {
            unlink($file);
        }
    }

    /**
     * The fourth shape, and the one that hid inside the third's fix: a
     * literal INSIDE an interpolation.
     *
     * `{$row['file']}` puts a whole `T_CONSTANT_ENCAPSED_STRING` in the
     * middle of a string, where it is part of the hole and not part of
     * the message. Reading it as a message literal matched neither branch
     * and dropped the call — ten more French messages, in files this same
     * change had already translated twice over.
     */
    public function testALiteralInsideAnInterpolationIsPartOfTheHole(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'assertion_messages_') . '.php';
        file_put_contents($file, <<<'PHP'
            <?php
            $this->assertFileExists($path, "Le manifeste référence {$row['file']}, qui manque au lot.");
            PHP);

        try {
            $messages = self::messagesIn($file, []);

            $this->assertCount(1, $messages);
            $this->assertStringContainsString('Le manifeste référence', $messages[0][1]);
            $this->assertStringContainsString('qui manque au lot.', $messages[0][1]);
            $this->assertStringNotContainsString('file', $messages[0][1], 'the array key is a hole, not prose');
            $this->assertTrue(self::looksFrench($messages[0][1]));
        } finally {
            unlink($file);
        }
    }

    /**
     * An escaped apostrophe, undone before the elision is counted.
     *
     * A single-quoted literal reaches the reader as its SOURCE spelling,
     * so « aucun échec d\'envoi journalisé » carries a backslash between
     * the `d` and the apostrophe — and the elision pattern wants them
     * adjacent. That message scored 0.0 and passed as English;
     * `SetupControllerTest` held exactly it.
     */
    public function testAnEscapedApostropheStillCountsAsAnElision(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'assertion_messages_') . '.php';
        file_put_contents($file, <<<'PHP'
            <?php
            $this->assertIsArray($row, 'aucun échec d\'envoi journalisé');
            PHP);

        try {
            $messages = self::messagesIn($file, []);

            $this->assertCount(1, $messages);
            $this->assertSame("aucun échec d'envoi journalisé", $messages[0][1]);
            $this->assertTrue(self::looksFrench($messages[0][1]));
        } finally {
            unlink($file);
        }
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
