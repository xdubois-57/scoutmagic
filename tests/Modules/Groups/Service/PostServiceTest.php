<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Security\Role;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Service\GroupActivityService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\PostService;
use Modules\Groups\Support\Timestamps;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * @group database
 */
#[Group('database')]
class PostServiceTest extends TestCase
{
    private \PDO $pdo;
    private PostService $postService;
    private PostRepository $postRepo;
    private GroupRepository $groupRepo;
    private int $groupId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->postRepo = new PostRepository($this->pdo);
        $this->postService = new PostService($this->postRepo, new GroupActivityService($this->groupRepo, $this->postRepo));

        $this->groupId = $this->groupRepo->create('Louveteaux', null, null, 1);
    }

    private function context(?int $accountId = 7): GroupSessionContext
    {
        return new GroupSessionContext($accountId, Role::IDENTIFIED, [3], 1, true);
    }

    private function minutesAgo(int $minutes): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$minutes} minutes")->format('Y-m-d H:i:s');
    }

    // --- edit window ---------------------------------------------------

    public function testAPostIsEditableByItsAuthorInsideTheFifteenMinuteWindow(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', $this->minutesAgo(14), 7, 3);

        $this->assertTrue($this->postService->canEdit($this->postRepo->findById($postId), $this->context()));
    }

    public function testAPostIsNoLongerEditableOnceTheWindowHasPassed(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', $this->minutesAgo(16), 7, 3);

        $this->assertFalse($this->postService->canEdit($this->postRepo->findById($postId), $this->context()));
    }

    public function testTheWindowIsMeasuredFromTheStoredCreatedAtNotFromAnythingTheClientSends(): void
    {
        // The forged case: a client that claims the post was written
        // seconds ago has no field to claim it in — the window is derived
        // from the row itself, and nothing in the request takes part.
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Vieux message', $this->minutesAgo(90), 7, 3);
        $post = $this->postRepo->findById($postId);

        $_POST['created_at'] = Timestamps::now();
        $_POST['edit_deadline'] = (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s');

        $this->assertFalse($this->postService->canEdit($post, $this->context()));
        $this->assertFalse($this->postService->isWithinEditWindow($post));

        $_POST = [];
    }

    public function testTheWindowDoesNotDriftWithTheServerDisplayTimezone(): void
    {
        // Both ends are UTC (Support\Timestamps): flipping PHP's ambient
        // timezone must not move the deadline by hours.
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', $this->minutesAgo(5), 7, 3);
        $post = $this->postRepo->findById($postId);

        $original = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Kiritimati'); // UTC+14
            $this->assertTrue($this->postService->isWithinEditWindow($post));
            date_default_timezone_set('Pacific/Niue'); // UTC-11
            $this->assertTrue($this->postService->isWithinEditWindow($post));
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testAnotherAccountNeverEditsSomeoneElsesPostEvenInsideTheWindow(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', $this->minutesAgo(1), 7, 3);

        $this->assertFalse($this->postService->canEdit($this->postRepo->findById($postId), $this->context(8)));
    }

    // --- editing and activity ------------------------------------------

    public function testEditingMarksThePostAndDoesNotChangeLastActivityAt(): void
    {
        $createdAt = $this->minutesAgo(5);
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Faute de frappe', $createdAt, 7, 3);

        $this->postService->edit($this->postRepo->findById($postId), 'Corrigé');

        $post = $this->postRepo->findById($postId);
        $this->assertSame('Corrigé', $post->body);
        $this->assertTrue($post->isEdited());
        // The whole point: a typo fix must not resurrect an old post to
        // the top of the feed (nor postpone its later purge).
        $this->assertSame($createdAt, $post->lastActivityAt);
    }

    public function testCreatingAPostBumpsBothThePostAndItsGroup(): void
    {
        $this->groupRepo->touchActivity($this->groupId, '2020-01-01 00:00:00');

        $postId = $this->postService->create($this->groupRepo->findById($this->groupId), 7, 3, 'Bonjour');

        $post = $this->postRepo->findById($postId);
        $this->assertSame($post->createdAt, $post->lastActivityAt, 'last_activity_at is seeded at creation, never null');
        $this->assertNotSame('2020-01-01 00:00:00', $this->groupRepo->findById($this->groupId)->lastActivityAt);
    }

    // --- deletion ------------------------------------------------------

    public function testTheAuthorMayDeleteTheirOwnPost(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', $this->minutesAgo(90), 7, 3);

        $this->assertTrue($this->postService->canDelete($this->postRepo->findById($postId), $this->context(), false));
    }

    public function testAModeratorMayDeleteAnyPostAndAnOtherMemberMayNot(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', $this->minutesAgo(1), 7, 3);
        $post = $this->postRepo->findById($postId);

        $this->assertTrue($this->postService->canDelete($post, $this->context(8), true));
        $this->assertFalse($this->postService->canDelete($post, $this->context(8), false));
    }

    public function testDeletionIsRealNotASoftFlag(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', $this->minutesAgo(1), 7, 3);

        $this->postService->delete($this->postRepo->findById($postId));

        $this->assertNull($this->postRepo->findById($postId));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM discussion_group_posts')->fetchColumn());
    }

    // --- body handling -------------------------------------------------

    public function testTheBodyIsBoundedServerSide(): void
    {
        $normalized = $this->postService->normalize(str_repeat('a', PostService::MAX_BODY_LENGTH + 500));

        $this->assertSame(PostService::MAX_BODY_LENGTH, mb_strlen($normalized));
    }

    public function testTheBodyKeepsItsLineBreaksAndIsStoredAsTypedNotAsMarkup(): void
    {
        $body = "Bonjour\r\n<b>gras</b> & compagnie\nà demain";

        $normalized = $this->postService->normalize($body);

        // Stored raw: no HTML is produced, none is stripped, nothing is
        // interpreted. Escaping happens in Twig at render time.
        $this->assertSame("Bonjour\n<b>gras</b> & compagnie\nà demain", $normalized);
    }

    public function testAnEmptyOrWhitespaceOnlyBodyIsNotPostable(): void
    {
        $this->assertFalse($this->postService->isPostable('   '));
        $this->assertFalse($this->postService->isPostable("\n\n"));
        $this->assertTrue($this->postService->isPostable('Bonjour'));
    }
}
