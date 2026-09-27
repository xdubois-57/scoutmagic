<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * `modules/registration/src/Service/` leaves its SQL to `Repository/`.
 *
 * The same rule `MemberCodeLeavesTheDatabaseToRepositoriesTest` checks
 * on `core/Member/` — ARCHITECTURE.md §13, « Repository is the only layer
 * that touches PDO » — on the module where issue #593 found it broken:
 * `PassageService` ran seven statements of its own and
 * `ReenrollmentRecipientService` one. They moved to
 * `Repository\PassageRosterRepository`.
 */
final class RegistrationServicesLeaveTheDatabaseToRepositoriesTest extends TestCase
{
    /** The floor: an empty verdict from a reader that found nothing means nothing. */
    private const AT_LEAST_THIS_MANY_FILES_ARE_READ = 15;

    /*
     * **There is no exemption list any more** (issue #646).
     *
     * There was one: `STILL_TO_MOVE`. Issue #593 named two Services, and
     * this guard, written for those two, reported six. The other four were
     * out of that ticket's scope, so they were parked the way `core/Member/`
     * once parked its own (issue #551), in a list asserted in BOTH
     * directions: a Service that stopped offending had to leave it, and one
     * that started could not join it quietly.
     *
     * It shrank to nothing. `ForecastService` and `SlotService` gave their
     * roster reads to `Repository\PassageRosterRepository`, which already
     * held the module's one definition of an animé; `ReconciliationService`
     * and `ExternalMailingListService` gave theirs — reads of every member a
     * Desk import produced, not only the animés — to a new
     * `Repository\ImportedMemberRepository`; and `SlotService`'s scout-year
     * label now comes from `Core\Config\ScoutYearService::findById()`. The
     * rows' decryption went with the statements. The machinery went with
     * the last line: an empty allowlist still teaches the next author that
     * there is a place to put a name.
     */

    /** Every Service moved so far, by name: none of them may take a `\PDO` again. */
    private const MOVED = [
        'PassageService' => 593,
        'ReenrollmentRecipientService' => 593,
        'ExternalMailingListService' => 646,
        'ForecastService' => 646,
        'ReconciliationService' => 646,
        'SlotService' => 646,
    ];

    public function testNoServiceTalksToPdo(): void
    {
        $offenders = [];
        $read = 0;

        foreach (self::services() as $path => $source) {
            ++$read;
            if (self::talksToPdo($source)) {
                $offenders[] = $path;
            }
        }

        $this->assertGreaterThanOrEqual(
            self::AT_LEAST_THIS_MANY_FILES_ARE_READ,
            $read,
            'the scan found almost no services to read, so its verdict means nothing'
        );

        $this->assertSame(
            [],
            $offenders,
            "a Service in modules/registration/ talks to PDO. ARCHITECTURE.md §13: the Repository layer is\n"
            . "the only one that does. Move the statement into modules/registration/src/Repository/:\n  "
            . implode("\n  ", $offenders) . "\n"
        );
    }

    /**
     * The Services issues #593 and #646 moved, by name. Not talking to PDO
     * is the rule above; not even TAKING one is what keeps the next
     * statement from being one line away.
     */
    public function testTheServicesAlreadyMovedStayMoved(): void
    {
        $services = self::services();

        foreach (self::MOVED as $name => $issue) {
            $path = 'modules/registration/src/Service/' . $name . '.php';
            $this->assertArrayHasKey($path, $services, $path . ' (issue #' . $issue . ') is no longer where it was');
            $this->assertFalse(self::talksToPdo($services[$path]), $path . ' talks to PDO again');
            $this->assertStringNotContainsString(
                '\\PDO $',
                self::withoutComments($services[$path]),
                $path . ' takes a PDO again (issue #' . $issue . ')'
            );
        }
    }

    /** A floor guards against a reader that finds nothing; only a known answer guards against one that approves everything. */
    public function testTheReaderSeesCodeAndNotProse(): void
    {
        $this->assertTrue(self::talksToPdo('<?php $stmt = $this->pdo->prepare(\'SELECT 1\');'));
        $this->assertFalse(self::talksToPdo(
            "<?php\n// This used to call \$this->pdo->prepare() and no longer does.\nreturn \$this->roster->find();"
        ));
    }

    private static function talksToPdo(string $source): bool
    {
        $code = self::withoutComments($source);
        foreach (['->prepare(', '->query(', '->exec(', 'getPdo()'] as $call) {
            if (str_contains($code, $call)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every `*Service.php` directly in `modules/registration/src/Service/`.
     *
     * @return array<string, string> relative path => source
     */
    private static function services(): array
    {
        $root = dirname(__DIR__, 2);
        $files = glob($root . '/modules/registration/src/Service/*Service.php');
        $sources = [];

        foreach ($files === false ? [] : $files as $file) {
            $source = file_get_contents($file);
            if ($source !== false) {
                $sources[str_replace($root . '/', '', $file)] = $source;
            }
        }

        return $sources;
    }

    /** Comments removed: a Service that explains the call it no longer makes would report itself. */
    private static function withoutComments(string $source): string
    {
        $kept = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    }
}
