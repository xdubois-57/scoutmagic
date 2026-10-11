<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Service;

use Core\Journal\JournalService;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Card\CardRenderer;
use Modules\Social\Card\CardService;
use Modules\Social\Meta\MetaClient;
use Modules\Social\Repository\CardRepository;
use Modules\Social\Repository\CommunicationRepository;
use Modules\Social\Repository\ConnectionRepository;
use Modules\Social\Repository\PublicationRepository;
use Modules\Social\Service\PublicPageService;
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
    /** Asks communicationBackedAlbum() for a real composed card. */
    private const DRAWN_CARD = '@drawn';

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

    /**
     * **A RAW album shape**, which since IT-04 means one thing only: a
     * retry of a share made before the composer existed, reached through
     * `…/reessayer/album/{albumId}/…`, where `id` is the album's own and
     * no communication row stands behind it. That share keeps going to
     * the Page as a photo, as it always has.
     *
     * A share saved by the composer carries
     * `kind = KIND_COMMUNICATION`, and goes as a link to its public page
     * instead — {@see testASavedAlbumShareGoesToFacebookAsALinkToItsPublicPage()}.
     */
    public function testALegacyAlbumShapeStillGoesAsTheSameBlurredCardToBothDestinations(): void
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

    public function testWhatWasSentIsKeptForTheHistoryAndARetry(): void
    {
        $this->publish($this->album(), [SocialPlatform::Facebook]);

        $publication = $this->publications->forSource('album', 3)['facebook'];
        $this->assertSame('Camp', $publication->sourceTitle);
        $this->assertSame('Les photos sont en ligne', $publication->caption);
        $this->assertSame('https://www.facebook.com/42_1', $publication->remoteUrl);
        $this->assertSame(7, $publication->userAccountId);
    }

    public function testAnArticleGoesAsALinkToFacebookAndAsAnImageToInstagram(): void
    {
        $this->publish($this->article(), [SocialPlatform::Facebook, SocialPlatform::Instagram]);

        $this->assertSame('https://unite.example/s/abc', $this->request('/feed')['fields']['link']);
        $this->assertNull($this->request('/photos'), 'No image post for an article on the Page.');
        $this->assertNotNull($this->request('/media'));
    }

    /**
     * **What Instagram actually receives of an article** (issue #706,
     * IT-03): the card, composed from the article's cover with its title
     * drawn on it and the site's address, and NOT blurred — an article's
     * cover is public by design, which is why `article()` answers
     * `imageFromGallery` false and the composer offers no slider for it.
     *
     * The test above only proved that `/media` was called at all. What
     * leaves is the thing worth pinning, and this is also the image that
     * differs from Facebook's: Facebook gets the link and shows the
     * article page's Open Graph preview — the bare cover, title and
     * summary as text beside it — which is why the composer says so.
     */
    public function testInstagramGetsTheArticlesCardUnblurredWithItsTitleOnIt(): void
    {
        $this->publish($this->article(), [SocialPlatform::Instagram]);

        $url = (string) ($this->request('/media')['fields']['image_url'] ?? '');
        $served = $this->cardsService()->open(substr($url, -64), $this->now);
        $this->assertNotNull($served, 'the address handed to Instagram serves nothing');
        $sent = (string) file_get_contents($served);

        $this->assertSame(
            (new \Modules\Social\Card\CardRenderer())
                ->render(H::groupPhoto(), 'Inscriptions', 'unite.example/s/abc', false, 0.0),
            $sent,
            'Instagram was handed something other than the article\'s unblurred card'
        );
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
        // Names the control on the page the chief is about to land on —
        // `publishNow()` redirects to the history whatever happened — and
        // not the composer's checkbox, which the album's prefilled
        // composer never shows (`Claude review` on PR #868).
        $this->assertStringContainsString(
            'relancez-la avec « Réessayer »',
            $unconfirmed->message,
            'Says what to do, where it can be done, not « déjà publié ».'
        );
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

    /**
     * A kept card IS an image, and the refusal gate has to agree.
     *
     * The gate predates the kept card and tested `image` alone, while
     * `cardBytes()` serves the card without ever reading `image`. So a
     * frozen share whose album lost its cover afterwards — the photo
     * deleted, its storage briefly unreadable — was refused on every
     * remaining destination although the card it would have sent was
     * right there; and the composer's image buttons are closed once
     * frozen, so the chief could never repair it. Found in the review
     * of #850.
     */
    public function testAKeptCardIsEnoughEvenWhenTheAlbumLostItsCover(): void
    {
        $kept = (new \Modules\Social\Card\CardRenderer())
            ->render(H::groupPhoto(), 'Camp', 'unite.example', true, 0.025);
        $coverless = new ShareSource(
            'album',
            3,
            'Camp',
            // The live cover is gone, and the album itself is not
            // blocked: it still exists and is still this chief's.
            null,
            true,
            null,
            'unite.example/gallery/3',
            'x',
            '/gallery/3/edit',
            null,
            null,
            null,
            null,
            card: $kept
        );

        $outcomes = $this->publish($coverless, [SocialPlatform::Instagram]);

        $this->assertTrue(
            $outcomes[0]->published,
            'the kept card was refused as « no image », with no way for the chief to repair it: '
                . $outcomes[0]->message
        );
        $this->assertStringNotContainsString('photo de couverture', $outcomes[0]->message);
        $this->assertNotSame([], $this->meta->requests, 'Instagram was handed nothing');
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

    /**
     * A second `CardService` over the same repository and directory, to
     * open what the first one issued — the only way to see the bytes
     * Meta was actually pointed at.
     */
    private function cardsService(): CardService
    {
        return new CardService(
            new CardRepository($this->pdo),
            new CardRenderer(),
            $this->settings,
            new JournalService($this->journal),
            $this->directory
        );
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
            new PublicPageService(new CommunicationRepository($this->pdo), $this->settings, $journal),
            new MetaClient($this->meta, static function (int $seconds): void {
            })
        );
    }

    /**
     * **What a saved album share receives on the Page: a link to its own
     * public page** (issue #706, IT-04).
     *
     * Not a photo post, because Facebook builds a far better preview
     * from a page's Open Graph tags — and not a link to the album, which
     * stays « Membres connectés » where no crawler can follow. The page
     * is minted at this instant, by this publication: it did not exist
     * when the composer was open.
     *
     * Instagram still receives the card, at the hour-long address. The
     * two destinations get different things on purpose, and the composer
     * says so before the chief presses « Publier ».
     */
    public function testASavedAlbumShareGoesToFacebookAsALinkToItsPublicPage(): void
    {
        $id = $this->savedAlbumShare();

        $outcomes = $this->publish(
            $this->communicationBackedAlbum($id),
            [SocialPlatform::Facebook, SocialPlatform::Instagram]
        );

        $this->assertTrue($outcomes[0]->published && $outcomes[1]->published);
        $token = (string) (new CommunicationRepository($this->pdo))->find($id)?->publicToken;
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame(
            'https://unite.example/partage/' . $token,
            $this->request('/feed')['fields']['link'] ?? null
        );
        $this->assertNull($this->request('/photos'), 'No image post for a saved album share on the Page.');
        // Instagram's own fetchable address is still issued, and it is
        // the ephemeral one — the public page serves the same bytes from
        // a durable address, but Instagram is handed neither the page nor
        // a lasting image.
        $this->assertStringStartsWith(
            'https://unite.example/partage/carte/',
            $this->request('/media')['fields']['image_url'] ?? ''
        );
    }

    /**
     * **A publication that needs no page leaves none behind.** The
     * roadmap says the public page is created at the first share that
     * needs it, « pas avant », and a page once created stays online for
     * good: minting one for an Instagram-only publication would leave a
     * permanent public address nobody was ever given.
     */
    public function testAnInstagramOnlyPublicationLeavesNoPublicPage(): void
    {
        $id = $this->savedAlbumShare();

        $this->publish($this->communicationBackedAlbum($id), [SocialPlatform::Instagram]);

        $this->assertNull((new CommunicationRepository($this->pdo))->find($id)?->publicToken);
    }

    /**
     * **A destination refused in advance mints no page.** `publish()`
     * turns one down before `claim()` records anything, and `send()` —
     * the only caller of `ensureUrlFor()` — runs after that row exists.
     * So a public page cannot outlive an attempt that never happened:
     * it comes into being exactly where the freeze does.
     */
    public function testADestinationRefusedInAdvanceLeavesNoPublicPage(): void
    {
        $id = $this->savedAlbumShare();
        $withoutCover = new ShareSource(
            ShareSource::KIND_COMMUNICATION,
            $id,
            'Camp',
            null,
            true,
            null,
            'unite.example/gallery/3',
            'x',
            '/medias-sociaux/' . $id,
            null,
            'https://unite.example/gallery/3',
            ShareSource::KIND_ALBUM
        );

        $outcomes = $this->publish($withoutCover, [SocialPlatform::Facebook]);

        $this->assertFalse($outcomes[0]->published);
        $this->assertSame('Cet album n\'a pas encore de photo de couverture.', $outcomes[0]->message);
        $this->assertNull((new CommunicationRepository($this->pdo))->find($id)?->publicToken);
    }

    /**
     * **An album with no kept card goes as a PHOTO, and leaves no public
     * page behind it.**
     *
     * `card_file_id` is null whenever the browser posted no drawn canvas
     * — JavaScript off, a photo still loading — which
     * `CommunicationController::refreshPostedCard()` keeps supported on
     * purpose, the publication composing a card server-side instead.
     * That composition reaches Instagram and the groups; it cannot reach
     * the public page, which serves the kept file and nothing else, never
     * the source's photo. So pointing Facebook at that page would point
     * it at no image at all, where the same input used to go out as a
     * photo post carrying one. Found in review on the pull request for
     * IT-04.
     *
     * The photo is the better post, so it is what goes — and because
     * nothing needed the page, none is minted: a page, once it exists,
     * stays online for good.
     */
    public function testAnAlbumWithNoKeptCardGoesAsAPhotoAndMintsNoPage(): void
    {
        $id = $this->savedAlbumShare();

        $outcomes = $this->publish($this->communicationBackedAlbum($id, null), [SocialPlatform::Facebook]);

        $this->assertTrue($outcomes[0]->published);
        $this->assertNull($this->request('/feed'), 'not a link to a page with nothing to show');
        // And the photo carries a real address: the ephemeral card has to
        // be issued for it, which is the half `needsCardUrl()` got wrong
        // at first — a photo post with an empty image URL.
        $this->assertStringStartsWith(
            'https://unite.example/partage/carte/',
            $this->request('/photos')['fields']['url'] ?? ''
        );
        $this->assertNull((new CommunicationRepository($this->pdo))->find($id)?->publicToken);
    }

    /**
     * Instagram is untouched by any of this: it receives a card composed
     * server-side, as it always has, because what it needs is bytes at an
     * address and not a page.
     */
    public function testInstagramStillTakesAnAlbumWithNoKeptCard(): void
    {
        $id = $this->savedAlbumShare();

        $outcomes = $this->publish($this->communicationBackedAlbum($id, null), [SocialPlatform::Instagram]);

        $this->assertTrue($outcomes[0]->published);
        $this->assertStringStartsWith(
            'https://unite.example/partage/carte/',
            $this->request('/media')['fields']['image_url'] ?? ''
        );
    }

    /** A row for the share, so the page it mints belongs to something. */
    private function savedAlbumShare(): int
    {
        return (new CommunicationRepository($this->pdo))->create(
            '',
            'Les photos sont en ligne',
            7,
            new \DateTimeImmutable(),
            ShareSource::KIND_ALBUM,
            3
        );
    }

    /**
     * An album share as the composer saves it: recorded against the
     * communication, speaking as an album.
     */
    private function communicationBackedAlbum(int $id, ?string $card = self::DRAWN_CARD): ShareSource
    {
        // A real composed card, not a marker string: Instagram's
        // destination issues these bytes through `CardService::issue()`,
        // which runs `ReceivedCard::assertUsable()` on them — exact
        // dimensions included.
        $card = $card === self::DRAWN_CARD
            ? $this->cardsService()->compose(H::groupPhoto(), 'Camp', 'unite.example', true, null)
            : $card;
        return new ShareSource(
            ShareSource::KIND_COMMUNICATION,
            $id,
            'Camp',
            H::groupPhoto(),
            true,
            null,
            'unite.example/gallery/3',
            'x',
            '/medias-sociaux/' . $id,
            null,
            'https://unite.example/gallery/3',
            ShareSource::KIND_ALBUM,
            null,
            // The card the browser drew and posted at « Publier ». Its
            // absence is a refusal since the review of IT-04, not a
            // fallback: the page Facebook is pointed at shows this card
            // and nothing else.
            $card
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
