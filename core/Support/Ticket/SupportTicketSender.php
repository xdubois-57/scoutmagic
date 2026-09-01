<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Ticket;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Statistics\StatisticsPayloadBuilder;
use Core\Statistics\StatisticsTransportInterface;

/**
 * Sends one support ticket to the receiver (roadmap IT-25).
 *
 * **One way, and it stays that way.** There is no thread, no reply
 * travelling back, and no later polling of the receiver: the maintainer
 * answers from their own mailbox. What this installation keeps is the
 * reference it was given and the date it sent — the local status is
 * « Envoyé » and there is deliberately no second one, because any other
 * status would be a claim about a conversation happening somewhere else.
 *
 * **The transport is `Core\Statistics`'.** Its interface takes a URL, a
 * JSON body, a bearer token and a user agent, which is exactly this call,
 * and `StreamStatisticsTransport` already carries the timeouts a page must
 * not exceed (10 s to connect, 20 s in total). A second implementation
 * would be a second place for those numbers to drift.
 *
 * **The description never reaches a log.** It is the one field a person
 * wrote, it is on its way to somebody else's installation, and the journal
 * entry this writes carries the reference and the category and nothing
 * else — a failure message included, which is why the reasons below are
 * categories rather than the receiver's prose.
 */
class SupportTicketSender
{
    /** Where the last accepted ticket's reference is kept, for display. */
    public const LAST_REFERENCE_SETTING = 'support_last_ticket_reference';
    public const LAST_SENT_AT_SETTING = 'support_last_ticket_sent_at';
    /** The category list the receiver last published (JSON). */
    public const CATEGORIES_SETTING = 'support_ticket_categories';

    /** No identity could be provisioned — `secrets.enc` is unavailable. */
    public const FAILURE_NO_IDENTITY = 'no_identity';
    /** The receiver never answered, or answered nothing readable. */
    public const FAILURE_UNREACHABLE = 'unreachable';
    /** It answered, and refused. The reason it named travels with it. */
    public const FAILURE_REFUSED = 'refused';
    /** It answered something this version cannot read. */
    public const FAILURE_MALFORMED_ANSWER = 'malformed_answer';

    public function __construct(
        private SettingService $settingService,
        private TicketIdentityService $identityService,
        private StatisticsTransportInterface $transport,
        private JournalService $journalService,
        private string $appVersion,
        /**
         * The usage report a ticket carries with it. Nullable so a caller
         * with no builder still sends a ticket — the report is context,
         * never the point.
         */
        private ?StatisticsPayloadBuilder $payloadBuilder = null
    ) {
    }

    /**
     * @param string $category one of the published category values
     * @param string $description what the administrator wrote — never
     *        logged, never echoed into an error message
     * @param string $contactEmail where the maintainer should answer
     */
    public function send(string $category, string $description, string $contactEmail): SupportTicketResult
    {
        $guard = $this->identityService->firstFailingGuard();
        if ($guard !== null) {
            return SupportTicketResult::failed($guard);
        }

        $endpoint = $this->identityService->endpoint();
        if ($endpoint === null) {
            return SupportTicketResult::failed(TicketIdentityService::GUARD_NO_DESTINATION);
        }

        $identity = $this->identityService->ensureIdentity();
        if ($identity === null) {
            return SupportTicketResult::failed(self::FAILURE_NO_IDENTITY);
        }

        $body = (string) json_encode([
            'installation_id' => $identity->installationId,
            'category' => $category,
            'description' => $description,
            'contact_email' => $contactEmail,
            'site_version' => $this->appVersion,
            'php_version' => PHP_VERSION,
            // The usage report travels WITH the ticket, always, even on an
            // installation that keeps the daily report switched off.
            //
            // It is not a change of heart about telemetry: the daily
            // report stays off, nothing is scheduled, and this leaves only
            // when somebody presses « Envoyer le ticket ». It is here
            // because a bug report without « which version, which
            // hosting, how many members » is a question a maintainer
            // cannot answer, and because a report sent as a SEPARATE call
            // could not be tied to the ticket it explains — which is
            // exactly what happened before: a report arrived, a ticket
            // arrived, and nothing said they were one event.
            //
            // The page says so above the button, in the same breath as
            // the identity, because this is the largest thing that leaves.
            'statistics' => $this->payloadBuilder?->build(),
        ]);

        try {
            $response = $this->transport->post(
                $endpoint,
                $body,
                $identity->secret,
                'ScoutMagic/' . $this->appVersion . ' (+support-ticket)'
            );
        } catch (\Throwable) {
            // Deliberately not the exception's message: it can quote the
            // request, and the request carries what somebody wrote.
            return SupportTicketResult::failed(self::FAILURE_UNREACHABLE);
        }

        if (!$response->isSuccessful()) {
            return SupportTicketResult::failed(self::FAILURE_UNREACHABLE);
        }

        $answer = json_decode((string) $response->body, true);
        if (!is_array($answer)) {
            return SupportTicketResult::failed(self::FAILURE_MALFORMED_ANSWER);
        }

        // Whatever else happened, the receiver's own list of categories is
        // worth keeping: it is how this installation renders a picker it
        // was not shipped with, and a refusal is exactly when the list has
        // most to say.
        $this->rememberCategories($answer['categories'] ?? null);

        if (($answer['status'] ?? '') !== 'accepted') {
            return SupportTicketResult::failed(self::FAILURE_REFUSED);
        }

        $reference = is_string($answer['ticket_reference'] ?? null)
            ? (string) $answer['ticket_reference']
            : null;
        if ($reference === null || $reference === '') {
            return SupportTicketResult::failed(self::FAILURE_MALFORMED_ANSWER);
        }

        $this->writeSetting(self::LAST_REFERENCE_SETTING, $reference);
        $this->writeSetting(self::LAST_SENT_AT_SETTING, (new \DateTimeImmutable())->format('Y-m-d H:i:s'));

        $this->journalService->log(
            'core',
            'support_ticket_sent',
            'info',
            'Ticket de support envoyé',
            ['reference' => $reference, 'category' => $category]
        );

        return SupportTicketResult::sent($reference);
    }

    /**
     * The categories to offer, most recent knowledge first: what the
     * receiver last published, or the list this version ships with.
     *
     * @return list<array{value: string, label: string}>
     */
    public function categories(): array
    {
        $stored = json_decode((string) ($this->settingService->get(self::CATEGORIES_SETTING) ?? ''), true);
        if (!is_array($stored) || $stored === []) {
            return TicketCategories::shipped();
        }

        $categories = [];
        foreach ($stored as $entry) {
            if (!is_array($entry) || !is_string($entry['value'] ?? null) || !is_string($entry['label'] ?? null)) {
                // One malformed entry discredits the whole stored list:
                // a picker half-built from a corrupted setting is worse
                // than the one this version was shipped with.
                return TicketCategories::shipped();
            }
            $categories[] = ['value' => $entry['value'], 'label' => $entry['label']];
        }

        return $categories;
    }

    /**
     * The reference and date of the last accepted ticket, if any.
     *
     * @return array{reference: string, sent_at: string}|null
     */
    public function lastSent(): ?array
    {
        $reference = (string) ($this->settingService->get(self::LAST_REFERENCE_SETTING) ?? '');
        if ($reference === '') {
            return null;
        }

        return [
            'reference' => $reference,
            'sent_at' => (string) ($this->settingService->get(self::LAST_SENT_AT_SETTING) ?? ''),
        ];
    }

    private function rememberCategories(mixed $categories): void
    {
        if (!is_array($categories) || $categories === []) {
            return;
        }

        $this->writeSetting(self::CATEGORIES_SETTING, (string) json_encode($categories));
    }

    /**
     * Bookkeeping must never be the reason a ticket that WAS accepted
     * reads as a failure — same posture as the statistics sender's own
     * state writes.
     */
    private function writeSetting(string $key, string $value): void
    {
        try {
            $this->settingService->setInternal($key, $value);
        } catch (\Throwable) {
            // Swallowed on purpose — see the docblock.
        }
    }
}
