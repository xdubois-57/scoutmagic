<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\MailboxPurpose;
use Modules\InboundMail\Api\MailboxScope;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Api\ReadMode;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\InboundMailService;
use Modules\InboundMail\Service\MailboxScopeService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * `Api\InboundMailInterface::listMailboxSummariesFor()` — the boxes one
 * module may analyse, named for that module's own screen (issue #748).
 *
 * The page « Biens à louer » used to list every box on the site under
 * « Courrier entrant », a box another module reads included. The
 * effective scope decides now, the one the sync itself obeys.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ScopedMailboxSummariesTest extends TestCase
{
    private \PDO $pdo;
    private InboundMailboxRepository $mailboxRepository;
    private MessageConsumerRegistry $registry;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);

        $this->mailboxRepository = new InboundMailboxRepository($this->pdo, $this->encryption());
        $this->registry = new MessageConsumerRegistry();
        $this->registry->register(new ScopeNamingConsumer('rental'));
        $this->registry->register(new ScopeNamingConsumer('camps'));
    }

    public function testADedicatedBoxAndASharedBoxOpenToTheModuleAreListed(): void
    {
        $dedicated = $this->mailbox('Locations');
        $this->mailboxRepository->setPurpose($dedicated, MailboxPurpose::DEDICATED, 'rental');
        $shared = $this->mailbox('Unité');
        $this->scope($shared, 'rental', true);

        $summaries = $this->service()->listMailboxSummariesFor('rental');

        $this->assertSame([$dedicated, $shared], array_keys($summaries));
        $this->assertSame('Locations', $summaries[$dedicated]['name']);
        $this->assertSame('Unité', $summaries[$shared]['name']);
    }

    /**
     * A box another module reads is not this module's: listing it on the
     * Locations page would read as « Locations receives this ».
     */
    public function testABoxOfAnotherModuleIsNeverListed(): void
    {
        $camps = $this->mailbox('Camps');
        $this->mailboxRepository->setPurpose($camps, MailboxPurpose::DEDICATED, 'camps');
        $sharedWithCampsOnly = $this->mailbox('Unité');
        $this->scope($sharedWithCampsOnly, 'camps', true);
        $this->scope($sharedWithCampsOnly, 'rental', false);
        $this->mailbox('Personne');

        $this->assertSame([], $this->service()->listMailboxSummariesFor('rental'));
        $this->assertSame(
            [$camps, $sharedWithCampsOnly],
            array_keys($this->service()->listMailboxSummariesFor('camps'))
        );
    }

    /**
     * A disabled box in scope is still this module's: it stays, flagged,
     * as the unscoped summaries already showed it.
     */
    public function testADisabledBoxInScopeStaysAndSaysSo(): void
    {
        $box = $this->mailbox('Locations', enabled: false);
        $this->scope($box, 'rental', true);

        $summaries = $this->service()->listMailboxSummariesFor('rental');

        $this->assertArrayHasKey($box, $summaries);
        $this->assertFalse($summaries[$box]['is_enabled']);
    }

    /** What a manager sees of a box: its name and state, never how to reach it. */
    public function testASummaryCarriesNoHostAndNoAccount(): void
    {
        $box = $this->mailbox('Locations');
        $this->scope($box, 'rental', true);

        $this->assertSame(
            ['name', 'state', 'is_enabled'],
            array_keys($this->service()->listMailboxSummariesFor('rental')[$box])
        );
    }

    /** Without a way to read scopes, nothing is in scope — never every box. */
    public function testWithoutAScopeServiceNothingIsListed(): void
    {
        $this->scope($this->mailbox('Locations'), 'rental', true);

        $service = new InboundMailService(
            new InboundMessageRepository($this->pdo, $this->encryption()),
            $this->mailboxRepository,
            new FileRepository($this->pdo),
            $this->registry
        );

        $this->assertSame([], $service->listMailboxSummariesFor('rental'));
    }

    private function service(): InboundMailService
    {
        return new InboundMailService(
            new InboundMessageRepository($this->pdo, $this->encryption()),
            $this->mailboxRepository,
            new FileRepository($this->pdo),
            $this->registry,
            new MailboxScopeService($this->mailboxRepository, $this->registry)
        );
    }

    private function mailbox(string $name, bool $enabled = true): int
    {
        return $this->mailboxRepository->create(
            $name,
            ProviderType::IMAP,
            'imap.test',
            993,
            'ssl',
            strtolower($name) . '@unite.be',
            'secret',
            ['INBOX'],
            $enabled
        );
    }

    private function scope(int $mailboxId, string $consumerId, bool $analyzes): void
    {
        $this->mailboxRepository->saveScope(
            $mailboxId,
            new MailboxScope($consumerId, $analyzes, $analyzes ? ReadMode::ALL : ReadMode::NONE)
        );
    }

    private function encryption(): EncryptionService
    {
        return new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }
}

/**
 * A consumer that exists only to have an id the scope rows can name.
 */
final class ScopeNamingConsumer implements MessageConsumerInterface
{
    public function __construct(private string $id)
    {
    }

    public function consumerId(): string
    {
        return $this->id;
    }

    public function displayName(): string
    {
        return $this->id;
    }

    public function analyze(CandidateMessage $message): AnalysisResult
    {
        return AnalysisResult::nothing();
    }

    public function analyzeStored(InboundMessage $message): AnalysisResult
    {
        return AnalysisResult::nothing();
    }

    public function onLinked(InboundMessage $message, MessageLink $link): void
    {
    }

    public function onUnlinked(InboundMessage $message, MessageLink $link): void
    {
    }

    public function canRead(string $businessReference, array $linkedMemberIds, string $role): bool
    {
        return false;
    }

    public function describeReference(string $businessReference): ?string
    {
        return null;
    }

    public function describeEvidence(): array
    {
        return [];
    }

    public function triageAudienceLabel(): string
    {
        return '';
    }

    public function triageAudienceCount(): int
    {
        return 0;
    }
}
