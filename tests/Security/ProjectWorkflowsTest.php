<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The workflows that touch ScoutMagic Project #1 (issue #923).
 *
 * `PROJECTS_TOKEN` is a classic personal access token scoped `project`: the
 * only way to read or write a USER-owned Project, since `github.token` has no
 * Projects permission at all. It is the most valuable secret these workflows
 * hold that is not a model credential, so where it may appear is a closed list
 * and each place is one step that starts no model, takes no free text from an
 * issue and runs scripts fetched from `main`.
 * `IssueTriageWorkflowPermissionsTest` asserts the same of the two triage
 * workflows; this file covers the three that only exist for the Project.
 */
final class ProjectWorkflowsTest extends TestCase
{
    private const OWN = [
        'project-canceled-sync.yml',
        'backlog-project.yml',
        'project-backfill.yml',
    ];

    /** Every workflow allowed to name the secret. Anything else naming it is a new exposure. */
    private const MAY_NAME_THE_PROJECTS_TOKEN = [
        'project-canceled-sync.yml',
        'backlog-project.yml',
        'project-backfill.yml',
        'issue-triage.yml',
        'issue-backlog-scan.yml',
    ];

    /** @return iterable<string, array{string}> */
    public static function ownWorkflows(): iterable
    {
        foreach (self::OWN as $file) {
            yield $file => [$file];
        }
    }

    public function testNoOtherWorkflowNamesTheProjectsToken(): void
    {
        foreach (glob(self::dir() . '/*.yml') ?: [] as $path) {
            if (str_contains((string) file_get_contents($path), 'secrets.PROJECTS_TOKEN')) {
                self::assertContains(
                    basename($path),
                    self::MAY_NAME_THE_PROJECTS_TOKEN,
                    basename($path) . ' exposes PROJECTS_TOKEN. Add it to the closed list in this test only after '
                    . 'reading what the step can do with a token that writes to the maintainer\'s Project.',
                );
            }
        }
    }

    #[DataProvider('ownWorkflows')]
    public function testTheTokenIsInOneStepThatStartsNoModel(string $file): void
    {
        $steps = array_values(array_filter(
            self::steps($file),
            static fn (string $step): bool => str_contains($step, 'secrets.PROJECTS_TOKEN'),
        ));
        self::assertCount(1, $steps, $file . ' must expose the Projects token to exactly one step.');
        self::assertStringNotContainsString('anthropics/claude-code-action', $steps[0]);
        self::assertStringNotContainsString('github.token', $steps[0], 'The Projects step must not also carry the repository token.');
    }

    #[DataProvider('ownWorkflows')]
    public function testNothingStartsAModelAndNothingRunsOffMain(string $file): void
    {
        $contents = self::contents($file);
        self::assertStringNotContainsString('claude-code-action', $contents, $file . ' starts a model.');
        self::assertStringContainsString("github.ref == 'refs/heads/main'", $contents, $file . ' can run edited code from a branch with the PAT.');
        self::assertMatchesRegularExpression('/^permissions: \{\}$/m', $contents, $file . ' lost its deny-by-default permissions.');
        self::assertStringNotContainsString('actions/checkout', $contents, $file . ' checks code out next to a PAT.');
        self::assertStringContainsString('?ref=main', $contents, $file . ' no longer fetches its scripts from main.');
    }

    /**
     * An input is data. Inside `run:` a `${{ }}` is textual substitution into
     * the shell, so an input (or an issue's text) written there is a command.
     */
    #[DataProvider('ownWorkflows')]
    public function testNoExpressionIsInterpolatedIntoAShellScript(string $file): void
    {
        foreach (self::steps($file) as $step) {
            if (preg_match('/\brun:\s*\|?\s*\n?(.*)/s', $step, $m) !== 1) {
                continue;
            }
            $run = $m[1];
            self::assertDoesNotMatchRegularExpression(
                '/\$\{\{\s*(inputs|github\.event|steps)\./',
                $run,
                $file . ' interpolates an input or event field into a shell script; pass it through `env:` instead.',
            );
        }
    }

    public function testTheCancellationClosesAsNotPlannedWithTheRepositoryToken(): void
    {
        $contents = self::contents('project-canceled-sync.yml');
        self::assertMatchesRegularExpression("/cron: '\\d+ \\* \\* \\* \\*'/", $contents, 'The reconciliation must run hourly.');
        $closing = array_values(array_filter(self::steps('project-canceled-sync.yml'), static fn (string $s): bool => str_contains($s, ' close ')));
        self::assertCount(1, $closing);
        self::assertStringContainsString('github.token', $closing[0]);
        self::assertStringNotContainsString('PROJECTS_TOKEN', $closing[0]);

        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/project-canceled-sync.sh');
        self::assertStringContainsString('state_reason=not_planned', $script);
        self::assertStringNotContainsString('state_reason=completed', $script, 'Canceled is not Done.');
        self::assertStringNotContainsString("-f labels", $script);
    }

    /** The dispatch workflow offers exactly the operations the script implements, and no more. */
    public function testTheDispatchOperationsAreTheScriptsOperations(): void
    {
        $contents = self::contents('backlog-project.yml');
        self::assertStringContainsString('options: [queue, status, claim, done, release]', $contents);
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/project-agent.sh');
        foreach (['claim', 'done', 'release', 'status'] as $operation) {
            self::assertStringContainsString($operation, $script);
        }
    }

    /** @return array<int, string> */
    private static function steps(string $file): array
    {
        $parts = preg_split('/^      - name: /m', self::contents($file)) ?: [];
        array_shift($parts);

        return array_map(static fn (string $part): string => '- name: ' . $part, $parts);
    }

    private static function contents(string $file): string
    {
        $contents = file_get_contents(self::dir() . '/' . $file);
        self::assertIsString($contents, 'Could not read ' . $file . '.');

        return $contents;
    }

    private static function dir(): string
    {
        return dirname(__DIR__, 2) . '/.github/workflows';
    }
}
