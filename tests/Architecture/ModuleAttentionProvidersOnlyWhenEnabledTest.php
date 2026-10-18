<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A module's attention points appear only while the module is enabled
 * (issue #718 § C: « Les points d'attention d'un module ne s'affichent que
 * pour un module activé »).
 *
 * Nothing in Core\Attention checks it, by design: the page runs whatever
 * is in `$attentionProviders`, and a module puts its contributor there
 * from inside its own `if ($isEnabled('…'))` block of public/index.php
 * (ARCHITECTURE.md §8.79). So the rule lives in where the line is written,
 * and this reads public/index.php to hold it: every
 * `new \Modules\X\…AttentionProvider(` must sit inside a block opened by
 * `$isEnabled('x')` — the module's own id, not merely some module's.
 * Moved outside, a disabled module would keep reporting on a feature the
 * unit switched off, which is the failure nobody notices from the page.
 */
class ModuleAttentionProvidersOnlyWhenEnabledTest extends TestCase
{
    public function testEveryModuleContributorIsBuiltInsideItsModulesEnabledBlock(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        $sites = self::providerSites($source);

        // The scan must keep finding them: a reader that stopped matching
        // would pass everything.
        $this->assertGreaterThanOrEqual(7, count($sites), 'the scan no longer finds the module contributors');

        $offenders = [];
        foreach ($sites as $site) {
            if (!in_array($site['module'], $site['enclosing'], true)) {
                $offenders[] = $site['class'] . ' (line ' . $site['line'] . ') is not inside $isEnabled(\''
                    . $site['module'] . '\') — enclosing: ' . ($site['enclosing'] === [] ? 'none' : implode(', ', $site['enclosing']));
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * @return list<array{class: string, module: string, line: int, enclosing: list<string>}>
     */
    private static function providerSites(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        /** @var list<?string> $stack the module id each open brace belongs to, or null */
        $stack = [];
        $pendingModule = null;
        $sites = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            // `$isEnabled('rental')` — remember it until the block opens.
            if (is_array($token) && $token[0] === T_VARIABLE && $text === '$isEnabled') {
                $j = self::next($tokens, $i);
                $k = self::next($tokens, $j);
                if ($tokens[$j] === '(' && is_array($tokens[$k]) && $tokens[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $pendingModule = trim($tokens[$k][1], '\'"');
                }
                continue;
            }

            if ($text === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $stack[] = $pendingModule;
                $pendingModule = null;
                continue;
            }
            if ($text === '}') {
                array_pop($stack);
                continue;
            }
            if ($text === ';') {
                // A guard used as a value (`$x = $isEnabled('a') ? …;`)
                // opens no block.
                $pendingModule = null;
                continue;
            }

            if (is_array($token) && $token[0] === T_NAME_FULLY_QUALIFIED
                && preg_match('/^\\\\Modules\\\\([A-Za-z]+)\\\\.*AttentionProvider$/', $text, $m) === 1
                && is_array($tokens[$i - 2] ?? null) && $tokens[$i - 2][0] === T_NEW
            ) {
                $sites[] = [
                    'class' => ltrim($text, '\\'),
                    'module' => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $m[1])),
                    'line' => $token[2],
                    'enclosing' => array_values(array_filter($stack, static fn(?string $id): bool => $id !== null)),
                ];
            }
        }

        return $sites;
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private static function next(array $tokens, int $i): int
    {
        do {
            $i++;
        } while (isset($tokens[$i]) && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true));

        return $i;
    }
}
