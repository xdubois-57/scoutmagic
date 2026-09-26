<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Service;

use Core\Module\SubProcessorView;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Repository\ConnectionRepository;
use Modules\Social\Service\SocialSharingService;
use Modules\Social\Service\SocialSubProcessorService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * What the module tells the rest of the site: where it can publish (the
 * Api), and whom it sends data to (the RGPD page).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class SocialServicesTest extends TestCase
{
    private ConnectionRepository $connections;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($pdo);
        $this->connections = new ConnectionRepository($pdo, H::encryption());
    }

    public function testCredentialsAloneAreNeitherADestinationNorASubProcessor(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');

        $this->assertSame([], (new SocialSharingService($this->connections))->connectedDestinations());
        $this->assertSame([], (new SocialSubProcessorService($this->connections))->getSubProcessors());
    }

    public function testAWorkingConnectionIsBoth(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Instagram, '123456', 'S');
        $this->connections->connect(
            SocialPlatform::Instagram,
            '9',
            'unite25sv',
            'T',
            new \DateTimeImmutable('+60 days'),
            new \DateTimeImmutable()
        );

        $destinations = (new SocialSharingService($this->connections))->connectedDestinations();
        $this->assertCount(1, $destinations);
        $this->assertSame(SocialPlatform::Instagram, $destinations[0]->platform);
        $this->assertSame('unite25sv', $destinations[0]->accountName);

        $views = (new SocialSubProcessorService($this->connections))->getSubProcessors();
        $this->assertCount(1, $views);
        $this->assertSame(SubProcessorView::CATEGORY_SOCIAL_PUBLISHING, $views[0]->category);
        $this->assertStringContainsString('Meta Platforms Ireland', $views[0]->name);
        $this->assertStringContainsString('Instagram', (string) $views[0]->details);
    }

    public function testARefusedConnectionIsNoLongerADestinationButStillDeclared(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->connections->connect(SocialPlatform::Facebook, '42', 'Unité 25', 'P', null, new \DateTimeImmutable());
        $this->connections->recordCheck(SocialPlatform::Facebook, false, new \DateTimeImmutable());

        $this->assertSame([], (new SocialSharingService($this->connections))->connectedDestinations());
        // Still connected in the RGPD sense: what was published through it
        // is still with Meta, and the unit may reconnect at any moment.
        $this->assertCount(1, (new SocialSubProcessorService($this->connections))->getSubProcessors());
    }
}
