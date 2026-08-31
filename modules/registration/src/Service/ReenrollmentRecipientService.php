<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Security\EncryptionService;
use Modules\Registration\Repository\ReenrollmentRepository;

/**
 * Who a campaign e-mail goes to, and about which children.
 *
 * **One e-mail per ADDRESS, never per child.** A family of three gets one
 * message listing three names; three messages would read as a mistake and
 * would be one. That is why the grouping happens here rather than in the
 * handler: the handler batches, and a batch must never split a family in
 * two.
 *
 * **« Having answered » means having answered for ALL of one's children.**
 * A family who answered for two of three is still owed a reminder, and
 * that reminder names only the child who is missing — telling a parent
 * about a form they already filled in for their other two is how a
 * reminder gets ignored.
 *
 * The addresses are decrypted here because this is the one place that
 * needs them, and the query itself lives in this module's own repository
 * layer (SECURITY.md §5). Nothing about a recipient reaches the journal:
 * the campaign counts, it does not name.
 */
class ReenrollmentRecipientService
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption,
        private ReenrollmentRepository $repository,
        private PassageService $passageService
    ) {
    }

    /**
     * The families a campaign e-mail is owed to, in a stable order.
     *
     * `$silentOnly` is what separates the opening e-mail (everybody) from
     * the three that follow (whoever still owes an answer).
     *
     * `$afterKey` is the batching cursor: the smallest member id of the
     * last group handled. Grouping by address and cursoring on the group's
     * own smallest member id is what keeps a family whole across two
     * batches — a cursor on the raw member id would send the first two
     * children in one message and the third in another.
     *
     * @return array<int, array{email: string, key: int, member_names: array<int, string>}>
     */
    public function pendingFamilies(
        int $publicYearId,
        int $targetYearId,
        bool $silentOnly,
        int $afterKey = 0,
        int $limit = 0
    ): array {
        $animeMemberIds = [];
        foreach ($this->passageService->getAnimeMemberYears($publicYearId) as $row) {
            $animeMemberIds[(int) $row['member_id']] = true;
        }
        if ($animeMemberIds === []) {
            return [];
        }

        $answered = [];
        foreach ($this->repository->answeredMemberIds($targetYearId) as $memberId) {
            $answered[$memberId] = true;
        }

        $placeholders = implode(',', array_fill(0, count($animeMemberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT member_id, first_name_encrypted, last_name_encrypted, email_encrypted
             FROM member_years
             WHERE scout_year_id = ? AND member_id IN ({$placeholders}) AND is_active = 1
             ORDER BY member_id ASC"
        );
        $stmt->execute([$publicYearId, ...array_keys($animeMemberIds)]);

        /** @var array<string, array{email: string, key: int, member_names: array<int, string>}> $byAddress */
        $byAddress = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $memberId = (int) $row['member_id'];
            if ($silentOnly && isset($answered[$memberId])) {
                continue;
            }
            if ($row['email_encrypted'] === null) {
                continue;
            }

            $email = trim($this->encryption->decrypt($row['email_encrypted'], 'member_years.email'));
            if ($email === '') {
                continue;
            }

            $name = trim(
                ($row['first_name_encrypted'] !== null
                    ? $this->encryption->decrypt($row['first_name_encrypted'], 'member_years.first_name')
                    : '')
                . ' '
                . ($row['last_name_encrypted'] !== null
                    ? $this->encryption->decrypt($row['last_name_encrypted'], 'member_years.last_name')
                    : '')
            );

            $key = mb_strtolower($email);
            if (!isset($byAddress[$key])) {
                $byAddress[$key] = ['email' => $email, 'key' => $memberId, 'member_names' => []];
            }
            $byAddress[$key]['member_names'][] = $name;
            $byAddress[$key]['key'] = min($byAddress[$key]['key'], $memberId);
        }

        $families = array_values($byAddress);
        usort($families, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        $families = array_values(array_filter(
            $families,
            static fn (array $family): bool => $family['key'] > $afterKey
        ));

        return $limit > 0 ? array_slice($families, 0, $limit) : $families;
    }
}
