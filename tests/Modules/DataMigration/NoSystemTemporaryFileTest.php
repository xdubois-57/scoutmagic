<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration;

use PHPUnit\Framework\TestCase;

/**
 * Issue #1259: nothing of the data migration module writes a copy in the
 * system's temporary directory. A copy there is deleted only by a
 * `finally`, which a request killed by a timeout or its memory limit never
 * reaches, and nothing else ever looks there — while the archive's
 * attachments and photos are the unit's personal data. Every working copy
 * goes through `ImportContext::temporaryFile()`, under
 * `storage/temp/data_migration/`, which the purges sweep.
 *
 * Read from the tokens, so a mention in a comment is not a call.
 */
final class NoSystemTemporaryFileTest extends TestCase
{
    private const FORBIDDEN = ['sys_get_temp_dir', 'tempnam', 'tmpfile'];

    public function testNoSourceFileOfTheModuleCallsTheSystemsTemporaryDirectory(): void
    {
        $root = dirname(__DIR__, 3) . '/modules/data_migration/src';
        $calls = [];
        $scanned = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS))
            as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $scanned++;
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && $token[0] === T_STRING && in_array(strtolower($token[1]), self::FORBIDDEN, true)) {
                    $calls[] = substr($file->getPathname(), strlen($root) + 1) . ':' . $token[2] . ' ' . $token[1];
                }
            }
        }

        $this->assertGreaterThan(30, $scanned, 'the scan reads the module');
        $this->assertSame([], $calls, 'Use ImportContext::temporaryFile() instead.');
    }
}
