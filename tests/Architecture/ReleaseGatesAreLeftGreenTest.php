<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * « Fixe le backlog » used to mean the accepted issues and nothing else,
 * and the gap that left was only ever visible at the worst moment.
 *
 * `scripts/release.sh` runs seven gates before it writes a single commit,
 * and three of them describe the state of the repository rather than the
 * state of the change being released: dependency freshness, SonarQube
 * Cloud, and the security queries. None of them is anybody's job between
 * releases, so each one accumulates quietly — five outdated direct
 * Composer packages, eleven blocking SonarCloud findings — and the first
 * person to notice is whoever typed `scripts/release.sh` expecting to
 * ship. The gate does its job and refuses; the work it names is an hour
 * that nobody budgeted, at the one moment it cannot be postponed.
 *
 * So there is an instruction for them, on 2026-09-19's wording: « toutes
 * les dépendances à jour, toutes les issues SonarCloud fixées, et en
 * général toutes les gates nécessaires pour faire une release vertes.
 * Sans pour cela lancer une release. »
 *
 * It lived inside § "Fix the backlog" until 2026-09-25 and was moved out,
 * which is why this class no longer carries « Backlog » in its name. Two
 * jobs under one instruction meant a dependency bump landing in a pull
 * request a reviewer was holding for a ticket, and it also meant this
 * knowledge disappeared the day the backlog instruction was simplified.
 * The maintainer now gives it in their own words, when they want it.
 *
 * That last sentence of theirs is half the rule and the half a test can
 * actually defend: green gates are not an invitation to ship. Releasing is
 * its own instruction, given separately, and an agent that cuts one because
 * the gates happened to go green has published to a production site on its
 * own initiative.
 *
 * Like Tests\Architecture\BacklogIsOneTicketAtATimeTest and
 * Tests\Architecture\AutoMergeRuleIsWrittenDownTest, this proves only that
 * the rule is still written down where the next agent reads it. No test
 * can check that anybody obeyed it.
 */
final class ReleaseGatesAreLeftGreenTest extends TestCase
{
    private static function agentRules(): string
    {
        $path = dirname(__DIR__, 2) . '/AGENTS.md';
        $contents = file_get_contents($path);

        self::assertIsString($contents, 'AGENTS.md is unreadable');

        return $contents;
    }

    /**
     * THE SECTION ITSELF. Without it the gates go back to being nobody's
     * job until somebody types `scripts/release.sh` and the first of them
     * refuses.
     *
     * They were a STEP of « fixe le backlog » until 2026-09-25 and are now
     * their own instruction, which is why neither this name nor the
     * assertion below speaks of the backlog any more. A test that still
     * called them part of it would invite the next edit to fold back the
     * coupling this was split out of.
     */
    public function testTheReleaseGatesHaveTheirOwnSection(): void
    {
        $this->assertStringContainsString(
            '## Leaving the release gates green',
            self::agentRules(),
            'AGENTS.md no longer has its own section asking for the release gates to be left green, so '
            . 'dependency freshness and SonarQube Cloud are once again nobody\'s job until the moment '
            . 'somebody wants to ship and a gate refuses.',
        );
    }

    /**
     * The two that rot on their own between releases, named rather than
     * left to "the gates" — a list an agent can work through beats a
     * category it has to interpret.
     */
    public function testTheTwoGatesThatRotAreNamedOutright(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            '**Dependencies up to date.**',
            $rules,
            'AGENTS.md no longer names dependency freshness as one of the gates to leave green.',
        );

        $this->assertStringContainsString(
            '**SonarQube Cloud at zero.**',
            $rules,
            'AGENTS.md no longer names SonarQube Cloud as one of the gates to leave green.',
        );
    }

    /**
     * The dangerous half. Everything above tells an agent to make the
     * gates pass; only this sentence stops it from concluding that it may
     * therefore ship.
     */
    public function testGreenGatesAreNotPermissionToRelease(): void
    {
        $this->assertStringContainsString(
            '**Never run `scripts/release.sh` for this.**',
            self::agentRules(),
            'AGENTS.md no longer says that leaving the release gates green is not permission to cut a '
            . 'release. Read without it, a step whose whole content is "make the release gates pass" '
            . 'invites the one act the maintainer excluded in the same sentence that asked for it.',
        );
    }

    /**
     * Each of these two is its OWN pull request. A lockfile bump inside a
     * ticket's pull request is a diff a reviewer is holding for a different
     * reason.
     *
     * This said « cut into blocks » until 2026-09-25, when the backlog
     * stopped being cut that way — the assertion below was updated then and
     * this wording was not, which is the drift the rest of that change was
     * spent removing.
     */
    public function testThisWorkIsItsOwnPullRequestRatherThanAnAdditionToAnother(): void
    {
        $this->assertStringContainsString(
            '**Dependency work and SonarQube Cloud work are each their own pull',
            self::agentRules(),
            'AGENTS.md no longer says that the dependency and SonarCloud work are pull requests of their own, '
            . 'so the next agent may fold a lockfile bump into a pull request opened to fix an issue.',
        );
    }

    /**
     * A gate that cannot be made green is the one thing this step hands
     * back, and it has to be handed back explicitly — a major version bump
     * that takes the suite red is the maintainer's call, and they can only
     * make it if they hear about it.
     */
    public function testAGateThatCannotBeMadeGreenGoesBackToTheMaintainer(): void
    {
        $this->assertStringContainsString(
            'A gate you cannot make green is what this sends back',
            self::agentRules(),
            'AGENTS.md no longer says what to do with a gate that cannot be made green, which leaves '
            . 'silently skipping it and blocking on it equally defensible.',
        );
    }
}
