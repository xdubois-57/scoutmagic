<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * No application code touches `$_SESSION`; `Core\Security\SessionStore`
 * is the only place that does.
 *
 * **Why this is a rule and not a preference.** `public/index.php` calls
 * `session_write_close()` early — before the database connection, so a
 * slow request does not hold the session file's lock for its whole
 * duration (ARCHITECTURE.md §8.20). After it, `session_status()` reports
 * `PHP_SESSION_NONE` for the rest of the request while `$_SESSION` stays
 * readable in memory. Two mistakes follow, and the codebase has made
 * both:
 *
 * - a write guarded on `session_status() === PHP_SESSION_ACTIVE` never
 *   runs at all;
 * - an unguarded write runs and is never persisted.
 *
 * Neither raises anything. `CsrfGuard`, `FlashMessage`, `WebAuthnService`
 * and `SetupController` each lost a write this way before `SessionStore`
 * existed, and the news form's Post/Redirect/Get made the first mistake
 * again on this very branch: the confirmation page found nothing in the
 * session and sent the family back to the article having taken their
 * answer. Its unit tests could not see it — they call the controller
 * directly, with a session nobody closed — and the browser tier caught
 * it on the first run.
 *
 * A rule that only lives in a docblock is a rule the next writer meets
 * after the bug. This is the same rule, where the writer meets it first.
 *
 * The tokenizer, not a regex: `$_SESSION` inside a comment is a dozen
 * services correctly documenting that they never read it, and that
 * documentation must not be what fails this test.
 *
 * **One assertion, deliberately.** The obvious companion — « nothing
 * gates on `PHP_SESSION_ACTIVE` » — was written and removed. Every use of
 * that constant left in the codebase is `SessionManager::isActive()` and
 * the two « close it if it is open » guards around
 * `session_write_close()` in `public/index.php`: the opposite direction,
 * and correct. The dangerous shape is a `$_SESSION` WRITE gated on it,
 * and the assertion below already makes that unreachable by forbidding
 * the write itself. A second test whose whole content is an allowlist of
 * legitimate uses is a test that fails for the wrong reason first, and
 * this repository has written down what that costs (`.claude/skills/
 * steward/SKILL.md`: a guard that cries wolf is a guard people learn to
 * ignore).
 */
class SessionWritesGoThroughSessionStoreTest extends TestCase
{
    /** The one file allowed to touch it — the whole point of it existing. */
    private const OWNER = 'core/Security/SessionStore.php';

    public function testNothingButSessionStoreTouchesTheSuperglobal(): void
    {
        $offenders = [];

        foreach (self::phpFiles() as $relativePath => $absolutePath) {
            if ($relativePath === self::OWNER) {
                continue;
            }

            foreach (self::sessionSuperglobalLines($absolutePath) as $line) {
                $offenders[] = "{$relativePath}:{$line}";
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "These touch \$_SESSION outside Core\\Security\\SessionStore:\n  "
            . implode("\n  ", $offenders)
            . "\nUse SessionStore::get()/set()/remove(). public/index.php closes the session early, so a direct"
            . " write is silently lost and a write guarded on PHP_SESSION_ACTIVE never runs (ARCHITECTURE.md §8.20)."
        );
    }

    /**
     * `$_SESSION` occurrences that are real code, as line numbers. A
     * mention in a comment or a docblock is not one.
     *
     * @return list<int>
     */
    private static function sessionSuperglobalLines(string $path): array
    {
        $lines = [];
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$_SESSION') {
                $lines[] = $token[2];
            }
        }

        return $lines;
    }

    /**
     * @return array<string, string> repo-relative path => absolute path
     */
    private static function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach (['core', 'modules', 'public'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $absolute = $file->getPathname();
                    $files[str_replace($root . '/', '', $absolute)] = $absolute;
                }
            }
        }

        ksort($files);

        return $files;
    }
}
