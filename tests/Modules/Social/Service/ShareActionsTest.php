<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Service;

use Modules\Social\Api\SocialDestination;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Api\SocialSharingInterface;
use Modules\Social\Service\AlbumShareAction;
use Modules\Social\Service\ArticleShareAction;
use Modules\Social\Service\ShareViewer;
use PHPUnit\Framework\TestCase;

/**
 * « Partager » is offered on an album and an article only while this
 * person has somewhere to publish — and when they have not, the page says
 * so in one line rather than losing a button without a word
 * (docs/chantiers/CHANTIER-medias-sociaux.md, IT-01).
 */
final class ShareActionsTest extends TestCase
{
    public function testNothingIsOfferedWithNowhereToPublish(): void
    {
        $this->assertSame([], (new AlbumShareAction($this->sharing(false), $this->chief()))->actionsFor(3));
        $this->assertSame([], (new ArticleShareAction($this->sharing(false), $this->chief()))->actionsFor(5));
    }

    public function testADestinationOffersTheComposerPrefilledFromTheSource(): void
    {
        $sharing = $this->sharing(true);

        $album = (new AlbumShareAction($sharing, $this->chief()))->actionsFor(3);
        $article = (new ArticleShareAction($sharing, $this->chief()))->actionsFor(5);

        // The one composer, prefilled — the dedicated share pages are gone.
        $this->assertSame(['Partager', '/medias-sociaux/nouvelle/album/3'], [$album[0]->label, $album[0]->url]);
        $this->assertSame(
            ['Partager', '/medias-sociaux/nouvelle/article/5'],
            [$article[0]->label, $article[0]->url]
        );
    }

    /**
     * The icon alone, with the label left to carry it as accessible name
     * and tooltip: « Partager » sits beside the controls that matter more
     * on both pages.
     */
    public function testTheButtonIsTheIconAlone(): void
    {
        $album = (new AlbumShareAction($this->sharing(true), $this->chief()))->actionsFor(3);
        $article = (new ArticleShareAction($this->sharing(true), $this->chief()))->actionsFor(5);

        $this->assertTrue($album[0]->iconOnly);
        $this->assertTrue($article[0]->iconOnly);
        $this->assertSame(['bi-share', 'bi-share'], [$album[0]->icon, $article[0]->icon]);
    }

    /**
     * The question asked is `canPublish()`, not the Meta accounts: a unit
     * that has connected nothing still publishes to its own discussion
     * groups, and the narrower question hid the button for exactly those
     * units.
     */
    public function testTheQuestionIsAskedOfTheViewerNotOfTheAccounts(): void
    {
        $sharing = new class implements SocialSharingInterface {
            public function connectedDestinations(): array
            {
                // No Meta account at all.
                return [];
            }

            public function canPublish(?string $email, string $role, ?int $userAccountId): bool
            {
                // …but a discussion group this person may post in.
                return $email === 'chef@unite.test';
            }
        };

        $offered = (new AlbumShareAction($sharing, $this->chief()))->actionsFor(3);
        $stranger = new ShareViewer('autre@unite.test', 'chief', 9);

        $this->assertCount(1, $offered);
        $this->assertSame([], (new AlbumShareAction($sharing, $stranger))->actionsFor(3));
    }

    public function testNoDestinationIsExplainedRatherThanLeftToBeGuessed(): void
    {
        $albumNote = (new AlbumShareAction($this->sharing(false), $this->chief()))->noteFor(3);
        $articleNote = (new ArticleShareAction($this->sharing(false), $this->chief()))->noteFor(5);

        $this->assertNotNull($albumNote);
        $this->assertNotNull($articleNote);
        $this->assertStringContainsString('Aucune destination', $albumNote->text);
        $this->assertStringContainsString('groupe de discussion', $albumNote->text);
        $this->assertSame($albumNote->text, $articleNote->text);
    }

    /**
     * The configuration screen is superadmin's, so offering its link to a
     * chief would send them into a 403 — a link nobody can follow is worse
     * than no link (SECURITY.md §3).
     */
    public function testTheConfigurationLinkIsOnlyOfferedToSomebodyWhoMayOpenIt(): void
    {
        $chief = (new AlbumShareAction($this->sharing(false), $this->chief()))->noteFor(3);
        $superadmin = (new AlbumShareAction(
            $this->sharing(false),
            new ShareViewer('admin@unite.test', 'superadmin', 1)
        ))->noteFor(3);

        $this->assertNull($chief->linkUrl);
        $this->assertNull($chief->linkLabel);
        $this->assertSame('/config/reseaux-sociaux', $superadmin->linkUrl);
        $this->assertNotNull($superadmin->linkLabel);
    }

    public function testThereIsNothingToExplainWhileTheButtonIsThere(): void
    {
        $this->assertNull((new AlbumShareAction($this->sharing(true), $this->chief()))->noteFor(3));
        $this->assertNull((new ArticleShareAction($this->sharing(true), $this->chief()))->noteFor(5));
    }

    private function chief(): ShareViewer
    {
        return new ShareViewer('chef@unite.test', 'chief', 7);
    }

    private function sharing(bool $canPublish): SocialSharingInterface
    {
        return new class ($canPublish) implements SocialSharingInterface {
            public function __construct(private readonly bool $canPublish)
            {
            }

            public function connectedDestinations(): array
            {
                return $this->canPublish
                    ? [new SocialDestination(SocialPlatform::Facebook, 'Unité 25')]
                    : [];
            }

            public function canPublish(?string $email, string $role, ?int $userAccountId): bool
            {
                return $this->canPublish;
            }
        };
    }
}
