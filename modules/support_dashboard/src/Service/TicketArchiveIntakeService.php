<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\File\EncryptedFileStorageService;
use Core\Journal\JournalService;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;

/**
 * Receives the diagnostic archive of an already-created ticket
 * (roadmap IT-26).
 *
 * **A separate call, and that is the whole point.** The ticket is two
 * kilobytes of JSON and the archive is megabytes from a shared host: one
 * request carrying both would lose the ticket every time the upload timed
 * out, and losing the report because the attachment was slow is exactly
 * backwards. The ticket exists first; the archive either joins it or does
 * not, and « archive non transmise » is a state the instance can retry
 * from.
 *
 * **It is no less protected here than it was there.** Stored through
 * `Core\File\EncryptedFileStorageService` — encrypted at rest, reachable
 * only through `/files/{id}`, `role_min: superadmin` — which is the same
 * floor the archive had on the installation that produced it. Anything
 * less would mean the safest place for a unit's diagnostics is the one it
 * came from.
 */
class TicketArchiveIntakeService
{
    /**
     * Sixty megabytes. The generator's own caps (25 log files, 8 MB of
     * them, 5 000 storage entries) put a real archive an order of
     * magnitude below this; the ceiling is here so a receiver cannot be
     * filled by a body nobody bounded, and it refuses **explicitly**
     * rather than truncating — half an archive is not a diagnosis.
     */
    public const MAX_ARCHIVE_BYTES = 62914560;

    public const STORAGE_SUBDIRECTORY = 'support-tickets';

    public const REJECT_UNAUTHENTICATED = 'unauthenticated';
    public const REJECT_UNKNOWN_TICKET = 'unknown_ticket';
    public const REJECT_TOO_LARGE = 'archive_too_large';
    public const REJECT_EMPTY = 'archive_empty';
    public const REJECT_ALREADY_RECEIVED = 'archive_already_received';

    public function __construct(
        private SupportInstallationRepository $installations,
        private SupportTicketRepository $tickets,
        private EncryptedFileStorageService $storage,
        private JournalService $journal
    ) {
    }

    /**
     * @param string $reference the ticket the archive belongs to
     * @param string $rawBody the archive's bytes, exactly as received
     */
    public function receive(
        string $reference,
        string $rawBody,
        string $authorizationHeader,
        string $clientIp,
        bool $isSecureTransport
    ): TicketIntakeResult {
        if (!$isSecureTransport) {
            return TicketIntakeResult::refused(TicketIntakeResult::REJECT_INSECURE_TRANSPORT);
        }

        // On the raw string, before anything else touches it.
        if (strlen($rawBody) > self::MAX_ARCHIVE_BYTES) {
            return $this->refuse(self::REJECT_TOO_LARGE, $clientIp);
        }
        if ($rawBody === '') {
            return $this->refuse(self::REJECT_EMPTY, $clientIp);
        }

        $secret = StatisticsIntakeService::extractBearerToken($authorizationHeader);
        if ($secret === null) {
            return $this->rejectUnauthenticated($clientIp);
        }

        $ticket = $this->tickets->findByReference($reference);
        if ($ticket === null) {
            // Deliberately the same 403 as a bad secret: a caller must not
            // be able to discover which references exist by watching which
            // answer comes back.
            return $this->rejectUnauthenticated($clientIp);
        }

        $installation = $this->installations->findById($ticket['installation_id']);
        if ($installation === null || !password_verify($secret, (string) $installation['secret_hash'])) {
            return $this->rejectUnauthenticated($clientIp);
        }

        if ($ticket['archive_file_id'] !== null) {
            // Idempotent from the caller's point of view: it already has
            // what it wanted, and a retry after a timeout that actually
            // succeeded must not store a second copy.
            return TicketIntakeResult::accepted($reference);
        }

        $fileId = $this->storage->store(
            $rawBody,
            'application/zip',
            'support-' . $reference . '.zip',
            self::STORAGE_SUBDIRECTORY,
            // The floor the archive had on the installation that produced
            // it. Anything lower here would make the receiver the weakest
            // place a unit's diagnostics ever sit.
            'superadmin'
        );

        $this->tickets->attachArchive((int) $ticket['id'], $fileId);

        $this->journal->log(
            'support_dashboard',
            'support_ticket_archive_received',
            'info',
            'Archive de diagnostic reçue pour un ticket de support',
            ['ticket_reference' => $reference, 'bytes' => strlen($rawBody)]
        );

        return TicketIntakeResult::accepted($reference);
    }

    private function refuse(string $reason, string $clientIp): TicketIntakeResult
    {
        $this->journal->log(
            'support_dashboard',
            'support_ticket_archive_refused',
            'warning',
            'Archive de diagnostic refusée',
            ['reason' => $reason, 'source_ip' => $clientIp]
        );

        return TicketIntakeResult::refused($reason);
    }

    private function rejectUnauthenticated(string $clientIp): TicketIntakeResult
    {
        $this->journal->log(
            'support_dashboard',
            'support_ticket_archive_unauthenticated',
            'security',
            'Archive de diagnostic refusée : authentification invalide',
            ['source_ip' => $clientIp]
        );

        return TicketIntakeResult::unauthenticated();
    }
}
