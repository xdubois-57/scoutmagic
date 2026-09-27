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
 *
 * **Four more Services still break it, and are named below rather than
 * fixed here.** Issue #593 named two; this guard, written for those two,
 * reports six. The other four are out of that ticket's scope, so they are
 * parked the way `core/Member/` once parked its own (issue #551): the list
 * is asserted in BOTH directions, so a Service that stops offending must
 * leave it and one that starts cannot join it quietly. It can only shrink.
 */
final class RegistrationServicesLeaveTheDatabaseToRepositoriesTest extends TestCase
{
    /** The floor: an empty verdict from a reader that found nothing means nothing. */
    private const AT_LEAST_THIS_MANY_FILES_ARE_READ = 15;

    /**
     * Services that still prepare statements of their own, to be moved.
     * Removing a line here is the whole point; adding one is not.
     */
    private const STILL_TO_MOVE = [
        'modules/registration/src/Service/ExternalMailingListService.php',
        'modules/registration/src/Service/ForecastService.php',
        'modules/registration/src/Service/ReconciliationService.php',
        'modules/registration/src/Service/SlotService.php',
    ];

    public function testNoServiceTalksToPdoExceptThoseStillToMove(): void
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

        $newOffenders = array_values(array_diff($offenders, self::STILL_TO_MOVE));
        $this->assertSame(
            [],
            $newOffenders,
            "a Service in modules/registration/ talks to PDO. ARCHITECTURE.md §13: the Repository layer is\n"
            . "the only one that does. Move the statement into modules/registration/src/Repository/:\n  "
            . implode("\n  ", $newOffenders) . "\n"
        );

        $moved = array_values(array_diff(self::STILL_TO_MOVE, $offenders));
        $this->assertSame(
            [],
            $moved,
            "these Services no longer talk to PDO — take them off STILL_TO_MOVE:\n  " . implode("\n  ", $moved) . "\n"
        );
    }

    /** The two Services issue #593 moved, by name: the list above must never grow back to them. */
    public function testTheServicesIssue593MovedStayMoved(): void
    {
        $services = self::services();

        foreach (['PassageService', 'ReenrollmentRecipientService'] as $name) {
            $path = 'modules/registration/src/Service/' . $name . '.php';
            $this->assertArrayHasKey($path, $services);
            $this->assertFalse(self::talksToPdo($services[$path]), $path . ' talks to PDO again');
            $this->assertStringNotContainsString('\\PDO $pdo', $services[$path], $path . ' takes a PDO again');
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
