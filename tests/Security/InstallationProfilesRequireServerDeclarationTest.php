<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Security;

use Core\Module\InstallationProfile;
use PHPUnit\Framework\TestCase;

final class InstallationProfilesRequireServerDeclarationTest extends TestCase
{
    public function testTheProductionTemplateGrantsNoInstallationCapabilities(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php.dist';

        self::assertSame([], $config['installation_profile']);
        self::assertSame([], InstallationProfile::resolve($config['base_url'], $config['base_url'])->all());
    }

    public function testTheUnitTestBootstrapDeclaresItsFixtureCapabilities(): void
    {
        self::assertSame(InstallationProfile::KNOWN_FLAGS, SCOUTMAGIC_TEST_INSTALLATION_PROFILE);
    }

    public function testBrowserAndDastConfigurationsExplicitlyDeclareTheirProfiles(): void
    {
        if (!defined('E2E_SUPPORT_TEST')) {
            define('E2E_SUPPORT_TEST', true);
        }
        require_once dirname(__DIR__, 2) . '/scripts/e2e-support.php';
        $root = sys_get_temp_dir() . '/installation-profile-' . bin2hex(random_bytes(4));
        mkdir($root, 0700);
        $previous = getenv('E2E_TRUST_FORWARDED_PROTO');
        try {
            foreach (['0', '1'] as $tls) {
                putenv('E2E_TRUST_FORWARDED_PROTO=' . $tls);
                e2eWriteAppConfig($root, 8123);
                $config = require $root . '/config/app.php';

                self::assertSame(['local_installation', 'statistics_receiver'], $config['installation_profile']);
                self::assertSame($tls === '1', $config['https_required']);
            }
        } finally {
            putenv($previous === false ? 'E2E_TRUST_FORWARDED_PROTO' : 'E2E_TRUST_FORWARDED_PROTO=' . $previous);
            @unlink($root . '/config/app.php');
            @rmdir($root . '/config');
            @rmdir($root);
        }
    }
}
