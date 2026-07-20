<?php

declare(strict_types=1);

namespace Core\Scheduler;

use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalService;
use Core\Mail\MailService;
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
        public readonly string $storagePath
    ) {
    }
}
