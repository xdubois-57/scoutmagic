<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\MailboxPurpose;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\InboundMailService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * `Api\InboundMailInterface::listMailboxSummariesFor()` — the boxes one
 * module analyses, named for that module's own screen (issue #748).
 *
 * The page « Biens à louer » used to list every box on the site under
 * « Courrier entrant », a box another module reads included. The box's
 * purpose decides now, the same rule the sync itself obeys (§8.58): the
 * general box and the module's own reserved box, never another module's.
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

    /** The box's address is its name (D7): there is no other to show. */
    public function testTheReservedBoxAndTheGeneralBoxAreListedByTheirAddress(): void
    {
        $dedicated = $this->mailbox('locations');
        $this->mailboxRepository->setPurpose($dedicated, MailboxPurpose::DEDICATED, 'rental');
        $shared = $this->mailbox('unite');

        $summaries = $this->service()->listMailboxSummariesFor('rental');

        $this->assertSame([$dedicated, $shared], array_keys($summaries));
        $this->assertSame('locations@unite.be', $summaries[$dedicated]['name']);
        $this->assertSame('unite@unite.be', $summaries[$shared]['name']);
    }

    /**
     * A box reserved to another module is not this module's: listing it on
     * the Locations page would read as « Locations receives this ».
     */
    public function testABoxReservedToAnotherModuleIsNeverListed(): void
    {
        $camps = $this->mailbox('camps');
        $this->mailboxRepository->setPurpose($camps, MailboxPurpose::DEDICATED, 'camps');
        $general = $this->mailbox('unite');

        $this->assertSame([$general], array_keys($this->service()->listMailboxSummariesFor('rental')));
        $this->assertSame([$camps, $general], array_keys($this->service()->listMailboxSummariesFor('camps')));
    }

    /**
     * A disabled box is still this module's: it stays, flagged, as the
     * unscoped summaries already showed it.
     */
    public function testADisabledBoxStaysAndSaysSo(): void
    {
        $box = $this->mailbox('locations', enabled: false);

        $summaries = $this->service()->listMailboxSummariesFor('rental');

        $this->assertArrayHasKey($box, $summaries);
        $this->assertFalse($summaries[$box]['is_enabled']);
    }

    /** What a manager sees of a box: its address and state, never how to log in. */
    public function testASummaryCarriesNoHost(): void
    {
        $box = $this->mailbox('locations');

        $this->assertSame(
            ['name', 'state', 'is_enabled'],
            array_keys($this->service()->listMailboxSummariesFor('rental')[$box])
        );
    }

    private function service(): InboundMailService
    {
        return new InboundMailService(
            new InboundMessageRepository($this->pdo, $this->encryption()),
            $this->mailboxRepository,
            new FileRepository($this->pdo),
            $this->registry
        );
    }

    private function mailbox(string $local, bool $enabled = true): int
    {
        return $this->mailboxRepository->create(
            ProviderType::IMAP,
            'imap.test',
            993,
            'ssl',
            $local . '@unite.be',
            'secret',
            ['INBOX'],
            $enabled
        );
    }

    private function encryption(): EncryptionService
    {
        return new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }
}

/**
 * A consumer that exists only to have an id a box can be reserved to.
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
