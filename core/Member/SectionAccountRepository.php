<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

/**
 * « Which accounts belong to this section, this year? » — the audience of a
 * notification about a SECTION object: an activity of a section's calendar,
 * an album of a section (issue #1348).
 *
 * An account belongs to a section when it is linked to a member who holds a
 * function in it. Both ways an address reaches a member count, exactly as
 * login itself resolves them (SECURITY.md §2, Core\Security\RoleResolver):
 *
 *  - the member's **Desk address** (`member_years.email_blind_index`);
 *  - every **confirmed secondary address** they added themselves — a
 *    `member_emails` row that is `valid` and `manual`. A `pending` or
 *    `inactive` row grants nothing anywhere, and a `desk` row only restates
 *    the Desk address, so neither is read here. Before this class the query
 *    followed the Desk address alone, and a parent or a teenager reached
 *    through a secondary address was never told anything.
 *
 * **A role never adds anybody to a section.** The query it replaces appended
 * every chef, admin and super-administrator to every section, which is how an
 * administrator who is the parent of two Goélands and a Pionnier received the
 * reminder for the Seeonee's weekend. What a section's chefs see, and what
 * the administrators may open, is the business of the pages' own access
 * checks; who is *told* is the personal link, and only that. A chef who leads
 * a section holds a function in it, so is linked to it like anybody else.
 *
 * Needs a PDO and nothing else, on purpose: the scheduled reminder builds its
 * services from a TaskContext, which carries neither a RoleResolver nor a
 * MemberService, and the answer must be the one the publication path gives.
 * One grouped query for any number of sections — never one per account.
 */
class SectionAccountRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * The accounts linked to a member who holds a function in one of the
     * sections, in one of the years. Each account once, however many
     * children, functions or addresses link it.
     *
     * @param int[] $sectionIds
     * @param int[] $scoutYearIds the years the object is relevant in
     * @return list<int> ascending
     */
    public function accountIdsLinkedToSections(array $sectionIds, array $scoutYearIds): array
    {
        $sectionIds = array_values(array_unique(array_map('intval', $sectionIds)));
        $scoutYearIds = array_values(array_unique(array_map('intval', $scoutYearIds)));
        if ($sectionIds === [] || $scoutYearIds === []) {
            return [];
        }

        $sections = implode(',', array_fill(0, count($sectionIds), '?'));
        $years = implode(',', array_fill(0, count($scoutYearIds), '?'));

        // The Desk address, then the confirmed secondary addresses of the
        // member: the same two roads RoleResolver walks. UNION, not UNION
        // ALL — an account reached both ways, or through two children, is
        // one row.
        $stmt = $this->pdo->prepare(
            "SELECT ua.id
               FROM user_accounts ua
               JOIN member_years my ON my.email_blind_index = ua.email_blind_index
               JOIN member_functions mf ON mf.member_year_id = my.id
              WHERE mf.section_id IN ({$sections})
                AND my.scout_year_id IN ({$years})
                AND my.is_active = 1
             UNION
             SELECT ua.id
               FROM user_accounts ua
               JOIN member_emails me ON me.email_blind_index = ua.email_blind_index
                AND me.status = 'valid' AND me.source = 'manual'
               JOIN member_years my ON my.member_id = me.member_id
               JOIN member_functions mf ON mf.member_year_id = my.id
              WHERE mf.section_id IN ({$sections})
                AND my.scout_year_id IN ({$years})
                AND my.is_active = 1"
        );
        $stmt->execute([...$sectionIds, ...$scoutYearIds, ...$sectionIds, ...$scoutYearIds]);

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * The cadres of the years, and the super-administrators: the accounts
     * reached — by the Desk address OR a confirmed secondary address — that
     * hold a function whose confirmed role is one of `$roles`.
     *
     * The role filter of a notification that a link alone does not decide
     * (a calendar reserved to the chefs, an album older than the previous
     * year). It follows the same two roads as accountIdsLinkedToSections():
     * `UserAccountRepository::findStaffAndSuperAdminIds()` joins by the Desk
     * address only, so intersecting the two dropped a chef who signs in by a
     * secondary address — linked, and never « allowed » (review of #1379).
     *
     * @param int[] $scoutYearIds
     * @param list<string> $roles the confirmed function roles that count
     * @return list<int> ascending
     */
    public function cadreAccountIds(array $scoutYearIds, array $roles = ['chief', 'admin']): array
    {
        $ids = [];

        $scoutYearIds = array_values(array_unique(array_map('intval', $scoutYearIds)));
        if ($scoutYearIds !== [] && $roles !== []) {
            $years = implode(',', array_fill(0, count($scoutYearIds), '?'));
            $rolePlaceholders = implode(',', array_fill(0, count($roles), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT ua.id
                   FROM user_accounts ua
                   JOIN member_years my ON my.email_blind_index = ua.email_blind_index
                   JOIN member_functions mf ON mf.member_year_id = my.id
                   JOIN functions f ON f.id = mf.function_id
                  WHERE my.scout_year_id IN ({$years}) AND my.is_active = 1 AND f.role IN ({$rolePlaceholders})
                 UNION
                 SELECT ua.id
                   FROM user_accounts ua
                   JOIN member_emails me ON me.email_blind_index = ua.email_blind_index
                    AND me.status = 'valid' AND me.source = 'manual'
                   JOIN member_years my ON my.member_id = me.member_id
                   JOIN member_functions mf ON mf.member_year_id = my.id
                   JOIN functions f ON f.id = mf.function_id
                  WHERE my.scout_year_id IN ({$years}) AND my.is_active = 1 AND f.role IN ({$rolePlaceholders})"
            );
            $stmt->execute([...$scoutYearIds, ...$roles, ...$scoutYearIds, ...$roles]);
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        }

        $superAdmins = $this->pdo->query('SELECT id FROM user_accounts WHERE is_super_admin = 1');
        \assert($superAdmins !== false);
        $superAdminIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $superAdmins->fetchAll(\PDO::FETCH_ASSOC)
        );
        $ids = [...$ids, ...$superAdminIds];
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
