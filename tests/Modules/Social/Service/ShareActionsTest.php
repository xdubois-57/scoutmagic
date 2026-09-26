<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Service;

use Modules\Social\Api\SocialDestination;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Api\SocialSharingInterface;
use Modules\Social\Service\AlbumShareAction;
use Modules\Social\Service\ArticleShareAction;
use PHPUnit\Framework\TestCase;

/**
 * « Partager » is offered on an album and an article only while some
 * destination can take it: a button that can only lead to « aucun compte
 * connecté » is noise on two busy pages.
 */
final class ShareActionsTest extends TestCase
{
    public function testNothingIsOfferedWhileNoAccountIsConnected(): void
    {
        $this->assertSame([], (new AlbumShareAction($this->sharing([])))->actionsFor(3));
        $this->assertSame([], (new ArticleShareAction($this->sharing([])))->actionsFor(5));
    }

    public function testOneConnectedAccountOffersTheShareScreen(): void
    {
        $sharing = $this->sharing([new SocialDestination(SocialPlatform::Facebook, 'Unité 25')]);

        $album = (new AlbumShareAction($sharing))->actionsFor(3);
        $article = (new ArticleShareAction($sharing))->actionsFor(5);

        $this->assertSame(['Partager', '/partage/album/3'], [$album[0]->label, $album[0]->url]);
        $this->assertSame(['Partager', '/partage/actualite/5'], [$article[0]->label, $article[0]->url]);
    }

    /**
     * @param list<SocialDestination> $destinations
     */
    private function sharing(array $destinations): SocialSharingInterface
    {
        return new class ($destinations) implements SocialSharingInterface {
            /** @param list<SocialDestination> $destinations */
            public function __construct(private readonly array $destinations)
            {
            }

            public function connectedDestinations(): array
            {
                return $this->destinations;
            }
        };
    }
}
