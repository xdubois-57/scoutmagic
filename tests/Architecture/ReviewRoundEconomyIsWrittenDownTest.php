<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * What a review round costs, and the discipline that arithmetic buys.
 *
 * One round of `Claude review` is 5 to 9 USD and 8 to 25 minutes, CI is
 * another 20, and every push cancels a review in flight and starts it again
 * — the cancelled one is paid for and thrown away. On one pull request here
 * that came to seven rounds and some 45 USD, and the reviewer was not the
 * reason: FIVE of its ten findings were in the code pushed to fix the four
 * before them. Each round opened a new one instead of closing the last.
 *
 * The rules that follow from it are cheap to write and easy to drop, because
 * each one asks an agent to do something slower now for something invisible
 * later. The measurement is the part no reasoning recovers once deleted, so
 * it is pinned beside them.
 *
 * These lived inside § "Fix the backlog" until 2026-09-25, under the step
 * that opened each block's pull request. They belong to driving ANY pull
 * request, which is what `.claude/skills/steward/SKILL.md` is for, so they
 * moved there — and this file replaces the assertion that pinned them in
 * Tests\Architecture\BacklogIsCutIntoBlocksTest, deleted with the scheme it
 * described.
 *
 * Like Tests\Architecture\AutoMergeRuleIsWrittenDownTest, this proves only
 * that the rule is still there to obey. No test can check that anybody
 * obeyed it.
 */
final class ReviewRoundEconomyIsWrittenDownTest extends TestCase
{
    private static function stewardSkill(): string
    {
        $path = dirname(__DIR__, 2) . '/.claude/skills/steward/SKILL.md';
        $contents = file_get_contents($path);

        self::assertIsString($contents, 'the steward skill is unreadable');

        return $contents;
    }

    /**
     * The floor. Everything below reads one section of one skill, and a test
     * reading a heading that has been renamed passes vacuously.
     */
    public function testTheEconomyStillHasItsOwnSectionInTheStewardSkill(): void
    {
        $this->assertStringContainsString(
            '### One round per reading, and the arithmetic that says why',
            self::stewardSkill(),
            'the steward skill no longer has the section on what a review round costs, so none of the '
            . 'rules below are anywhere an agent handling a review comment would read them.',
        );
    }

    /**
     * The measurement. Without it every rule below is a preference, and the
     * first agent in a hurry drops all of them at once.
     */
    public function testTheArithmeticThatJustifiesTheDisciplineIsKept(): void
    {
        $skill = self::stewardSkill();

        $this->assertStringContainsString(
            'seven rounds and some 45 USD',
            $skill,
            'the steward skill no longer says what the undisciplined loop actually cost here. A price '
            . 'nobody wrote down becomes a matter of taste.',
        );

        $this->assertStringContainsString(
            'five of its ten findings were in the code pushed to fix the four',
            $skill,
            'the steward skill no longer says that half those findings were in the FIXES. That is the '
            . 'whole reason the rules below exist, and without it they read as thrift.',
        );
    }

    /** The two rules about when to push, which are the ones that spend money. */
    public function testTheRulesAboutWhenToPushAreKept(): void
    {
        $skill = self::stewardSkill();

        $this->assertStringContainsString(
            'Fix everything that round reported, then push ONCE',
            $skill,
            'the steward skill no longer says to answer a whole round in one push, so two pushes pay '
            . 'twice for the same reading.',
        );

        $this->assertStringContainsString(
            '**Never push while a review is in flight**',
            $skill,
            'the steward skill no longer says not to push during a review. The run is cancelled and '
            . 'restarted from zero, and the cancelled one is paid for.',
        );
    }

    /**
     * The mutation discipline, and the misstep that taught it: the proof was
     * done for the production code and skipped for the guards' own fixtures,
     * and two of them could not fail at all.
     */
    public function testTheMutationProofDisciplineIsKept(): void
    {
        $skill = self::stewardSkill();

        $this->assertStringContainsString(
            '**Prove every new assertion can FAIL**',
            $skill,
            'the steward skill no longer requires a new assertion to be shown failing. An assertion '
            . 'that cannot fail passes for ever and guards nothing.',
        );

        $this->assertStringContainsString(
            'Never restore a file with `git checkout` to undo a mutation',
            $skill,
            'the steward skill no longer warns that `git checkout` restores from HEAD and so deletes '
            . 'the uncommitted work the mutation was testing — after which every assertion fails for '
            . 'the wrong reason.',
        );
    }

    /**
     * And the ordering rule, which is the one that makes every OTHER pull
     * request cheaper rather than this one.
     */
    public function testFlakesAreFixedBeforeAnythingElse(): void
    {
        $this->assertStringContainsString(
            '**And fix a flake before anything else.**',
            self::stewardSkill(),
            'the steward skill no longer puts flake fixes first. A test that fails for a reason that '
            . 'is not the defect it watches costs a round to every pull request that follows.',
        );
    }
}
