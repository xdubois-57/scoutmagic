<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * `.github/workflows/issue-triage.yml` runs on `issues: [opened,
 * reopened]`, which makes it the only workflow in this repository whose
 * input is written by a member of the public: anyone with a GitHub
 * account can open an issue here, and its title and body reach a model
 * that then acts on this repository.
 *
 * What keeps that safe is not the prompt and not the skill file — a
 * prompt is an instruction, and instructions are what a hostile issue
 * body competes with. It is the token: the job holds `issues: write` and
 * `id-token: write`, checks nothing out, and therefore has no path to
 * `main` at all, whatever anybody talks it into. Adding `contents: write`
 * "just to look at a file" would hand every future issue body a way to
 * reach the default branch, and the GitHub MCP tools already read code
 * without a working copy.
 *
 * That property is one line away from being lost, and nothing else would
 * notice: a widened `permissions:` block is a green pull request, a green
 * CI run and a working workflow. So it is asserted here rather than left
 * to review — the same reasoning as every other audit in this directory.
 *
 * Deliberately line-based rather than YAML-parsed: this repository ships
 * no YAML parser for PHP (no `symfony/yaml`, no `ext-yaml`), and pulling
 * a dependency in for one audit would cost more than it buys on a small,
 * hand-written file. The assertions below are written to fail closed —
 * an unreadable or restructured file fails rather than passes.
 */
class IssueTriageWorkflowPermissionsTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/issue-triage.yml';

    /** The complete set the triage job may hold. Nothing may be added. */
    private const ALLOWED_PERMISSIONS = [
        'issues' => 'write',
        'id-token' => 'write',
    ];

    public function testTheWorkflowExists(): void
    {
        self::assertFileExists(
            dirname(__DIR__, 2) . '/' . self::WORKFLOW,
            self::WORKFLOW . ' is missing — the tests below would pass over nothing.',
        );
    }

    public function testItGrantsNothingBeyondIssuesAndIdToken(): void
    {
        $granted = [];

        foreach ($this->lines() as $line) {
            // `  issues: write`, `      contents: read` — a permission
            // grant is a bare `key: read|write|none` line. The workflow's
            // own `permissions: {}` and every other key (`name:`, `on:`)
            // fail this shape and are ignored.
            if (preg_match('/^\s+([a-z][a-z-]*):\s*(read|write|none)\s*$/', $line, $m) !== 1) {
                continue;
            }

            [, $key, $value] = $m;

            // `none` grants nothing and is never a widening.
            if ($value !== 'none') {
                $granted[$key] = $value;
            }
        }

        self::assertSame(
            self::ALLOWED_PERMISSIONS,
            $granted,
            "The triage job's permissions changed. This job reads an issue body written by the public "
            . "and must keep no path to the repository: `contents` in particular would give one. "
            . 'If a new permission is genuinely needed, change ALLOWED_PERMISSIONS here in the same '
            . 'commit and say why in the pull request.',
        );
    }

    public function testItNeverChecksTheRepositoryOut(): void
    {
        foreach ($this->lines() as $number => $line) {
            // `uses:` lines only. The header of that file discusses the
            // absence of a checkout at some length, and matching the
            // prose would fail on the very comment that explains the
            // rule — which is how an audit gets deleted for being noisy.
            if (preg_match('/^\s*-?\s*uses:\s*(\S+)/', $line, $m) !== 1) {
                continue;
            }

            self::assertStringNotContainsString(
                'actions/checkout',
                $m[1],
                'Line ' . ($number + 1) . ' of ' . self::WORKFLOW . ' checks the repository out. '
                . 'A checkout is only ever needed in order to write; Claude reads code through the '
                . 'GitHub MCP tools, which need no working copy.',
            );
        }
    }

    public function testItStillDeniesEverythingAtTheWorkflowLevel(): void
    {
        // Without this, a job that forgets its own `permissions:` block
        // inherits the repository default — which is how a workflow ends
        // up with write access nobody granted it on purpose.
        self::assertContains(
            'permissions: {}',
            array_map('trim', $this->lines()),
            self::WORKFLOW . ' no longer denies permissions at the workflow level.',
        );
    }

    public function testTheActionIsPinnedToACommitRatherThanATag(): void
    {
        $pinned = false;

        foreach ($this->lines() as $line) {
            if (!str_contains($line, 'anthropics/claude-code-action@')) {
                continue;
            }

            // A tag moves and this job holds a Claude subscription token,
            // so code arriving through a moved tag would run with it.
            $pinned = preg_match('/anthropics\/claude-code-action@[0-9a-f]{40}\b/', $line) === 1;
        }

        self::assertTrue(
            $pinned,
            self::WORKFLOW . ' must pin anthropics/claude-code-action to a 40-character commit SHA, '
            . 'the convention ci.yml and claude-review.yml already follow.',
        );
    }

    /**
     * @return array<int, string>
     */
    private function lines(): array
    {
        $path = dirname(__DIR__, 2) . '/' . self::WORKFLOW;
        $contents = file_get_contents($path);

        self::assertIsString($contents, 'Could not read ' . self::WORKFLOW . '.');

        return explode("\n", $contents);
    }
}
