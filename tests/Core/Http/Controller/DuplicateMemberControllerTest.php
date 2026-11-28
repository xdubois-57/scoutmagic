<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\DuplicateMemberController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Import\ImportReportRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\Duplicate\DuplicateMemberRepository;
use Core\Member\Duplicate\MemberMergeListenerRegistry;
use Core\Member\Duplicate\MemberMergeRepository;
use Core\Member\Duplicate\MemberMergeService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\TestTwig;

/**
 * « Fusionner » on /admin/doublons, when the service refuses the pair.
 */
#[Group('database')]
final class DuplicateMemberControllerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private DuplicateMemberRepository $duplicates;
    private DuplicateMemberController $controller;
    private MemberMergeListenerRegistry $listeners;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->duplicates = new DuplicateMemberRepository($this->pdo, $this->encryption);
        $this->listeners = new MemberMergeListenerRegistry();
        $settings = new SettingService(new SettingRepository($this->pdo));
        $this->controller = new DuplicateMemberController(
            TestTwig::create(),
            $this->duplicates,
            new MemberMergeService(
                new MemberMergeRepository($this->pdo, $this->duplicates, $this->listeners),
                $this->duplicates,
                new JournalService(new JournalRepository($this->pdo))
            ),
            new ImportReportRepository($this->pdo, $this->encryption),
            new ScoutYearResolver(
                new ScoutYearService($this->pdo),
                $settings,
                new MemberYearRepository($this->pdo)
            )
        );

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = ['_csrf_token' => 'test-token'];
        $_SESSION['_auth'] = ['user_account_id' => 1, 'email' => 'chef@test.com', 'role' => 'chief'];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * A pair proposed before a later import put both records in the same
     * scout year: the merge is refused with the service's own sentence,
     * and neither record is merged nor the proposal closed.
     */
    public function testAPairTheServiceRefusesIsReportedAndNothingIsMerged(): void
    {
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current)
             VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)"
        );
        $year = (int) $this->pdo->lastInsertId();
        $kept = $this->createMember('T001', $year);
        $duplicate = $this->createMember('T900', $year);
        $candidate = $this->duplicates->recordCandidate($kept, $duplicate, false);

        $response = $this->controller->merge(
            new Request('POST', "/admin/doublons/{$candidate}/fusionner", [], ['_csrf_token' => 'test-token'], [], []),
            ['id' => (string) $candidate]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/doublons', $response->getHeaders()['Location'] ?? null);
        $this->assertSame(
            [
                'type' => 'error',
                'message' => 'Ces deux fiches sont présentes la même année scoute : Desk les considère comme deux '
                    . "personnes distinctes. La fusion ne concerne qu'une fiche recréée d'une année à l'autre.",
            ],
            FlashMessage::get()
        );
        $merged = $this->pdo->query('SELECT COUNT(*) FROM members WHERE merged_into_member_id IS NOT NULL');
        $this->assertSame(0, (int) $merged->fetchColumn(), 'a refused merge marked a record as merged');
        $this->assertSame('pending', $this->duplicates->findById($candidate)['status'] ?? null);
    }

    /**
     * Issue #1462: the screen lists, before the confirmation, what a merge
     * would bring along — the chef d'unité's notes and every enabled
     * module's own rows, in the module's own words.
     */
    public function testTheScreenListsTheNotesAndWhatEachModuleWouldBringAlong(): void
    {
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current)
             VALUES ('2024-2025', '2024-09-01', '2025-08-31', 0), ('2025-2026', '2025-09-01', '2026-08-31', 1)"
        );
        $years = $this->pdo->query('SELECT id FROM scout_years ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $kept = $this->createMember('T001', (int) $years[0]);
        $duplicate = $this->createMember('T900', (int) $years[1]);
        $this->pdo->prepare('INSERT INTO member_notes (member_id, body) VALUES (?, ?)')->execute([$duplicate, 'x']);
        $this->duplicates->recordCandidate($kept, $duplicate, false);
        $this->listeners->register(
            new \Tests\Core\Member\Duplicate\RecordingMergeListener(['soirée(s) de présence' => 10], 10)
        );

        $body = $this->controller->index(new Request('GET', '/admin/doublons', [], [], [], []), [])->getBody();

        $this->assertStringContainsString("<strong>1</strong> note(s) du chef d&#039;unité", $body);
        $this->assertStringContainsString('<strong>10</strong> soirée(s) de présence', $body);
    }

    private function createMember(string $deskId, int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$deskId]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $this->encryption->encrypt('Pierre', 'member_years.first_name'),
            $this->encryption->encrypt('Grosjean', 'member_years.last_name'),
        ]);

        return $memberId;
    }
}
