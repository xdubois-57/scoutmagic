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

    /**
     * The last few accepted references, newest first (JSON).
     *
     * **A reference exists to be copied into a GitHub issue**, and nobody
     * reports within the minute: they send the evidence, look at the
     * problem some more, and write the issue that evening or the next
     * morning. Keeping only the latest made the reference of two days ago
     * unrecoverable — which is exactly the one somebody comes back to this
     * page for.
     *
     * A JSON list in one setting rather than a table: it is five short
     * rows that only this page ever reads, it is written on a path that
     * must never fail over bookkeeping, and a table would need a schema, a
     * repository and a retention rule for something a unit sends a handful
     * of times a year. {@see self::LAST_REFERENCE_SETTING} stays beside it
     * — `SupportArchiveSender` compares against that one to know whether
     * the archive it transmitted belongs to the current ticket.
     */
    public const RECENT_SETTING = 'support_recent_tickets';

    /**
     * How many are kept.
     *
     * Five. Enough that a reference is still there a week later, few
     * enough that the list stays something you scan rather than read, and
     * few enough that this setting cannot grow without bound on an
     * installation having a very bad month.
     */
    public const RECENT_KEPT = 5;

    /**
     * How many times a losing writer re-reads before giving up.
     *
     * The contenders here are superadmins of one installation clicking one
     * form, so two at once is already the unlikely case and three is not a
     * case at all. Five is loop insurance, not a contention estimate.
     */
    public const RECENT_WRITE_ATTEMPTS = 5;

    /** The category list the receiver last published (JSON). */
    public const CATEGORIES_SETTING = 'support_ticket_categories';

    /**
     * How a per-module category is spelled on the wire — the same string
     * `Modules\SupportDashboard\TicketCategory::MODULE_PREFIX` accepts on
     * the other side. Repeated rather than imported because core must
     * keep working with the support_dashboard module absent, which is the
     * ordinary case: exactly one installation in the world runs it.
     */
    public const MODULE_CATEGORY_PREFIX = 'module_';

    /** The escape hatch, which stays last whatever else is offered. */
    public const OTHER_CATEGORY = 'other';

    /** No identity could be provisioned — `secrets.enc` is unavailable. */
    public const FAILURE_NO_IDENTITY = 'no_identity';

    /**
     * The consent the archive box on Configuration > Support asks for,
     * as a version: `triage-extract-v1` is the sentence that names the
     * anonymised extract read by the GitHub triage. The receiver
     * compares it against the scope the extract route requires.
     */
    public const ARCHIVE_CONSENT_SCOPE = 'triage-extract-v1';
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
        private ?StatisticsPayloadBuilder $payloadBuilder = null,
        /**
         * The modules this installation has enabled, `id => human name`,
         * as `Core\Module\ModuleManager::getEnabledModuleNames()` gives
         * them. One category is minted per entry — see categories().
         * Empty for a caller with no module manager, which simply offers
         * the fixed list.
         *
         * @var array<string, string>
         */
        private array $moduleNames = []
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
            return $this->refuse($guard, $category);
        }

        $endpoint = $this->identityService->endpoint();
        if ($endpoint === null) {
            return $this->refuse(TicketIdentityService::GUARD_NO_DESTINATION, $category);
        }

        $identity = $this->identityService->ensureIdentity();
        if ($identity === null) {
            return $this->refuse(self::FAILURE_NO_IDENTITY, $category);
        }

        $body = (string) json_encode([
            'installation_id' => $identity->installationId,
            'category' => $category,
            'description' => $description,
            'contact_email' => $contactEmail,
            'site_version' => $this->appVersion,
            'php_version' => PHP_VERSION,
            // WHAT THE ARCHIVE BOX ON THIS VERSION'S PAGE SAID. The
            // receiver serves an anonymised extract of the archive to the
            // GitHub triage (ARCHITECTURE.md §8.49sexies) only for a ticket
            // whose sender ticked a consent sentence that named that use —
            // and the sentence is a property of the page that showed it,
            // which is this version. A ticket from an older version
            // carries no scope, and its archive reaches no triage. Bump
            // the scope when the sentence changes what it covers.
            'archive_consent' => self::ARCHIVE_CONSENT_SCOPE,
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
            return $this->refuse(self::FAILURE_UNREACHABLE, $category);
        }

        if (!$response->isSuccessful()) {
            return $this->refuse(self::FAILURE_UNREACHABLE, $category);
        }

        $answer = json_decode((string) $response->body, true);
        if (!is_array($answer)) {
            return $this->refuse(self::FAILURE_MALFORMED_ANSWER, $category);
        }

        // Whatever else happened, the receiver's own list of categories is
        // worth keeping: it is how this installation renders a picker it
        // was not shipped with, and a refusal is exactly when the list has
        // most to say.
        $this->rememberCategories($answer['categories'] ?? null);

        if (($answer['status'] ?? '') !== 'accepted') {
            return $this->refuse(self::FAILURE_REFUSED, $category);
        }

        $reference = is_string($answer['ticket_reference'] ?? null)
            ? (string) $answer['ticket_reference']
            : null;
        if ($reference === null || $reference === '') {
            return $this->refuse(self::FAILURE_MALFORMED_ANSWER, $category);
        }

        $sentAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->writeSetting(self::LAST_REFERENCE_SETTING, $reference);
        $this->writeSetting(self::LAST_SENT_AT_SETTING, $sentAt);
        $this->rememberSent($reference, $sentAt, $category);

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
     * The categories to offer: the fixed vocabulary — what the receiver
     * last published, or the list this version ships with — plus one
     * entry per enabled module, slotted in ahead of « Autre ».
     *
     * **The modules are added HERE and never received**, because which
     * modules are enabled is a fact about this installation and about no
     * other. The receiver publishes a vocabulary for every unit at once;
     * offering a unit « Locations » because the receiver happens to run
     * the rental module would be a category for a feature that unit does
     * not have, and losing « Camps » because the receiver does not run it
     * would be worse.
     *
     * Ahead of « Autre » because « Autre » is the escape hatch and an
     * escape hatch that is not last is the only answer anybody picks.
     *
     * @return list<array{value: string, label: string}>
     */
    public function categories(): array
    {
        return $this->withModuleCategories($this->fixedCategories());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function fixedCategories(): array
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
     * @param list<array{value: string, label: string}> $fixed
     * @return list<array{value: string, label: string}>
     */
    private function withModuleCategories(array $fixed): array
    {
        if ($this->moduleNames === []) {
            return $fixed;
        }

        $modules = [];
        foreach ($this->moduleNames as $moduleId => $name) {
            $modules[] = [
                'value' => self::MODULE_CATEGORY_PREFIX . $moduleId,
                'label' => $name,
            ];
        }
        usort($modules, static fn (array $a, array $b): int => strcoll($a['label'], $b['label']));

        $escapeHatch = [];
        $before = [];
        foreach ($fixed as $entry) {
            if ($entry['value'] === self::OTHER_CATEGORY) {
                $escapeHatch[] = $entry;
                continue;
            }
            $before[] = $entry;
        }

        return array_merge($before, $modules, $escapeHatch);
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

    /**
     * The last {@see self::RECENT_KEPT} accepted references, newest first.
     *
     * The category travels with each one as its **label**, resolved here
     * against what the receiver published: a page rendering `module_camps`
     * beside a reference would be showing its reader an identifier rather
     * than an answer, and the list exists to be recognised at a glance.
     * An entry whose category no longer resolves — a module since removed,
     * a vocabulary the receiver has changed — keeps its reference and
     * loses only the label, which is the half that matters.
     *
     * @return array<int, array{reference: string, sent_at: string, category_label: ?string}>
     */
    public function recentlySent(): array
    {
        $stored = json_decode((string) ($this->settingService->get(self::RECENT_SETTING) ?? ''), true);
        if (!is_array($stored) || !array_is_list($stored)) {
            // Nothing kept yet, or a value this version cannot read —
            // `array_is_list()` because a JSON OBJECT decodes to an array
            // too, and one would walk straight past a plain `is_array()`
            // into the loop below, come out empty, and report an
            // installation that has been sending for a year as one that
            // never sent anything. The
            // last reference is still worth showing on its own — an
            // installation that has been sending for a year must not read
            // as one that never sent anything, just because this list is
            // newer than its history.
            $last = $this->lastSent();

            return $last === null
                ? []
                : [['reference' => $last['reference'], 'sent_at' => $last['sent_at'], 'category_label' => null]];
        }

        $labels = [];
        foreach ($this->categories() as $category) {
            $labels[(string) $category['value']] = (string) $category['label'];
        }

        $entries = [];
        foreach (array_slice($stored, 0, self::RECENT_KEPT) as $entry) {
            $reference = is_array($entry) ? (string) ($entry['reference'] ?? '') : '';
            if ($reference === '') {
                continue;
            }

            $category = is_array($entry) ? (string) ($entry['category'] ?? '') : '';
            $entries[] = [
                'reference' => $reference,
                'sent_at' => is_array($entry) ? (string) ($entry['sent_at'] ?? '') : '',
                'category_label' => $labels[$category] ?? null,
            ];
        }

        return $entries;
    }

    /**
     * Push one accepted reference onto the list.
     *
     * The category is stored as its VALUE and turned into a label only at
     * display time: a label is the receiver's wording of the moment, and a
     * copy frozen here would go on printing last year's vocabulary beside
     * a reference for as long as the row survives.
     *
     * Written through {@see self::writeSetting()} like everything else on
     * this path, so a bookkeeping failure can never make a ticket that WAS
     * accepted read as one that was not.
     */
    private function rememberSent(string $reference, string $sentAt, string $category): void
    {
        // Read-modify-write, made atomic by compare-and-swap rather than
        // by a lock (#199). Two accepted sends in the same few
        // milliseconds used to read the same value before either wrote,
        // and the second erased the first's reference from the list.
        //
        // The database arbitrates: the write lands only if the value is
        // still the one that was read, and the loser re-reads and tries
        // again with the winner's list in hand. No lock is held across
        // application code, nothing is added to the schema, and it
        // behaves the same on MySQL and on SQLite.
        for ($attempt = 0; $attempt < self::RECENT_WRITE_ATTEMPTS; $attempt++) {
            $current = (string) ($this->settingService->get(self::RECENT_SETTING) ?? '');
            $stored = json_decode($current, true);

            // Same guard as the reader, and for the same reason: prepending
            // onto a decoded JSON object would write back a value neither
            // side can read.
            $entries = is_array($stored) && array_is_list($stored) ? $stored : [];

            array_unshift($entries, [
                'reference' => $reference,
                'sent_at' => $sentAt,
                'category' => $category,
            ]);

            $next = (string) json_encode(array_slice($entries, 0, self::RECENT_KEPT), JSON_UNESCAPED_UNICODE);

            if ($this->replaceSetting(self::RECENT_SETTING, $current, $next)) {
                return;
            }
        }

        // Five losses in a row, and the list is left exactly as the
        // winners wrote it.
        //
        // There WAS a fallback here — the plain last-writer-wins write of
        // every version before this one — and it was wrong. Work through
        // the two ways to arrive: under real contention it would clobber
        // whoever won, which is the very bug this method was rewritten to
        // fix; and where the compare-and-swap cannot work at all (an
        // unregistered row, a settings implementation without the method)
        // the plain write cannot work either, because it goes through the
        // same missing row. A fallback that helps in neither case, and
        // erases a reference in one of them, is not a fallback.
        //
        // What is lost by giving up is this ONE entry in a display list.
        // The reference itself is not lost: the sender was shown it on
        // the page, {@see self::LAST_REFERENCE_SETTING} holds it, and the
        // receiver has the ticket.
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
    /**
     * A ticket that did NOT leave, written down.
     *
     * Only the success was journaled, and that turned out to be the worst
     * possible half: an administrator presses « Envoyer le ticket », the
     * send fails, and the one place anybody would look afterwards — the
     * event journal, which is also what the diagnostic archive carries —
     * says nothing at all. « Je crois que j'ai envoyé un ticket » is then
     * unanswerable from either end. A support channel whose failures are
     * invisible is a support channel nobody can debug, which is exactly
     * how this came to be found: from an archive in which no entry
     * mentioned a ticket the unit was sure they had sent.
     *
     * `warning`, not `error`: nothing is broken here, an attempt did not
     * get through — and the same reason keeps the CATEGORY in and the
     * description and the contact address out. The reason is one of this
     * class's own constants, never the receiver's prose, because prose
     * can quote the request and the request carries what somebody wrote.
     */
    private function refuse(string $reason, string $category): SupportTicketResult
    {
        $this->journalService->log(
            'core',
            'support_ticket_not_sent',
            'warning',
            "Ticket de support non envoyé",
            ['reason' => $reason, 'category' => $category]
        );

        return SupportTicketResult::failed($reason);
    }

    /**
     * One compare-and-swap attempt, swallowing exactly what
     * {@see self::writeSetting()} swallows.
     *
     * A failure to write is reported as « somebody else won », so the loop
     * above retries and then falls back — never as an exception escaping
     * into a send that has already been accepted by the receiver.
     */
    private function replaceSetting(string $key, string $expected, string $value): bool
    {
        try {
            return $this->settingService->replaceIfUnchanged($key, $expected, $value);
        } catch (\Throwable) {
            return false;
        }
    }

    private function writeSetting(string $key, string $value): void
    {
        try {
            $this->settingService->setInternal($key, $value);
        } catch (\Throwable) {
            // Swallowed on purpose — see the docblock.
        }
    }
}
