<?php

declare(strict_types=1);

namespace Tests\Core\Badge;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Badge\TreasurerBadgeDeskImportListener;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The half of issue #222 a refusal cannot cover: an animateur who carried
 * the Trésorier badge appears as « Intendant » in the next Desk export,
 * the assignment is untouched, and from that moment it grants nothing.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TreasurerBadgeDeskImportListenerTest extends TestCase
{
    private \PDO $pdo;
    private BadgeService $badgeService;
    private TreasurerBadgeDeskImportListener $listener;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $memberBadges = new MemberBadgeRepository($this->pdo);
        $this->badgeService = new BadgeService(
            new BadgeRepository($this->pdo),
            $memberBadges,
            new SectionService(
                Connection::withPdo($this->pdo),
                new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
                $memberBadges
            )
        );
        $this->listener = new TreasurerBadgeDeskImportListener(
            $this->badgeService,
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    public function testAnImportThatStrandsNobodyWritesNothing(): void
    {
        $this->treasurerWhoAnimates();

        $this->listener->onDeskImportCompleted($this->scoutYearId, []);

        $this->assertSame(0, $this->warningCount());
    }

    public function testAnImportThatStripsTheAnimatingFunctionSaysSo(): void
    {
        $memberYearId = $this->treasurerWhoAnimates();
        $this->demoteToIntendant($memberYearId);

        $this->listener->onDeskImportCompleted($this->scoutYearId, []);

        $this->assertSame(1, $this->warningCount());

        $row = $this->lastWarning();
        $this->assertSame('warning', $row['level']);
        $context = json_decode((string) $row['context'], true);
        $this->assertSame([$memberYearId], $context['member_year_ids']);
        $this->assertSame(1, $context['count']);
    }

    /**
     * One line per import, never one per person — the same rule the
     * leadership listener states at length.
     */
    public function testTwoStrandedHoldersProduceOneLine(): void
    {
        foreach ([$this->treasurerWhoAnimates('D1'), $this->treasurerWhoAnimates('D2')] as $memberYearId) {
            $this->demoteToIntendant($memberYearId);
        }

        $this->listener->onDeskImportCompleted($this->scoutYearId, []);

        $this->assertSame(1, $this->warningCount());
        $this->assertSame(2, json_decode((string) $this->lastWarning()['context'], true)['count']);
    }

    /** No name, no totem, no address — ids and a count (SECURITY.md §11). */
    public function testTheLineNamesNobody(): void
    {
        $this->demoteToIntendant($this->treasurerWhoAnimates());

        $this->listener->onDeskImportCompleted($this->scoutYearId, []);

        $row = $this->lastWarning();
        $written = strtolower((string) $row['context'] . ' ' . (string) $row['description']);
        $this->assertStringNotContainsString('dupont', $written);
        $this->assertStringNotContainsString('@', $written);
    }

    private function treasurerWhoAnimates(string $deskId = 'D1'): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('{$deskId}')");
        $memberId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted)'
            . ' VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $this->scoutYearId, 'enc', 'enc']);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BR_{$deskId}', 'Branch', 10)");
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute(['SEC_' . $deskId, $branchId, 'Section']);
        $sectionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec(
            "INSERT INTO functions (desk_code, label, role) VALUES ('FN_" . uniqid() . "', 'Chef', 'chief')"
        );
        $functionId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId]);

        $this->badgeService->ensureDefaults();
        $badge = (new BadgeRepository($this->pdo))->findByName(BadgeService::BADGE_TREASURER);
        $this->assertNotNull($badge);
        $this->badgeService->toggleAssignment($memberYearId, $badge->id, null);

        return $memberYearId;
    }

    private function demoteToIntendant(int $memberYearId): void
    {
        $this->pdo->exec(
            "UPDATE functions SET role = 'intendant' WHERE id IN ("
            . "SELECT function_id FROM member_functions WHERE member_year_id = {$memberYearId})"
        );
    }

    private function warningCount(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM event_log WHERE event_type = 'treasurer_badge_stranded'"
        )->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function lastWarning(): array
    {
        $row = $this->pdo->query(
            "SELECT level, context, description FROM event_log WHERE event_type = 'treasurer_badge_stranded'"
            . ' ORDER BY id DESC LIMIT 1'
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        return $row;
    }
}
