<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Repository;

use Core\Database\Connection;
use Core\Member\MemberEmailRepository;
use Core\Security\EncryptionService;

/**
 * Which sections an ACCOUNT staffs, resolved from its address alone
 * (issue #551).
 *
 * **It answers the whole question, blind index included, and that is the
 * point.** `SectionStaffAuthorizationService` used to derive the index with
 * purpose `'email'`, hand it to {@see MemberEmailRepository} for the
 * secondary addresses, and then splice it into a statement of its own — one
 * Service holding a purpose string, a `\PDO` and a query. Splitting that into
 * « repository derives » and « service relays » would have spelled `'email'`
 * twice, once per read. Here it is spelled once and used for both.
 *
 * **Its own class rather than a method on {@see SectionRepository}**: that one
 * would need an `EncryptionService`, and it is constructed in 157 places. A
 * constructor change there is a different change from moving SQL out of a
 * Service — #551's own rule about not putting two reasonings in one review
 * round.
 */
class StaffedSectionRepository
{
    /**
     * $memberEmails is optional for the same reason it was on the Service,
     * and the semantics are unchanged: given one, the account is resolved
     * through its currently-`valid` secondary addresses as well as its Desk
     * address. Omitting it can only ever return FEWER sections, so a call
     * site that forgets it **fails closed** — while also silently stripping
     * an animateur who signs in with a secondary address of every section
     * they staff, which is why the composition root passes it.
     */
    public function __construct(
        private Connection $connection,
        private EncryptionService $encryption,
        private ?MemberEmailRepository $memberEmails = null
    ) {
    }

    /**
     * The distinct section ids this address staffs, in no particular order —
     * the caller sorts, because the order every section picker on this site
     * uses is a presentation rule (ARCHITECTURE.md §8.8), not a fact about
     * the rows.
     *
     * The address reaches a member two ways and both are resolved, exactly
     * like `MemberService::getLinkedMembers()`: the Desk address on
     * `member_years`, and every member reachable through a currently-`valid`
     * secondary address — a `pending` or `inactive` one grants nothing, the
     * same rule as `Core\Security\RoleResolver`. Matching only the Desk
     * address once made an animateur who signs in with their secondary
     * address the animateur of no section at all.
     *
     * @return int[]
     */
    public function staffedSectionIds(string $email, int $scoutYearId): array
    {
        $blindIndex = $this->encryption->blindIndex(strtolower(trim($email)), 'email');
        $memberIds = $this->memberEmails?->findMemberIdsByValidBlindIndex($blindIndex) ?? [];
        $memberIdPlaceholders = $memberIds !== []
            ? implode(',', array_fill(0, count($memberIds), '?'))
            : null;

        // Same trap as SectionService::getSectionStaff(): an animé's own
        // member_functions row carries the same section_id as their section's
        // staff — without the role filter, every animé would come back as an
        // « animateur » of their own section.
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT DISTINCT mf.section_id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE (my.email_blind_index = ?'
            . ($memberIdPlaceholders !== null ? " OR my.member_id IN ({$memberIdPlaceholders})" : '')
            . ') AND my.scout_year_id = ? AND my.is_active = 1
               AND f.role IN (\'chief\', \'admin\')
               AND mf.section_id IS NOT NULL'
        );
        $stmt->execute([$blindIndex, ...$memberIds, $scoutYearId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
