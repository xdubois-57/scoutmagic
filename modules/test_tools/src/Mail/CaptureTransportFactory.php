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
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailTransportInterface;
use Core\Mail\PhpMailerTransport;
use Core\Mail\SuspendedMailTransport;
use Core\Module\InstallationProfile;
use Core\Module\ModuleRegistryRepository;
use Core\Security\EncryptionService;
use Core\Security\SandboxLossState;
use Modules\TestTools\Repository\CapturedEmailRepository;
use Modules\TestTools\Service\MailSandboxService;

/**
 * The one decision the composition root delegates: is outgoing mail
 * captured on THIS installation, right now? (ARCHITECTURE.md §8.63)
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
 *
 * A fourth setting rides along but is **not** a fourth condition: whether
 * the sign-in link is exempted from the capture
 * (`mail_capture_deliver_magic_links`). It never decides whether capture
 * happens, only what one category of message does once it has — so it is
 * read here and handed to the transport, and it is deliberately absent
 * from shouldCapture().
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
     * What the composition roots hand MailTransportFactory::build(): the
     * blocking transport while the capture is armed and no longer acting
     * (issue #1469), otherwise forInstallation()'s answer unchanged.
     *
     * The caller has already recorded this request's loss reason on
     * $lossState (Modules\TestTools\Sandbox\SandboxLoss); this only reads
     * the outcome, so that a lost capture blocks rather than falling
     * through to null — which is « send for real ». While blocked the
     * sign-in link still leaves only if the capture was letting it leave
     * (Core\Mail\SuspendedMailTransport).
     *
     * **That one recorded answer is the whole decision**: the profile and
     * the module are not read a second time. Asking shouldCapture() again
     * here would read the registry row again, and a module switched off
     * between the two reads would find neither a loss (the first read saw
     * it on) nor a capture (the second saw it off) — null, real delivery.
     * With no loss recorded, an armed switch therefore means the capture
     * holds, and the capture is built.
     */
    public static function transportFor(
        SettingService $settingService,
        SandboxLossState $lossState,
        \PDO $pdo,
        EncryptionService $encryption,
        string $storagePath
    ): ?MailTransportInterface {
        if ($lossState->isBlocked(SandboxLossState::CHANNEL_MAIL)) {
            return new SuspendedMailTransport(
                MailSandboxService::deliversMagicLinks($settingService) ? new PhpMailerTransport() : null
            );
        }

        if ($lossState->reason(SandboxLossState::CHANNEL_MAIL) !== null) {
            // Lost and released: a superadmin reopened real sending.
            return null;
        }

        if (!MailSandboxService::isArmed($settingService)) {
            return null;
        }

        return self::captureTransport($settingService, $pdo, $encryption, $storagePath);
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

        return self::captureTransport($settingService, $pdo, $encryption, $storagePath);
    }

    private static function captureTransport(
        SettingService $settingService,
        \PDO $pdo,
        EncryptionService $encryption,
        string $storagePath
    ): CaptureTransport {
        return new CaptureTransport(
            new CapturedEmailRepository($pdo, $encryption),
            new EncryptedFileStorageService(new FileRepository($pdo), $encryption, $storagePath),
            // The transport MailService would have used had none of this
            // been wired: the one exempted category goes out through
            // exactly that path, not through a second delivery mechanism
            // that could drift from it.
            new PhpMailerTransport(),
            MailSandboxService::deliversMagicLinks($settingService),
            // Only ever written to on one path: a sign-in link that left
            // the server and could not be filed (CaptureTransport::
            // journalUnfiledDelivery()).
            new JournalService(new JournalRepository($pdo))
        );
    }
}
