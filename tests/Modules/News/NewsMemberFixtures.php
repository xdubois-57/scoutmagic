<?php

declare(strict_types=1);

namespace Tests\Modules\News;

use Core\Security\EncryptionService;

/**
 * Members a « Membres » form field can offer, written straight into the
 * test database the way a Desk import leaves them: a `members` row (the
 * Desk id) and one `member_years` row per scout year, its names encrypted
 * and its contact address blind-indexed so that RoleResolver links it to
 * the account answering.
 *
 * Every person here is fictitious.
 */
final class NewsMemberFixtures
{
    public function __construct(private \PDO $pdo, private EncryptionService $encryption)
    {
    }

    public function year(string $label, string $start, string $end, bool $current): int
    {
        $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, ?)')
            ->execute([$label, $start, $end, $current ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    /** A Desk identity with no annual record yet. */
    public function identity(string $deskId): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute([$deskId]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * One annual record of a member, reachable from `$email`.
     *
     * @return int the member_year id
     */
    public function record(
        int $memberId,
        int $scoutYearId,
        string $firstName,
        string $lastName,
        ?string $totem,
        string $email
    ): int {
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                totem_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $memberId,
            $scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt($lastName, 'member_years.last_name'),
            $totem !== null ? $this->encryption->encrypt($totem, 'member_years.totem') : null,
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex(strtolower($email), 'email'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A member and their record for one year, in one call.
     *
     * @return array{member_id: int, member_year_id: int}
     */
    public function member(
        string $deskId,
        int $scoutYearId,
        string $firstName,
        string $lastName,
        ?string $totem,
        string $email
    ): array {
        $memberId = $this->identity($deskId);

        return [
            'member_id' => $memberId,
            'member_year_id' => $this->record($memberId, $scoutYearId, $firstName, $lastName, $totem, $email),
        ];
    }

    /** What a Desk re-import does to a record whose names changed. */
    public function rename(int $memberYearId, string $firstName, string $lastName, ?string $totem): void
    {
        $this->pdo->prepare(
            'UPDATE member_years SET first_name_encrypted = ?, last_name_encrypted = ?, totem_encrypted = ? WHERE id = ?'
        )->execute([
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt($lastName, 'member_years.last_name'),
            $totem !== null ? $this->encryption->encrypt($totem, 'member_years.totem') : null,
            $memberYearId,
        ]);
    }

    /** The contact address of a record changes: the account no longer reaches it. */
    public function detach(int $memberYearId, string $otherEmail): void
    {
        $this->pdo->prepare('UPDATE member_years SET email_encrypted = ?, email_blind_index = ? WHERE id = ?')
            ->execute([
                $this->encryption->encrypt($otherEmail, 'member_years.email'),
                $this->encryption->blindIndex(strtolower($otherEmail), 'email'),
                $memberYearId,
            ]);
    }
}
