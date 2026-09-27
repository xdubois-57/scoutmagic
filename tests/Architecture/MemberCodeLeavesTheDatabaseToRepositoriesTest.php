<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * `core/Member/` keeps its SQL and its decryption in its Repositories.
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
 * **What is allowed is named, not what is tolerated** (issue #629). In all
 * of `core/Member/`, recursively, only a `*Repository.php` file may call
 * `prepare(`, `->query(`, `->exec(`, `getPdo()`, `->decrypt(`,
 * `->encrypt(` or `->blindIndex(`. Until #629 the scan read
 * `core/Member/*Service.php` alone — not recursive, and only that suffix —
 * and six files fell outside it: the export's row builder decrypting
 * seventeen columns, the merge Service preparing eight statements, three
 * files deriving blind indexes, a task handler reaching for the PDO. A
 * guard that covers one filename suffix gives a confidence it does not
 * have.
 *
 * « Recursive except `Repository/` » would not have been the rule: most of
 * the Repositories of `core/Member/` sit at its root, beside the Services
 * (`SectionRosterRepository`, `MemberEmailRepository`,
 * `FeeEstimationRepository`…). The filename is what says a class is a
 * Repository here, so the filename is what the rule names.
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
final class MemberCodeLeavesTheDatabaseToRepositoriesTest extends TestCase
{
    /**
     * The floor. `assertSame([], $offenders)` is satisfied perfectly by a
     * reader that found no files at all, so the count of files read is
     * asserted separately — the failure mode every ratchet in this
     * repository is written against.
     */
    private const AT_LEAST_THIS_MANY_FILES_ARE_READ = 60;

    /*
     * **There is no exemption list any more, and that is what « done » meant**
     * (issue #551).
     *
     * There was one: `STILL_TO_MOVE`, naming four Services and the calls each
     * was allowed. It is worth saying what it did, because the shape is
     * reusable. Issue #413 named TWO Services — « les deux Services métier qui
     * lisent des données de membres » — and this guard, written for those two,
     * immediately reported four more. The rule was broken in three times as
     * many places as the ticket describing it knew about, which is what a rule
     * nobody checks does.
     *
     * The four were parked rather than fixed, because #413 already rewrote 240
     * construction sites across 159 files and four more read models would have
     * made one review round carry two unrelated pieces of reasoning. The list
     * was asserted in BOTH directions, so a file that stopped offending had to
     * leave it and a test failed until it did — it could only shrink, and
     * parking a fifth Service there cost a line the next reader would ask
     * about.
     *
     * It shrank to nothing. `FeeEstimationService` and `SectionRosterService`
     * gave their purpose strings back to the repositories that own the
     * encryption dependency; `UnitStaffSectionService` and
     * `SectionStaffAuthorizationService` gave their statements to
     * `Core\Member\Repository\UnitStaffSectionRepository` and
     * `StaffedSectionRepository`. The exemption machinery went with the last
     * line: an empty allowlist still teaches the next author that there is a
     * place to put a name.
     *
     * **And none came back when the scan was widened** (issue #629). The six
     * files the narrower scan could not see were fixed before this guard was
     * pointed at them, in the same change, so it arrived with nothing to
     * park.
     */

    public function testNothingButARepositoryInMemberPreparesStatements(): void
    {
        $offenders = [];
        $read = 0;

        foreach (self::nonRepositoriesInMember() as $path => $source) {
            ++$read;
            foreach (['prepare(', '->query(', '->exec(', 'getPdo()'] as $call) {
                if (!str_contains(self::withoutComments($source), $call)) {
                    continue;
                }
                $offenders[] = $path . ' uses ' . $call;
            }
        }

        $this->assertGreaterThanOrEqual(
            self::AT_LEAST_THIS_MANY_FILES_ARE_READ,
            $read,
            'the scan found almost no files to read, so its empty verdict means nothing'
        );
        $this->assertSame(
            [],
            $offenders,
            "a file of core/Member/ that is not a Repository talks to PDO. ARCHITECTURE.md §13: the\n"
            . "Repository layer is the only one that does. Move the statement into a *Repository.php:\n  "
            . implode("\n  ", $offenders) . "\n"
        );
    }

    public function testNothingButARepositoryInMemberCallsTheEncryptionService(): void
    {
        $offenders = [];

        foreach (self::nonRepositoriesInMember() as $path => $source) {
            $code = self::withoutComments($source);
            foreach (['->decrypt(', '->encrypt(', '->blindIndex('] as $call) {
                if (!str_contains($code, $call)) {
                    continue;
                }
                $offenders[] = $path . ' calls ' . $call;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "a file of core/Member/ that is not a Repository calls EncryptionService. SECURITY.md §5 keeps\n"
            . "that in one place so the encryption contexts have a single home:\n  "
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
     * The scan reaches below `core/Member/`'s root, and it reads what is not
     * a Service too.
     *
     * The two blind spots issue #629 found, pinned: a file in a
     * sub-directory, and a file whose name does not end in `Service`. The
     * floor is a count and cannot say which files were read: a scan that
     * lost one sub-directory, or that dropped the files named neither
     * Service nor Repository, would still clear it.
     */
    public function testTheScanReadsSubdirectoriesAndNonServices(): void
    {
        $read = array_keys(self::nonRepositoriesInMember());

        $this->assertContains('core/Member/Export/MemberExportRowBuilder.php', $read);
        $this->assertContains('core/Member/Task/CompressSectionDocumentHandler.php', $read);
        $this->assertContains('core/Member/MemberAccountResolver.php', $read);
        $this->assertNotContains(
            'core/Member/SectionRosterRepository.php',
            $read,
            'a Repository is where the SQL is supposed to be; reading it would report the fix as the offence'
        );
        $this->assertNotContains('core/Member/Repository/StaffedSectionRepository.php', $read);
    }

    /**
     * Every PHP file under `core/Member/`, recursively, except the
     * `*Repository.php` files — the one place the rule allows the calls.
     *
     * @return array<string, string> relative path => source
     */
    private static function nonRepositoriesInMember(): array
    {
        $root = self::repositoryRoot();
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/core/Member', \FilesystemIterator::SKIP_DOTS)
        );
        $sources = [];

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            if (str_ends_with($file->getFilename(), 'Repository.php')) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if ($source !== false) {
                $sources[str_replace($root . '/', '', $file->getPathname())] = $source;
            }
        }
        ksort($sources);

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
