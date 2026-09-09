<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The language rule has three halves that an editing pass would each shorten
 * away for a different reason, and losing any one of them puts English back
 * into the history.
 *
 * WHAT WENT WRONG. `AGENTS.md` § Language said, from the beginning,
 * "commits, PR titles and descriptions: **English**". Every agent obeyed it,
 * which is exactly why English kept appearing in a log the maintainer reads
 * in French — the discipline was fine, the file was wrong. It was corrected
 * on 2026-09-09 on the maintainer's instruction. A rule that spent that long
 * saying the opposite of what was wanted is precisely the rule to pin: the
 * next well-meaning edit that "restores consistency" with the English code
 * would undo it silently, and nothing would go red.
 *
 * THE EXCEPTION IS PINNED TOO, and for the opposite reason. A reply on a
 * review thread matches the language of the thread
 * (`.claude/skills/steward/SKILL.md`), because reviews arrive in English.
 * That carve-out reads like an inconsistency to anybody tidying the section,
 * and deleting it would put this file back into contradiction with the
 * skill `CLAUDE.md` names as the authority on pull request events — which is
 * how it was caught in the first place, on the very pull request that
 * introduced the rule.
 *
 * THE MNEMONIC IS PINNED because it is the line people actually remember,
 * and its first draft said the opposite of the rule it summarised ("the code
 * is English, everything said about the code is French" — a docblock is said
 * about the code, and stays English). A summary that contradicts its own
 * section is worse than no summary.
 *
 * Like Tests\Architecture\AutoMergeRuleIsWrittenDownTest and
 * Tests\Architecture\CodeQlRuleIsWrittenDownTest, this checks only that the
 * rule is still there to obey. No test can check that anybody obeyed it —
 * a commit message's language is not something the build can read.
 */
final class CommitLanguageRuleIsWrittenDownTest extends TestCase
{
    private static function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $contents = file_get_contents($path);
        self::assertIsString($contents, $relativePath . ' is unreadable');

        return $contents;
    }

    private static function agentRules(): string
    {
        return self::read('AGENTS.md');
    }

    public function testEverythingWrittenAboutAChangeIsFrench(): void
    {
        $this->assertStringContainsString(
            'Everything written *about* a change: French.',
            self::agentRules(),
            'AGENTS.md no longer requires French for commit messages, PR text and release notes'
        );
    }

    /**
     * The three carriers, named one by one: a rule that survives as a slogan
     * while losing its list is a rule each reader scopes for themselves.
     */
    public function testTheRuleNamesWhatItCovers(): void
    {
        $rules = self::agentRules();

        foreach ([
            'Commit messages',
            'pull request titles and descriptions',
            'release notes',
        ] as $carrier) {
            $this->assertStringContainsString(
                $carrier,
                $rules,
                "AGENTS.md § Language no longer names {$carrier} as covered by the French rule"
            );
        }
    }

    /**
     * Deleting this puts AGENTS.md back into contradiction with the steward
     * skill, which `CLAUDE.md` names as the authority on pull request events.
     */
    public function testTheReviewThreadExceptionSurvives(): void
    {
        $this->assertStringContainsString(
            'A reply on a pull request review thread is the one exception',
            self::agentRules(),
            'the review-thread carve-out is gone — AGENTS.md now contradicts the steward skill'
        );
    }

    /**
     * Both files point at each other on purpose. Either pointer going away
     * leaves the next reader with one half of a rule that has two.
     */
    public function testBothFilesStillCiteEachOther(): void
    {
        $this->assertStringContainsString(
            '.claude/skills/steward/SKILL.md',
            self::agentRules(),
            'AGENTS.md no longer points at the steward skill for thread replies'
        );

        $this->assertStringContainsString(
            'AGENTS.md',
            self::read('.claude/skills/steward/SKILL.md'),
            'the steward skill no longer points back at AGENTS.md § Language'
        );
    }

    /**
     * The summary line, kept verbatim because its first draft contradicted
     * the section it summarises.
     */
    public function testTheMnemonicStillSaysCommentsAreEnglish(): void
    {
        $this->assertStringContainsString(
            'the code
and its comments are English, everything written *about a change* is
French.',
            self::agentRules(),
            'the one-line summary of the language split is gone or reworded — check it still '
            . 'says comments are English, which its first draft got backwards'
        );
    }
}
