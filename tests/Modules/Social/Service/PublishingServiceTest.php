<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Service;

use Core\Journal\JournalService;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Card\CardRenderer;
use Modules\Social\Card\CardService;
use Modules\Social\Meta\MetaClient;
use Modules\Social\Repository\CardRepository;
use Modules\Social\Repository\ConnectionRepository;
use Modules\Social\Repository\PublicationRepository;
use Modules\Social\Service\PublishingService;
use Modules\Social\Service\ShareSource;
use PHPUnit\Framework\TestCase;
use Tests\Core\Http\Controller\RecordingJournalRepository;
use Tests\Core\Http\Controller\RemoteBackupSettingsDouble;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\FakeMetaTransport;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * What leaves, where, and once — with Meta played by a fake transport.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PublishingServiceTest extends TestCase
{
    private \PDO $pdo;
    private ConnectionRepository $connections;
    private PublicationRepository $publications;
    private RecordingJournalRepository $journal;
    private RemoteBackupSettingsDouble $settings;
    private FakeMetaTransport $meta;
    private string $directory;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->connections = new ConnectionRepository($this->pdo, H::encryption());
        $this->publications = new PublicationRepository($this->pdo);
        $this->journal = new RecordingJournalRepository();
        $this->settings = new RemoteBackupSettingsDouble(['base_url' => 'https://unite.example']);
        $this->meta = H::transport([
            '/photos' => H::ok(['id' => 'P', 'post_id' => '42_1']),
            '/feed' => H::ok(['id' => '42_2']),
            '/media_publish' => H::ok(['id' => 'IG1']),
            '/media' => H::ok(['id' => 'C1']),
            'C1?' => H::ok(['status_code' => 'FINISHED']),
        ]);
        $this->directory = sys_get_temp_dir() . '/social-publish-' . bin2hex(random_bytes(4));
        $this->now = new \DateTimeImmutable();

        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->connections->connect(SocialPlatform::Facebook, '42', 'Unité 25', 'PAGE', null, $this->now);
        $this->connections->saveCredentials(SocialPlatform::Instagram, '123456', 'S');
        $this->connections->connect(SocialPlatform::Instagram, '9', 'unite25', 'IGT', $this->now->modify('+60 days'), $this->now);
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

    public function testAnAlbumGoesAsTheSameBlurredCardToBothDestinations(): void
    {
        $outcomes = $this->publish($this->album(), [SocialPlatform::Facebook, SocialPlatform::Instagram]);

        $this->assertTrue($outcomes[0]->published && $outcomes[1]->published);
        $photo = $this->request('/photos');
        $container = $this->request('/media');
        $this->assertSame($photo['fields']['url'], $container['fields']['image_url'], 'One card for both.');
        $this->assertStringStartsWith('https://unite.example/partage/carte/', $photo['fields']['url']);

        $row = $this->pdo->query('SELECT blurred FROM social_cards')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) ($row['blurred'] ?? 0), 'A gallery cover is blurred.');
        $this->assertSame(2, $this->journal->countOf('published'));
        $this->assertStringNotContainsString('Les photos', $this->journal->textOf('published'), 'No caption in the journal.');
    }

    public function testAnArticleGoesAsALinkToFacebookAndAsAnImageToInstagram(): void
    {
        $this->publish($this->article(), [SocialPlatform::Facebook, SocialPlatform::Instagram]);

        $this->assertSame('https://unite.example/s/abc', $this->request('/feed')['fields']['link']);
        $this->assertNull($this->request('/photos'), 'No image post for an article on the Page.');
        $this->assertNotNull($this->request('/media'));
    }

    public function testASecondPublicationToTheSameDestinationIsRefusedServerSide(): void
    {
        $this->publish($this->album(), [SocialPlatform::Facebook]);
        $calls = count($this->meta->requests);

        $outcomes = $this->publish($this->album(), [SocialPlatform::Facebook]);

        $this->assertFalse($outcomes[0]->published);
        $this->assertStringContainsString('Déjà publié', $outcomes[0]->message);
        $this->assertCount($calls, $this->meta->requests, 'Meta is not called again.');
    }

    public function testAFailureIsRecordedAndARetryNeedsToBeConfirmed(): void
    {
        $this->meta->answers = ['/media' => ['status' => 400, 'body' => (string) json_encode(
            ['error' => ['message' => 'Only photo or video can be accepted', 'code' => 9004]]
        )]] + $this->meta->answers;

        $outcomes = $this->publish($this->album(), [SocialPlatform::Instagram]);

        $this->assertFalse($outcomes[0]->published);
        $failed = $this->publications->forSource('album', 3)['instagram'];
        $this->assertSame('failed', $failed->status);
        $this->assertSame(1, $this->journal->countOf('publish_failed'));
        $this->assertStringContainsString('code 9004', $this->journal->textOf('publish_failed'));

        $this->meta->answers = array_diff_key($this->meta->answers, ['/media' => true]) + ['/media' => H::ok(['id' => 'C1'])];
        $this->meta->answers = ['/media_publish' => H::ok(['id' => 'IG1'])] + $this->meta->answers;

        $unconfirmed = $this->publish($this->album(), [SocialPlatform::Instagram])[0];
        $this->assertFalse($unconfirmed->published, 'Not confirmed.');
        $this->assertStringContainsString('Je confirme : réessayer', $unconfirmed->message, 'Says what to do, not « déjà publié ».');
        $this->assertTrue($this->publish($this->album(), [SocialPlatform::Instagram], [SocialPlatform::Instagram])[0]->published);
    }

    public function testAnUnexpectedFailureIsRecordedAsFailedWithoutItsText(): void
    {
        // The fake transport throws a LogicException, naming the URL (and
        // so the token), on a request it has no answer for.
        unset($this->meta->answers['/photos']);

        $outcomes = $this->publish($this->album(), [SocialPlatform::Facebook]);

        $this->assertFalse($outcomes[0]->published);
        $this->assertStringContainsString('sur le site lui-même', $outcomes[0]->message);
        $this->assertSame('failed', $this->publications->forSource('album', 3)['facebook']->status, 'Never left pending.');
        $this->assertSame(1, $this->journal->countOf('publish_failed'));
        $this->assertStringNotContainsString('PAGE', $this->journal->textOf('publish_failed'), 'No token.');
    }

    public function testARetryLeavesThePublishedDestinationAlone(): void
    {
        $this->publish($this->album(), [SocialPlatform::Facebook]);
        $calls = count($this->meta->requests);

        $outcomes = $this->publish($this->album(), [SocialPlatform::Facebook], [SocialPlatform::Facebook]);

        $this->assertFalse($outcomes[0]->published);
        $this->assertCount($calls, $this->meta->requests);
    }

    public function testNothingLeavesForASourceThatMayNotLeaveTheSite(): void
    {
        $outcomes = $this->publish($this->article('Cette actualité est réservée aux animateurs.'), [SocialPlatform::Facebook]);

        $this->assertFalse($outcomes[0]->published);
        $this->assertSame([], $this->meta->requests);
    }

    public function testInstagramWithoutAnImageAndACaptionTooLongAreRefused(): void
    {
        $noImage = new ShareSource('article', 5, 'T', null, false, 'https://unite.example/s/abc', 'unite.example/s/abc', 'x', '/news/5/gerer');
        $this->assertStringContainsString('pas d\'image', $this->publish($noImage, [SocialPlatform::Instagram])[0]->message);

        $long = $this->service()->publish($this->album(), [SocialPlatform::Facebook], str_repeat('a', 2201), [], 7, $this->now);
        $this->assertStringContainsString('2200', $long[0]->message);
        $this->assertSame([], $this->meta->requests);
    }

    public function testASiteWithoutItsAddressPublishesNothing(): void
    {
        $this->settings->values = [];

        $this->assertStringContainsString('adresse du site', $this->publish($this->album(), [SocialPlatform::Facebook])[0]->message);
    }

    /**
     * @param list<SocialPlatform> $destinations
     * @param list<SocialPlatform> $retries
     * @return list<\Modules\Social\Service\PublishOutcome>
     */
    private function publish(ShareSource $source, array $destinations, array $retries = []): array
    {
        return $this->service()->publish($source, $destinations, 'Les photos sont en ligne', $retries, 7, $this->now);
    }

    private function service(): PublishingService
    {
        $journal = new JournalService($this->journal);

        return new PublishingService(
            $this->connections,
            $this->publications,
            new CardService(new CardRepository($this->pdo), new CardRenderer(), $this->settings, $journal, $this->directory),
            $this->settings,
            $journal,
            new MetaClient($this->meta, static function (int $seconds): void {
            })
        );
    }

    private function album(): ShareSource
    {
        return new ShareSource('album', 3, 'Camp', H::groupPhoto(), true, null, 'unite.example/gallery/3', 'x', '/gallery/3/edit');
    }

    private function article(?string $blocked = null): ShareSource
    {
        return new ShareSource(
            'article',
            5,
            'Inscriptions',
            H::groupPhoto(),
            false,
            'https://unite.example/s/abc',
            'unite.example/s/abc',
            'x',
            '/news/5/gerer',
            $blocked
        );
    }

    /**
     * @return array{method: string, url: string, fields: array<string, string>}|null
     */
    private function request(string $fragment): ?array
    {
        foreach ($this->meta->requests as $request) {
            if (str_contains($request['url'], $fragment) && !str_contains($request['url'], '/media_publish')) {
                return $request;
            }
        }

        return null;
    }
}
