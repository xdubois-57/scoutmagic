<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Import\MemberYearRepository;
use Core\Member\MemberEmailRepository;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\MemberService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Issue #1101 (audit SEC-003), and the maintainer's rule behind its fix: an
 * address has access to everything about a member — every year — exactly
 * while it is one of that member's addresses NOW (the Desk address of the
 * latest record, or a confirmed secondary address). Added, in Desk or on
 * the member page, it gains all of it; removed, it loses all of it.
 *
 * The flaw it closes: access was decided by the address printed on the
 * record being opened, so a parent Desk replaced this year still opened
 * last year's record — and through it the member's CURRENT health form,
 * addresses and the means to add their own.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class MemberAccessFollowsCurrentAddressesTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private MemberService $service;
    private int $lastYear;
    private int $thisYear;
    private int $memberId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->service = new MemberService(
            new MemberYearRepository($this->pdo),
            new MemberProfileRepository(\Core\Database\Connection::withPdo($this->pdo), $this->encryption),
            null,
            new MemberEmailRepository($this->pdo, $this->encryption)
        );

        // Inserted newest first: the latest record is the one with the
        // latest start_date, never the highest id.
        $this->thisYear = $this->year('2025-2026', '2025-09-01');
        $this->lastYear = $this->year('2024-2025', '2024-09-01');
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
    }

    /**
     * The audited scenario: last year's Desk address, replaced this year,
     * opens nothing — neither last year's record nor this year's.
     */
    public function testAnAddressDeskReplacedLosesEveryYear(): void
    {
        $old = $this->record($this->lastYear, 'ancien@example.be');
        $new = $this->record($this->thisYear, 'nouveau@example.be');

        $this->assertFalse($this->service->canAccess('ancien@example.be', $old, 'identified'));
        $this->assertFalse($this->service->canAccess('ancien@example.be', $new, 'identified'));
        $this->assertFalse($this->service->isLinkedToMember('ancien@example.be', $this->memberId, $this->lastYear));
    }

    /** The current Desk address reaches every year, the past one included. */
    public function testTheCurrentDeskAddressReachesEveryYear(): void
    {
        $old = $this->record($this->lastYear, 'ancien@example.be');
        $new = $this->record($this->thisYear, 'nouveau@example.be');

        $this->assertTrue($this->service->canAccess('nouveau@example.be', $new, 'identified'));
        $this->assertTrue($this->service->canAccess('nouveau@example.be', $old, 'identified'));
        $this->assertTrue($this->service->isLinkedToMember('nouveau@example.be', $this->memberId, $this->lastYear));
    }

    /**
     * Desk REMOVING the address — the latest record carries none — takes it
     * away too: the older record's address is not fallen back on.
     */
    public function testAnAddressDeskRemovedLosesEveryYear(): void
    {
        $old = $this->record($this->lastYear, 'ancien@example.be');
        $this->record($this->thisYear, null);

        $this->assertFalse($this->service->canAccess('ancien@example.be', $old, 'identified'));
    }

    /**
     * A confirmed secondary address reaches every year; removed on the
     * member page, it loses all of it.
     */
    public function testASecondaryAddressReachesEveryYearUntilItIsRemoved(): void
    {
        $old = $this->record($this->lastYear, 'desk@example.be');
        $new = $this->record($this->thisYear, 'desk@example.be');
        $secondaryId = $this->secondary('ajoutee@example.be', 'valid');

        $this->assertTrue($this->service->canAccess('ajoutee@example.be', $old, 'identified'));
        $this->assertTrue($this->service->canAccess('ajoutee@example.be', $new, 'identified'));

        $this->pdo->prepare("UPDATE member_emails SET status = 'inactive' WHERE id = ?")->execute([$secondaryId]);

        $this->assertFalse($this->service->canAccess('ajoutee@example.be', $old, 'identified'));
        $this->assertFalse($this->service->canAccess('ajoutee@example.be', $new, 'identified'));
    }

    /**
     * Through the real gate of the health form — the most sensitive of the
     * five surfaces the audit listed: the replaced address is refused on
     * last year's record id, the current one admitted. HealthSheetControllerTest
     * proves every route of the form writes nothing once this says no.
     */
    public function testTheHealthFormGateFollowsTheSameRule(): void
    {
        $old = $this->record($this->lastYear, 'ancien@example.be');
        $this->record($this->thisYear, 'nouveau@example.be');
        $gate = new \Modules\OfficialDocuments\Security\OwnMemberOnly($this->service);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }

        try {
            \Core\Security\AuthSession::login(1, 'ancien@example.be', 'identified');
            $this->assertFalse($gate->allows($old));
            $this->assertNull($gate->profileFor($old));

            \Core\Security\AuthSession::login(2, 'nouveau@example.be', 'identified');
            $this->assertTrue($gate->allows($old));
        } finally {
            \Core\Security\AuthSession::logout();
            $_SESSION = [];
        }
    }

    /** Staff keep their own rule, unchanged. */
    public function testChiefsAndAdminsKeepTheirAccess(): void
    {
        $old = $this->record($this->lastYear, 'ancien@example.be');
        $this->record($this->thisYear, 'nouveau@example.be');

        $this->assertTrue($this->service->canAccess('chef@example.be', $old, 'chief'));
        $this->assertTrue($this->service->canAccess('admin@example.be', $old, 'admin'));
    }

    private function year(string $label, string $start): int
    {
        $end = (string) ((int) substr($start, 0, 4) + 1) . '-08-31';
        $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 0)')
            ->execute([$label, $start, $end]);

        return (int) $this->pdo->lastInsertId();
    }

    private function record(int $scoutYearId, ?string $email): int
    {
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                email_encrypted, email_blind_index) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->memberId,
            $scoutYearId,
            $this->encryption->encrypt('Léa', 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $email !== null ? $this->encryption->encrypt($email, 'member_years.email') : null,
            $email !== null ? $this->encryption->blindIndex(strtolower($email), 'email') : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function secondary(string $email, string $status): int
    {
        $this->pdo->prepare(
            "INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (?, ?, ?, 'manual', ?)"
        )->execute([
            $this->memberId,
            $this->encryption->encrypt($email, 'member_emails.email'),
            $this->encryption->blindIndex(strtolower($email), 'email'),
            $status,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
