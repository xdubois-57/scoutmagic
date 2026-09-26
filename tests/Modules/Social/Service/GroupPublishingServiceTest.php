<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Service;

use Core\Journal\JournalService;
use Modules\Social\Repository\PublicationRepository;
use Modules\Social\Service\GroupPublishingService;
use Modules\Social\Service\ShareSource;
use PHPUnit\Framework\TestCase;
use Tests\Core\Http\Controller\RecordingJournalRepository;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\FakeGroupPublisher;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * A discussion group as a destination: the photo as it is, a real link,
 * one post and one « once » per group.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class GroupPublishingServiceTest extends TestCase
{
    private PublicationRepository $publications;
    private FakeGroupPublisher $groups;
    private RecordingJournalRepository $journal;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($pdo);
        $this->publications = new PublicationRepository($pdo);
        $this->groups = new FakeGroupPublisher();
        $this->journal = new RecordingJournalRepository();
        $this->now = new \DateTimeImmutable();
    }

    public function testEachGroupGetsItsOwnPostWithThePhotoAsItIsAndARealLink(): void
    {
        $outcomes = $this->publish($this->album(), [3, 4]);

        $this->assertTrue($outcomes[0]->published && $outcomes[1]->published);
        $this->assertSame(['Staff Lutins', 'Staff d\'unité'], [$outcomes[0]->label(), $outcomes[1]->label()]);
        $this->assertCount(2, $this->groups->posts);
        $this->assertSame('RAW-PHOTO', $this->groups->posts[0]['image'], 'Never blurred, never a card.');
        $this->assertSame('https://unite.example/gallery/3', $this->groups->posts[0]['link']);
        $this->assertSame('Les photos sont en ligne', $this->groups->posts[0]['body']);

        $rows = $this->publications->forSource('album', 3);
        $this->assertSame('/groups/3#post-101', $rows['group:3']->remoteUrl);
        $this->assertSame('Staff Lutins', $rows['group:3']->destinationLabel);
        $this->assertSame(2, $this->journal->countOf('published'));
        $this->assertStringNotContainsString('Les photos', $this->journal->textOf('published'));
    }

    public function testOnceHoldsGroupByGroup(): void
    {
        $this->publish($this->album(), [3]);

        $outcomes = $this->publish($this->album(), [3, 4]);

        $this->assertFalse($outcomes[0]->published);
        $this->assertStringContainsString('Déjà publié dans ce groupe', $outcomes[0]->message);
        $this->assertTrue($outcomes[1]->published, 'Another group is another destination.');
        $this->assertCount(2, $this->groups->posts);
    }

    public function testARefusedPostIsAFailureToRetryOnConfirmation(): void
    {
        $this->groups->refusals[3] = 'Vous publiez trop vite.';

        $outcomes = $this->publish($this->album(), [3]);

        $this->assertFalse($outcomes[0]->published);
        $this->assertSame('Vous publiez trop vite.', $outcomes[0]->message);
        $this->assertSame('failed', $this->publications->forSource('album', 3)['group:3']->status);

        unset($this->groups->refusals[3]);
        $this->assertFalse($this->publish($this->album(), [3])[0]->published, 'Not confirmed.');
        $this->assertTrue($this->publish($this->album(), [3], [3])[0]->published);
    }

    public function testAGroupThePersonMayNotPostInIsRefusedWithoutAPost(): void
    {
        $outcomes = $this->publish($this->album(), [9]);

        $this->assertFalse($outcomes[0]->published);
        $this->assertSame('Vous ne pouvez pas publier dans ce groupe.', $outcomes[0]->message);
        $this->assertSame([], $this->groups->posts);
        $this->assertSame([], $this->publications->forSource('album', 3));
    }

    public function testNothingLeavesWithoutAnImageOrWhenTheContentMayNotLeave(): void
    {
        $noImage = new ShareSource('communication', 5, 'T', null, false, null, 'unite.example', 'x', '/communications/5');
        $blocked = new ShareSource('article', 6, 'T', 'IMG', false, null, 'a', 'x', '/news/6/gerer', 'Réservée aux animateurs.');

        $this->assertStringContainsString('sans image', $this->publish($noImage, [3])[0]->message);
        $this->assertSame('Réservée aux animateurs.', $this->publish($blocked, [3])[0]->message);
        $this->assertSame([], $this->groups->posts);
    }

    public function testTheGroupKeyIsReadStrictly(): void
    {
        $this->assertSame(3, GroupPublishingService::groupIdOf('group:3'));
        $this->assertNull(GroupPublishingService::groupIdOf('group:'));
        $this->assertNull(GroupPublishingService::groupIdOf('group:3x'));
        $this->assertNull(GroupPublishingService::groupIdOf('group:0'));
        $this->assertNull(GroupPublishingService::groupIdOf('facebook'));
    }

    /**
     * @param list<int> $groupIds
     * @param list<int> $retries
     * @return list<\Modules\Social\Service\PublishOutcome>
     */
    private function publish(ShareSource $source, array $groupIds, array $retries = []): array
    {
        return (new GroupPublishingService($this->groups, $this->publications, new JournalService($this->journal)))
            ->publish($source, $groupIds, 'Les photos sont en ligne', $retries, 'chef@unite.be', 'chief', 7, $this->now);
    }

    private function album(): ShareSource
    {
        return new ShareSource(
            'album', 3, 'Camp', 'RAW-PHOTO', true, null, 'unite.example/gallery/3', 'x', '/gallery/3/edit',
            null, 'https://unite.example/gallery/3'
        );
    }
}
