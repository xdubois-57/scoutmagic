<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMessage;
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
 * `Api\InboundMailInterface::probeAddressesFor()` — the one method of this
 * API that hands out an account address rather than the name-and-state
 * summary a manager gets (roadmap IT-27).
 *
 * It is a deliberate exception, and the whole of it is *scope*: a
 * consumer is told about the boxes it may already analyse, and about no
 * others. Everything below pins one edge of that.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ProbeAddressesTest extends TestCase
{
    private \PDO $pdo;
    private InboundMailboxRepository $mailboxRepository;
    private MessageConsumerRegistry $registry;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->mailboxRepository = new InboundMailboxRepository($this->pdo, $encryption);
        $this->registry = new MessageConsumerRegistry();
        $this->registry->register(new ProbingConsumer('support'));
        $this->registry->register(new ProbingConsumer('rental'));
    }

    public function testAConsumerIsToldAboutTheBoxesItMayAnalyse(): void
    {
        $open = $this->mailbox('support@unite.be');
        $this->allow($open, 'support');

        $this->assertSame(['support@unite.be'], $this->service()->probeAddressesFor('support'));
    }

    /**
     * A box this consumer does not analyse is not a box to probe: the
     * message would land where nobody claims it and produce a « jamais
     * reçu » that says nothing about the mail path.
     */
    public function testABoxAnotherConsumerAnalysesIsNotOffered(): void
    {
        $this->allow($this->mailbox('locations@unite.be'), 'rental');

        $this->assertSame([], $this->service()->probeAddressesFor('support'));
    }

    /** A box with no scope row at all is inert for everybody. */
    public function testABoxNobodyWasGrantedIsNotOffered(): void
    {
        $this->mailbox('divers@unite.be');

        $this->assertSame([], $this->service()->probeAddressesFor('support'));
    }

    /**
     * Writing to a box nothing synchronises would produce a « jamais reçu »
     * that is about the box being off, not about the mail path.
     */
    public function testADisabledBoxIsNotOffered(): void
    {
        $id = $this->mailbox('support@unite.be', enabled: false);
        $this->allow($id, 'support');

        $this->assertSame([], $this->service()->probeAddressesFor('support'));
    }

    /**
     * Some servers authenticate on a bare account name. That is not a
     * destination, and offering it would produce a bounce rather than a
     * diagnosis.
     */
    public function testAUsernameThatIsNotAnAddressIsLeftOut(): void
    {
        $this->allow($this->mailbox('support'), 'support');
        $this->allow($this->mailbox('support@unite.be'), 'support');

        $this->assertSame(['support@unite.be'], $this->service()->probeAddressesFor('support'));
    }

    /**
     * Without a scope service the gateway can answer nothing about scope,
     * and the safe answer to « which boxes may I probe » is none —
     * never « all of them ».
     */
    public function testWithoutAScopeServiceNothingIsOffered(): void
    {
        $this->allow($this->mailbox('support@unite.be'), 'support');

        $service = new InboundMailService(
            new InboundMessageRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
            $this->mailboxRepository,
            new FileRepository($this->pdo),
            $this->registry
        );

        $this->assertSame([], $service->probeAddressesFor('support'));
    }

    private function service(): InboundMailService
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new InboundMailService(
            new InboundMessageRepository($this->pdo, $encryption),
            $this->mailboxRepository,
            new FileRepository($this->pdo),
            $this->registry,
            new MailboxScopeService($this->mailboxRepository, $this->registry)
        );
    }

    private function mailbox(string $username, bool $enabled = true): int
    {
        return $this->mailboxRepository->create(
            'Boîte ' . $username,
            ProviderType::IMAP,
            'imap.test',
            993,
            'ssl',
            $username,
            'secret',
            ['INBOX'],
            $enabled
        );
    }

    private function allow(int $mailboxId, string $consumerId): void
    {
        $this->mailboxRepository->saveScope(
            $mailboxId,
            new MailboxScope($consumerId, true, ReadMode::ALL)
        );
    }
}

/**
 * A consumer that exists only to have an id the scope rows can name.
 */
final class ProbingConsumer implements MessageConsumerInterface
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
