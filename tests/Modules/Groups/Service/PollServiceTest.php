<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PollRepository;
use Modules\Groups\Service\PollService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * A poll attached to a post: what counts as one, WHO counts as one voter,
 * how many answers each of them may give, and the tally a card renders.
 *
 * @group database
 */
#[Group('database')]
class PollServiceTest extends TestCase
{
    private \PDO $pdo;
    private PollRepository $repository;
    private PollService $service;
    private int $postId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $groupId = (new GroupRepository($this->pdo))->create('Louveteaux', null, null, 1);
        $this->postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'Sondage', '2026-01-01 10:00:00');

        $this->repository = new PollRepository($this->pdo);
        $this->service = new PollService($this->repository);
    }

    // ---- what counts as a poll ----

    public function testAQuestionWithTwoOptionsIsAPoll(): void
    {
        $poll = $this->service->normalise('  Qui vient ?  ', ['Samedi', ' Dimanche ']);

        $this->assertSame([
            'question' => 'Qui vient ?',
            'options' => ['Samedi', 'Dimanche'],
            // The defaults, and they are the point: one answer per
            // identified login, one choice each.
            'vote_scope' => PollService::SCOPE_ACCOUNT,
            'allow_multiple' => false,
        ], $poll);
    }

    public function testAPollCanBeAskedPerMemberAndWithSeveralAnswers(): void
    {
        $poll = $this->service->normalise('Qui vient ?', ['Samedi', 'Dimanche'], PollService::SCOPE_MEMBER, true);

        $this->assertSame(PollService::SCOPE_MEMBER, $poll['vote_scope']);
        $this->assertTrue($poll['allow_multiple']);
    }

    /**
     * A hand-made request cannot invent a third scope: anything but the
     * one word meaning "per member" is the default.
     */
    public function testAnUnknownScopeFallsBackToOneAnswerPerLogin(): void
    {
        $poll = $this->service->normalise('Qui vient ?', ['Samedi', 'Dimanche'], 'whatever');

        $this->assertSame(PollService::SCOPE_ACCOUNT, $poll['vote_scope']);
    }

    /**
     * The composer always keeps an empty box waiting at the end, so
     * submitting with it untouched is the ordinary way to make a poll
     * with exactly the options that were typed.
     */
    public function testBlankOptionBoxesAreDroppedRatherThanStored(): void
    {
        $poll = $this->service->normalise('Qui vient ?', ['Samedi', 'Dimanche', '', '   ']);

        $this->assertSame(['Samedi', 'Dimanche'], $poll['options']);
    }

    public function testASingleOptionIsNotAPoll(): void
    {
        $this->assertNull($this->service->normalise('Qui vient ?', ['Samedi', '']));
    }

    public function testOptionsWithoutAQuestionAreNotAPoll(): void
    {
        $this->assertNull($this->service->normalise('   ', ['Samedi', 'Dimanche']));
    }

    public function testNothingSubmittedIsNotAPoll(): void
    {
        $this->assertNull($this->service->normalise('', []));
    }

    public function testTooManyOptionsAreCutRatherThanRefused(): void
    {
        $labels = [];
        for ($i = 1; $i <= PollService::MAX_OPTIONS + 5; $i++) {
            $labels[] = 'Choix ' . $i;
        }

        $this->assertCount(PollService::MAX_OPTIONS, $this->service->normalise('Qui vient ?', $labels)['options']);
    }

    // ---- voting ----

    /**
     * @return array<int, array{id: int, label: string}>
     */
    private function seedPoll(string $scope = PollService::SCOPE_ACCOUNT, bool $multiple = false): array
    {
        $this->service->attachTo($this->postId, [
            'question' => 'Qui vient ?',
            'options' => ['Samedi', 'Dimanche'],
            'vote_scope' => $scope,
            'allow_multiple' => $multiple,
        ]);
        $poll = $this->repository->findByPostId($this->postId);

        return $this->repository->optionsForPolls([$poll['id']])[$poll['id']];
    }

    public function testALoginVotesAndTheTallyFollows(): void
    {
        $options = $this->seedPoll();

        $this->assertTrue($this->service->vote($this->postId, $options[0]['id'], 9, 0, [3]));

        $poll = $this->service->forPosts([$this->postId], 9, [3])[$this->postId];
        $this->assertSame(1, $poll['total']);
        $this->assertSame(1, $poll['options'][0]['votes']);
        $this->assertTrue($poll['options'][0]['is_own']);
    }

    /**
     * The scope is the poll's, not the request's: an account-scoped poll
     * records the login whatever member id the form carried, so two
     * members of one account cannot answer it twice.
     */
    public function testAnAccountScopedPollCountsTheLoginOnceHoweverManyMembersItHas(): void
    {
        $options = $this->seedPoll();

        $this->service->vote($this->postId, $options[0]['id'], 9, 3, [3, 4]);
        $this->service->vote($this->postId, $options[1]['id'], 9, 4, [3, 4]);

        $poll = $this->service->forPosts([$this->postId], 9, [3, 4])[$this->postId];
        $this->assertSame(1, $poll['total']);
        $this->assertSame(0, $poll['options'][0]['votes']);
        $this->assertSame(1, $poll['options'][1]['votes']);
    }

    /**
     * A member-scoped poll is what a parent of two needs: the same login
     * answers once per child, and each answer stands on its own.
     */
    public function testAMemberScopedPollTakesOneAnswerPerMember(): void
    {
        $options = $this->seedPoll(PollService::SCOPE_MEMBER);

        $this->service->vote($this->postId, $options[0]['id'], 9, 3, [3, 4]);
        $this->service->vote($this->postId, $options[1]['id'], 9, 4, [3, 4]);

        $poll = $this->service->forPosts([$this->postId], 9, [3, 4])[$this->postId];
        $this->assertSame(2, $poll['total']);
        $this->assertSame([3 => [$options[0]['id']], 4 => [$options[1]['id']]], $poll['own_by_member']);
    }

    /**
     * Answering for somebody this caller is not linked to falls back to
     * their own first member rather than being recorded as that person —
     * the form is a request, never an authority.
     */
    public function testAnsweringForAMemberThatIsNotYoursRecordsYourOwn(): void
    {
        $options = $this->seedPoll(PollService::SCOPE_MEMBER);

        $this->service->vote($this->postId, $options[0]['id'], 9, 99, [3]);

        $poll = $this->service->forPosts([$this->postId], 9, [3])[$this->postId];
        $this->assertSame([3 => [$options[0]['id']]], $poll['own_by_member']);
    }

    /**
     * Several answers when the poll allows them, and a second tap on one
     * you already hold takes it back — the only way to unpick one.
     */
    public function testAMultipleAnswerPollHoldsSeveralChoicesAndCanUnpickOne(): void
    {
        $options = $this->seedPoll(PollService::SCOPE_ACCOUNT, true);

        $this->service->vote($this->postId, $options[0]['id'], 9, 0, [3]);
        $this->service->vote($this->postId, $options[1]['id'], 9, 0, [3]);

        $poll = $this->service->forPosts([$this->postId], 9, [3])[$this->postId];
        // One voter, two answers — which is why the total counts people
        // and not ballots.
        $this->assertSame(1, $poll['total']);
        $this->assertSame(1, $poll['options'][0]['votes']);
        $this->assertSame(1, $poll['options'][1]['votes']);

        $this->service->vote($this->postId, $options[0]['id'], 9, 0, [3]);

        $poll = $this->service->forPosts([$this->postId], 9, [3])[$this->postId];
        $this->assertSame(0, $poll['options'][0]['votes']);
        $this->assertSame(1, $poll['options'][1]['votes']);
    }

    /**
     * A single-answer poll has no such move: tapping your own answer
     * again leaves it exactly where it was, rather than quietly leaving
     * you having answered nothing.
     */
    public function testTappingYourOwnAnswerAgainInASingleAnswerPollChangesNothing(): void
    {
        $options = $this->seedPoll();
        $this->service->vote($this->postId, $options[0]['id'], 9, 0, [3]);

        $this->service->vote($this->postId, $options[0]['id'], 9, 0, [3]);

        $poll = $this->service->forPosts([$this->postId], 9, [3])[$this->postId];
        $this->assertSame(1, $poll['total']);
        $this->assertSame(1, $poll['options'][0]['votes']);
    }

    /**
     * One answer per voter in a single-answer poll, and changing it
     * replaces rather than adds.
     */
    public function testChangingYourMindReplacesYourVoteRatherThanAddingOne(): void
    {
        $options = $this->seedPoll();
        $this->service->vote($this->postId, $options[0]['id'], 9, 0, [3]);

        $this->service->vote($this->postId, $options[1]['id'], 9, 0, [3]);

        $poll = $this->service->forPosts([$this->postId], 9, [3])[$this->postId];
        $this->assertSame(1, $poll['total']);
        $this->assertSame(0, $poll['options'][0]['votes']);
        $this->assertSame(1, $poll['options'][1]['votes']);
    }

    /**
     * The option is re-checked against the post's own poll, so a
     * hand-made request cannot vote for an option belonging to another
     * one.
     */
    public function testAnOptionFromAnotherPollIsRefused(): void
    {
        $this->seedPoll();
        $otherPostId = GroupsTestHelper::createPostAt($this->pdo, 1, 'Autre', '2026-01-02 10:00:00');
        $this->service->attachTo($otherPostId, [
            'question' => 'Autre ?',
            'options' => ['Oui', 'Non'],
            'vote_scope' => PollService::SCOPE_ACCOUNT,
            'allow_multiple' => false,
        ]);
        $otherPoll = $this->repository->findByPostId($otherPostId);
        $foreignOption = $this->repository->optionsForPolls([$otherPoll['id']])[$otherPoll['id']][0]['id'];

        $this->assertFalse($this->service->vote($this->postId, $foreignOption, 9, 0, [3]));
        $this->assertSame(0, $this->service->forPosts([$this->postId], 9, [3])[$this->postId]['total']);
    }

    public function testVotingOnAPostWithNoPollIsRefused(): void
    {
        $bare = GroupsTestHelper::createPostAt($this->pdo, 1, 'Pas de sondage', '2026-01-02 10:00:00');

        $this->assertFalse($this->service->vote($bare, 1, 9, 0, [3]));
    }

    // ---- what a card renders ----

    public function testOptionsKeepTheOrderTheAuthorTypedThem(): void
    {
        $this->service->attachTo($this->postId, [
            'question' => 'Quand ?',
            'options' => ['Samedi', 'Dimanche', 'Les deux'],
            'vote_scope' => PollService::SCOPE_ACCOUNT,
            'allow_multiple' => false,
        ]);

        $labels = array_column($this->service->forPosts([$this->postId], 9, [])[$this->postId]['options'], 'label');

        $this->assertSame(['Samedi', 'Dimanche', 'Les deux'], $labels);
    }

    public function testPercentagesAreComputedOnceHereRatherThanInTheTemplate(): void
    {
        $options = $this->seedPoll();
        $this->service->vote($this->postId, $options[0]['id'], 3, 0, []);
        $this->service->vote($this->postId, $options[0]['id'], 4, 0, []);
        $this->service->vote($this->postId, $options[1]['id'], 5, 0, []);

        $poll = $this->service->forPosts([$this->postId], null, [])[$this->postId];

        $this->assertSame(3, $poll['total']);
        $this->assertSame(67, $poll['options'][0]['percent']);
        $this->assertSame(33, $poll['options'][1]['percent']);
    }

    public function testAPollWithNoVotesShowsZeroPercentRatherThanDividingByZero(): void
    {
        $this->seedPoll();

        $poll = $this->service->forPosts([$this->postId], 9, [])[$this->postId];

        $this->assertSame(0, $poll['total']);
        $this->assertSame(0, $poll['options'][0]['percent']);
    }

    public function testAPostWithoutAPollIsSimplyAbsentFromTheResult(): void
    {
        $this->assertSame([], $this->service->forPosts([$this->postId], 9, [3]));
    }

    /**
     * An account whose members answered a MEMBER-scoped poll sees every
     * one of those answers highlighted — and an account-scoped poll never
     * lights up because one of its members answered a different question.
     */
    public function testOwnAnswersAreReadThroughThePollsOwnScope(): void
    {
        $options = $this->seedPoll(PollService::SCOPE_MEMBER);
        $this->service->vote($this->postId, $options[1]['id'], 9, 4, [3, 4]);

        $poll = $this->service->forPosts([$this->postId], 9, [3, 4])[$this->postId];

        $this->assertSame([$options[1]['id']], $poll['own_option_ids']);
        $this->assertTrue($poll['options'][1]['is_own']);
    }
}
