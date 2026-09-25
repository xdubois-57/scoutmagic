<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A test that expects a mail to be SENT says to WHOM (#439).
 *
 * `MailService` is the most doubled class in this repository. Almost every
 * double used to be questioned on the NUMBER of sends —
 * `expects($this->once())->method('send')` — and on nothing else. A mocked
 * method with no argument constraint accepts any argument at all, so the
 * recipient was not read, and therefore not checked: **a change that sent
 * every message to the wrong person passed green.** Measured rather than
 * feared, by replacing the recipient with a fixed foreign address:
 * `MemberEmailService` and `RequestEmailService` were both fully green
 * under it when #439 was written.
 *
 * What is carried is nominative — a confirmation link, a registration
 * decision, a digest naming who answered a form — so a recipient mix-up is
 * not a lost e-mail, it is personal data handed to a third party.
 *
 * **The population is smaller than the issue's headline, and the
 * difference is the point of this docblock.** #439 counts 107 files
 * doubling `MailService`; that is the number of DOUBLES, not of
 * assertions. Most of those files double it because the composition root
 * asks for one and never expect a send at all — nothing about them is
 * wrong. Measured here, of the files that double it, the great majority
 * expect no send, and the ones that did without naming a recipient were
 * eight expectations across six files. The count that matters is the one
 * this test makes zero and keeps there.
 *
 * `expects($this->never())` is deliberately NOT asked for a recipient:
 * nothing is sent, so there is no address to name. A first version of this
 * scan reported twelve files, and reading them — rather than trusting the
 * number — showed half were `never()` expectations already doing exactly
 * the right thing. The exclusion is the finding, not a convenience.
 */
final class MailDoublesNameTheirRecipientTest extends TestCase
{
    /** How a test file gets hold of a `MailService` it can question. */
    private const DOUBLES_IT = '/createMock\(\s*(?:\\\\?Core\\\\Mail\\\\)?MailService::class\s*\)'
        . '|getMockBuilder\(\s*(?:\\\\?Core\\\\Mail\\\\)?MailService::class\s*\)'
        . '|extends\s+MailService\b/';

    /** The call under inspection. */
    private const EXPECTS_A_SEND = '/->method\(\s*[\'"]send[\'"]\s*\)/';

    /**
     * Anything that reads an argument rather than counting calls. A
     * callback counts because capturing the recipient and asserting it
     * afterwards is the only way to pin a send to SEVERAL addresses —
     * `with()` applies one constraint to every matching call.
     *
     * **Presence is not enough, and a reviewer found the live proof.**
     * `MailService::send()` takes the address FIRST, so a
     * `->with($this->anything(), …)` reads an argument and pins nothing
     * that matters. Two expectations in `Retro\Service\BoardServiceTest`
     * were exactly that, and this scanner called them constrained — a
     * regression routing the closing mail to the wrong address would have
     * passed both that test and this gate, which is the one bug class
     * #439 exists for. So `firstArgumentOf()` below reads the first
     * argument, and `anything()` there is not a constraint.
     */
    private const READS_AN_ARGUMENT = '/->with\(|->withConsecutive\(|willReturnCallback/';

    /**
     * How far back from `->method('send')` the `expects(...)` that owns it
     * can sit, and how far forward its constraint can. Generous enough for
     * the idiom as this suite writes it — the chain broken over three or
     * four lines.
     *
     * **They used to be byte counts and nothing else, and this docblock
     * claimed neither could reach the next statement. It was not true**, a
     * reviewer had to say so, and the consequence was a false NEGATIVE in
     * a gate whose whole job is to have none: a `->with(` belonging to an
     * unrelated expectation on the following line fell inside the forward
     * window, so a bare `send` expectation was silently counted as
     * constrained. The mirror case — an unrelated `expects(` inside the
     * look-back — could invent an owner for a plain stub.
     *
     * Both windows are now clipped at the nearest `;`, so what is read is
     * the current statement and never its neighbour. The byte counts stay
     * as an upper bound, because a statement that runs longer than this is
     * not the idiom and should be looked at by a human.
     */
    private const LOOK_BACK = 200;
    private const LOOK_FORWARD = 300;

    /**
     * The floor for « this scan still finds the suite ». The main assertion
     * below is `assertSame([], …)`, which a scan that matched nothing
     * satisfies perfectly — the failure mode every ratchet in this
     * repository is written against.
     */
    private const MINIMUM_FILES_DOUBLING_IT = 60;
    private const MINIMUM_CONSTRAINED_EXPECTATIONS = 12;

    /**
     * **There is no exemption, and there used to be one.**
     *
     * This file quotes the very call it forbids — in its docblocks, to
     * explain the rule, and in the literal fixtures the shape tests below
     * are fed — so for two rounds it had to exempt itself from its own
     * scan, pinned by a test asserting the exemption was still earned.
     *
     * `codeOnly()` retired it. Prose is comment and a fixture is a string
     * literal; neither is an expectation anybody runs, and the scan no
     * longer sees either. The pinning test then failed, saying the
     * exemption had become dead weight — which is precisely the job it was
     * written to do, and the reason it was written rather than trusted.
     * The rule now applies to this file like any other.
     */
    public function testEverySendThatIsExpectedNamesItsRecipient(): void
    {
        $unnamed = [];

        foreach ($this->filesDoublingMailService() as $relative => $source) {
            foreach ($this->sendExpectations($source) as ['line' => $line, 'constrained' => $constrained]) {
                if (!$constrained) {
                    $unnamed[] = $relative . ':' . $line;
                }
            }
        }

        sort($unnamed);

        $this->assertSame(
            [],
            $unnamed,
            "A test expects a mail to be sent and never says to whom. A mocked method with no\n"
            . "argument constraint accepts any argument, so the recipient is not checked, and a\n"
            . "change sending every message to the wrong person keeps these green (issue #439).\n"
            . "Add `->with(\$this->identicalTo('…'))` — `send()`'s first parameter is the\n"
            . "recipient — or capture it in a `willReturnCallback` and assert the addresses\n"
            . "afterwards when there are several:\n  "
            . implode("\n  ", $unnamed)
        );
    }

    /**
     * The premise of the test above, in both halves: the scan finds the
     * doubles, and it finds constrained expectations among them.
     *
     * The second half is the one that matters for a scan that stopped
     * reading — `DOUBLES_IT` matching nothing leaves an empty list that
     * looks exactly like success.
     *
     * **What these floors do NOT catch, stated because measuring it is the
     * only way to know:** a detector broken the other way, so that
     * `READS_AN_ARGUMENT` matches everything, makes every expectation look
     * constrained — the offender list is empty and this count goes UP, so
     * both floors are satisfied. What fails then is
     * testABareCountIsReportedAndAConstrainedOneIsNot(), on literal source,
     * A floor guards against a
     * reader that finds nothing; only a fixture with a known answer guards
     * against one that approves everything.
     */
    public function testTheScanStillRecognisesTheSuiteItReads(): void
    {
        $files = $this->filesDoublingMailService();
        $constrained = 0;

        foreach ($files as $source) {
            foreach ($this->sendExpectations($source) as $expectation) {
                if ($expectation['constrained']) {
                    $constrained++;
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_FILES_DOUBLING_IT,
            count($files),
            'the scan stopped finding the MailService doubles, so its empty verdict means nothing'
        );
        $this->assertGreaterThanOrEqual(
            self::MINIMUM_CONSTRAINED_EXPECTATIONS,
            $constrained,
            'the scan stopped recognising a constrained send, so every expectation now looks correct'
        );
    }

    // ————— The shape of the reader, on literal source —————

    /**
     * Fed literal source rather than a real file, so a file edited later
     * cannot quietly turn these into assertions about nothing.
     */
    public function testABareCountIsReportedAndAConstrainedOneIsNot(): void
    {
        $bare = $this->sendExpectations(<<<'PHP'
            $mail = $this->createMock(MailService::class);
            $mail->expects($this->once())->method('send');
            PHP);

        $this->assertCount(1, $bare);
        $this->assertFalse($bare[0]['constrained'], 'a bare count must be reported');

        $named = $this->sendExpectations(<<<'PHP'
            $mail = $this->createMock(MailService::class);
            $mail->expects($this->once())->method('send')
                ->with($this->identicalTo('akela@example.test'));
            PHP);

        $this->assertCount(1, $named);
        $this->assertTrue($named[0]['constrained'], 'a named recipient must not be reported');
    }

    /**
     * **A comment cannot make an unpinned recipient look pinned**, and this
     * is the shape that proved it could.
     *
     * The comma in « The recipient, named rather than… » is where the old
     * reader stopped: it returned that comment fragment as the « first
     * argument » and never reached the argument two lines below. In the
     * commit that introduced the idiom it answered correctly by accident,
     * the fragment happening not to spell `anything()`. Here the same
     * comment sits above a recipient nobody pinned, which is the direction
     * that matters — and the direction the reviewer named.
     */
    public function testACommentAboveTheRecipientIsNotReadAsTheRecipient(): void
    {
        $waved = $this->sendExpectations(<<<'PHP'
            $mail->expects($this->once())->method('send')->with(
                // The recipient, waved through: a comma in this line is
                // where a byte scanner stops reading.
                $this->anything(),
                $this->stringContains('Résumé')
            );
            PHP);

        $this->assertCount(1, $waved);
        $this->assertFalse(
            $waved[0]['constrained'],
            'a comment carrying a comma hid an unpinned recipient behind itself'
        );
    }

    /**
     * And a `;` in a comment does not end the statement early.
     *
     * The mirror of the case above, one step sooner: the statement is cut
     * at the first `;`, so prose carrying one used to truncate the
     * expectation before its own `->with(` was ever reached — leaving a
     * pinned recipient reported as bare. Which is the harmless direction,
     * and the reason it is worth pinning: a guard that cries wolf on
     * correct code is how a guard gets switched off.
     */
    public function testASemicolonInACommentDoesNotEndTheStatement(): void
    {
        $named = $this->sendExpectations(<<<'PHP'
            $mail->expects($this->once())->method('send')
                // Sent once; to the chief; and nowhere else.
                ->with($this->identicalTo('akela@example.test'));
            PHP);

        $this->assertCount(1, $named);
        $this->assertTrue(
            $named[0]['constrained'],
            'a semicolon in a comment cut the statement before its own constraint'
        );
    }

    /**
     * **`anything()` in first position constrains nothing**, and the
     * scanner used to call it constrained because `->with(` was merely
     * there.
     *
     * A reviewer found this live, in two expectations of
     * `Retro\Service\BoardServiceTest`: the closing mail's recipient was
     * `anything()`, its bodies were pinned, and a regression sending it to
     * the wrong address would have passed both that test and this gate.
     * `send()` takes the address FIRST, which is what makes the first
     * argument the one to read.
     */
    public function testAnythingInFirstPositionIsNotAConstrainedRecipient(): void
    {
        $waved = $this->sendExpectations(<<<'PHP'
            $mail->expects($this->once())->method('send')->with(
                $this->anything(),
                $this->anything(),
                $this->stringContains('Résumé'),
                $this->stringContains('Résumé')
            );
            PHP);

        $this->assertCount(1, $waved);
        $this->assertFalse(
            $waved[0]['constrained'],
            'a recipient left to anything() must be reported, whatever the other arguments pin'
        );

        // And the same shape with the address named is not reported — the
        // other `anything()`s are the test's business, not this scan's.
        $named = $this->sendExpectations(<<<'PHP'
            $mail->expects($this->once())->method('send')->with(
                $this->identicalTo('akela@example.test'),
                $this->anything(),
                $this->anything(),
                $this->anything()
            );
            PHP);

        $this->assertTrue($named[0]['constrained']);
    }

    /**
     * **A neighbour's constraint cannot be borrowed**, which the byte
     * windows allowed until they were clipped at the statement's `;`.
     *
     * The `->with(` below belongs to `$repo`, not to the send, and sits
     * well inside the 300-byte forward window. Reading it as the send's
     * turned a bare expectation into a constrained one — a false negative
     * in a gate that exists to have none.
     */
    public function testAConstraintBelongingToTheNextStatementIsNotRead(): void
    {
        $borrowed = $this->sendExpectations(<<<'PHP'
            $mail->expects($this->once())->method('send');
            $repo->expects($this->once())->method('save')->with($this->identicalTo($member));
            PHP);

        $this->assertCount(1, $borrowed);
        $this->assertFalse(
            $borrowed[0]['constrained'],
            "the send expectation is bare; the constraint two lines down is another mock's"
        );
    }

    /**
     * And the mirror: an `expects(` from the PREVIOUS statement must not
     * invent an owner for a plain stub, which is what made the look-back
     * worth clipping too.
     */
    public function testAnExpectsBelongingToThePreviousStatementIsNotItsOwner(): void
    {
        $this->assertSame(
            [],
            $this->sendExpectations(<<<'PHP'
                $repo->expects($this->once())->method('save');
                $mail->method('send');
                PHP),
            'a stub with no expectation of its own is not an expectation'
        );
    }

    /**
     * The exclusion that reading the files taught, and the reason this
     * scan reports six files rather than twelve.
     */
    public function testAnExpectationOfNoSendIsNotAskedForARecipient(): void
    {
        $this->assertSame(
            [],
            $this->sendExpectations(<<<'PHP'
                $mail = $this->createMock(MailService::class);
                $mail->expects($this->never())->method('send');
                PHP),
            'nothing is sent, so there is no address to name'
        );
    }

    /**
     * A capture is a constraint: it is the only way to pin a send to
     * several different addresses, since `with()` applies one constraint to
     * every matching call.
     */
    public function testACapturingCallbackCountsAsReadingTheRecipient(): void
    {
        $captured = $this->sendExpectations(<<<'PHP'
            $mail = $this->createMock(MailService::class);
            $mail->expects($this->exactly(2))->method('send')
                ->willReturnCallback(function (string $to) use (&$sentTo): void { $sentTo[] = $to; });
            PHP);

        $this->assertCount(1, $captured);
        $this->assertTrue($captured[0]['constrained']);
    }

    /**
     * A stub is not an expectation. `->method('send')` without `expects()`
     * says « answer this if it is called », which asserts nothing about
     * sending and therefore owes nothing about the recipient — the file
     * that needs a working double to reach the code it does test.
     */
    public function testAStubWithoutAnExpectationIsNotAnExpectation(): void
    {
        $this->assertSame(
            [],
            $this->sendExpectations(<<<'PHP'
                $mail = $this->createMock(MailService::class);
                $mail->method('send')->willThrowException(new MailException('nope'));
                PHP)
        );
    }

    /**
     * Every `expects(...)->method('send')` in one source, with whether it
     * reads an argument.
     *
     * @return list<array{line: int, constrained: bool}>
     */
    private function sendExpectations(string $source): array
    {
        $source = self::codeOnly($source);

        preg_match_all(self::EXPECTS_A_SEND, $source, $matches, PREG_OFFSET_CAPTURE);

        $found = [];

        foreach ($matches[0] as [, $offset]) {
            $before = self::currentStatementBefore($source, $offset);
            $owner = strrpos($before, 'expects(');
            if ($owner === false) {
                continue;
            }

            // `never()` says nothing is sent, so nothing is addressed.
            if (str_contains(substr($before, $owner), 'never(')) {
                continue;
            }

            $found[] = [
                'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                'constrained' => self::pinsTheRecipient(self::currentStatementAfter($source, $offset)),
            ];
        }

        return $found;
    }

    /**
     * The text before this `->method('send')`, clipped at the previous
     * statement's `;` so an unrelated `expects(` cannot be read as its
     * owner.
     */
    private static function currentStatementBefore(string $source, int $offset): string
    {
        $window = substr($source, max(0, $offset - self::LOOK_BACK), min($offset, self::LOOK_BACK));
        $boundary = strrpos($window, ';');

        return $boundary === false ? $window : substr($window, $boundary + 1);
    }

    /**
     * The text after it, clipped at this statement's own `;` so a
     * neighbour's constraint cannot be borrowed.
     */
    private static function currentStatementAfter(string $source, int $offset): string
    {
        $window = substr($source, $offset, self::LOOK_FORWARD);
        $boundary = strpos($window, ';');

        return $boundary === false ? $window : substr($window, 0, $boundary);
    }

    /**
     * The source with its prose and its text neutralised, so that only real
     * code punctuation is left for a byte scanner to read.
     *
     * **Applied once to the whole source, before anything is searched in
     * it** — not per window. A `;` inside a comment ends a statement just
     * as wrongly as a `,` inside one starts the next argument, so the
     * stripping has to happen before the statement is cut, not only before
     * its arguments are read. Doing it per window would also mean
     * tokenising a fragment cut at a byte count, whose first token can be a
     * truncated one; a whole file never has that problem.
     *
     * A reviewer found this, and the offending shape was introduced BY the
     * commit that added `firstArgumentOf()`: a four-line comment sitting
     * immediately after `->with(`, whose first line reads « The recipient,
     * named rather than waved through ». That comma is where the old reader
     * stopped, so the « first argument » it returned was the comment
     * fragment and never the `identicalTo()` two lines below. It answered
     * « constrained » for the right reason by accident — the fragment
     * happens not to spell `anything()` — and the same comment above a
     * genuine `anything()` would have answered the same thing for a
     * recipient nobody pinned. A guard whose verdict depends on the prose
     * above the code is not a guard.
     *
     * Two deliberate asymmetries:
     *
     * - **A comment becomes its own newlines**, not a space. Line numbers
     *   are what this test reports to whoever has to fix the file, and they
     *   have to keep pointing at the right line.
     * - **Text keeps its characters; only `;,()` inside it become `x`.**
     *   Emptying string bodies outright would have emptied `'send'` too,
     *   and the scan would then have found no expectation anywhere — a
     *   guard that reports nothing passes beautifully. Neutralising just
     *   the four characters a byte scanner reacts to leaves `'send'`,
     *   `'chief@example.test'` and every other string the regex needs
     *   intact, and cannot create or destroy an `anything()`.
     *
     * That second rule is also what retired this class's one exemption,
     * and the effect is worth spelling out because it looks like a side
     * effect and is the point: the fixtures below are PHP source held in
     * HEREDOCS, so the whole fixture is one run of text, and
     * `->method('send')` inside it becomes `->methodx'send'x` — no longer
     * an expectation to anybody reading. Docblock prose goes by the first
     * rule. Neither was ever an expectation somebody runs, which is why
     * this file no longer needs exempting from its own rule. Remove either
     * rule and the file reports itself again.
     *
     * `token_get_all()` needs an opening tag before it tokenises anything —
     * a fragment without one comes back as a single T_INLINE_HTML, comments
     * and all — so the source is prefixed and the prefix dropped.
     */
    private static function codeOnly(string $source): string
    {
        $code = '';

        foreach (token_get_all('<?php ' . $source) as $index => $token) {
            if ($index === 0) {
                // The `<?php ` this method added, never the caller's text.
                continue;
            }

            if (!is_array($token)) {
                $code .= $token;
                continue;
            }

            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                $code .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }

            // A plain string is one token; an interpolated one is a run of
            // them between two bare quotes, and its literal part carries
            // punctuation just the same.
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE) {
                $code .= str_replace([';', ',', '(', ')'], 'x', $token[1]);
                continue;
            }

            $code .= $token[1];
        }

        return $code;
    }

    /**
     * Does this expectation constrain the ADDRESS, which `send()` takes
     * first?
     *
     * A callback or `withConsecutive()` is taken at its word: both read
     * every argument, and what they then assert is the test's business.
     * A plain `with()` is read, because `anything()` in first position is
     * a constraint on nothing.
     */
    private static function pinsTheRecipient(string $statement): bool
    {
        if (preg_match(self::READS_AN_ARGUMENT, $statement) !== 1) {
            return false;
        }

        if (str_contains($statement, 'withConsecutive(') || str_contains($statement, 'willReturnCallback')) {
            return true;
        }

        $with = strpos($statement, '->with(');
        if ($with === false) {
            return false;
        }

        return preg_match('/anything\(\s*\)/', self::firstArgumentOf(substr($statement, $with + 7))) !== 1;
    }

    /**
     * Everything up to the first comma that is not inside parentheses —
     * `$this->identicalTo('a@b.c')` carries its own, so splitting on every
     * comma would cut it in half.
     */
    private static function firstArgumentOf(string $arguments): string
    {
        $depth = 0;
        $first = '';

        foreach (str_split($arguments) as $character) {
            if ($character === '(') {
                $depth++;
            }
            if ($character === ')') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            }
            if ($character === ',' && $depth === 0) {
                break;
            }
            $first .= $character;
        }

        return $first;
    }

    /**
     * @return array<string, string> relative path => source
     */
    private function filesDoublingMailService(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/tests', \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (preg_match(self::DOUBLES_IT, $source) !== 1) {
                continue;
            }

            $files[substr($file->getPathname(), strlen($root) + 1)] = $source;
        }

        return $files;
    }
}
