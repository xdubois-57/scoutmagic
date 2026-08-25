<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Attention;

/**
 * The aggregate reads behind the core's own attention points.
 *
 * Every method here returns a count or a short list of labels, never a
 * roster. The page is opened on demand and runs all of this on every
 * display, so a query per member — or a decryption pass over the unit —
 * would make it slower every year the unit grows, in a way nobody would
 * attribute to this page.
 */
class CoreAttentionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Active badges that nobody holds this scout year.
     *
     * A Trésorier, an Infirmier or a « Référent {section} » who leaves
     * takes their badge's job with them, and nothing on the site says so:
     * the badge simply stops appearing on anybody. Deactivated badges are
     * excluded — a badge somebody switched off is not a vacancy.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function findUnheldActiveBadges(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.id, b.name
             FROM badges b
             WHERE b.is_active = 1
               AND NOT EXISTS (
                   SELECT 1 FROM member_badges mb
                   JOIN member_years my ON my.id = mb.member_year_id
                   WHERE mb.badge_id = b.id
                     AND my.scout_year_id = ?
                     AND my.is_active = 1
               )
             ORDER BY b.name'
        );
        $stmt->execute([$scoutYearId]);

        return array_map(
            static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * How many members are flagged as leaving while Desk still lists them
     * as active.
     *
     * The flag is set on the site by a chief; Desk knows nothing about
     * it. As long as Desk still holds them, the federation still bills
     * them — so a flag left standing is a real cost, and it stays true
     * until somebody updates Desk, which is exactly the shape of an
     * attention point.
     */
    public function countLeavingButStillActive(int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM member_years
             WHERE scout_year_id = ? AND is_active = 1 AND leaving = 1'
        );
        $stmt->execute([$scoutYearId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * How many active members hold no function at all.
     *
     * They exist and belong nowhere: no section picker lists them, no
     * staff page shows them, and their own member page is close to empty.
     * Almost always a Desk encoding that was never finished.
     */
    public function countActiveWithoutFunction(int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM member_years my
             WHERE my.scout_year_id = ? AND my.is_active = 1
               AND NOT EXISTS (SELECT 1 FROM member_functions mf WHERE mf.member_year_id = my.id)'
        );
        $stmt->execute([$scoutYearId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Desk functions still awaiting a role on Config Desk.
     *
     * Whoever holds one sees no more than an ordinary member until
     * somebody qualifies it (SECURITY.md §3) — the first cause of "I
     * can't see anything any more" after an import, and true until it is
     * fixed rather than until an import happens to mention it again.
     *
     * @return array<int, array{id: int, label: string}>
     */
    public function findUnconfirmedFunctions(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, label, desk_code FROM functions WHERE confirmed = 0 ORDER BY label'
        );
        if ($stmt === false) {
            return [];
        }

        return array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) ($row['label'] !== '' ? $row['label'] : $row['desk_code']),
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }
}
