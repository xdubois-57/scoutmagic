<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\Backup;
use PHPUnit\Framework\TestCase;

/**
 * A ratchet: **a `portable` backup must be built by
 * `createPortableBackup()`, not by the ordinary path.**
 *
 * The two entry points meet in one background task, and the branch that
 * separates them is a single ternary in `Task\CreateBackupHandler`. Lose
 * it — a refactor that "simplifies" the call, a merge that keeps the wrong
 * side — and a portable request produces a perfectly ordinary archive,
 * marked `portable` in the database, listed as such on the page, offered
 * for download under `sauvegarde-portable.zip`, and containing **none of
 * the keys that are its entire reason for existing**. No test fails, no
 * journal entry is wrong, and the operator learns on the day they try to
 * restore it on a new host, which is the one day this feature exists for.
 *
 * The check is textual, and for the reason its two siblings give
 * ({@see BackupPairReservationTest}, `BackupIntegrityWiringTest`): the
 * behavioural alternative is a runtime test of the handler against a live
 * database engine, and `Tests\Core\Maintenance\Task\CreateBackupHandlerTest`
 * runs on SQLite precisely because the real archive path needs a server.
 * The archive itself IS tested for real —
 * {@see PortableBackupArchiveTest} builds one against MariaDB and opens
 * it — so what is left uncovered is exactly the dispatch, and that is what
 * this file holds.
 */
final class PortableDispatchTest extends TestCase
{
    private const HANDLER = 'core/Maintenance/Task/CreateBackupHandler.php';

    /**
     * The handler's CODE, with every comment removed.
     *
     * The tokenizer rather than the raw file, and the first version of
     * this test is why: it read the file whole, and the class docblock
     * above the code mentions `createPortableBackup()` in prose. Every
     * assertion below would therefore have passed with the call itself
     * deleted and the comment describing it left behind — a test that
     * checks the documentation of the thing instead of the thing. That is
     * the same mistake, in the same file, that IT-05's review caught in
     * its ratchet and IT-04's in `GALLERY_TYPES`: what a piece of code is
     * CALLED, or says about itself, is not evidence of what it does.
     */
    private function handlerCode(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::HANDLER;
        $source = file_get_contents($path);
        $this->assertIsString($source, self::HANDLER . ' is unreadable — has the handler moved?');

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        $this->assertStringContainsString('createFullBackup', $code, 'this is not the handler this test means');

        return $code;
    }

    public function testTheHandlerStillRoutesThePortableTypeToItsOwnBuilder(): void
    {
        $code = $this->handlerCode();

        $this->assertStringContainsString(
            'createPortableBackup(',
            $code,
            'The background handler no longer calls createPortableBackup(). A portable request would now '
            . 'produce an ordinary archive — one recorded as portable, downloadable as portable, and '
            . 'carrying none of the keys that make it portable.'
        );
        $this->assertStringContainsString(
            'Backup::PORTABLE_TYPE',
            $code,
            'The handler no longer dispatches on the portable type by name.'
        );
    }

    /**
     * And the branch is on the type, not on something incidental.
     *
     * A condition testing anything else — a payload flag, the presence of
     * a passphrase — would be a second way of saying "portable" that the
     * controller and the retention family do not share, which is how the
     * three drift apart.
     */
    public function testTheBranchIsDecidedByTheTypeItself(): void
    {
        $code = $this->handlerCode();

        $branchAt = strpos($code, 'Backup::PORTABLE_TYPE');
        $buildAt = strpos($code, 'createPortableBackup(');

        $this->assertIsInt($branchAt);
        $this->assertIsInt($buildAt);
        $this->assertLessThan(
            $buildAt,
            $branchAt,
            'createPortableBackup() is called before anything checks the type — so it is called for every '
            . 'backup, or for none of the right ones.'
        );
    }

    /**
     * The type the controller writes is the type the handler dispatches on.
     *
     * Three layers name this type — the row, the branch, the family — and
     * the constant is what keeps them one name. A literal `'portable'`
     * typed into any of them is a backup nothing purges and nothing seals.
     */
    public function testTheOneNameIsAConstantEveryLayerReads(): void
    {
        $this->assertSame('portable', Backup::PORTABLE_TYPE);
        $this->assertContains(Backup::PORTABLE_TYPE, Backup::TYPES);
        $this->assertSame(
            \Core\Maintenance\BackupFamily::Portable,
            \Core\Maintenance\BackupFamily::tryFromType(Backup::PORTABLE_TYPE)
        );

        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/core/Http/Controller/MaintenanceController.php'
        );
        $this->assertStringContainsString(
            'Backup::PORTABLE_TYPE',
            $controller,
            'The controller writes the type as a literal instead of the constant the handler reads.'
        );
    }
}
