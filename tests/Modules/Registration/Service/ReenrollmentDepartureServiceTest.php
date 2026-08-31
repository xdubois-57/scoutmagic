<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\DepartureRepository;
use Core\Member\DepartureService;
use Core\Member\MemberService;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\ReenrollmentRepository;
use Modules\Registration\Service\ReenrollmentDepartureService;
use Modules\Registration\Service\ReenrollmentService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * IT-16 — a family's answer and the staff's « Départs » box write the same
 * fact, and this is the class that keeps them one fact.
 *
 * Every test here goes through ReenrollmentService::recordAnswer(), never
 * through ReenrollmentDepartureService::apply() alone: what has to hold is
 * that recording an answer MOVES the box, and a test that called the link
 * by hand would pass on a day the two came apart.
 *
 * @group database
 */
class ReenrollmentDepartureServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ReenrollmentService $service;
    private ReenrollmentRepository $repository;
    private DepartureService $departureService;
    private ReenrollmentDepartureService $link;
    private int $publicYearId;
    private int $targetYearId;
    private int $memberId;
    private int $memberYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $scoutYearService = new ScoutYearService($this->pdo);
        $this->publicYearId = $scoutYearService->ensureYear('2026-2027');
        $this->targetYearId = $scoutYearService->ensureYear('2027-2028');

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register(
            ReenrollmentService::SETTING_FRIEND_WISH_LIMIT,
            '3',
            'number',
            'Souhaits « avec qui »',
            "Combien d'amis une famille peut citer.",
            'registration'
        );

        $this->repository = new ReenrollmentRepository($this->pdo, $this->encryption);
        $this->departureService = new DepartureService(
            new DepartureRepository($this->pdo, $this->encryption),
            new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo))
        );
        $this->link = RegistrationTestHelper::departureLink($this->pdo, $this->encryption, $settingService);

        $this->service = new ReenrollmentService(
            $this->repository,
            $settingService,
            new MemberService(
                new MemberYearRepository($this->pdo),
                $this->encryption,
                Connection::withPdo($this->pdo)
            ),
            $this->link,
            RegistrationTestHelper::projectedPopulation($this->pdo, $this->encryption, $settingService),
            new \Core\Member\SectionService(
                Connection::withPdo($this->pdo),
                $this->encryption,
                new \Core\Badge\MemberBadgeRepository($this->pdo)
            )
        );

        [$this->memberId, $this->memberYearId] = $this->createMember('Léa');
    }

    // ── the answer moves the box ──────────────────────────────────────

    public function testAnAnswerSayingTheChildLeavesTicksTheDepartureBox(): void
    {
        $this->answer('leaving');

        $this->assertTrue($this->departureService->getStatus($this->memberYearId)?->leaving);
    }

    public function testAnAnswerSayingTheChildStaysLeavesTheBoxAlone(): void
    {
        $this->answer('reenrolled');

        $this->assertFalse($this->departureService->getStatus($this->memberYearId)?->leaving);
    }

    public function testAFamilyChangingTheirMindUnticksWhatTheirOwnEarlierAnswerTicked(): void
    {
        $this->answer('leaving');
        $this->answer('reenrolled');

        $this->assertFalse(
            $this->departureService->getStatus($this->memberYearId)?->leaving,
            'the box the family ticked is the box the family may untick'
        );
    }

    public function testTheBoxIsWrittenOnThePublicYearNotOnTheTargetYear(): void
    {
        // A second membership row, for the year the answer is ABOUT. It
        // must stay untouched: "will not be back next year" is a fact
        // about the membership that exists today, which is the row
        // « Départs », Passage and Prévisions all read.
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->memberId,
            $this->targetYearId,
            $this->encryption->encrypt('Léa', 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
        ]);
        $futureMemberYearId = (int) $this->pdo->lastInsertId();

        $this->answer('leaving');

        $this->assertTrue($this->departureService->getStatus($this->memberYearId)?->leaving);
        $this->assertFalse($this->departureService->getStatus($futureMemberYearId)?->leaving);
    }

    // ── the staff have the last word ──────────────────────────────────

    public function testAStaffCorrectionSurvivesTheFamilyAnsweringAgain(): void
    {
        $this->answer('leaving');
        // The chief knows better: the family told the section they are
        // staying after all.
        $this->departureService->unmarkLeaving($this->memberYearId);

        $this->answer('leaving');

        $this->assertFalse(
            $this->departureService->getStatus($this->memberYearId)?->leaving,
            'the staff decision stands, and stands again'
        );
    }

    public function testAStaffTickSurvivesAnAnswerSayingTheChildStays(): void
    {
        $this->departureService->markLeaving($this->memberYearId, 'Parti en cours de saison');

        $this->answer('reenrolled');

        $this->assertTrue(
            $this->departureService->getStatus($this->memberYearId)?->leaving,
            'a box the staff ticked before any answer is theirs, not the form\'s'
        );
    }

    public function testTheFamilyAnswerItselfIsUntouchedByAStaffCorrection(): void
    {
        $this->answer('leaving', 'Nous déménageons en juillet.');
        $this->departureService->unmarkLeaving($this->memberYearId);

        $answer = $this->repository->findAnswer($this->memberId, $this->targetYearId);

        $this->assertNotNull($answer);
        $this->assertSame('leaving', $answer->decision);
        $this->assertSame('Nous déménageons en juillet.', $answer->familyComment);
    }

    public function testTheStaffTakingTheBoxBackIsUndoableByTheStaffThemselves(): void
    {
        $this->answer('leaving');
        $this->departureService->unmarkLeaving($this->memberYearId);
        // Putting it back where the automation left it hands ownership
        // back: the rule is "is the box still where I put it?", not "has
        // a human ever touched this row".
        $this->departureService->markLeaving($this->memberYearId, null);

        $this->answer('reenrolled');

        $this->assertFalse($this->departureService->getStatus($this->memberYearId)?->leaving);
    }

    public function testTickingTheBoxNeverFabricatesAFamilyAnswer(): void
    {
        $this->departureService->markLeaving($this->memberYearId, 'Note du staff');

        $this->assertNull(
            $this->repository->findAnswer($this->memberId, $this->targetYearId),
            'the link runs one way: an answer is something a parent said'
        );
    }

    // ── the two comments are two fields ───────────────────────────────

    public function testAFamilyAnswerNeverErasesTheStaffNote(): void
    {
        $this->departureService->markLeaving($this->memberYearId, "Situation familiale suivie par l'animateur");
        // Back to "not leaving" would clear the note, so the staff note is
        // set on a row the automation then agrees with: the family says
        // the same thing, and the note must still be there afterwards.
        $this->answer('leaving');

        $this->assertSame(
            "Situation familiale suivie par l'animateur",
            $this->departureService->getStatus($this->memberYearId)?->comment
        );
    }

    public function testTheFamilyCommentNeverLandsInTheStaffNote(): void
    {
        $this->answer('leaving', 'Nous déménageons en juillet.');

        $this->assertNull(
            $this->departureService->getStatus($this->memberYearId)?->comment,
            'leaving_comment_encrypted is the staff\'s field and only theirs'
        );
    }

    // ── the journal ───────────────────────────────────────────────────

    public function testTheAutomaticTickIsJournaledUnderItsOwnTypeWithTheMemberIdOnly(): void
    {
        $this->answer('leaving', 'Nous déménageons en juillet.');

        $entry = $this->journalEntry('member_leaving_set_by_family');

        $this->assertNotNull($entry, 'an automatic write with no trace is what a journal exists to prevent');
        $this->assertSame('info', $entry['level']);
        $this->assertStringNotContainsString('déménageons', (string) $entry['context']);
        $this->assertStringNotContainsString('déménageons', (string) $entry['description']);
        $this->assertSame(['member_id' => $this->memberId], json_decode((string) $entry['context'], true));
    }

    public function testAnAnswerThatChangesNothingJournalsNothing(): void
    {
        $this->answer('reenrolled');

        $this->assertNull($this->journalEntry('member_leaving_set_by_family'));
        $this->assertNull($this->journalEntry('member_leaving_cleared_by_family'));
    }

    public function testUnticklingByTheFamilyIsJournaledToo(): void
    {
        $this->answer('leaving');
        $this->answer('reenrolled');

        $this->assertNotNull($this->journalEntry('member_leaving_cleared_by_family'));
    }

    // ── divergences ───────────────────────────────────────────────────

    public function testADivergenceIsCountedAndAMatchIsNot(): void
    {
        [, $secondMemberYearId] = $this->createMember('Noé');
        [$thirdMemberId, $thirdMemberYearId] = $this->createMember('Zoé');

        // Léa: the family says leaving, the chief disagrees → divergence.
        $this->answer('leaving');
        $this->departureService->unmarkLeaving($this->memberYearId);

        // Zoé: the family says leaving and the box agrees → no divergence.
        $this->answer('leaving', null, $thirdMemberId);

        // Noé: nobody answered → counted as silent, never as a divergence.

        $result = $this->link->annotate($this->rowsFor([
            $this->memberYearId => $this->memberId,
            $secondMemberYearId => $this->memberIdOf($secondMemberYearId),
            $thirdMemberYearId => $thirdMemberId,
        ]), '2026-2027');

        $this->assertSame(1, $result['divergences']);
        $this->assertSame(1, $result['unanswered']);
        $this->assertTrue($result['visible']);
        $this->assertSame('2027-2028', $result['target_year_label']);
    }

    public function testAUnitThatNeverRanACampaignIsNotShownTheColumn(): void
    {
        $result = $this->link->annotate($this->rowsFor([$this->memberYearId => $this->memberId]), '2026-2027');

        $this->assertFalse($result['visible']);
        $this->assertSame(0, $result['divergences']);
        $this->assertSame(1, $result['unanswered']);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function answer(string $decision, ?string $comment = null, ?int $memberId = null): void
    {
        $this->service->recordAnswer(
            $memberId ?? $this->memberId,
            $this->targetYearId,
            $decision,
            null,
            $comment,
            [],
            $this->publicYearId
        );
    }

    /**
     * @param array<int, int> $memberYearIdToMemberId
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(array $memberYearIdToMemberId): array
    {
        $rows = [];
        foreach ($memberYearIdToMemberId as $memberYearId => $memberId) {
            $rows[] = [
                'profile' => new class ($memberId) {
                    public function __construct(public readonly int $memberId)
                    {
                    }
                },
                'leaving' => $this->departureService->getStatus($memberYearId)?->leaving ?? false,
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: int, 1: int} member id, member_year id
     */
    private function createMember(string $firstName): array
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->publicYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
        ]);

        return [$memberId, (int) $this->pdo->lastInsertId()];
    }

    private function memberIdOf(int $memberYearId): int
    {
        $stmt = $this->pdo->prepare('SELECT member_id FROM member_years WHERE id = ?');
        $stmt->execute([$memberYearId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function journalEntry(string $type): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event_log WHERE event_type = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$type]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
