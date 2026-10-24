<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

/**
 * mass_mail_targets — who a communication is meant for, one member per
 * classic list and one per mail-merge row with a Tiers (issue #999). Holds
 * no address: the messages are Repository\DeliveryRepository's, tied to the
 * targets they concern through mass_mail_delivery_targets.
 *
 * The member page's history is read here, one entry per target (§2.5 of
 * docs/chantiers/CHANTIER-envois-groupes.md):
 * - at least one of its messages is 'sent' → an ordinary entry;
 * - none is 'sent' and none is still 'pending' (all in error, or no message
 *   at all) → an entry « Non envoyée »;
 * - otherwise it is still going out, and is not shown yet.
 */
class TargetRepository
{
    /**
     * One row per target with what its messages come to. Three correlated
     * subqueries on the target's own links (idx_mmdt_target) rather than a
     * GROUP BY over the join: nothing to list under ONLY_FULL_GROUP_BY, no
     * TEXT column grouped, and a target without any message simply counts
     * zero of both — which is exactly « Non envoyée ».
     *
     * Wrapped as a derived table so the outer query can filter and sort on
     * the three results by name.
     */
    private const ENTRY_SELECT = "SELECT t.id AS id, t.email_id AS email_id, t.member_id AS member_id,
                t.scout_year_id AS scout_year_id, t.audience_row_id AS audience_row_id,
                t.created_at AS created_at, e.subject AS subject, e.body_html AS body_html,
                e.list_type AS list_type, s.name AS section_name,
                (SELECT MIN(d.sent_at) FROM mass_mail_delivery_targets dt
                    JOIN mass_mail_deliveries d ON d.id = dt.delivery_id
                    WHERE dt.target_id = t.id AND d.status = 'sent') AS first_sent_at,
                (SELECT COUNT(*) FROM mass_mail_delivery_targets dt
                    JOIN mass_mail_deliveries d ON d.id = dt.delivery_id
                    WHERE dt.target_id = t.id AND d.status = 'sent') AS sent_count,
                (SELECT COUNT(*) FROM mass_mail_delivery_targets dt
                    JOIN mass_mail_deliveries d ON d.id = dt.delivery_id
                    WHERE dt.target_id = t.id AND d.status = 'pending') AS pending_count
         FROM mass_mail_targets t
         JOIN mass_mail_emails e ON e.id = t.email_id
         JOIN sections s ON s.id = e.section_id";

    /** An entry still going out is not shown yet. */
    private const SHOWN = ' WHERE x.sent_count > 0 OR x.pending_count = 0';

    public function __construct(private \PDO $pdo)
    {
    }

    public function create(int $emailId, int $memberId, ?int $scoutYearId, ?int $audienceRowId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mass_mail_targets (email_id, member_id, scout_year_id, audience_row_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$emailId, $memberId, $scoutYearId, $audienceRowId]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return Target[]
     */
    public function findByEmailId(int $emailId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_targets WHERE email_id = ? ORDER BY id ASC');
        $stmt->execute([$emailId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The targets one message concerns — none for a contact's message, one
     * for a mail-merge row's, every member sharing the address for a
     * classic list's.
     *
     * @return Target[]
     */
    public function findByDelivery(int $deliveryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.* FROM mass_mail_targets t
             JOIN mass_mail_delivery_targets dt ON dt.target_id = t.id
             WHERE dt.delivery_id = ?
             ORDER BY t.id ASC'
        );
        $stmt->execute([$deliveryId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Backs Api\MassMailQueryInterface::getRecentEmailsForMember(): the
     * member's latest entries, newest first — by the first message of the
     * target that left, or, for an entry « Non envoyée », by when the
     * communication was launched (the freeze wrote the target then).
     *
     * One entry per TARGET, never per message: three addresses are three
     * messages and one line, and the limit counts lines.
     *
     * Subject and body come out as STORED — the template, for a
     * publipostage; Service\MassMailQueryService puts the row's values back.
     *
     * @return array<int, array{
     *     id: int, email_id: int, member_id: int, scout_year_id: int|null, audience_row_id: int|null,
     *     subject: string, body_html: string, list_type: string, section_name: string,
     *     sent_at: string, not_sent: bool
     * }>
     */
    public function findRecentEntriesForMember(int $memberId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM (' . self::ENTRY_SELECT
                . " WHERE t.member_id = ? AND e.status IN ('sending', 'sent')) x"
                . self::SHOWN
                . ' ORDER BY COALESCE(x.first_sent_at, x.created_at) DESC, x.id DESC LIMIT ' . max(0, $limit)
        );
        $stmt->execute([$memberId]);

        return array_map([$this, 'hydrateEntry'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * One entry, re-checked against its member server-side so a target id
     * from one member's page can never open another member's mail; null
     * when it is not that member's, or not shown yet (still going out).
     *
     * @return array{
     *     id: int, email_id: int, member_id: int, scout_year_id: int|null, audience_row_id: int|null,
     *     subject: string, body_html: string, list_type: string, section_name: string,
     *     sent_at: string, not_sent: bool
     * }|null
     */
    public function findEntryForMember(int $targetId, int $memberId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM (' . self::ENTRY_SELECT
                . " WHERE t.id = ? AND t.member_id = ? AND e.status IN ('sending', 'sent')) x"
                . self::SHOWN
        );
        $stmt->execute([$targetId, $memberId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrateEntry($row) : null;
    }

    /**
     * The other shown entries of the same communication — « Cette
     * communication concerne aussi … ». Only the caller knows which of
     * these members the reader may see, and it names nobody else.
     *
     * Only the three columns that question needs: a classic list to the
     * whole unit has hundreds of targets, and each one would otherwise
     * carry the body again and three counts. The visibility rule is
     * SHOWN's, written out: a message sent, or none still pending.
     *
     * @return list<array{target_id: int, member_id: int, scout_year_id: int|null}>
     */
    public function findOtherEntriesOfEmail(int $emailId, int $exceptTargetId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.id AS target_id, t.member_id, t.scout_year_id
             FROM mass_mail_targets t
             JOIN mass_mail_emails e ON e.id = t.email_id
             JOIN sections s ON s.id = e.section_id
             WHERE t.email_id = ? AND t.id <> ? AND e.status IN ('sending', 'sent')
               AND (EXISTS (SELECT 1 FROM mass_mail_delivery_targets dt
                              JOIN mass_mail_deliveries d ON d.id = dt.delivery_id
                             WHERE dt.target_id = t.id AND d.status = 'sent')
                    OR NOT EXISTS (SELECT 1 FROM mass_mail_delivery_targets dt
                              JOIN mass_mail_deliveries d ON d.id = dt.delivery_id
                             WHERE dt.target_id = t.id AND d.status = 'pending'))
             ORDER BY t.id ASC"
        );
        $stmt->execute([$emailId, $exceptTargetId]);

        return array_map(
            static fn(array $row): array => [
                'target_id' => (int) $row['target_id'],
                'member_id' => (int) $row['member_id'],
                'scout_year_id' => $row['scout_year_id'] !== null ? (int) $row['scout_year_id'] : null,
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *     id: int, email_id: int, member_id: int, scout_year_id: int|null, audience_row_id: int|null,
     *     subject: string, body_html: string, list_type: string, section_name: string,
     *     sent_at: string, not_sent: bool
     * }
     */
    private function hydrateEntry(array $row): array
    {
        $notSent = (int) $row['sent_count'] === 0;

        return [
            'id' => (int) $row['id'],
            'email_id' => (int) $row['email_id'],
            'member_id' => (int) $row['member_id'],
            'scout_year_id' => $row['scout_year_id'] !== null ? (int) $row['scout_year_id'] : null,
            'audience_row_id' => $row['audience_row_id'] !== null ? (int) $row['audience_row_id'] : null,
            'subject' => (string) $row['subject'],
            'body_html' => (string) $row['body_html'],
            'list_type' => (string) $row['list_type'],
            'section_name' => (string) $row['section_name'],
            // The date the entry is sorted and shown by.
            'sent_at' => $notSent ? (string) $row['created_at'] : (string) $row['first_sent_at'],
            'not_sent' => $notSent,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Target
    {
        return new Target(
            id: (int) $row['id'],
            emailId: (int) $row['email_id'],
            memberId: (int) $row['member_id'],
            scoutYearId: $row['scout_year_id'] !== null ? (int) $row['scout_year_id'] : null,
            audienceRowId: $row['audience_row_id'] !== null ? (int) $row['audience_row_id'] : null,
            createdAt: (string) $row['created_at']
        );
    }
}
