<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\Journal\JournalService;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\TicketCategory;

/**
 * The reading and the closing of tickets on the receiver (roadmap IT-28).
 *
 * **The search runs here, in PHP, after decryption.** `description` is an
 * encrypted `BLOB`: a `WHERE … LIKE` on the column matches ciphertext and
 * would find nothing, and a blind index is for an exact-match *lookup*,
 * not for a substring search — building one here would mean keeping a
 * searchable derivative of what people wrote about their own
 * installations, for a search a maintainer runs a handful of times a week
 * (SECURITY.md §5). The receiver's ticket table is small by construction
 * (five per installation per hour, two years of retention), so reading it
 * and filtering in memory is the honest implementation rather than a
 * compromise.
 */
class SupportTicketService
{
    public function __construct(
        private SupportTicketRepository $tickets,
        private JournalService $journal,
        private ?MailProbeService $probes = null
    ) {
    }

    /**
     * The filtered, sorted list.
     *
     * @return list<array<string, mixed>>
     */
    public function list(TicketListFilters $filters): array
    {
        $needle = $filters->search !== '' ? self::fold($filters->search) : null;
        $rows = [];

        foreach ($this->tickets->findAllWithInstallation() as $ticket) {
            if (!$filters->acceptsStatus((string) $ticket['status'])) {
                continue;
            }
            $category = $ticket['category'];
            if ($filters->category !== null
                && (!$category instanceof TicketCategory || $category->value !== $filters->category)
            ) {
                continue;
            }
            if ($filters->installation !== null
                && ($ticket['installation']['public_id'] ?? null) !== $filters->installation
            ) {
                continue;
            }
            if ($needle !== null && !self::matches($ticket, $needle)) {
                continue;
            }

            $rows[] = $ticket;
        }

        return self::sorted($rows, $filters);
    }

    /**
     * One ticket, with what the receiver knows about the installation that
     * sent it and the mail probes that installation asked for.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $id): ?array
    {
        $ticket = $this->tickets->findWithInstallation($id);
        if ($ticket === null) {
            return null;
        }

        // The probes belong to the installation, not to the ticket — a
        // unit that wrote « mes e-mails ne partent pas » and ran a probe
        // the same afternoon is exactly the case this detail page exists
        // for. Null when nothing wired the service, like everywhere else.
        $ticket['probes'] = $this->probes?->resultsFor((int) $ticket['installation_id']) ?? [];

        return $ticket;
    }

    /**
     * The two readings of one installation, side by side: what it reported
     * **with** the ticket, and what it has reported since.
     *
     * The left column is the reason the snapshot exists at all — by the
     * time somebody reads a three-week-old ticket, the version and the
     * member count on the installation row have moved, and « quelle
     * version avaient-ils quand ça a cassé » is the question the report
     * was attached to answer.
     *
     * @param array<string, mixed> $ticket a row from detail()
     * @return list<array{label: string, at_ticket: string|null, latest: string|null, changed: bool}>
     */
    public static function statisticsComparison(array $ticket): array
    {
        $snapshot = is_array($ticket['statistics_snapshot'] ?? null) ? $ticket['statistics_snapshot'] : [];
        $latest = is_array($ticket['installation'] ?? null) ? $ticket['installation'] : [];
        $frozen = $snapshot === [] ? [] : ReportedFacts::fromPayload($snapshot);

        $rows = [];
        foreach (ReportedFacts::LABELS as $key => $label) {
            $atTicket = self::readable($frozen[$key] ?? null);
            $now = self::readable($latest[$key] ?? null);

            $rows[] = [
                'label' => $label,
                'at_ticket' => $snapshot === [] ? null : $atTicket,
                'latest' => $now,
                // Only a real difference between two known values is a
                // change: « non renseigné » on one side is an absence, and
                // highlighting it would cry wolf on every older ticket.
                'changed' => $snapshot !== []
                    && $atTicket !== null
                    && $now !== null
                    && $atTicket !== $now,
            ];
        }

        return $rows;
    }

    /**
     * Whether any compared field actually moved — what decides if the page
     * says so at all.
     *
     * @param array<string, mixed> $ticket
     */
    public static function statisticsDrifted(array $ticket): bool
    {
        foreach (self::statisticsComparison($ticket) as $row) {
            if ($row['changed']) {
                return true;
            }
        }

        return false;
    }

    private static function readable(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'Oui' : 'Non';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The categories actually present, for a filter that never offers an
     * empty result.
     *
     * @return list<TicketCategory>
     */
    public function categoriesInUse(): array
    {
        $seen = [];
        foreach ($this->tickets->findAllWithInstallation() as $ticket) {
            $category = $ticket['category'];
            if ($category instanceof TicketCategory) {
                $seen[$category->value] = $category;
            }
        }

        return array_values($seen);
    }

    /**
     * Close a ticket with the note that makes the corpus worth keeping.
     *
     * @return bool false when the ticket does not exist or was already closed
     */
    public function close(int $id, ?string $resolutionNote, \DateTimeImmutable $now): bool
    {
        $ticket = $this->tickets->find($id);
        if ($ticket === null) {
            return false;
        }

        if (!$this->tickets->close($id, $resolutionNote, $now)) {
            return false;
        }

        // The reference and nothing else. The note is the maintainer's own
        // words about somebody's installation and belongs in the ticket,
        // not in a journal that outlives it.
        $this->journal->log(
            'support_dashboard',
            'support_ticket_closed',
            'info',
            'Ticket de support clôturé',
            ['reference' => (string) $ticket['reference']]
        );

        return true;
    }

    /**
     * Reopen a closed ticket.
     *
     * A ticket is closed on a judgement — « je pense que c'est réglé » —
     * and a judgement can turn out to be wrong three days later when the
     * unit writes back. Without this the only way back was a second
     * ticket, which loses the first one's note and its archive.
     *
     * @return bool false when the ticket does not exist or is already open
     */
    public function reopen(int $id): bool
    {
        $ticket = $this->tickets->find($id);
        if ($ticket === null) {
            return false;
        }

        if (!$this->tickets->reopen($id)) {
            return false;
        }

        $this->journal->log(
            'support_dashboard',
            'support_ticket_reopened',
            'info',
            'Ticket de support rouvert',
            ['reference' => (string) $ticket['reference']]
        );

        return true;
    }

    /**
     * Case- and accent-insensitive, because « problème » and « probleme »
     * are the same search to the person typing it.
     */
    private static function fold(string $value): string
    {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return mb_strtolower($folded !== false ? $folded : $value);
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private static function matches(array $ticket, string $needle): bool
    {
        // The reference is searched too: it is what an instance was given
        // and what a maintainer has in front of them in an e-mail.
        $haystack = self::fold(implode(' ', [
            (string) $ticket['description'],
            (string) $ticket['reference'],
            (string) ($ticket['resolution_note'] ?? ''),
            $ticket['category']?->label() ?? '',
        ]));

        return str_contains($haystack, $needle);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $rows, TicketListFilters $filters): array
    {
        usort($rows, static function (array $a, array $b) use ($filters): int {
            $comparison = match ($filters->sort) {
                TicketListFilters::SORT_STATUS => strcmp((string) $a['status'], (string) $b['status']),
                TicketListFilters::SORT_CATEGORY => strcmp(
                    $a['category']?->label() ?? '',
                    $b['category']?->label() ?? ''
                ),
                TicketListFilters::SORT_INSTALLATION => strcmp(
                    (string) ($a['installation']['instance_url'] ?? ''),
                    (string) ($b['installation']['instance_url'] ?? '')
                ),
                default => strcmp((string) $a['created_at'], (string) $b['created_at']),
            };

            // Ties fall back to the date, then the id: two tickets of the
            // same category on the same day must not swap places between
            // two renderings of the same URL.
            if ($comparison === 0) {
                $comparison = strcmp((string) $a['created_at'], (string) $b['created_at'])
                    ?: ((int) $a['id'] <=> (int) $b['id']);
            }

            return $comparison;
        });

        return $filters->descending ? array_reverse($rows) : $rows;
    }
}
