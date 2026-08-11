<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Scheduler;

use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Notification\NotificationService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;

class TaskContext
{
    public function __construct(
        public readonly Connection $connection,
        public readonly EncryptionService $encryption,
        public readonly MailService $mailService,
        public readonly JournalService $journal,
        public readonly SettingService $settings,
        public readonly UserAccountRepository $userAccounts,
        public readonly string $storagePath,
        public readonly ?NotificationService $notifications = null
    ) {
    }
}
