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
     */
    private const READS_AN_ARGUMENT = '/->with\(|->withConsecutive\(|willReturnCallback/';

    /**
     * How far back from `->method('send')` the `expects(...)` that owns it
     * can sit, and how far forward its constraint can. Both are generous
     * enough for the idiom as this suite writes it — the chain broken over
     * three or four lines — and neither can reach the next statement,
     * which is what would make the scan answer for the wrong call.
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
     * This file is the one exemption, and it is not a courtesy.
     *
     * It quotes the very call it forbids — in its docblocks, to explain the
     * rule, and in the literal fixtures the shape tests below are fed — so
     * reading itself reports its own prose as an offence. The same
     * exemption, for the same reason, as
     * `DatabaseBackedTestsReallyRunTest`. And it is pinned rather than
     * trusted: the test below asserts this file still offends, so an
     * exemption that stopped being needed cannot quietly go on hiding a
     * file that does.
     */
    private const EXEMPT = 'tests/Architecture/MailDoublesNameTheirRecipientTest.php';

    public function testEverySendThatIsExpectedNamesItsRecipient(): void
    {
        $unnamed = [];

        foreach ($this->filesDoublingMailService() as $relative => $source) {
            if ($relative === self::EXEMPT) {
                continue;
            }

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
     * and testTheOneExemptionIsStillNeeded(). A floor guards against a
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

    /**
     * The exemption above earns its place on every run.
     *
     * An exemption nobody re-checks is how a rule quietly stops applying to
     * a file: this one exists because this file quotes the forbidden call
     * in prose and in fixtures, and the day it no longer does, the line
     * must go rather than stay as a blanket over whatever is written here
     * next.
     */
    public function testTheOneExemptionIsStillNeeded(): void
    {
        $files = $this->filesDoublingMailService();

        $this->assertArrayHasKey(
            self::EXEMPT,
            $files,
            'the exempted file is no longer found by the scan, so the exemption names nothing'
        );

        $own = array_filter(
            $this->sendExpectations($files[self::EXEMPT]),
            static fn(array $expectation): bool => !$expectation['constrained']
        );

        $this->assertNotEmpty(
            $own,
            'this file no longer quotes the call it forbids, so the exemption is dead weight — remove it'
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
        preg_match_all(self::EXPECTS_A_SEND, $source, $matches, PREG_OFFSET_CAPTURE);

        $found = [];

        foreach ($matches[0] as [, $offset]) {
            $before = substr($source, max(0, $offset - self::LOOK_BACK), min($offset, self::LOOK_BACK));
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
                'constrained' => preg_match(
                    self::READS_AN_ARGUMENT,
                    substr($source, $offset, self::LOOK_FORWARD)
                ) === 1,
            ];
        }

        return $found;
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
