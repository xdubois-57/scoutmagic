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
 * A poll attached to a post: what counts as one, one answer per member,
 * and the tally a card renders.
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

        $this->assertSame(['question' => 'Qui vient ?', 'options' => ['Samedi', 'Dimanche']], $poll);
    }

    /**
     * The composer offers a fixed number of boxes, so leaving the last
     * ones empty is the ordinary way to make a two-option poll.
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

    private function seedPoll(): array
    {
        $this->service->attachTo($this->postId, ['question' => 'Qui vient ?', 'options' => ['Samedi', 'Dimanche']]);
        $poll = $this->repository->findByPostId($this->postId);

        return $this->repository->optionsForPolls([$poll['id']])[$poll['id']];
    }

    public function testAMemberVotesAndTheTallyFollows(): void
    {
        $options = $this->seedPoll();

        $this->assertTrue($this->service->vote($this->postId, $options[0]['id'], 3));

        $poll = $this->service->forPosts([$this->postId], [3])[$this->postId];
        $this->assertSame(1, $poll['total']);
        $this->assertSame(1, $poll['options'][0]['votes']);
        $this->assertTrue($poll['options'][0]['is_own']);
    }

    /**
     * One answer per member, and changing it replaces rather than adds —
     * the UNIQUE (poll, member) index is what makes that an UPDATE.
     */
    public function testChangingYourMindReplacesYourVoteRatherThanAddingOne(): void
    {
        $options = $this->seedPoll();
        $this->service->vote($this->postId, $options[0]['id'], 3);

        $this->service->vote($this->postId, $options[1]['id'], 3);

        $poll = $this->service->forPosts([$this->postId], [3])[$this->postId];
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
        $this->service->attachTo($otherPostId, ['question' => 'Autre ?', 'options' => ['Oui', 'Non']]);
        $otherPoll = $this->repository->findByPostId($otherPostId);
        $foreignOption = $this->repository->optionsForPolls([$otherPoll['id']])[$otherPoll['id']][0]['id'];

        $this->assertFalse($this->service->vote($this->postId, $foreignOption, 3));
        $this->assertSame(0, $this->service->forPosts([$this->postId], [3])[$this->postId]['total']);
    }

    public function testVotingOnAPostWithNoPollIsRefused(): void
    {
        $bare = GroupsTestHelper::createPostAt($this->pdo, 1, 'Pas de sondage', '2026-01-02 10:00:00');

        $this->assertFalse($this->service->vote($bare, 1, 3));
    }

    // ---- what a card renders ----

    public function testOptionsKeepTheOrderTheAuthorTypedThem(): void
    {
        $this->service->attachTo($this->postId, ['question' => 'Quand ?', 'options' => ['Samedi', 'Dimanche', 'Les deux']]);

        $labels = array_column($this->service->forPosts([$this->postId], [])[$this->postId]['options'], 'label');

        $this->assertSame(['Samedi', 'Dimanche', 'Les deux'], $labels);
    }

    public function testPercentagesAreComputedOnceHereRatherThanInTheTemplate(): void
    {
        $options = $this->seedPoll();
        $this->service->vote($this->postId, $options[0]['id'], 3);
        $this->service->vote($this->postId, $options[0]['id'], 4);
        $this->service->vote($this->postId, $options[1]['id'], 5);

        $poll = $this->service->forPosts([$this->postId], [])[$this->postId];

        $this->assertSame(3, $poll['total']);
        $this->assertSame(67, $poll['options'][0]['percent']);
        $this->assertSame(33, $poll['options'][1]['percent']);
    }

    public function testAPollWithNoVotesShowsZeroPercentRatherThanDividingByZero(): void
    {
        $this->seedPoll();

        $poll = $this->service->forPosts([$this->postId], [])[$this->postId];

        $this->assertSame(0, $poll['total']);
        $this->assertSame(0, $poll['options'][0]['percent']);
    }

    public function testAPostWithoutAPollIsSimplyAbsentFromTheResult(): void
    {
        $this->assertSame([], $this->service->forPosts([$this->postId], [3]));
    }

    /**
     * An account linked to two members of the same group has ONE answer
     * to show — the same identity rule every other per-member read in
     * this module follows.
     */
    public function testAnAccountLinkedToSeveralMembersSeesTheAnswerEitherOfThemGave(): void
    {
        $options = $this->seedPoll();
        $this->service->vote($this->postId, $options[1]['id'], 4);

        $poll = $this->service->forPosts([$this->postId], [3, 4])[$this->postId];

        $this->assertSame($options[1]['id'], $poll['own_option_id']);
    }
}
