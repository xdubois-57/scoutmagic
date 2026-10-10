<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Service;

use Core\Journal\JournalService;
use Modules\Social\Card\CardRenderer;
use Modules\Social\Card\CardService;
use Modules\Social\Repository\CardRepository;
use Modules\Social\Repository\PublicationRepository;
use Modules\Social\Service\GroupPublishingService;
use Modules\Social\Service\ShareSource;
use PHPUnit\Framework\TestCase;
use Tests\Core\Http\Controller\RecordingJournalRepository;
use Tests\Core\Http\Controller\RemoteBackupSettingsDouble;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\FakeGroupPublisher;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * A discussion group as a destination: **the same card as every other
 * destination**, a real link, one post and one « once » per group.
 *
 * Until issue #706, IT-02 a group received the photo exactly as it was,
 * unblurred, on the reasoning that the group is private and its members
 * already see the gallery. That reasoning held while the blur was a
 * fixed rule and stopped holding when it became the chief's choice —
 * « the same card everywhere » is what the composer now shows and
 * promises.
 *
 * **This file asserted the opposite until the review of #850**, and
 * passed, because it built the service without a `CardService` at all:
 * the production wiring always passes one, so the only path the tests
 * ever took was a fallback. The helper below passes a real one now.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class GroupPublishingServiceTest extends TestCase
{
    private PublicationRepository $publications;
    private FakeGroupPublisher $groups;
    private RecordingJournalRepository $journal;
    private \DateTimeImmutable $now;
    private \PDO $pdo;
    private string $directory;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->publications = new PublicationRepository($this->pdo);
        $this->groups = new FakeGroupPublisher();
        $this->journal = new RecordingJournalRepository();
        $this->now = new \DateTimeImmutable();
        $this->directory = sys_get_temp_dir() . '/social-groups-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testEachGroupGetsItsOwnPostWithTheCardAndARealLink(): void
    {
        $photo = H::groupPhoto();
        $outcomes = $this->publish($this->album($photo), [3, 4]);

        $this->assertTrue($outcomes[0]->published && $outcomes[1]->published);
        $this->assertSame(['Staff Lutins', 'Staff d\'unité'], [$outcomes[0]->label(), $outcomes[1]->label()]);
        $this->assertCount(2, $this->groups->posts);

        // The CARD, not the photo: a 1080 square composed from it.
        $sent = (string) $this->groups->posts[0]['image'];
        $this->assertNotSame($photo, $sent, 'the group was handed the gallery photo itself');
        $size = (array) getimagesizefromstring($sent);
        $this->assertSame([CardRenderer::SIZE, CardRenderer::SIZE], [$size[0] ?? null, $size[1] ?? null]);
        $this->assertSame('image/jpeg', $size['mime'] ?? null);
        // And every group gets the same bytes, composed once.
        $this->assertSame($sent, (string) $this->groups->posts[1]['image']);

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

    /**
     * **The card the browser drew wins**, down to the bytes: a group
     * receives exactly what Facebook and Instagram received.
     */
    public function testAKeptCardTravelsToTheGroupUnchanged(): void
    {
        $kept = (new CardRenderer())->render(H::groupPhoto(), 'Week-end', 'unite.example', true, 0.025);
        $source = new ShareSource(
            'album', 3, 'Camp', H::groupPhoto(), true, null, 'unite.example/gallery/3', 'x', '/gallery/3/edit',
            null, 'https://unite.example/gallery/3',
            // Named, because the two that matter sit far down a long
            // positional list and counting them is how this test first
            // passed a float as the card.
            blurRatio: 0.025,
            card: $kept
        );

        $this->assertTrue($this->publish($source, [3])[0]->published);
        $this->assertSame($kept, (string) $this->groups->posts[0]['image']);
    }

    /**
     * **A gallery photo never falls back to itself.** When no card can be
     * composed the publication is refused, with the reason: falling back
     * would send the photo sharp and whole, which is the one thing the
     * slider must never do by accident. Found in the review of #850,
     * where the fallback sent `$source->image` for every source alike.
     */
    public function testAGalleryPhotoIsRefusedRatherThanSentWithoutItsCard(): void
    {
        // Bytes that are not an image at all: composition cannot succeed.
        $outcomes = $this->publish($this->album('RAW-PHOTO'), [3]);

        $this->assertFalse($outcomes[0]->published);
        $this->assertStringContainsString('ne part pas sans son flou', $outcomes[0]->message);
        $this->assertSame([], $this->groups->posts, 'the gallery photo was posted anyway');
        $this->assertSame('failed', $this->publications->forSource('album', 3)['group:3']->status);
    }

    /**
     * An image that is not the gallery's does fall back: an upload or an
     * article's cover was chosen to be public, and a message that
     * arrives without its card is worth more than one that does not
     * arrive at all.
     */
    public function testAnImageThatIsNotTheGallerysStillGoesAsItIs(): void
    {
        $upload = new ShareSource(
            'communication', 8, 'Camp', 'NOT-AN-IMAGE', false, null, 'unite.example', 'x', '/communications/8',
            null, 'https://unite.example/communications/8'
        );

        $this->assertTrue($this->publish($upload, [3])[0]->published);
        $this->assertSame('NOT-AN-IMAGE', (string) $this->groups->posts[0]['image']);
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
        $journal = new JournalService($this->journal);
        // A REAL card service, as `public/index.php` always passes: the
        // three-argument construction this helper used before #850 sent
        // every test down a fallback path production never takes.
        $cards = new CardService(
            new CardRepository($this->pdo),
            new CardRenderer(),
            new RemoteBackupSettingsDouble([]),
            $journal,
            $this->directory
        );

        return (new GroupPublishingService($this->groups, $this->publications, $journal, $cards))
            ->publish($source, $groupIds, 'Les photos sont en ligne', $retries, 'chef@unite.be', 'chief', 7, $this->now);
    }

    private function album(?string $image = null): ShareSource
    {
        return new ShareSource(
            'album', 3, 'Camp', $image ?? H::groupPhoto(), true, null,
            'unite.example/gallery/3', 'x', '/gallery/3/edit',
            null, 'https://unite.example/gallery/3'
        );
    }
}
