<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The scripts that read or write ScoutMagic Project #1 (issue #923).
 *
 * Each has a `.test.sh` neighbour that replaces `gh` with a fixture and
 * needs no network and no token. Those files used to be « run by hand,
 * nothing in CI runs it » (`triage-comment-gate.test.sh` still says so);
 * for scripts that decide what an agent may work on, close issues and write
 * to a user's Project that is not good enough, so PHPUnit runs them here and
 * a red shell test is a red `test` job.
 *
 * The second half pins the one rule that must survive every later edit:
 * `Priority` is the maintainer's alone. No triage path, no sync and no
 * catch-up may compute, propose or write it.
 */
final class BacklogProjectScriptsTest extends TestCase
{
    /** Scripts that WRITE to a Project or an issue and must never mention Priority. */
    private const WRITERS_THAT_NEVER_MENTION_PRIORITY = [
        'project-effort.sh',
        'project-canceled-sync.sh',
        'triage-title.sh',
        'triage-dependencies.sh',
        'project-backfill.sh',
    ];

    /** @return iterable<string, array{string}> */
    public static function shellTests(): iterable
    {
        foreach ([
            'project-effort',
            'backlog-ready-queue',
            'triage-title',
            'triage-dependencies',
            'project-canceled-sync',
            'project-agent',
            'project-backfill',
        ] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('shellTests')]
    public function testTheScriptsOwnOfflineTestPasses(string $name): void
    {
        $script = self::root() . '/scripts/' . $name . '.sh';
        $test = self::root() . '/scripts/' . $name . '.test.sh';
        self::assertFileExists($script, $name . '.sh is gone, but its test is still listed.');
        self::assertFileExists($test, $name . '.sh has no offline test.');

        exec('command -v jq', $unused, $jq);
        self::assertSame(0, $jq, 'jq is required by these scripts and by their tests; install it rather than skip them.');

        $output = [];
        $code = -1;
        exec('bash ' . escapeshellarg($test) . ' 2>&1', $output, $code);
        self::assertSame(0, $code, $name . ".test.sh failed:\n" . implode("\n", $output));
    }

    /** Every script a workflow fetches or runs is the one on disk, and it parses. */
    #[DataProvider('shellTests')]
    public function testTheScriptParses(string $name): void
    {
        $output = [];
        $code = -1;
        exec('bash -n ' . escapeshellarg(self::root() . '/scripts/' . $name . '.sh') . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
    }

    /**
     * Priority is decided by the maintainer alone (issue #923 § 8). The
     * writers below never name it, so there is no path by which they could
     * set it — and a future edit that adds one has to delete this assertion
     * to do so, in the open.
     */
    public function testNoWriterMentionsPriority(): void
    {
        foreach (self::WRITERS_THAT_NEVER_MENTION_PRIORITY as $file) {
            $path = self::root() . '/scripts/' . $file;
            if (!is_file($path)) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression(
                '/priority/i',
                self::withoutComments((string) file_get_contents($path)),
                $file . ' mentions Priority. That field is the maintainer\'s alone; the triage and its syncs '
                . 'never compute, propose or write it (issue #923 § 8).',
            );
        }
    }

    /**
     * The library READS Priority (the queue orders by it) in `project_items`,
     * and nothing after that: its write helpers take a field id from their
     * caller and have no field of their own.
     */
    public function testTheLibraryOnlyReadsPriority(): void
    {
        $lib = self::withoutComments((string) file_get_contents(self::root() . '/scripts/project-lib.sh'));
        $afterReader = substr($lib, (int) strpos($lib, 'project_meta()'));
        self::assertNotSame('', $afterReader);
        self::assertDoesNotMatchRegularExpression('/priority/i', $afterReader);
    }

    /** The queue is read-only: no mutation anywhere in it, and no fall-back to labels. */
    public function testTheReadyQueueNeverWritesAndNeverFallsBackToLabels(): void
    {
        $queue = (string) file_get_contents(self::root() . '/scripts/backlog-ready-queue.sh');
        self::assertStringNotContainsString('mutation', $queue);
        self::assertStringNotContainsString('-X POST', $queue);
        self::assertStringNotContainsString('-X PATCH', $queue);
        self::assertStringNotContainsString('labels=', $queue);
        self::assertStringNotContainsString('gh issue list', $queue);
        self::assertStringContainsString('UNKNOWN, not empty', $queue);
    }

    /** Nothing here sends a GitHub token to a command line a model could read back. */
    public function testNoScriptEchoesATokenOrAssignsAnIssue(): void
    {
        foreach (glob(self::root() . '/scripts/{project-*,triage-title,triage-dependencies,backlog-*}.sh', GLOB_BRACE) ?: [] as $path) {
            $source = (string) file_get_contents($path);
            self::assertDoesNotMatchRegularExpression("/echo[^\n]*\\$\\{?(GH_TOKEN|PROJECTS_TOKEN)/", $source, basename($path));
            // An assignee would start a native agent session (issue #923 § 3).
            self::assertDoesNotMatchRegularExpression('/--add-assignee|assignees\[\]|-f assignee/', $source, basename($path));
        }
    }

    /** Comments explain the rule; only code could break it. */
    private static function withoutComments(string $shell): string
    {
        return (string) preg_replace('/^\s*#.*$/m', '', $shell);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
