<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\File\StoredFileReader;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Repository\SupportReportRateLimitRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;

/**
 * Serves the anonymised extract of a ticket's archive to the automated
 * GitHub triage (ARCHITECTURE.md §8.49sexies).
 *
 * **One credential, one caller.** The bearer token is the receiver's own
 * triage token (`TriageTokenService`), held by the repository's
 * `SUPPORT_TRIAGE_TOKEN` secret and by nothing else — not an
 * installation's identity, which would let any installation read the
 * extract of any ticket whose reference it guessed. What the caller
 * presents beside it is a ticket reference, read off the GitHub issue by
 * the workflow, and the issue's number, read off the event payload.
 *
 * **Every refusal is the same 403**, and the order of checks below is
 * the cheap-before-expensive order of `TicketIntakeService`: transport,
 * credential, then the parse, then the database, then the file. A caller
 * with a wrong token never learns whether a reference exists; a caller
 * with the right one never learns whether the archive was purged or
 * claimed by another issue, because it needs neither — the triage simply
 * runs without an extract, which is its ordinary case.
 *
 * **The reference is bound to the first issue that cites it.** The link
 * is written here, on the first successful call, and a later call naming
 * a different issue is refused: a reference known to one reporter belongs
 * to one report. Re-triaging the same issue — the reporter answered a
 * question — asks with the same number and is served again.
 *
 * **Served, never stored.** The extract is built from the decrypted
 * archive in a temporary file that lives for the call, and what leaves is
 * a copy the receiver does not keep. The `security` journal entry names
 * the reference, the issue and the size — never a line of what was sent.
 */
class TriageExtractService
{
    /** A JSON body naming an issue is a few dozen bytes; anything past this is not one. */
    public const MAX_BODY_BYTES = 4096;

    /**
     * The consent a ticket must carry for its archive to be served here:
     * the scope the sender's archive box declared when the ticket left
     * (`Core\Support\Ticket\SupportTicketSender::ARCHIVE_CONSENT_SCOPE`,
     * stored as `support_tickets.archive_consent_scope`). An archive
     * transmitted under a sentence that never named the triage — every
     * ticket from before this scope existed — is refused, whatever the
     * reporter cites: the consent is the sender's, given on their page,
     * and it cannot be inferred from somebody citing a reference later.
     */
    public const REQUIRED_CONSENT_SCOPE = 'triage-extract-v1';

    /**
     * Unauthenticated attempts journaled per source address per hour.
     * A wrong token costs one hash comparison, which is cheap; the
     * `security` journal row it writes is not free, and a stranger must
     * not be able to fill the journal with them or bury a real guessing
     * attempt under thousands of decoys. Past the limit the answer is the
     * same 403 and nothing is written.
     */
    public const UNAUTHENTICATED_JOURNAL_LIMIT = 20;
    public const UNAUTHENTICATED_WINDOW_MINUTES = 60;

    public function __construct(
        private TriageTokenService $tokens,
        private SupportTicketRepository $tickets,
        private StoredFileReader $files,
        private TriageExtractBuilder $builder,
        private JournalService $journal,
        private SupportReportRateLimitRepository $rateLimits,
        private EncryptionService $encryption
    ) {
    }

    public function serve(
        string $reference,
        string $rawBody,
        string $authorizationHeader,
        string $clientIp,
        bool $isSecureTransport,
        \DateTimeImmutable $now
    ): TriageExtractResult {
        if (!$isSecureTransport) {
            return $this->refuse(TriageExtractResult::REJECT_INSECURE_TRANSPORT, $clientIp);
        }

        // The credential before anything else — before the parse, before
        // the reference is even looked at — so a stranger's request costs
        // one hash comparison and learns nothing.
        $presented = StatisticsIntakeService::extractBearerToken($authorizationHeader);
        if ($presented === null || !$this->tokens->matches($presented)) {
            return $this->rejectUnauthenticated($clientIp);
        }

        $issueNumber = self::issueNumberIn($rawBody);
        if ($issueNumber === null) {
            return $this->refuse(TriageExtractResult::REJECT_MALFORMED, $clientIp);
        }

        // A reference is six characters of a fixed alphabet behind a
        // fixed prefix. Anything else is not a reference this receiver
        // ever issued, so it is refused before it reaches a query.
        if (preg_match(SupportTicketRepository::REFERENCE_PATTERN, $reference) !== 1) {
            return $this->refuse(TriageExtractResult::REJECT_UNKNOWN_REFERENCE, $clientIp, $issueNumber);
        }

        $ticket = $this->tickets->findByReference($reference);
        if ($ticket === null) {
            return $this->refuse(TriageExtractResult::REJECT_UNKNOWN_REFERENCE, $clientIp, $issueNumber);
        }

        // Before the link and before the file: a ticket whose sender was
        // never shown the sentence naming this use has given no consent
        // to it, and nothing done on GitHub afterwards can supply one.
        if ($ticket['archive_consent_scope'] !== self::REQUIRED_CONSENT_SCOPE) {
            return $this->refuse(TriageExtractResult::REJECT_NO_CONSENT, $clientIp, $issueNumber, $reference);
        }

        // Linked to another issue: refused, and NOT re-pointed. Linked to
        // this one, or to none yet: served, and the link written if it
        // was not there. The repository's `IS NULL` guard is what makes
        // two concurrent first calls resolve to one owner.
        $linked = $ticket['github_issue_number'];
        if ($linked !== null && $linked !== $issueNumber) {
            return $this->refuse(TriageExtractResult::REJECT_ISSUE_MISMATCH, $clientIp, $issueNumber, $reference);
        }
        if ($linked === null && !$this->tickets->linkGithubIssue((int) $ticket['id'], $issueNumber, $now)) {
            $ticket = $this->tickets->findByReference($reference);
            if ($ticket === null || $ticket['github_issue_number'] !== $issueNumber) {
                return $this->refuse(
                    TriageExtractResult::REJECT_ISSUE_MISMATCH,
                    $clientIp,
                    $issueNumber,
                    $reference
                );
            }
        }

        $fileId = $ticket['archive_file_id'];
        $archive = $fileId === null ? null : $this->files->read((int) $fileId);
        if ($archive === null || $archive === '') {
            // No archive was ever transmitted, or its retention has
            // passed. The triage runs on the issue alone, which is the
            // case it was written for.
            return $this->refuse(TriageExtractResult::REJECT_NO_ARCHIVE, $clientIp, $issueNumber, $reference);
        }

        try {
            $bytes = $this->builder->build($ticket, $archive, $issueNumber, $now);
        } catch (\RuntimeException) {
            return $this->refuse(TriageExtractResult::REJECT_UNBUILDABLE, $clientIp, $issueNumber, $reference);
        }

        // `security` rather than `info`, for the reason the sending
        // installation journals the transmission at that level: this is
        // the moment a derivative of a unit's diagnostics leaves this
        // server for a third party's runner.
        $this->journal->log(
            'support_dashboard',
            'support_triage_extract_served',
            'security',
            'Extrait anonymisé d\'une archive de diagnostic servi au triage GitHub',
            [
                'ticket_reference' => $reference,
                'github_issue_number' => $issueNumber,
                'bytes' => strlen($bytes),
                'source_ip' => $clientIp,
            ]
        );

        return TriageExtractResult::accepted($reference, $bytes);
    }

    /**
     * The one field the body carries, or null when the body is not a
     * small JSON object holding a positive integer under that name.
     */
    private static function issueNumberIn(string $rawBody): ?int
    {
        if ($rawBody === '' || strlen($rawBody) > self::MAX_BODY_BYTES) {
            return null;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return null;
        }

        $number = $payload['github_issue_number'] ?? null;
        if (!is_int($number) || $number < 1) {
            return null;
        }

        return $number;
    }

    private function refuse(
        string $reason,
        string $clientIp,
        ?int $issueNumber = null,
        ?string $reference = null
    ): TriageExtractResult {
        $context = ['reason' => $reason, 'source_ip' => $clientIp];
        if ($issueNumber !== null) {
            $context['github_issue_number'] = $issueNumber;
        }
        // The reference is journaled only once it is known to be one of
        // ours: a refused unknown reference is whatever somebody typed.
        if ($reference !== null) {
            $context['ticket_reference'] = $reference;
        }

        $this->journal->log(
            'support_dashboard',
            'support_triage_extract_refused',
            'warning',
            'Extrait de triage refusé',
            $context
        );

        return TriageExtractResult::rejected($reason);
    }

    private function rejectUnauthenticated(string $clientIp): TriageExtractResult
    {
        // Same table and same blind-index shape as the statistics intake's
        // per-address limit, under its own purpose so the two never
        // count each other's attempts. The count and the row are ONE
        // reservation, not a check followed by a write: a burst of
        // parallel wrong tokens must not all count nineteen and all
        // write (SupportReportRateLimitRepository::reserve()).
        $ipHash = $this->encryption->blindIndex('support_triage_ip:' . $clientIp);
        $since = (new \DateTimeImmutable('-' . self::UNAUTHENTICATED_WINDOW_MINUTES . ' minutes'))
            ->format('Y-m-d H:i:s');
        if (!$this->rateLimits->reserve($ipHash, $since, self::UNAUTHENTICATED_JOURNAL_LIMIT)) {
            return TriageExtractResult::rejected(TriageExtractResult::REJECT_UNAUTHENTICATED);
        }

        $this->journal->log(
            'support_dashboard',
            'support_triage_extract_unauthenticated',
            'security',
            'Extrait de triage refusé : jeton invalide ou absent',
            ['source_ip' => $clientIp]
        );

        return TriageExtractResult::rejected(TriageExtractResult::REJECT_UNAUTHENTICATED);
    }
}
