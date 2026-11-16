<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\MemberEmail;
use Core\Member\MemberEmailRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * MemberEmailRepository::deleteStaleDeskOverrides() is a DELETE whose rows
 * a correlated subquery decides (issue #1100) — the shape AGENTS.md
 * § Database asks to see on the engine production runs, with the real
 * foreign keys SQLite does not enforce.
 */
#[Group('database')]
final class MemberEmailRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private EncryptionService $encryption;
    private MemberEmailRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new MemberEmailRepository($this->pdo, $this->encryption);
    }

    public function testDeleteStaleDeskOverridesDeletesOnlyTheRowsOfAFormerDeskAddress(): void
    {
        $ended = $this->scoutYear('2025-08-01', '2026-07-31');
        $current = $this->scoutYear('2026-08-01', '2027-07-31');
        $member = $this->member('D1');
        $other = $this->member('D2');
        $this->memberYear($member, $ended, 'oldest@test.com');
        $this->memberYear($member, $current, 'current@test.com');
        $this->memberYear($other, $current, 'parent@test.com');

        $this->repository->create($member, 'former@test.com', MemberEmail::SOURCE_DESK, MemberEmail::STATUS_VALID, null, null);
        $this->repository->create($member, 'oldest@test.com', MemberEmail::SOURCE_DESK, MemberEmail::STATUS_VALID, null, null);
        $this->repository->create($member, 'parent@test.com', MemberEmail::SOURCE_DESK, MemberEmail::STATUS_VALID, null, null);
        $this->repository->create($member, 'current@test.com', MemberEmail::SOURCE_DESK, MemberEmail::STATUS_INACTIVE, null, null);
        $this->repository->create($member, 'alias@test.com', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null);

        $this->assertSame(3, $this->repository->deleteStaleDeskOverrides(new \DateTimeImmutable('2026-11-12'), [$current]));

        $this->assertNull($this->repository->findByMemberAndEmail($member, 'former@test.com'));
        $this->assertNull($this->repository->findByMemberAndEmail($member, 'oldest@test.com'));
        $this->assertNull($this->repository->findByMemberAndEmail($member, 'parent@test.com'));
        $this->assertSame(
            MemberEmail::STATUS_INACTIVE,
            $this->repository->findByMemberAndEmail($member, 'current@test.com')?->status
        );
        $this->assertNotNull($this->repository->findByMemberAndEmail($member, 'alias@test.com'));

        // Nothing left to delete: a second run, the next import, is a no-op.
        $this->assertSame(0, $this->repository->deleteStaleDeskOverrides(new \DateTimeImmutable('2026-11-12'), [$current]));
    }

    /**
     * Two mailings resolve the same Desk address at once. The other one
     * has inserted the override row and not committed yet: this one does
     * not see it, its own insert waits on the unique index, and fails once
     * the other commits. It then takes the row the other one wrote rather
     * than failing the mailing.
     */
    public function testADeskOverrideThatLosesTheRaceTakesTheRowTheOtherRequestWrote(): void
    {
        $member = $this->member('D1');
        $credentials = self::productionEngineCredentials();
        $code = <<<'PHP'
            require 'vendor/autoload.php';
            [, $host, $port, $database, $user, $password, $member] = $argv;
            $pdo = (new Core\Database\Connection($host, (int) $port, $database, $user, $password))->getPdo();
            $encryption = new Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
            $pdo->beginTransaction();
            $id = (new Core\Member\MemberEmailRepository($pdo, $encryption))->create(
                (int) $member, 'race@test.com', 'desk', 'valid', null, null
            );
            fwrite(STDOUT, "INSERTED $id\n");
            fflush(STDOUT);
            usleep(1500000);
            $pdo->commit();
            fwrite(STDOUT, "COMMITTED\n");
            PHP;
        $process = proc_open(
            [
                PHP_BINARY, '-r', $code, '--',
                $credentials['host'], (string) $credentials['port'],
                (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn(),
                $credentials['user'], $credentials['password'], (string) $member,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3)
        );
        $this->assertIsResource($process);

        try {
            $line = (string) fgets($pipes[1]);
            if (preg_match('/^INSERTED (\d+)$/', trim($line), $match) !== 1) {
                $this->fail('the other session did not insert: ' . $line . stream_get_contents($pipes[2]));
            }

            $row = $this->repository->findOrCreateDeskOverride($member, 'race@test.com');

            $this->assertSame("COMMITTED\n", stream_get_contents($pipes[1]));
        } finally {
            proc_close($process);
        }

        $this->assertSame((int) $match[1], $row->id, 'the row the other request wrote is the one returned');
        $this->assertSame(MemberEmail::SOURCE_DESK, $row->source);
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM member_emails')->fetchColumn(),
            'no second row'
        );
    }

    /**
     * A write the database refuses for a reason other than the duplicate
     * is not the race: the re-read finds nothing, and what comes back is
     * the database's own error — not an \Error from the assertion (or from
     * the return type, where assertions are off) that hides it (#1237).
     */
    public function testARefusedDeskOverrideWriteSurfacesTheDatabaseErrorNotAnError(): void
    {
        $member = $this->member('D1');
        $this->pdo->exec(
            "CREATE TRIGGER refuse_member_emails BEFORE INSERT ON member_emails FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'adresse refusée'"
        );

        try {
            $this->repository->findOrCreateDeskOverride($member, 'refused@test.com');
            $this->fail('a refused write cannot produce a row');
        } catch (\PDOException $e) {
            $this->assertSame('45000', (string) $e->getCode(), 'the refusal itself, with its own code');
        } finally {
            $this->pdo->exec('DROP TRIGGER refuse_member_emails');
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM member_emails')->fetchColumn());
    }

    /** is_current = 1, as ScoutYearService::ensureYear() writes every row. */
    private function scoutYear(string $start, string $end): int
    {
        $this->pdo->prepare(
            'INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 1)'
        )->execute([substr($start, 0, 4) . '-' . substr($end, 0, 4), $start, $end]);

        return (int) $this->pdo->lastInsertId();
    }

    private function member(string $deskId): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute([$deskId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function memberYear(int $memberId, int $scoutYearId, string $email): void
    {
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $memberId,
            $scoutYearId,
            $this->encryption->encrypt('Prénom', 'member_years.first_name'),
            $this->encryption->encrypt('Nom', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex($email, 'email'),
        ]);
    }
}
