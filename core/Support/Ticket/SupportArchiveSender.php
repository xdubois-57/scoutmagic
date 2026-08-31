<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Ticket;

use Core\Config\SettingService;
use Core\File\EncryptedFileStorageService;
use Core\Journal\JournalService;
use Core\Support\SupportPackageState;

/**
 * Transmits the diagnostic archive of a ticket already sent
 * (roadmap IT-26).
 *
 * **Nothing here happens without an explicit act.** The archive is
 * generated on the administrator's own request, its contents are listed
 * on screen in French with its size, and this service refuses to send
 * anything unless the acknowledgement checkbox came back ticked — checked
 * **here**, on the server, not merely in the browser, because a checkbox
 * enforced only in the page is a decoration.
 *
 * **A failure never costs the ticket.** The ticket was created by its own
 * call and stands whatever happens to this one; what is recorded locally
 * is « archive non transmise » and a retry, which is the entire reason
 * the two are separate calls.
 *
 * **What travels is what was already on disk.** The archive is read back
 * through `EncryptedFileStorageService` — secrets were redacted when it
 * was built, and nothing here rebuilds or re-reads a collector.
 */
class SupportArchiveSender
{
    /** When the last archive left, for the « transmise » line. */
    public const ARCHIVE_SENT_AT_SETTING = 'support_last_ticket_archive_sent_at';
    /** Which ticket it was attached to, so a later ticket reads as untransmitted. */
    public const ARCHIVE_REFERENCE_SETTING = 'support_last_ticket_archive_reference';

    public const FAILURE_NOT_ACKNOWLEDGED = 'not_acknowledged';
    public const FAILURE_NO_TICKET = 'no_ticket';
    public const FAILURE_NO_ARCHIVE = 'no_archive';
    public const FAILURE_UNREADABLE_ARCHIVE = 'unreadable_archive';
    public const FAILURE_REFUSED = 'refused';
    public const FAILURE_UNREACHABLE = 'unreachable';

    public function __construct(
        private SettingService $settingService,
        private TicketIdentityService $identityService,
        private EncryptedFileStorageService $storage,
        private ArchiveTransportInterface $transport,
        private JournalService $journalService,
        private string $appVersion
    ) {
    }

    /**
     * @param string $reference the ticket the archive belongs to
     * @param bool $acknowledged what the administrator ticked
     */
    public function send(string $reference, bool $acknowledged): SupportTicketResult
    {
        if (!$acknowledged) {
            return SupportTicketResult::failed(self::FAILURE_NOT_ACKNOWLEDGED);
        }

        if ($reference === '') {
            return SupportTicketResult::failed(self::FAILURE_NO_TICKET);
        }

        $guard = $this->identityService->firstFailingGuard();
        if ($guard !== null) {
            return SupportTicketResult::failed($guard);
        }

        $fileId = (int) ($this->settingService->get(SupportPackageState::FILE_ID) ?? '0');
        if ($fileId <= 0) {
            return SupportTicketResult::failed(self::FAILURE_NO_ARCHIVE);
        }

        try {
            $bytes = $this->storage->retrieve($fileId);
        } catch (\Throwable) {
            return SupportTicketResult::failed(self::FAILURE_UNREADABLE_ARCHIVE);
        }

        $identity = $this->identityService->ensureIdentity();
        if ($identity === null) {
            return SupportTicketResult::failed(SupportTicketSender::FAILURE_NO_IDENTITY);
        }

        $endpoint = $this->identityService->endpoint();
        if ($endpoint === null) {
            return SupportTicketResult::failed(TicketIdentityService::GUARD_NO_DESTINATION);
        }

        try {
            $response = $this->transport->postArchive(
                $endpoint . '/' . rawurlencode($reference) . '/archive',
                $bytes,
                $identity->secret,
                'ScoutMagic/' . $this->appVersion . ' (+support-archive)'
            );
        } catch (\Throwable) {
            return SupportTicketResult::failed(self::FAILURE_UNREACHABLE);
        }

        if (!$response->isSuccessful()) {
            return SupportTicketResult::failed(self::FAILURE_UNREACHABLE);
        }

        $answer = json_decode((string) $response->body, true);
        if (!is_array($answer) || ($answer['status'] ?? '') !== 'accepted') {
            return SupportTicketResult::failed(self::FAILURE_REFUSED);
        }

        $this->writeSetting(self::ARCHIVE_REFERENCE_SETTING, $reference);
        $this->writeSetting(self::ARCHIVE_SENT_AT_SETTING, (new \DateTimeImmutable())->format('Y-m-d H:i:s'));

        // The transmission itself is worth a `security` entry rather than
        // an `info` one: this is the moment a unit's diagnostics — server
        // logs with visitor IP addresses among them — left the
        // installation. Whoever audits this site later should find it
        // beside the other decisions of that weight, not among the
        // routine ones.
        $this->journalService->log(
            'core',
            'support_archive_transmitted',
            'security',
            "Archive de diagnostic transmise au support pour le ticket {$reference}",
            ['reference' => $reference, 'bytes' => strlen($bytes)]
        );

        return SupportTicketResult::sent($reference);
    }

    /**
     * Whether the archive of THIS ticket has been transmitted. A later
     * ticket reads as untransmitted, which is what it is.
     */
    public function wasTransmittedFor(string $reference): bool
    {
        return $reference !== ''
            && (string) ($this->settingService->get(self::ARCHIVE_REFERENCE_SETTING) ?? '') === $reference;
    }

    public function transmittedAt(): string
    {
        return (string) ($this->settingService->get(self::ARCHIVE_SENT_AT_SETTING) ?? '');
    }

    private function writeSetting(string $key, string $value): void
    {
        try {
            $this->settingService->setInternal($key, $value);
        } catch (\Throwable) {
            // Bookkeeping must never turn a transmitted archive into a
            // failure the administrator would repeat.
        }
    }
}
