<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * `core/Member/` keeps its SQL and its decryption in `Repository/`.
 *
 * `ARCHITECTURE.md` §13 and `SECURITY.md` §1 say « Repository is the only
 * layer that touches PDO », and §5 « only Repositories call
 * EncryptionService ». Both rules were written down and neither was
 * checked: `MemberService` prepared three statements and `SectionService`
 * fifteen, and between them they decrypted eighteen columns from the
 * Service layer (issue #413).
 *
 * **Nothing was exploitable, and that is exactly why it lasted.** Every
 * statement was prepared, no parameter concatenated. What a layering
 * violation costs is not an incident, it is the next person going to
 * `core/Member/Repository/` for code that is not there — which is how the
 * two hydration paths came to build different `MemberProfile`s without
 * anybody noticing.
 *
 * The rule is checked on **`core/Member/` only**, deliberately. Elsewhere
 * in `core/` a good twenty classes hold a `\PDO` — `Core\Database\*`,
 * `Core\Scheduler\CronPassLock`, `Core\Maintenance\InstallLock`,
 * `Core\Security\LoginThrottler` — and they are infrastructure whose whole
 * subject IS the database; routing them through a Repository would buy
 * nothing. Widening this test to all of `core/` would therefore need an
 * allowlist of exactly those, and an allowlist is the thing that quietly
 * grows. `Core\Security\RoleResolver` and `Core\Security\AuthService` are
 * the genuinely arguable middle, and issue #413 puts them out of scope on
 * purpose.
 */
final class MemberServicesLeaveTheDatabaseToRepositoriesTest extends TestCase
{
    /**
     * The floor. `assertSame([], $offenders)` is satisfied perfectly by a
     * reader that found no files at all, so the count of files read is
     * asserted separately — the failure mode every ratchet in this
     * repository is written against.
     */
    private const AT_LEAST_THIS_MANY_FILES_ARE_READ = 8;

    public function testNoServiceInMemberPreparesItsOwnStatements(): void
    {
        $offenders = [];
        $read = 0;

        foreach (self::servicesInMember() as $path => $source) {
            ++$read;
            foreach (['prepare(', '->query(', '->exec(', 'getPdo()'] as $call) {
                if (str_contains(self::withoutComments($source), $call)) {
                    $offenders[] = $path . ' uses ' . $call;
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            self::AT_LEAST_THIS_MANY_FILES_ARE_READ,
            $read,
            'the scan found almost no services to read, so its empty verdict means nothing'
        );
        $this->assertSame(
            [],
            $offenders,
            "a Service in core/Member/ talks to PDO. ARCHITECTURE.md §13: the Repository layer is the\n"
            . "only one that does. Move the statement into core/Member/Repository/:\n  "
            . implode("\n  ", $offenders) . "\n"
        );
    }

    public function testNoServiceInMemberDecryptsForItself(): void
    {
        $offenders = [];

        foreach (self::servicesInMember() as $path => $source) {
            $code = self::withoutComments($source);
            foreach (['->decrypt(', '->encrypt(', '->blindIndex('] as $call) {
                if (str_contains($code, $call)) {
                    $offenders[] = $path . ' calls ' . $call;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "a Service in core/Member/ calls EncryptionService. SECURITY.md §5 keeps that in one place so\n"
            . "the encryption contexts have a single home:\n  "
            . implode("\n  ", $offenders) . "\n"
        );
    }

    /**
     * And the repositories the rule points at still exist.
     *
     * A rule whose subject was renamed away is a rule that quietly stopped
     * applying: with no `Repository/` directory, both assertions above pass
     * on a `core/Member/` that talks to PDO from everywhere.
     */
    public function testTheRepositoriesTheRulePointsAtExist(): void
    {
        $directory = self::repositoryRoot() . '/core/Member/Repository';
        $this->assertDirectoryExists($directory);

        $files = glob($directory . '/*.php');
        $this->assertNotFalse($files);
        $this->assertGreaterThanOrEqual(
            3,
            count($files),
            'core/Member/Repository/ has lost classes, so the SQL this rule banishes went somewhere else'
        );
    }

    /**
     * The reader recognises what it must, on literal source.
     *
     * A floor guards against a reader that finds nothing; only a fixture
     * with a known answer guards against one that approves everything.
     */
    public function testTheReaderSeesCodeAndNotProse(): void
    {
        $code = <<<'PHP'
            $stmt = $this->connection->getPdo()->prepare('SELECT 1');
            PHP;
        $prose = <<<'PHP'
            // This used to call $pdo->prepare('SELECT 1') and no longer does.
            return $this->sections->findById($id);
            PHP;

        $this->assertStringContainsString('prepare(', self::withoutComments($code));
        $this->assertStringNotContainsString(
            'prepare(',
            self::withoutComments($prose),
            'a comment explaining the forbidden call reads as the call itself'
        );
    }

    /**
     * Every `*Service.php` directly in `core/Member/`.
     *
     * Not recursive: `core/Member/Repository/` is where the SQL is supposed
     * to be, and reading it here would report the fix as the offence.
     *
     * @return array<string, string> relative path => source
     */
    private static function servicesInMember(): array
    {
        $files = glob(self::repositoryRoot() . '/core/Member/*Service.php');
        $sources = [];

        foreach ($files === false ? [] : $files as $file) {
            $source = file_get_contents($file);
            if ($source !== false) {
                $sources[str_replace(self::repositoryRoot() . '/', '', $file)] = $source;
            }
        }

        return $sources;
    }

    /**
     * Comments removed, their newlines kept.
     *
     * Stripped because a Service that explains the call it no longer makes
     * would report itself; the newlines stay because a line number that
     * points into a file nobody has is worse than none.
     */
    private static function withoutComments(string $source): string
    {
        $fragment = !str_contains($source, '<?php');
        $kept = '';

        foreach (token_get_all($fragment ? '<?php ' . $source : $source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $kept .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $fragment ? substr($kept, strlen('<?php ')) : $kept;
    }

    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
