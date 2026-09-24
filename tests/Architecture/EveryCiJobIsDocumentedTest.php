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
     * @return list<string>
     */
    private function jobs(): array
    {
        preg_match_all('/^  ([a-z0-9-]+):$/m', $this->read(self::WORKFLOW), $found);

        $this->assertNotEmpty($found[1], self::WORKFLOW . ' declares no job.');

        return $found[1];
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
