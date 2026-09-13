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
        public readonly ?NotificationService $notifications = null,
        private readonly ?TaskCapabilities $capabilities = null,
        /**
         * How fast the mailing lane may go right now
         * (Core\Mail\Transport\BulkCadence, ARCHITECTURE.md §8.106).
         *
         * Nullable for the same reason `$notifications` is: a test
         * double builds a context without one, and a handler must
         * degrade rather than fatal. It carries numbers and a provider's
         * name — never a credential; a relay's password is read in one
         * place, `TransportConfigurator`, and is on no object that
         * reaches a handler.
         *
         * On the context rather than rebuilt by each handler because a
         * handler is auto-resolved with `new $class()` and receives only
         * this object — the same reason `MailService` is here.
         */
        public readonly ?\Core\Mail\Transport\BulkCadence $bulkCadence = null
    ) {
    }

    /**
     * Another module's capability, published under its `Api\` namespace
     * and registered by the scheduler bootstrap — or null when it was
     * never registered, its providing module is disabled right now, or
     * this context carries no capabilities at all (a test double). A
     * handler treats null exactly like a nullable constructor dependency
     * on the HTTP path: the feature is simply unavailable
     * (ARCHITECTURE.md §7.5, applied to the scheduled path).
     *
     * Explicit and typed — `getOptional(LlmConnectorInterface::class)` —
     * never a free string, and never a generic container (see
     * TaskCapabilities' own docblock).
     *
     * @template T of object
     * @param class-string<T> $interface
     * @return T|null
     */
    public function getOptional(string $interface): ?object
    {
        return $this->capabilities?->resolve($interface);
    }

    /**
     * Whether a module is enabled right now — re-read on every call, since
     * the enabled set can change mid-request on the Modules page. False
     * when this context carries no capabilities (a test double): a handler
     * must degrade on "don't know" exactly as it degrades on "disabled".
     *
     * The supported replacement for the raw
     * `SELECT enabled FROM module_registry` workaround a handler once had
     * to invent (Modules\Calendar\Task\AutoCreateRetroHandler).
     */
    public function isModuleEnabled(string $moduleId): bool
    {
        return $this->capabilities !== null && $this->capabilities->isModuleEnabled($moduleId);
    }
}
