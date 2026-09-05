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
        $blocks = $this->indentedPermissionBlocks();

        // Exactly one job, so exactly one job-level block. Two would mean
        // a job this test has never looked at.
        self::assertCount(
            1,
            $blocks,
            self::WORKFLOW . ' has ' . count($blocks) . ' job-level `permissions:` blocks; this test '
            . 'reads one. A second job means a second permission surface nobody is checking.',
        );

        self::assertSame(
            self::ALLOWED_PERMISSIONS,
            $blocks[0],
            "The triage job's permissions changed. This job reads an issue body written by the public "
            . 'and must keep no path to the repository: `contents` in particular would give one. '
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
        // Column zero, not merely somewhere: a nested `permissions: {}`
        // inside a job denies that job and says nothing about the file's
        // default, which is the whole point of this assertion. Trimming
        // before comparing — as this test first did — accepted the wrong
        // one as the right one.
        $atTopLevel = array_filter(
            $this->lines(),
            static fn (string $line): bool => preg_match('/^permissions:\s*\{\s*\}\s*$/', $line) === 1,
        );

        self::assertCount(
            1,
            $atTopLevel,
            self::WORKFLOW . ' must carry exactly one `permissions: {}` at column zero. Without it, a '
            . 'job that forgets its own `permissions:` block inherits the repository default — which '
            . 'is how a workflow ends up with write access nobody granted it on purpose.',
        );
    }

    /**
     * Named for what it can actually check. A 40-character hex object SHA
     * is immutable whether it names a commit or an annotated tag object,
     * and telling those apart means resolving the object against the
     * remote — which a unit test does not get to do. What it does check is
     * that no reference is a movable ref, which is the property that
     * matters: this job holds a Claude subscription token, so code
     * arriving through a moved `v1` would run with it.
     */
    public function testEveryActionReferenceIsPinnedToAnImmutableObject(): void
    {
        $references = 0;

        foreach ($this->lines() as $number => $line) {
            if (!str_contains($line, 'anthropics/claude-code-action@')) {
                continue;
            }

            ++$references;

            // Asserted per reference rather than accumulated into one
            // flag: a later pinned line used to overwrite an earlier
            // unpinned one, so a second, movable reference passed.
            self::assertSame(
                1,
                preg_match('/anthropics\/claude-code-action@[0-9a-f]{40}(\s|$)/', $line),
                'Line ' . ($number + 1) . ' of ' . self::WORKFLOW . ' does not pin '
                . 'anthropics/claude-code-action to a 40-character object SHA, the convention '
                . 'ci.yml already follows.',
            );
        }

        self::assertGreaterThan(
            0,
            $references,
            self::WORKFLOW . ' no longer references anthropics/claude-code-action at all — this test '
            . 'would otherwise pass over nothing.',
        );
    }

    /**
     * Every job-level `permissions:` block, as a map of what it grants.
     *
     * Scoped deliberately. The first version of this test scanned the
     * whole file for `key: read|write|none` lines, which had two holes a
     * review found: an inline `permissions: { contents: write }` granted
     * something no line of that shape would show, and two unrelated
     * `issues: write` / `id-token: write` lines anywhere in the file could
     * reconstruct the expected set while the real block said something
     * else. Both are refused here — an inline mapping fails outright, and
     * only the lines indented inside the block are read.
     *
     * @return array<int, array<string, string>>
     */
    private function indentedPermissionBlocks(): array
    {
        $lines = $this->lines();
        $blocks = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/^(\s+)permissions:(.*)$/', $line, $m) !== 1) {
                continue;
            }

            [, $indent, $rest] = $m;

            // An inline mapping is legal YAML and unreadable here, so it
            // is refused rather than skipped — skipping it is exactly how
            // a grant would pass unseen.
            self::assertSame(
                '',
                trim($rest),
                'The job-level `permissions:` in ' . self::WORKFLOW . ' is written inline. Write it as '
                . 'an indented block, one `key: value` per line, so this audit can read what it grants.',
            );

            $granted = [];

            for ($i = $index + 1; $i < count($lines); $i++) {
                $next = $lines[$i];

                if (trim($next) === '' || preg_match('/^\s*#/', $next) === 1) {
                    continue;
                }

                // The block ends at the first line no deeper than the
                // `permissions:` key itself.
                if (preg_match('/^(\s*)\S/', $next, $n) === 1 && strlen($n[1]) <= strlen($indent)) {
                    break;
                }

                if (preg_match('/^\s+([a-z][a-z-]*):\s*(read|write|none)\s*$/', $next, $g) === 1) {
                    // `none` grants nothing and is never a widening.
                    if ($g[2] !== 'none') {
                        $granted[$g[1]] = $g[2];
                    }

                    continue;
                }

                self::fail(
                    'Unreadable line inside the `permissions:` block of ' . self::WORKFLOW . ': '
                    . trim($next) . ' — this audit fails closed rather than ignore what it cannot parse.',
                );
            }

            $blocks[] = $granted;
        }

        return $blocks;
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
