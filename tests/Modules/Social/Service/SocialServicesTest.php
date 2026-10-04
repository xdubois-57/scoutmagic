<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Service;

use Core\Journal\JournalService;
use Core\Module\SubProcessorView;
use Modules\Groups\Api\GroupPublisherInterface;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Repository\ConnectionRepository;
use Modules\Social\Repository\PublicationRepository;
use Modules\Social\Service\GroupPublishingService;
use Modules\Social\Service\SocialSharingService;
use Modules\Social\Service\SocialSubProcessorService;
use PHPUnit\Framework\TestCase;
use Tests\Core\Http\Controller\RecordingJournalRepository;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\FakeGroupPublisher;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * What the module tells the rest of the site: where it can publish (the
 * Api), and whom it sends data to (the RGPD page).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class SocialServicesTest extends TestCase
{
    private ConnectionRepository $connections;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->connections = new ConnectionRepository($this->pdo, H::encryption());
    }

    // ————— canPublish(): has THIS person anywhere to publish —————
    //
    // Against the real service, not a double of its interface: the
    // branching below is what the « Partager » button turns on, and a
    // hand-written implementation of SocialSharingInterface proves only
    // that ShareActionsTest's doubles behave as written.

    public function testAUsableMetaAccountIsEnoughToPublish(): void
    {
        $this->connect(SocialPlatform::Facebook);

        $this->assertTrue($this->sharing(null)->canPublish('chef@unite.test', 'chief', 7));
    }

    /**
     * The groups module off: Meta is then the whole answer, and a unit
     * that has connected nothing has nowhere to publish.
     */
    public function testWithoutGroupsAndWithoutMetaNobodyCanPublish(): void
    {
        $this->assertFalse($this->sharing(null)->canPublish('chef@unite.test', 'chief', 7));
    }

    /**
     * A discussion group alone is enough — the whole reason this question
     * is broader than the Meta accounts: a unit with no Meta account at
     * all still shares to its own groups.
     */
    public function testAPostableGroupAloneIsEnoughToPublish(): void
    {
        $this->assertTrue($this->sharing($this->groupDestination())->canPublish('chef@unite.test', 'chief', 7));
    }

    /**
     * Asked of the VIEWER: the fake refuses an intendant, so two people
     * get two answers from the same site, which is the point.
     */
    public function testSomebodyWithNoPostableGroupCannotPublish(): void
    {
        $this->assertFalse($this->sharing($this->groupDestination())->canPublish('claire@unite.test', 'intendant', 9));
    }

    /**
     * No account id, no group: GroupPublishingService::publish() needs one
     * and the groups module answers per person, so there is nobody to ask
     * for — an anonymous caller is not « somebody with no groups ».
     */
    public function testWithoutAnAccountIdThereIsNobodyToHaveGroups(): void
    {
        $this->assertFalse($this->sharing($this->groupDestination())->canPublish('chef@unite.test', 'chief', null));
    }

    /**
     * It never throws: a groups module in trouble reads as « no group »,
     * so the button simply does not appear instead of taking the album
     * page down with it.
     */
    public function testAGroupsModuleInTroubleReadsAsNoGroup(): void
    {
        $exploding = new class implements GroupPublisherInterface {
            public function postableGroups(?string $email, string $role, ?int $userAccountId): array
            {
                throw new \RuntimeException('groups are down');
            }

            public function publish(
                int $groupId,
                ?string $email,
                string $role,
                int $userAccountId,
                string $body,
                ?string $image,
                ?string $link
            ): \Modules\Groups\Api\PublishedGroupPost {
                throw new \RuntimeException('unused');
            }
        };

        $this->assertFalse(
            $this->sharing($this->groupDestination($exploding))->canPublish('chef@unite.test', 'chief', 7)
        );
    }

    private function sharing(?GroupPublishingService $groups): SocialSharingService
    {
        return new SocialSharingService($this->connections, $groups);
    }

    private function groupDestination(?GroupPublisherInterface $publisher = null): GroupPublishingService
    {
        return new GroupPublishingService(
            $publisher ?? new FakeGroupPublisher(),
            new PublicationRepository($this->pdo),
            new JournalService(new RecordingJournalRepository())
        );
    }

    private function connect(SocialPlatform $platform): void
    {
        $this->connections->saveCredentials($platform, '123456', 'S');
        $this->connections->connect($platform, '42', 'Unité 25', 'PAGE', null, new \DateTimeImmutable());
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
