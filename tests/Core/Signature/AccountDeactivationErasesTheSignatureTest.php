<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Signature;

use PHPUnit\Framework\TestCase;

/**
 * Every way an account is deactivated erases its personal signature (issue
 * #926, decision Q4).
 *
 * The erasure lives in `Core\Security\SuperAdminService::deactivate()`,
 * beside the journal entry the deactivation already owed, and
 * `Tests\Core\Security\SuperAdminServiceTest` proves it there. What that
 * test cannot see is a SECOND caller of
 * `UserAccountRepository::deactivate()` added tomorrow — a deactivation of
 * ordinary accounts, a clean-up task — which would withdraw the access and
 * leave the signature behind, silently, since an account is never deleted
 * and the foreign key's cascade never runs.
 *
 * So this is a tripwire on the source: the only production code calling
 * `deactivate()` on something named after an account is that service. A
 * new one fails here, with the decision to go and read; the fix is to go
 * through the service, or to erase the signature there too and add the
 * file below.
 */
final class AccountDeactivationErasesTheSignatureTest extends TestCase
{
    private const ERASING_CALLERS = ['core/Security/SuperAdminService.php'];

    public function testOnlyTheServiceThatErasesTheSignatureDeactivatesAnAccount(): void
    {
        $root = dirname(__DIR__, 3);
        $callers = [];

        foreach (['core', 'modules', 'public', 'scripts'] as $directory) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (preg_match('/(?:\$|->)\w*(?:[Uu]ser|[Aa]ccount)\w*->deactivate\(/', $source) === 1) {
                    $callers[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($callers);

        $this->assertSame(
            self::ERASING_CALLERS,
            $callers,
            "An account is deactivated somewhere other than SuperAdminService::deactivate(), which\n"
                . "erases the account's personal signature (issue #926, decision Q4). Go through that\n"
                . 'service, or erase the signature with PersonalSignatureService::eraseOnDeactivation().'
        );
    }

    public function testTheServiceReallyErasesIt(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/core/Security/SuperAdminService.php');

        $this->assertStringContainsString('->eraseOnDeactivation(', $source);
    }
}
