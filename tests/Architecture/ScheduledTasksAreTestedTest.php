<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every scheduled task a module declares is run by a test.
 *
 * **This layer got the way it was for a structural reason, not a lazy
 * one.** A coverage measurement of the whole repository put the scheduled
 * handlers twenty-five points below every other kind of class — 67 %
 * against 96 % for repositories — with ten of them at zero. That is not
 * an accident of effort: it is what happens when the natural way to
 * satisfy yourself that something works is to open it in a browser.
 * Nobody opens a scheduled task. It runs at four in the morning on
 * somebody else's server, and when it stops doing its job the first
 * symptom is an e-mail that never arrived — which nobody reports, because
 * nobody knows they were owed it.
 *
 * The tests written to close that gap found a real defect on the way: the
 * reenrollment campaign handed its reminder over once per hourly poll,
 * so a family without an answer received the same e-mail up to
 * twenty-four times in a day. It had been that way since the feature
 * shipped.
 *
 * So this file exists to stop the gap reopening one commit at a time. It
 * is deliberately cheap to satisfy — a test that CONSTRUCTS the handler
 * — because the point is not to prescribe how much testing is enough; it
 * is that a scheduled task cannot arrive with none at all.
 *
 * **Construction, not mention.** The weaker rule — the class name appears
 * somewhere under tests/ — was measured and found wanting: three handlers
 * satisfied it while their code was never executed by anything. A name in
 * a `use` statement is not a test.
 *
 * The allowlist is EMPTY, and that is the whole design: it can only ever
 * shrink. Adding an entry means writing down, in this file, that a task
 * which mails or deletes on its own is shipping untested — which is a
 * decision somebody has to defend in review, not a silence.
 */
class ScheduledTasksAreTestedTest extends TestCase
{
    /**
     * Handlers allowed to have no test. It starts empty and may only get
     * shorter; an entry needs a comment saying why, and a way out.
     *
     * @var array<int, string>
     */
    private const ALLOWED_WITHOUT_A_TEST = [];

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Every `scheduled_tasks` entry of every module manifest.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function declaredScheduledTasks(): array
    {
        $root = dirname(__DIR__, 2);
        $cases = [];

        foreach (glob($root . '/modules/*/module.json') ?: [] as $manifest) {
            $moduleId = basename(dirname($manifest));
            $declared = json_decode((string) file_get_contents($manifest), true);
            if (!is_array($declared)) {
                continue;
            }

            foreach ((array) ($declared['scheduled_tasks'] ?? []) as $task) {
                if (!is_array($task) || !isset($task['handler'], $task['key'])) {
                    continue;
                }

                $handler = (string) $task['handler'];
                $short = substr((string) strrchr($handler, '\\'), 1);
                $cases[$moduleId . ' / ' . $task['key']] = [$moduleId, (string) $task['key'], $short];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('declaredScheduledTasks')]
    public function testADeclaredScheduledTaskIsConstructedBySomeTest(
        string $moduleId,
        string $taskKey,
        string $handler
    ): void {
        if (in_array($handler, self::ALLOWED_WITHOUT_A_TEST, true)) {
            $this->markTestSkipped($handler . ' is on the allowlist.');
        }

        $this->assertTrue(
            $this->isConstructedInATest($handler),
            sprintf(
                'No test constructs %s (%s / %s). A scheduled task runs where nobody is watching: '
                    . 'write one before shipping it, or add it to ALLOWED_WITHOUT_A_TEST with a reason.',
                $handler,
                $moduleId,
                $taskKey
            )
        );
    }

    /**
     * The list may only shrink. A pull request that lengthens it changes
     * this number too, which is what puts the decision in front of a
     * reviewer instead of leaving it in a diff nobody reads.
     */
    public function testTheAllowlistIsEmptyAndStaysThatWay(): void
    {
        $this->assertCount(
            0,
            self::ALLOWED_WITHOUT_A_TEST,
            'Every scheduled task in the repository has a test. Keep it that way.'
        );
    }

    public function testTheRuleItselfWouldNoticeAnUntestedHandler(): void
    {
        $this->assertFalse(
            $this->isConstructedInATest('AHandlerNobodyEverWrote'),
            'A check that passes for a class that does not exist checks nothing.'
        );
    }

    /**
     * Searched across tests/ excluding the end-to-end suite: a browser
     * scenario exercises a task through a whole instance, which is
     * valuable and is not what this rule asks for.
     */
    private function isConstructedInATest(string $handler): bool
    {
        $pattern = '/new\s+\\\\?' . preg_quote($handler, '/') . '\s*\(/';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->repositoryRoot() . '/tests', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'e2e' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                return true;
            }
        }

        return false;
    }
}
