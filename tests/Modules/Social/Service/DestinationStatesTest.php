<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Service;

use Core\Journal\JournalService;
use Modules\Social\Card\CardRenderer;
use Modules\Social\Card\CardService;
use Modules\Social\Meta\MetaClient;
use Modules\Social\Repository\CardRepository;
use Modules\Social\Repository\ConnectionRepository;
use Modules\Social\Repository\PublicationRepository;
use Modules\Social\Service\DestinationStates;
use Modules\Social\Service\PublishingService;
use Modules\Social\Service\PublishRequest;
use Modules\Social\Service\ShareSource;
use PHPUnit\Framework\TestCase;
use Tests\Core\Http\Controller\RecordingJournalRepository;
use Tests\Core\Http\Controller\RemoteBackupSettingsDouble;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * A publication request always answers for what it asked — never an
 * empty, green « success ».
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class DestinationStatesTest extends TestCase
{
    public function testGroupsAskedForWhereTheyAreNotOfferedAreRefusedAloud(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($pdo);
        $settings = new RemoteBackupSettingsDouble(['base_url' => 'https://unite.example']);
        $journal = new JournalService(new RecordingJournalRepository());
        $connections = new ConnectionRepository($pdo, H::encryption());
        $publications = new PublicationRepository($pdo);
        $publishing = new PublishingService(
            $connections,
            $publications,
            new CardService(new CardRepository($pdo), new CardRenderer(), $settings, $journal, sys_get_temp_dir()),
            $settings,
            $journal,
            new MetaClient(H::transport([]))
        );
        $states = new DestinationStates($publishing, $publications, $connections, $settings);
        $source = new ShareSource('album', 3, 'Camp', 'IMG', true, null, 'a', 'x', '/gallery/3/edit');

        $outcomes = $states->publish($source, new PublishRequest([], [], [3]), 'x', 'c@u.be', 'chief', 7, new \DateTimeImmutable());

        $this->assertCount(1, $outcomes);
        $this->assertFalse($outcomes[0]->published);
        [$type, $message] = DestinationStates::summary($outcomes);
        $this->assertSame('error', $type);
        $this->assertStringContainsString('pas disponibles', $message);
    }

    public function testNothingAtAllIsNeverASuccess(): void
    {
        $this->assertSame(['error', 'Rien n\'a été publié.'], DestinationStates::summary([]));
    }
}
