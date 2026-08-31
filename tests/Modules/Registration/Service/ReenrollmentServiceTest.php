<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\FriendWish;
use Modules\Registration\Repository\ReenrollmentAnswer;
use Modules\Registration\Repository\ReenrollmentRepository;
use Modules\Registration\Service\ReenrollmentService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The reenrollment answer: what a family says about next year, the friends
 * they name, and the two rules that make the wish cap safe to change.
 *
 * The data is the point of this iteration — there is no interface yet — so
 * what is pinned here is the behaviour a screen will later depend on:
 *
 * - **Nothing is a "pending" row.** The absence of an answer IS "no answer
 *   yet"; a third decision value would be a second way to say nothing, and
 *   a reminder query could then disagree with it.
 * - **The cap is revalidated on the server, silently.** A form that posts
 *   more names has the extra ones dropped, never refused: a parent who
 *   typed four into a form that should have offered three has done nothing
 *   wrong, and an error at that moment costs the whole answer.
 * - **Lowering the cap destroys nothing.** It is applied on read. A unit
 *   that goes from three to two stops using the third and gets it back by
 *   raising the setting again.
 * - **A third party's name never leaves the repository in clear**, and
 *   never reaches the database in clear either.
 *
 * @group database
 */
class ReenrollmentServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private SettingService $settingService;
    private ReenrollmentRepository $repository;
    private ReenrollmentService $service;
    private int $currentYearId;
    private int $targetYearId;
    private int $sectionId;
    private int $otherSectionId;

    /** @var array<string, int> display name => member id */
    private array $members = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $branchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $this->sectionId = $this->createSection('LOUV1', $branchId, 'Louveteaux A');
        $this->otherSectionId = $this->createSection('LOUV2', $branchId, 'Louveteaux B');

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register(
            ReenrollmentService::SETTING_FRIEND_WISH_LIMIT,
            '3',
            'number',
            'Souhaits « avec qui »',
            'Combien d\'amis une famille peut citer.',
            'registration'
        );

        $memberService = new MemberService(
            new MemberYearRepository($this->pdo),
            $this->encryption,
            Connection::withPdo($this->pdo)
        );

        $this->repository = new ReenrollmentRepository($this->pdo, $this->encryption);
        $this->service = new ReenrollmentService($this->repository, $this->settingService, $memberService);

        $this->members['Léo Martin'] = $this->createMember('Léo', 'Martin');
        $this->members['Zoé Martin'] = $this->createMember('Zoé', 'Martin');
        $this->members['Léo Dupont'] = $this->createMember('Léo', 'Dupont');
        $this->members['Sacha Petit'] = $this->createMember('Sacha', 'Petit');
    }

    // ── writing then reading back ─────────────────────────────────────

    public function testNoRowMeansNoAnswerYet(): void
    {
        $this->assertNull(
            $this->service->findAnswer($this->members['Sacha Petit'], $this->targetYearId),
            'The absence of a row IS "no answer" — there is no third decision value to look for.'
        );
    }

    public function testAnAnswerWithoutWishesComesBackWhole(): void
    {
        $answer = $this->service->recordAnswer(
            $this->members['Sacha Petit'],
            $this->targetYearId,
            ReenrollmentAnswer::DECISION_REENROLLED,
            $this->sectionId,
            "Sacha préfère rester avec les mêmes animateurs, s'il vous plaît.",
            [],
            $this->currentYearId,
            42
        );

        $this->assertTrue($answer->isReenrolled());
        $this->assertSame($this->sectionId, $answer->preferredSectionId);
        $this->assertSame("Sacha préfère rester avec les mêmes animateurs, s'il vous plaît.", $answer->familyComment);
        $this->assertSame(42, $answer->answeredByUserAccountId);
        $this->assertSame([], $answer->friendWishes);

        $reread = $this->service->findAnswer($this->members['Sacha Petit'], $this->targetYearId);
        $this->assertNotNull($reread);
        $this->assertSame($answer->familyComment, $reread->familyComment);
    }

    public function testAnAnswerWithWishesKeepsTheFamilysOwnOrder(): void
    {
        $answer = $this->service->recordAnswer(
            $this->members['Sacha Petit'],
            $this->targetYearId,
            ReenrollmentAnswer::DECISION_REENROLLED,
            null,
            null,
            ['Zoé Martin', 'Léo Dupont'],
            $this->currentYearId
        );

        $this->assertSame(['Zoé Martin', 'Léo Dupont'], array_column($answer->friendWishes, 'rawName'));
        $this->assertSame([0, 1], array_column($answer->friendWishes, 'position'));
    }

    public function testAnsweringAgainReplacesTheWholeAnswerRatherThanAddingToIt(): void
    {
        $memberId = $this->members['Sacha Petit'];

        $this->service->recordAnswer($memberId, $this->targetYearId, 'reenrolled', $this->sectionId, 'Premier avis.', ['Zoé Martin'], $this->currentYearId);
        $answer = $this->service->recordAnswer($memberId, $this->targetYearId, 'reenrolled', $this->otherSectionId, 'Deuxième avis.', ['Léo Dupont'], $this->currentYearId);

        $this->assertSame('Deuxième avis.', $answer->familyComment);
        $this->assertSame($this->otherSectionId, $answer->preferredSectionId);
        $this->assertSame(['Léo Dupont'], array_column($answer->friendWishes, 'rawName'));
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM registration_reenrollments')->fetchColumn(),
            'The table\'s UNIQUE says one answer per member and year; a second row would be a second truth.'
        );
    }

    public function testAFamilyWhoIsLeavingIsNotAskedWhereOrWithWhom(): void
    {
        $answer = $this->service->recordAnswer(
            $this->members['Sacha Petit'],
            $this->targetYearId,
            ReenrollmentAnswer::DECISION_LEAVING,
            $this->sectionId,
            'Nous déménageons.',
            ['Zoé Martin'],
            $this->currentYearId
        );

        $this->assertFalse($answer->isReenrolled());
        $this->assertNull($answer->preferredSectionId, 'A form that posted a section answered a question nobody asked.');
        $this->assertSame([], $answer->friendWishes);
        $this->assertSame('Nous déménageons.', $answer->familyComment, 'The comment is still theirs to write.');
    }

    // ── the cap ───────────────────────────────────────────────────────

    public function testTheServerDropsWhatExceedsTheCapWithoutRefusingTheAnswer(): void
    {
        $answer = $this->service->recordAnswer(
            $this->members['Sacha Petit'],
            $this->targetYearId,
            'reenrolled',
            null,
            null,
            ['Zoé Martin', 'Léo Dupont', 'Léo Martin', 'Quatrième Nom', 'Cinquième Nom'],
            $this->currentYearId
        );

        $this->assertCount(3, $answer->friendWishes);
        $this->assertSame(['Zoé Martin', 'Léo Dupont', 'Léo Martin'], array_column($answer->friendWishes, 'rawName'));
    }

    public function testLoweringTheCapHidesTheExtraWishesAndDeletesNothing(): void
    {
        $memberId = $this->members['Sacha Petit'];
        $this->service->recordAnswer($memberId, $this->targetYearId, 'reenrolled', null, null, ['Zoé Martin', 'Léo Dupont', 'Léo Martin'], $this->currentYearId);

        $this->settingService->set(ReenrollmentService::SETTING_FRIEND_WISH_LIMIT, '1', 'registration');

        $answer = $this->service->findAnswer($memberId, $this->targetYearId);
        $this->assertNotNull($answer);
        $this->assertCount(3, $answer->friendWishes, 'Nothing a family entered is destroyed because a chief moved a number.');
        $this->assertCount(1, $this->service->usableWishes($answer));
        $this->assertSame(
            3,
            (int) $this->pdo->query('SELECT COUNT(*) FROM registration_friend_wishes')->fetchColumn()
        );

        // And raising it again brings them back into play.
        $this->settingService->set(ReenrollmentService::SETTING_FRIEND_WISH_LIMIT, '3', 'registration');
        $this->assertCount(3, $this->service->usableWishes($answer));
    }

    public function testACapOfZeroStoresNoWishAtAll(): void
    {
        $this->settingService->set(ReenrollmentService::SETTING_FRIEND_WISH_LIMIT, '0', 'registration');

        $answer = $this->service->recordAnswer(
            $this->members['Sacha Petit'],
            $this->targetYearId,
            'reenrolled',
            null,
            null,
            ['Zoé Martin'],
            $this->currentYearId
        );

        $this->assertSame([], $answer->friendWishes);
    }

    public function testASettingSomebodyTypedAWordIntoFallsBackToTheShippedDefault(): void
    {
        // Written straight to the repository: SettingService validates a
        // `number`, so this cannot happen through the page — it can happen
        // through a migration, a restored backup or a hand-edited row, and
        // those are exactly the cases nobody is watching.
        (new SettingRepository($this->pdo))->updateValue('registration', ReenrollmentService::SETTING_FRIEND_WISH_LIMIT, 'trois');
        $this->settingService->clearCache();

        $this->assertSame(
            3,
            $this->service->friendWishLimit(),
            'Falling back to zero would silently switch the whole question off.'
        );
    }

    public function testANegativeCapIsReadAsNoneRatherThanAsAnArraySliceOddity(): void
    {
        (new SettingRepository($this->pdo))->updateValue('registration', ReenrollmentService::SETTING_FRIEND_WISH_LIMIT, '-2');
        $this->settingService->clearCache();

        $this->assertSame(0, $this->service->friendWishLimit());
    }

    // ── resolving a name ──────────────────────────────────────────────

    public function testAnUnambiguousNameIsMatchedToItsMember(): void
    {
        $answer = $this->service->recordAnswer(
            $this->members['Sacha Petit'],
            $this->targetYearId,
            'reenrolled',
            null,
            null,
            ['zoé   MARTIN'],
            $this->currentYearId
        );

        $wish = $answer->friendWishes[0];
        $this->assertSame(FriendWish::MATCH_UNIQUE, $wish->matchState);
        $this->assertSame($this->members['Zoé Martin'], $wish->matchedMemberId);
        $this->assertTrue($wish->isUsable());
        $this->assertSame('zoé   MARTIN', $wish->rawName, 'What the family typed is kept as they typed it.');
    }

    public function testAFirstNameSharedByTwoChildrenIsAmbiguousNotAGuess(): void
    {
        $answer = $this->service->recordAnswer(
            $this->members['Sacha Petit'],
            $this->targetYearId,
            'reenrolled',
            null,
            null,
            ['Léo'],
            $this->currentYearId
        );

        $wish = $answer->friendWishes[0];
        $this->assertSame(FriendWish::MATCH_AMBIGUOUS, $wish->matchState);
        $this->assertNull($wish->matchedMemberId);
        $this->assertFalse($wish->isUsable());
    }

    public function testAFullNameIsPreferredOverAFirstNameSharedByOthers(): void
    {
        $answer = $this->service->recordAnswer(
            $this->members['Sacha Petit'],
            $this->targetYearId,
            'reenrolled',
            null,
            null,
            ['Leo Dupont'],
            $this->currentYearId
        );

        $wish = $answer->friendWishes[0];
        $this->assertSame(FriendWish::MATCH_UNIQUE, $wish->matchState);
        $this->assertSame($this->members['Léo Dupont'], $wish->matchedMemberId);
    }

    public function testANameNobodyCarriesIsRecordedAsSuchRatherThanRefused(): void
    {
        $answer = $this->service->recordAnswer(
            $this->members['Sacha Petit'],
            $this->targetYearId,
            'reenrolled',
            null,
            null,
            ['Un Enfant Qui N\'Existe Pas'],
            $this->currentYearId
        );

        $wish = $answer->friendWishes[0];
        $this->assertSame(FriendWish::MATCH_NONE, $wish->matchState);
        $this->assertNull($wish->matchedMemberId);
        $this->assertSame(
            "Un Enfant Qui N'Existe Pas",
            $wish->rawName,
            'The form gives no feedback about who was found, so a miss is stored, never reported.'
        );
    }

    public function testAChildNamingThemselvesIsNotAWish(): void
    {
        $answer = $this->service->recordAnswer(
            $this->members['Zoé Martin'],
            $this->targetYearId,
            'reenrolled',
            null,
            null,
            ['Zoé Martin'],
            $this->currentYearId
        );

        $this->assertSame(FriendWish::MATCH_NONE, $answer->friendWishes[0]->matchState);
    }

    public function testABlankNameIsDroppedRatherThanStoredEmpty(): void
    {
        $answer = $this->service->recordAnswer(
            $this->members['Sacha Petit'],
            $this->targetYearId,
            'reenrolled',
            null,
            null,
            ['', '   ', 'Zoé Martin'],
            $this->currentYearId
        );

        $this->assertCount(1, $answer->friendWishes);
        $this->assertSame('Zoé Martin', $answer->friendWishes[0]->rawName);
    }

    // ── what the database actually holds ──────────────────────────────

    public function testNeitherAThirdPartysNameNorTheCommentIsReadableInTheDatabase(): void
    {
        $this->service->recordAnswer(
            $this->members['Sacha Petit'],
            $this->targetYearId,
            'reenrolled',
            null,
            'Sacha a été malade toute l\'année.',
            ['Zoé Martin'],
            $this->currentYearId
        );

        $comment = (string) $this->pdo->query('SELECT family_comment_encrypted FROM registration_reenrollments')->fetchColumn();
        $name = (string) $this->pdo->query('SELECT raw_name_encrypted FROM registration_friend_wishes')->fetchColumn();

        $this->assertNotSame('', $comment);
        $this->assertStringNotContainsString('malade', $comment);
        $this->assertNotSame('', $name);
        $this->assertStringNotContainsString('Zoé', $name);
        $this->assertStringNotContainsString('Martin', $name);
    }

    public function testAnEmptyCommentIsStoredAsNothingRatherThanAsAnEncryptedEmptyString(): void
    {
        $this->service->recordAnswer($this->members['Sacha Petit'], $this->targetYearId, 'reenrolled', null, '   ', [], $this->currentYearId);

        $this->assertNull($this->pdo->query('SELECT family_comment_encrypted FROM registration_reenrollments')->fetchColumn() ?: null);
        $answer = $this->service->findAnswer($this->members['Sacha Petit'], $this->targetYearId);
        $this->assertNotNull($answer);
        $this->assertNull($answer->familyComment);
    }

    public function testTheAnsweredIdsAreReadableWithoutDecryptingAnything(): void
    {
        $this->service->recordAnswer($this->members['Sacha Petit'], $this->targetYearId, 'reenrolled', null, 'x', [], $this->currentYearId);
        $this->service->recordAnswer($this->members['Zoé Martin'], $this->targetYearId, 'leaving', null, null, [], $this->currentYearId);

        $answered = $this->repository->answeredMemberIds($this->targetYearId);
        sort($answered);
        $expected = [$this->members['Sacha Petit'], $this->members['Zoé Martin']];
        sort($expected);

        $this->assertSame($expected, $answered);
    }

    public function testAnswersForAYearComeBackKeyedByMember(): void
    {
        $this->service->recordAnswer($this->members['Sacha Petit'], $this->targetYearId, 'reenrolled', null, null, [], $this->currentYearId);
        $this->service->recordAnswer($this->members['Zoé Martin'], $this->targetYearId, 'leaving', null, null, [], $this->currentYearId);
        // Another year's answer must not leak into this one's.
        $this->service->recordAnswer($this->members['Léo Martin'], $this->currentYearId, 'reenrolled', null, null, [], $this->currentYearId);

        $answers = $this->repository->findAnswersForYear($this->targetYearId);

        $this->assertCount(2, $answers);
        $this->assertTrue($answers[$this->members['Sacha Petit']]->isReenrolled());
        $this->assertFalse($answers[$this->members['Zoé Martin']]->isReenrolled());
    }

    // ── fixture ───────────────────────────────────────────────────────

    private function createSection(string $deskCode, int $branchId, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name, is_visible) VALUES (?, ?, ?, 1)');
        $stmt->execute([$deskCode, $branchId, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createMember(string $firstName, string $lastName): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted, gender_encrypted, leaving, scout_year_offset)
             VALUES (?, ?, ?, ?, ?, ?, 0, 0)'
        );
        $stmt->execute([
            $memberId,
            $this->currentYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt($lastName, 'member_years.last_name'),
            $this->encryption->encrypt('2016-06-01', 'member_years.birth_date'),
            $this->encryption->encrypt('M', 'member_years.gender'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('identified', 'Fn identified', 'identified')");
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'identified'")->fetchColumn();
        $branchId = (int) $this->pdo->query('SELECT age_branch_id FROM sections WHERE id = ' . $this->sectionId)->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $this->sectionId, $branchId]);

        return $memberId;
    }
}
