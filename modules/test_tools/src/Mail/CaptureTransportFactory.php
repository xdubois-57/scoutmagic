<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\TestTools\Mail;

use Core\Config\SettingService;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Module\InstallationProfile;
use Core\Module\ModuleRegistryRepository;
use Core\Security\EncryptionService;
use Modules\TestTools\Repository\CapturedEmailRepository;
use Modules\TestTools\Service\MailSandboxService;

/**
 * The one decision the composition root delegates: is outgoing mail
 * captured on THIS installation, right now? (ARCHITECTURE.md §8.61)
 *
 * MailService is wired long before modules load, so this cannot ask
 * ModuleManager whether the module is enabled — it reads the registry row
 * directly, which is exactly why the registry row is only one of three
 * conditions and never sufficient on its own.
 *
 * All three must hold:
 *
 *  1. the installation profile carries `reference_installation` or
 *     `local_installation` — a deploying unit's installation can never
 *     satisfy this, whatever is in its database;
 *  2. the `test_tools` module is enabled;
 *  3. the arm switch is on.
 *
 * A forged `module_registry` row alone therefore captures nothing. Living
 * in a factory rather than inline in public/index.php is what makes the
 * three conditions testable at all — nothing in the composition root is.
 */
final class CaptureTransportFactory
{
    /**
     * The flags that make this a machine where capturing mail is
     * legitimate. OR, like every `visible_when` list.
     *
     * @var array<int, string>
     */
    private const CAPTURE_FLAGS = [
        InstallationProfile::FLAG_REFERENCE_INSTALLATION,
        InstallationProfile::FLAG_LOCAL_INSTALLATION,
    ];

    public static function shouldCapture(
        InstallationProfile $profile,
        ModuleRegistryRepository $registryRepo,
        SettingService $settingService
    ): bool {
        if (!$profile->hasAny(self::CAPTURE_FLAGS)) {
            return false;
        }

        $registry = $registryRepo->findByModuleId(MailSandboxService::MODULE_ID);
        if ($registry === null || !$registry['enabled']) {
            return false;
        }

        return MailSandboxService::isArmed($settingService);
    }

    /**
     * The transport to hand MailServiceFactory, or null to keep the
     * default one — i.e. to keep sending mail exactly as before.
     */
    public static function forInstallation(
        InstallationProfile $profile,
        ModuleRegistryRepository $registryRepo,
        SettingService $settingService,
        \PDO $pdo,
        EncryptionService $encryption,
        string $storagePath
    ): ?CaptureTransport {
        if (!self::shouldCapture($profile, $registryRepo, $settingService)) {
            return null;
        }

        return new CaptureTransport(
            new CapturedEmailRepository($pdo, $encryption),
            new EncryptedFileStorageService(new FileRepository($pdo), $encryption, $storagePath)
        );
    }
}
