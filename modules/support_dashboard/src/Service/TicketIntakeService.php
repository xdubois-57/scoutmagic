<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\Journal\JournalService;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\TicketCategory;

/**
 * Accepts, authenticates and stores one incoming support ticket
 * (roadmap IT-23, ARCHITECTURE.md §8.49ter).
 *
 * Built on the statistics intake's shape rather than beside it: same
 * order of operations — transport, size, credentials, parse — so that
 * nothing expensive runs before something cheap has had a chance to
 * refuse, and same identity, because an installation already has one.
 *
 * **The identity is the statistics identity, and that is deliberate.**
 * `Core\Statistics` already gives every installation an opaque id and a
 * secret sent as `Authorization: Bearer`, established trust-on-first-use.
 * A second identity for tickets would be a second thing to lose, a second
 * thing to rotate and a second thing to get wrong. An installation that
 * refused telemetry has no row yet — IT-24 provisions one on its first
 * ticket, without turning the daily report on.
 *
 * **Everything but a bad signature answers 200.** A client receiving a
 * non-2xx retries, and there is nothing to retry about a category that
 * does not exist, a body too large or an hourly quota already spent —
 * whereas a ticket accepted and then retried would be filed twice. The
 * refusal travels in the body, where an instance can show it.
 *
 * **The archive does not come through here.** A ticket body is a couple
 * of kilobytes; the diagnostic archive is megabytes and arrives on its own
 * call (IT-26), after an explicit tick from an administrator.
 */
class TicketIntakeService
{
    /** A ticket is a description and an address; 32 KB is generous. */
    public const MAX_BODY_BYTES = 32768;

    /** Enough for a bad afternoon, few enough that nobody scripts it. */
    public const RATE_LIMIT_MAX_TICKETS = 5;
    public const RATE_LIMIT_WINDOW_MINUTES = 60;

    /** Long enough to describe a problem, short enough to stay a ticket. */
    public const MAX_DESCRIPTION_LENGTH = 5000;

    public function __construct(
        private SupportInstallationRepository $installations,
        private SupportTicketRepository $tickets,
        private JournalService $journal
    ) {
    }

    /**
     * @param string $rawBody the request body, exactly as received
     * @param string $authorizationHeader the raw `Authorization` header
     * @param string $clientIp the source address — journaled on a
     *        rejection, never stored on a ticket
     */
    public function receive(
        string $rawBody,
        string $authorizationHeader,
        string $clientIp,
        bool $isSecureTransport
    ): TicketIntakeResult {
        if (!$isSecureTransport) {
            return $this->refuse(TicketIntakeResult::REJECT_INSECURE_TRANSPORT, $clientIp);
        }

        // On the raw string, before any parse: a 1 MB body must cost a
        // strlen(), not a JSON decode.
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return $this->refuse(TicketIntakeResult::REJECT_PAYLOAD_TOO_LARGE, $clientIp);
        }

        // Credentials before the parse, so an unsigned caller gets its 403
        // whatever it sent: presenting no bearer at all is the same answer
        // as presenting the wrong one, and neither deserves a JSON decode.
        $secret = StatisticsIntakeService::extractBearerToken($authorizationHeader);
        if ($secret === null) {
            return $this->rejectUnauthenticated($clientIp);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $this->refuse(TicketIntakeResult::REJECT_MALFORMED, $clientIp);
        }

        $installationId = $payload['installation_id'] ?? null;
        if (!is_string($installationId) || $installationId === '') {
            return $this->rejectUnauthenticated($clientIp);
        }

        $installation = $this->installations->findByInstallationId($installationId);
        // An unknown installation and a wrong secret are the same answer,
        // and both cost the same password_verify(): answering faster for an
        // unknown id would publish which ids exist.
        $hash = is_array($installation) ? (string) $installation['secret_hash'] : self::DUMMY_HASH;
        if (!password_verify($secret, $hash) || !is_array($installation)) {
            return $this->rejectUnauthenticated($clientIp);
        }

        $installationRowId = (int) $installation['id'];

        if ($this->isRateLimited($installationRowId)) {
            return $this->refuse(TicketIntakeResult::REJECT_RATE_LIMITED, $clientIp, $installationId);
        }

        $category = TicketCategory::tryFromValue(
            is_string($payload['category'] ?? null) ? (string) $payload['category'] : null
        );
        if ($category === null) {
            return $this->refuse(TicketIntakeResult::REJECT_UNKNOWN_CATEGORY, $clientIp, $installationId);
        }

        $description = self::trimmedString($payload['description'] ?? null, self::MAX_DESCRIPTION_LENGTH);
        $contactEmail = self::trimmedString($payload['contact_email'] ?? null, 255);
        if ($description === null || $contactEmail === null || !self::looksLikeEmail($contactEmail)) {
            return $this->refuse(TicketIntakeResult::REJECT_MALFORMED, $clientIp, $installationId);
        }

        $ticketId = $this->tickets->create(
            $installationRowId,
            $category,
            $description,
            $contactEmail,
            self::trimmedString($payload['site_version'] ?? null, 50),
            self::trimmedString($payload['php_version'] ?? null, 20)
        );

        // The category and the installation, and nothing a person wrote:
        // the description and the address are exactly what this entry must
        // not carry (SECURITY.md §11).
        $this->journal->log(
            'support_dashboard',
            'support_ticket_received',
            'info',
            'Ticket de support reçu',
            [
                'installation_id' => $installationId,
                'category' => $category->value,
                'ticket_id' => $ticketId,
            ]
        );

        return TicketIntakeResult::accepted($ticketId);
    }

    /**
     * A real bcrypt hash of sixteen random bytes nobody kept, so an
     * unknown installation costs the same verification as a known one.
     * Hard-coded rather than computed: the point is that the WORK happens
     * on both paths, and hashing a fresh value per request would spend
     * that work twice on the one path that has nothing to check. Nothing
     * about it is secret — no value hashes to it that anybody knows.
     */
    private const DUMMY_HASH = '$2y$12$KMigOoo9nnZl6KEJMu3E4uMm2KpC7H8YKtsB2GHUr8uwkRSymy7Xy';

    private function isRateLimited(int $installationRowId): bool
    {
        $since = (new \DateTimeImmutable('-' . self::RATE_LIMIT_WINDOW_MINUTES . ' minutes'))
            ->format('Y-m-d H:i:s');

        return $this->tickets->countSince($installationRowId, $since) >= self::RATE_LIMIT_MAX_TICKETS;
    }

    /**
     * A refusal the caller is told about in a 200 body, and that the
     * receiver writes down.
     *
     * `warning` rather than `security`: a category nobody knows or a quota
     * spent is a client being wrong, not somebody trying to get in.
     */
    private function refuse(string $reason, string $clientIp, ?string $installationId = null): TicketIntakeResult
    {
        $context = ['reason' => $reason, 'source_ip' => $clientIp];
        if ($installationId !== null) {
            $context['installation_id'] = $installationId;
        }

        $this->journal->log(
            'support_dashboard',
            'support_ticket_refused',
            'warning',
            'Ticket de support refusé',
            $context
        );

        return TicketIntakeResult::refused($reason);
    }

    /**
     * The one rejection that is a 403, and the one that is `security`:
     * somebody presented credentials this receiver does not accept.
     *
     * The entry says the source address and nothing else — naming the
     * installation id somebody TRIED would file a failed attempt under a
     * unit that may have had nothing to do with it.
     */
    private function rejectUnauthenticated(string $clientIp): TicketIntakeResult
    {
        $this->journal->log(
            'support_dashboard',
            'support_ticket_unauthenticated',
            'security',
            'Ticket de support refusé : authentification invalide',
            ['source_ip' => $clientIp]
        );

        return TicketIntakeResult::unauthenticated();
    }

    private static function trimmedString(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? mb_substr($value, 0, $maxLength) : null;
    }

    /**
     * Deliberately shallow: this is an address to answer on, not a login.
     * Nothing is sent to it by this endpoint, and refusing a valid address
     * that happens to look unusual would lose a ticket for nothing.
     */
    private static function looksLikeEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
}
