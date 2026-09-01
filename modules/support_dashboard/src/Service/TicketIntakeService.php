<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\Journal\JournalService;
use Core\Notification\NotificationService;
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
    /**
     * Sixty-four kilobytes. It was half that until a ticket started
     * carrying its own usage report: a 5 000-character description of
     * accented French can reach ~15 KB once JSON-escaped, and the report
     * adds a few more. Refusing a legitimate ticket because it brought
     * the very context that makes it answerable would be the wrong
     * failure, and this is still two orders of magnitude below the
     * archive's own ceiling.
     */
    public const MAX_BODY_BYTES = 65536;

    /** Enough for a bad afternoon, few enough that nobody scripts it. */
    public const RATE_LIMIT_MAX_TICKETS = 5;
    public const RATE_LIMIT_WINDOW_MINUTES = 60;

    /** Long enough to describe a problem, short enough to stay a ticket. */
    public const MAX_DESCRIPTION_LENGTH = 5000;

    /**
     * Declared in this module's `module.json` "notifications" section —
     * `role_min: superadmin`, so its recipients ARE every superadmin of
     * this receiver.
     */
    public const NOTIFICATION_TICKET_RECEIVED = 'support_dashboard.ticket_received';

    public function __construct(
        private SupportInstallationRepository $installations,
        private SupportTicketRepository $tickets,
        private JournalService $journal,
        /**
         * Null when nothing wired one — a ticket is then stored and
         * journaled exactly as before, silently. The queue is not a
         * mailbox anybody watches, so this is how a ticket stops waiting
         * for somebody to think of opening the page.
         */
        private ?NotificationService $notifications = null
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

        // The installation id has to look like one before it is used to
        // create a row: the statistics intake's own rule, applied here so a
        // first ticket cannot register a row under arbitrary text.
        if (preg_match('/^[0-9a-zA-Z_-]{8,64}$/', $installationId) !== 1) {
            return $this->rejectUnauthenticated($clientIp);
        }

        $installation = $this->installations->findByInstallationId($installationId);

        if ($installation === null) {
            // **Trust on first use, the same rule the statistics intake
            // applies** (roadmap IT-24): a unit that refused telemetry has
            // no row here, and requiring one would mean buying support with
            // data. The secret it presents is hashed and kept, and every
            // later call must match it — an attacker can register a fake
            // installation, which is noise a superadmin deletes, but can
            // never take over a real one.
            //
            // The row is marked as having no telemetry: without that it
            // would read on the dashboard as an installation silent for
            // months, which is the one thing it is not.
            $installationRowId = $this->installations->register(
                $installationId,
                password_hash($secret, PASSWORD_DEFAULT),
                '',
                [],
                false
            );

            $this->journal->log(
                'support_dashboard',
                'support_installation_provisioned',
                'info',
                'Installation enregistrée à l\'occasion de son premier ticket',
                ['installation_id' => $installationId]
            );
        } else {
            // A known installation and a wrong secret cost the same
            // password_verify() as an unknown one did before this branch
            // existed: answering faster for either would publish which ids
            // exist.
            if (!password_verify($secret, (string) $installation['secret_hash'])) {
                return $this->rejectUnauthenticated($clientIp);
            }

            $installationRowId = (int) $installation['id'];
        }

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

        // The usage report the instance sent along with its ticket.
        //
        // **One transmission, two purposes, and that is the point.** A
        // report that travelled separately could not be tied to the
        // ticket it explains — which is exactly what went wrong before:
        // a report arrived, a ticket arrived, and nothing said they were
        // the same event. Arriving inside the ticket, it is frozen onto
        // the ticket AND fed through the ordinary denormalisation so the
        // installation row is up to date, with no second call and no
        // window in which the two disagree.
        $statistics = is_array($payload['statistics'] ?? null) ? $payload['statistics'] : null;

        $reference = $this->tickets->create(
            $installationRowId,
            $category,
            $description,
            $contactEmail,
            self::trimmedString($payload['site_version'] ?? null, 50),
            self::trimmedString($payload['php_version'] ?? null, 20),
            $statistics !== null ? (string) json_encode($statistics) : null
        );

        if ($statistics !== null) {
            // Same path an ordinary report takes, so the range checks and
            // the URL-scheme rule of §8.49 apply to it too — a payload
            // arriving through this route is no more trusted than one
            // arriving through the statistics route.
            $this->installations->recordReport(
                $installationRowId,
                (string) json_encode($statistics),
                StatisticsIntakeService::denormalize($statistics)
            );
        }

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
                'ticket_reference' => $reference,
            ]
        );

        // Last, and never before the ticket is stored, journaled and
        // answered: whoever runs this receiver has to be told, but a
        // receiver whose push keys are misconfigured must not start
        // refusing tickets over it.
        $this->announce($reference, $category);

        return TicketIntakeResult::accepted($reference);
    }

    /**
     * Tell every superadmin of this receiver that a ticket landed.
     *
     * The queue is not a mailbox anybody watches. Without this, a ticket
     * sent on a Friday evening waited for somebody to think of opening
     * `/support-dashboard/tickets` — which is exactly the delay a support
     * channel exists to remove.
     *
     * **Nothing a person wrote travels in it.** A notification becomes a
     * push payload on a phone and, for whoever switched that channel on,
     * an e-mail: the description and the contact address are precisely
     * what it may never carry (SECURITY.md §11). The reference and the
     * category are this site's own vocabulary and are enough to decide
     * whether to open the page now.
     *
     * A failure is journaled rather than swallowed. Bookkeeping that
     * fails in silence is how the archive line came to lie for weeks
     * (ARCHITECTURE.md §8.4); an unsent notification must not cost the
     * ticket, but it must leave a trace.
     */
    private function announce(string $reference, TicketCategory $category): void
    {
        if ($this->notifications === null) {
            return;
        }

        try {
            $recipients = $this->notifications->recipientsForType(self::NOTIFICATION_TICKET_RECEIVED);
            if ($recipients === []) {
                return;
            }

            $ticket = $this->tickets->findByReference($reference);

            $this->notifications->dispatch(
                self::NOTIFICATION_TICKET_RECEIVED,
                $recipients,
                [
                    'title' => 'Nouveau ticket de support',
                    'body' => $category->label() . ' — ' . $reference,
                    'url' => $ticket !== null
                        ? '/support-dashboard/tickets/' . $ticket['id']
                        : '/support-dashboard/tickets',
                ]
            );
        } catch (\Throwable $e) {
            $this->journal->log(
                'support_dashboard',
                'support_ticket_notification_failed',
                'warning',
                "Ticket de support reçu, mais la notification n'a pas pu être envoyée",
                [
                    'ticket_reference' => $reference,
                    'reason' => $e->getMessage(),
                ]
            );
        }
    }

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
