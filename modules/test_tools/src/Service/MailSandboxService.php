<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\TestTools\Service;

use Core\Config\SettingService;
use Core\File\EncryptedFileStorageService;
use Core\Journal\JournalService;
use Modules\TestTools\Repository\CapturedEmailRepository;

/**
 * The mail sandbox's business layer (ARCHITECTURE.md §8.61): the arm
 * switch, the browsable list, and the raw message a detail page shows.
 *
 * Nothing here touches $_POST or $_SESSION — the Controller reads the
 * request, this class decides.
 */
class MailSandboxService
{
    public const MODULE_ID = 'test_tools';

    /** The arm switch. Registered non-editable, toggled only from here. */
    public const SETTING_ARMED = 'mail_capture_armed';

    public function __construct(
        private CapturedEmailRepository $repository,
        private SettingService $settingService,
        private EncryptedFileStorageService $fileStorage,
        private JournalService $journalService
    ) {
    }

    /**
     * Whether capture is armed right now.
     *
     * Static so the composition root can ask the question before any of
     * this module's services exist — it has to decide whether to inject the
     * transport into MailService, and that happens long before a route is
     * ever resolved.
     */
    public static function isArmed(SettingService $settingService): bool
    {
        return (string) ($settingService->get(self::SETTING_ARMED, self::MODULE_ID) ?? '0') === '1';
    }

    public function armed(): bool
    {
        return self::isArmed($this->settingService);
    }

    /**
     * Arms or disarms the capture.
     *
     * Journaled at level `security` every single time, in both directions:
     * this switch changes the sending behaviour of the whole site, and an
     * operator finding no mail arriving needs the journal to say when it
     * was turned on and by whom. No address ever appears in the entry.
     */
    public function setArmed(bool $armed, ?int $userId = null): void
    {
        // setInternal(), not set(): the switch is deliberately registered
        // non-editable so it never renders as a row on Configuration >
        // Paramètres. It is toggled here and only here.
        $this->settingService->setInternal(self::SETTING_ARMED, $armed ? '1' : '0', self::MODULE_ID);

        $this->journalService->log(
            self::MODULE_ID,
            $armed ? 'mail_capture_armed' : 'mail_capture_disarmed',
            'security',
            $armed
                ? 'Capture des e-mails sortants activée : plus aucun message ne quitte le serveur.'
                : 'Capture des e-mails sortants désactivée : les messages repartent normalement.',
            [],
            $userId
        );
    }

    /**
     * @return array<int, CapturedEmail>
     */
    public function page(int $limit, int $offset, ?string $subject = null, ?string $recipient = null): array
    {
        return $this->repository->findPage($limit, $offset, $subject, $recipient);
    }

    public function count(?string $subject = null, ?string $recipient = null): int
    {
        return $this->repository->countAll($subject, $recipient);
    }

    public function find(int $id): ?CapturedEmail
    {
        return $this->repository->findById($id);
    }

    /**
     * The stored raw RFC 5322 message, or null when assembly had failed and
     * there is nothing to show.
     */
    public function rawMessage(CapturedEmail $email): ?string
    {
        if ($email->mimeFileId === null) {
            return null;
        }

        try {
            return $this->fileStorage->retrieve($email->mimeFileId);
        } catch (\RuntimeException) {
            // The row outlived its file (a manual deletion, a restored
            // backup). The sandbox says so rather than erroring out.
            return null;
        }
    }

    /**
     * Deletes a captured message: its metadata rows AND every encrypted
     * file they reference, never one without the other.
     */
    public function delete(int $id): void
    {
        foreach ($this->repository->findFileIds($id) as $fileId) {
            $this->fileStorage->delete($fileId);
        }

        $this->repository->delete($id);
    }
}
