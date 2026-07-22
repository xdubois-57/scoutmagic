<?php

declare(strict_types=1);

namespace Modules\MassMail\Repository;

use Core\Security\EncryptionService;

/**
 * Resolves "which members does this mailing list currently mean" — every
 * method here is a fresh, uncached query against member_years/
 * member_functions (module spec: "Résolution dynamique à chaque
 * utilisation (pas de cache de membres)"). Deliberately its own
 * repository, separate from Repository\MailingListRepository (custom
 * lists' own identity/criteria) and Repository\RecipientRepository
 * (already-sent recipients) — this one only ever reads core member
 * tables, never mass_mail's own.
 *
 * Every query filters member_years.is_active = 1 only. An earlier version
 * also filtered on member_years.unit_mail_consent — deliberately dropped:
 * the "Courrier d'unité" Desk column it comes from isn't reliable enough
 * to gate delivery on (unit decision).
 *
 * @phpstan-type ResolvedMember array{member_id: int, email: ?string}
 */
class MemberResolutionRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * The "Section - {nom}" default list — every member (animateur,
     * intendant, animé) holding any function within this section.
     *
     * @return ResolvedMember[]
     */
    public function resolveSectionMembers(int $sectionId, int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT my.member_id, my.email_encrypted
             FROM member_years my
             JOIN member_functions mf ON mf.member_year_id = my.id
             WHERE mf.section_id = ? AND my.scout_year_id = ?
               AND my.is_active = 1'
        );
        $stmt->execute([$sectionId, $scoutYearId]);
        return $this->hydrateResolvedMembers($stmt);
    }

    /**
     * The "Membres actifs" default list — every active member of the
     * unit, any section.
     *
     * @return ResolvedMember[]
     */
    public function resolveActiveMembers(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT member_id, email_encrypted
             FROM member_years
             WHERE scout_year_id = ? AND is_active = 1'
        );
        $stmt->execute([$scoutYearId]);
        return $this->hydrateResolvedMembers($stmt);
    }

    /**
     * The "Chefs uniquement" default list — a function whose role is
     * chief or above (chief, admin, superadmin).
     *
     * @return ResolvedMember[]
     */
    public function resolveChiefs(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT my.member_id, my.email_encrypted
             FROM member_years my
             JOIN member_functions mf ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE my.scout_year_id = ? AND my.is_active = 1
               AND f.role IN ('chief', 'admin', 'superadmin')"
        );
        $stmt->execute([$scoutYearId]);
        return $this->hydrateResolvedMembers($stmt);
    }

    /**
     * A custom list's criteria: a member qualifies when they hold one of
     * the selected functions WITHIN one of the selected sections (AND
     * between the two criteria groups, OR within each — module spec:
     * "une combinaison de fonctions ET de sections à inclure").
     *
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @return ResolvedMember[]
     */
    public function resolveCustomList(array $functionIds, array $sectionIds, int $scoutYearId): array
    {
        if ($functionIds === [] || $sectionIds === []) {
            return [];
        }

        $functionPlaceholders = implode(',', array_fill(0, count($functionIds), '?'));
        $sectionPlaceholders = implode(',', array_fill(0, count($sectionIds), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT my.member_id, my.email_encrypted
             FROM member_years my
             JOIN member_functions mf ON mf.member_year_id = my.id
             WHERE mf.function_id IN ({$functionPlaceholders})
               AND mf.section_id IN ({$sectionPlaceholders})
               AND my.scout_year_id = ? AND my.is_active = 1"
        );
        $stmt->execute([...array_values($functionIds), ...array_values($sectionIds), $scoutYearId]);
        return $this->hydrateResolvedMembers($stmt);
    }

    /**
     * @return array{member_id: int, email: ?string}[]
     */
    private function hydrateResolvedMembers(\PDOStatement $stmt): array
    {
        $members = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $members[] = [
                'member_id' => (int) $row['member_id'],
                'email' => $row['email_encrypted'] !== null ? $this->encryption->decrypt($row['email_encrypted']) : null,
            ];
        }
        return $members;
    }
}
