<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The third reader has to be written down like the other two.
 *
 * `docs/quality-pipeline.md` § Code review carries pages on `Claude review`
 * — what it does, and every way it has reported success without reading
 * anything. CodeRabbit had one paragraph saying what it is configured to
 * do, and nothing saying what it does not prove (issue #304). A reader
 * comparing the two would conclude the quiet one is the reliable one.
 *
 * What this test pins is the pairing, not the prose: every claim the map
 * makes about a CodeRabbit setting must still be true of `.coderabbit.yaml`.
 * Flipping `auto_incremental_review` back on, or letting the reviewer
 * submit formal approvals, changes what a green pull request means here and
 * would leave the map asserting the opposite in confident prose.
 *
 * Like Tests\Architecture\AutoMergeRuleIsWrittenDownTest, this checks only
 * that the description is still there and still matches. No test can check
 * that anybody read it.
 */
final class CodeRabbitIsDescribedTest extends TestCase
{
    private static function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $contents = @file_get_contents($path);

        self::assertIsString($contents, $relativePath . ' must be readable');

        return $contents;
    }

    /**
     * The two settings that decide what a CodeRabbit comment means, read
     * off the configuration rather than trusted from the prose.
     */
    public function testTheConfigurationStillHoldsWhatTheMapSaysItHolds(): void
    {
        $config = self::read('.coderabbit.yaml');

        // Reviewing once is what makes every later push stale — the whole
        // subject of the paragraph the map now carries.
        $this->assertMatchesRegularExpression(
            '/auto_incremental_review:\s*false/',
            $config,
            'The map describes a reviewer that reads a pull request once; this setting is what makes that true.'
        );

        // Off is what keeps the reviewer from satisfying a required review
        // on `main` with an approval of its own.
        $this->assertMatchesRegularExpression(
            '/request_changes_workflow:\s*false/',
            $config,
            'The map says CodeRabbit cannot submit an approval that would satisfy a required review.'
        );
    }

    public function testTheMapSaysWhatReviewingOnceCosts(): void
    {
        $map = self::read('docs/quality-pipeline.md');

        $this->assertStringContainsString('## Code review', $map);
        $this->assertStringContainsString('auto_incremental_review', $map);

        // The trap itself: not "it may miss findings" — that is obvious —
        // but that everything it posts keeps describing the first commit
        // while looking current, because the comment is rewritten in place.
        $this->assertStringContainsString('pinned to the commit range it first read', $map);
        $this->assertStringContainsString('rewritten in place', $map);

        // And the one line a reader can check it against, without which
        // the warning names a hazard and no way to see it.
        $this->assertStringContainsString('« Commits » block', $map);
    }

    /**
     * AGENTS.md § Pipeline documentation maintenance: « A check that can be
     * green without having run belongs in its last section. [...] When you
     * find another, write it down there; it is the one part of that
     * document nothing else in the repository records. »
     *
     * A verdict pinned to commit 1 and rewritten in place on every push is
     * exactly that, and worse than the usual shape of it: the check is not
     * merely green having proved nothing, it is green having declared the
     * pull request wrong. Describing it under § Code review is not the same
     * as listing it where a reader goes looking for this failure mode — so
     * this asserts the section, not just the document.
     */
    public function testTheFailureModeSectionListsTheStaleVerdict(): void
    {
        $map = self::read('docs/quality-pipeline.md');

        $heading = '## The failure mode this repository keeps meeting';
        $position = strpos($map, $heading);
        self::assertIsInt($position, 'The failure-mode section must exist to carry this.');

        $section = substr($map, $position);
        $this->assertStringContainsString('CodeRabbit', $section);
        $this->assertStringContainsString('rewritten in place', $section);
        $this->assertStringContainsString('« Commits » block', $section);
    }

    /**
     * A reader who does not know this asks the wrong reader for the wrong
     * guarantee — and, worse, reads its silence as coverage.
     */
    public function testTheMapSaysWhatCodeRabbitDoesNotRun(): void
    {
        $map = self::read('docs/quality-pipeline.md');

        $this->assertStringContainsString('reads the diff, and only the diff', $map);
        $this->assertStringContainsString('runs no test', $map);
    }
}
