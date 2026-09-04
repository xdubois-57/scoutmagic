<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The one gate no local command reproduces has to stay written down.
 *
 * A change under `public/assets/js/` shipped two HIGH `js/xss-through-dom`
 * alerts with PHPStan, PHPUnit, `npm run typecheck`, Vitest and the
 * end-to-end suite all green: a `data-file-viewer` attribute read straight
 * into `image.src` and `download.href`, both navigable sinks, one of them
 * handed to `win.open()` by the same file. Reachable, not theoretical —
 * any page rendering that attribute from user-controlled content could put
 * `javascript:…` into a sink that runs on click. It was found days later,
 * by hand, because a release refused.
 *
 * The correction is an instruction rather than code, because the defect is
 * not something a running program can notice about itself. An instruction
 * that nothing defends is one an editing pass removes without anybody
 * seeing it go, which is why this test exists at all: it does not check
 * that anybody obeyed the rule — no test could — only that the rule is
 * still there to obey, in the file every agent working on this repository
 * reads.
 */
final class CodeQlRuleIsWrittenDownTest extends TestCase
{
    private static function agentRules(): string
    {
        $path = dirname(__DIR__, 2) . '/AGENTS.md';
        $contents = file_get_contents($path);
        self::assertIsString($contents, 'AGENTS.md is unreadable');

        return $contents;
    }

    public function testTheRuleHasItsOwnSection(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            '## CodeQL',
            $rules,
            'AGENTS.md no longer tells an agent to check code scanning after touching JavaScript — '
            . 'the one gate nothing run locally reproduces'
        );
        $this->assertStringContainsString('code-scanning/alerts', $rules);
    }

    /**
     * The fallback matters as much as the rule.
     *
     * An agent whose token was never granted `security_events` gets a bare
     * `403` from that endpoint. Without a written fallback the honest
     * outcome — say what was and was not verified, and hand the human the
     * Security tab — is left to improvisation, and improvisation under a
     * 403 reads as "checked, nothing found".
     */
    public function testTheRuleSaysWhatToDoWhenTheApiRefuses(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString('403', $rules);
        $this->assertStringContainsString('check-runs', $rules);
    }

    /**
     * The sentence that would have prevented the alert, kept verbatim
     * because it is the part that is easy to talk yourself out of.
     */
    public function testTheRuleSaysThatYourOwnTemplateIsNotATrustBoundary(): void
    {
        $this->assertStringContainsString(
            'not safe because it came from your own template',
            self::agentRules()
        );
    }

    public function testThePrChecklistPointsAtIt(): void
    {
        // The checklist is what gets read before submitting; a rule only
        // in a later section is a rule found after the fact.
        $rules = self::agentRules();
        $checklist = substr($rules, 0, (int) strpos($rules, '## Exception messages'));

        $this->assertStringContainsString('CodeQL', $checklist);
    }
}
