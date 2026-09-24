<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * README.md's « Intégration continue » section enumerates the jobs a pull
 * request runs. That list is what a contributor reads to know what will
 * judge their change, and what a maintainer reads when deciding whether a
 * check may be made required.
 *
 * `database-mariadb` had dropped out of it. The job ran on every pull
 * request the whole time; only the inventory was short. That is the
 * dangerous direction: a job nobody knows about is a job nobody thinks to
 * look at when it goes red, and a list that is short in one place reads as
 * complete everywhere else.
 *
 * The jobs are read out of the workflow rather than restated here, so
 * adding one makes this test red until the section names it.
 */
final class EveryCiJobIsDocumentedTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/checks.yml';
    private const README = 'README.md';

    /**
     * `All checks` is deliberately exempt: it runs nothing of its own and
     * README describes it in the paragraph above the list rather than as
     * an entry in it, which is the right place for a roll-up.
     */
    private const NOT_AN_ENTRY = ['all-checks'];

    public function testEveryJobOfTheReusableWorkflowIsNamedInTheReadme(): void
    {
        // The job must have an ENTRY of its own, not merely be mentioned.
        // Searching the whole file for `job` was the first version, and it
        // was nearly inert: `test`, `sonarqube`, `e2e-tests` and
        // `javascript-tests` are each quoted in a neighbouring bullet's
        // prose ("la même suite complète que `test`"), so deleting their
        // own entry left the name matching elsewhere and the test green.
        // Only `database-mariadb` is quoted exactly once in README — which
        // is why the mutation that removed ITS bullet went red, and why
        // that red proved nothing about the other seven.
        preg_match_all(
            '/^- \*\*`([a-z0-9-]+)`\*\*/m',
            $this->continuousIntegrationSection(),
            $listed
        );

        $this->assertNotEmpty($listed[1], "README.md's job list could not be parsed.");

        $undocumented = [];

        foreach ($this->jobs() as $job) {
            if (in_array($job, self::NOT_AN_ENTRY, true)) {
                continue;
            }

            if (!in_array($job, $listed[1], true)) {
                $undocumented[] = $job;
            }
        }

        $this->assertSame(
            [],
            $undocumented,
            'Jobs of ' . self::WORKFLOW . " that README.md's « Intégration continue » section "
            . "gives no entry of its own:\n  " . implode("\n  ", $undocumented)
        );
    }

    /**
     * The other direction: a backtick-quoted job name README still lists
     * after the workflow stopped defining it sends a contributor looking
     * for a check that cannot go red.
     */
    public function testTheReadmeNamesNoJobTheWorkflowNoLongerDefines(): void
    {
        $jobs = $this->jobs();
        $section = $this->continuousIntegrationSection();
        $stale = [];

        preg_match_all('/^- \*\*`([a-z0-9-]+)`\*\*/m', $section, $listed);

        foreach ($listed[1] as $name) {
            if (!in_array($name, $jobs, true)) {
                $stale[] = $name;
            }
        }

        $this->assertNotEmpty($listed[1], "README.md's job list could not be parsed.");
        $this->assertSame([], $stale);
    }

    /**
     * Top-level keys of the workflow's `jobs:` mapping — two spaces of
     * indent, which is the only level YAML puts them at here.
     *
     * **The three spellings GitHub accepts, not just the one in use.**
     * A job may be written `deploy:`, `"deploy":` or `'deploy':`, and may
     * carry a trailing comment. `checks.yml` uses only the bare form
     * today, so a stricter pattern would pass — and would silently stop
     * seeing a job the day somebody quotes one or annotates it. A job
     * this method cannot see is a job the README need not document, which
     * is exactly the hole this class exists to close.
     *
     * Matched here rather than parsed: the project has no YAML parser,
     * and pulling one in for an architecture test would be a dependency
     * bought for a regular expression.
     *
     * **Scoped to the `jobs:` block**, which the first widening was not:
     * two-space keys also occur under `on:`, so accepting `_` turned
     * `workflow_call:` into a job the README was asked to document. The
     * block runs from `jobs:` to the next key at column zero.
     *
     * @return list<string>
     */
    private function jobs(): array
    {
        $matched = preg_match(
            '/^jobs:[ \t]*(?:#.*)?$\n(.*?)(?=^\S|\z)/ms',
            $this->read(self::WORKFLOW),
            $block
        );

        $this->assertSame(1, $matched, self::WORKFLOW . ' has no `jobs:` block.');

        preg_match_all(
            '/^  (?:"([A-Za-z0-9_-]+)"|\'([A-Za-z0-9_-]+)\'|([A-Za-z0-9_-]+)):[ \t]*(?:#.*)?$/m',
            $block[1],
            $found
        );

        $jobs = array_values(array_filter(
            array_merge($found[1], $found[2], $found[3]),
            static fn (string $job): bool => $job !== ''
        ));

        $this->assertNotEmpty($jobs, self::WORKFLOW . ' declares no job.');

        return $jobs;
    }

    private function continuousIntegrationSection(): string
    {
        $matched = preg_match(
            '/^## Intégration continue$(.*?)(?=^## )/msu',
            $this->read(self::README),
            $found
        );

        $this->assertSame(1, $matched, "README.md has no « Intégration continue » section.");

        return $found[1];
    }

    private function read(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
    }
}
