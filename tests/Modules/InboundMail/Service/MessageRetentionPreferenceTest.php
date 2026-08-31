<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Api\MessageRetentionPreference;
use Modules\InboundMail\Client\FakeMailboxClient;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\AnalysisResultApplier;
use Modules\InboundMail\Service\AttachmentPolicy;
use Modules\InboundMail\Service\MailboxClientFactory;
use Modules\InboundMail\Service\MailboxErrorFormatter;
use Modules\InboundMail\Service\MailboxSyncService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use Modules\InboundMail\Service\MessageContentSanitizer;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * What a consumer asks to be kept of the messages it claims (roadmap
 * IT-22), through the real synchronisation path.
 *
 * Three properties, and the first one is the one that would hurt: a
 * consumer that says nothing must behave **exactly** as before. The
 * others are the point of the option — the raw headers are where a mail
 * diagnosis lives, and a probe that only needs them has no business
 * keeping what anybody wrote.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MessageRetentionPreferenceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private InboundMailboxRepository $mailboxRepository;
    private InboundMessageRepository $messageRepository;
    private MessageConsumerRegistry $registry;
    private FakeMailboxClient $client;
    private MailboxSyncService $service;
    private int $mailboxId;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->mailboxRepository = new InboundMailboxRepository($this->pdo, $this->encryption);
        $this->messageRepository = new InboundMessageRepository($this->pdo, $this->encryption);
        $this->registry = new MessageConsumerRegistry();
        $this->client = new FakeMailboxClient();

        $factory = new MailboxClientFactory();
        $factory->register(ProviderType::FAKE, $this->client);

        $this->storagePath = sys_get_temp_dir() . '/inbound-headers-test-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0777, true);

        $this->service = new MailboxSyncService(
            $this->mailboxRepository,
            $this->messageRepository,
            $this->registry,
            new MessageContentSanitizer(new HtmlSanitizer()),
            new AttachmentPolicy(),
            new MailboxErrorFormatter(),
            $factory,
            new AnalysisResultApplier($this->messageRepository),
            new UploadHandler(new FileRepository($this->pdo), $this->storagePath)
        );

        $this->mailboxId = $this->mailboxRepository->create(
            'Support',
            ProviderType::FAKE,
            'imap.test',
            993,
            'ssl',
            'support@unite.be',
            'un-mot-de-passe',
            ['INBOX'],
            true
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            foreach (glob($this->storagePath . '/*/*') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($this->storagePath . '/*') ?: [] as $directory) {
                @rmdir($directory);
            }
            @rmdir($this->storagePath);
        }
    }

    /**
     * The compatibility guarantee: three modules implement the consumer
     * contract and none of them declares anything, so "declares nothing"
     * has to be the behaviour that has always been.
     */
    public function testAConsumerDeclaringNothingKeepsTheBodyAndNoHeaders(): void
    {
        $this->registry->register(new FakeMessageConsumer(
            id: 'rental',
            onAnalyze: static fn(CandidateMessage $m): AnalysisResult
                => AnalysisResult::linkedTo('rental', 'LOC-1', LinkOrigin::REFERENCE)
        ));
        $this->addMessage(10);

        $this->sync();

        $row = $this->row();
        $this->assertNull($row['raw_headers_encrypted']);
        $this->assertStringContainsString('Bonjour', $this->decrypt($row['body_text_encrypted'], 'body_text'));
    }

    /**
     * The diagnosis case: the headers are kept, encrypted, and the body is
     * not kept at all.
     */
    public function testAConsumerWantingHeadersWithoutTheBodyGetsExactlyThat(): void
    {
        $this->registry->register($this->probe(wantsHeaders: true, wantsBody: false));
        $this->addMessage(10);

        $this->sync();

        $row = $this->row();
        $this->assertNotNull($row['raw_headers_encrypted']);

        $headers = $this->decrypt($row['raw_headers_encrypted'], 'raw_headers');
        $this->assertStringContainsString('Authentication-Results:', $headers);
        $this->assertStringContainsString('Received:', $headers);
        $this->assertStringNotContainsString(
            'Authentication-Results',
            (string) $row['raw_headers_encrypted'],
            'at rest it is a ciphertext, not a header block'
        );

        $this->assertSame('', $this->decrypt($row['body_text_encrypted'], 'body_text'));
        $this->assertSame('', $this->decrypt($row['body_html_encrypted'], 'body_html'));
    }

    /**
     * One module's frugality must not delete what another needs off the
     * same message — a mail can be a booking's correspondence and a probe
     * at once.
     */
    public function testTheWiderAnswerWinsWhenTwoConsumersClaimTheSameMessage(): void
    {
        $this->registry->register($this->probe(wantsHeaders: true, wantsBody: false, id: 'support'));
        $this->registry->register(new FakeMessageConsumer(
            id: 'rental',
            onAnalyze: static fn(CandidateMessage $m): AnalysisResult
                => AnalysisResult::linkedTo('rental', 'LOC-1', LinkOrigin::REFERENCE)
        ));
        $this->addMessage(10);

        $this->sync();

        $row = $this->row();
        $this->assertNotNull($row['raw_headers_encrypted'], 'the probe asked for them');
        $this->assertStringContainsString(
            'Bonjour',
            $this->decrypt($row['body_text_encrypted'], 'body_text'),
            'and rental still gets the body it never renounced'
        );
    }

    /**
     * A consumer that did not claim the message decides nothing about it:
     * its preference applies to its own mail, not to the whole box.
     */
    public function testAConsumerThatClaimedNothingDecidesNothing(): void
    {
        $this->registry->register($this->probe(wantsHeaders: true, wantsBody: false, claims: false));
        $this->registry->register(new FakeMessageConsumer(
            id: 'rental',
            onAnalyze: static fn(CandidateMessage $m): AnalysisResult
                => AnalysisResult::linkedTo('rental', 'LOC-1', LinkOrigin::REFERENCE)
        ));
        $this->addMessage(10);

        $this->sync();

        $row = $this->row();
        $this->assertNull($row['raw_headers_encrypted']);
        $this->assertStringContainsString('Bonjour', $this->decrypt($row['body_text_encrypted'], 'body_text'));
    }

    /**
     * A message that crossed several relays carries a long chain, and
     * there is no useful ceiling on how long. What is cut says so inside
     * itself: a diagnosis read from a silently shortened block is a
     * diagnosis of the wrong message.
     */
    public function testAVeryLongHeaderBlockIsTruncatedAndSaysSo(): void
    {
        $this->registry->register($this->probe(wantsHeaders: true, wantsBody: true));

        $relays = [];
        for ($i = 0; $i < 400; $i++) {
            $relays['X-Relay-' . $i] = str_repeat('relay' . $i . '.example.net ', 6);
        }
        $this->client->addRawMessage('INBOX', 10, InboundMailTestHelper::rawMessage(
            [
                'From' => 'Jeanne Martin <jeanne@example.be>',
                'To' => 'support@unite.be',
                'Subject' => 'Un long voyage',
                'Message-ID' => '<long@example.be>',
                'Date' => 'Mon, 12 Jul 2027 09:30:00 +0200',
                'Content-Type' => 'text/plain; charset=UTF-8',
            ] + $relays,
            'Bonjour'
        ));

        $this->sync();

        $headers = $this->decrypt($this->row()['raw_headers_encrypted'], 'raw_headers');
        $this->assertLessThanOrEqual(
            InboundMessageRepository::MAX_RAW_HEADERS_BYTES + 64,
            strlen($headers)
        );
        $this->assertStringContainsString(
            InboundMessageRepository::RAW_HEADERS_TRUNCATION_MARKER,
            $headers
        );
    }

    /**
     * What is kept comes back through the module's own contract, so a
     * consumer reads it the way it reads everything else — decrypted in
     * the repository and nowhere else (SECURITY.md §5).
     */
    public function testWhatIsKeptComesBackOnTheApiMessage(): void
    {
        $this->registry->register($this->probe(wantsHeaders: true, wantsBody: true));
        $this->addMessage(10);
        $this->sync();

        $messages = $this->messageRepository->findForReference('support', 'PROBE-1');
        $this->assertCount(1, $messages);
        $this->assertNotNull($messages[0]->rawHeaders);
        $this->assertStringContainsString('DKIM-Signature:', $messages[0]->rawHeaders);
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function addMessage(int $uid): void
    {
        $this->client->addRawMessage('INBOX', $uid, InboundMailTestHelper::rawMessage([
            'From' => 'Jeanne Martin <jeanne@example.be>',
            'To' => 'support@unite.be',
            'Subject' => 'Un message',
            'Message-ID' => '<msg-1@example.be>',
            'Date' => 'Mon, 12 Jul 2027 09:30:00 +0200',
            'Received' => 'from mx.example.net by unite.be; Mon, 12 Jul 2027 09:30:01 +0200',
            'Authentication-Results' => 'unite.be; spf=pass smtp.mailfrom=example.be; dkim=pass',
            'DKIM-Signature' => 'v=1; a=rsa-sha256; d=example.be; s=mail; b=abcdef',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ], 'Bonjour, ceci est le corps.'));
    }

    /**
     * A consumer that declares what it wants kept — the shape the support
     * probe of Lot D will have.
     */
    private function probe(
        bool $wantsHeaders,
        bool $wantsBody,
        string $id = 'support',
        bool $claims = true
    ): MessageConsumerInterface {
        return new class ($id, $wantsHeaders, $wantsBody, $claims)
            implements MessageConsumerInterface, MessageRetentionPreference {
            public function __construct(
                private string $id,
                private bool $headers,
                private bool $body,
                private bool $claims
            ) {
            }

            public function consumerId(): string
            {
                return $this->id;
            }

            public function displayName(): string
            {
                return 'Support';
            }

            public function analyze(CandidateMessage $message): AnalysisResult
            {
                return $this->claims
                    ? AnalysisResult::linkedTo($this->id, 'PROBE-1', LinkOrigin::REFERENCE)
                    : AnalysisResult::nothing();
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

            public function describeEvidence(): array
            {
                return ['adresse de diagnostic'];
            }

            public function triageAudienceLabel(): string
            {
                return 'les superadministrateurs';
            }

            public function triageAudienceCount(): int
            {
                return 1;
            }

            public function wantsRawHeaders(): bool
            {
                return $this->headers;
            }

            public function wantsBody(): bool
            {
                return $this->body;
            }
        };
    }

    private function sync(): void
    {
        $mailbox = $this->mailboxRepository->findById($this->mailboxId);
        $this->assertNotNull($mailbox);
        $this->service->syncMailbox($mailbox, new \DateTimeImmutable('2027-07-12 10:00:00'));
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $row = $this->pdo->query('SELECT * FROM inbound_messages ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertIsArray($row);

        return $row;
    }

    private function decrypt(mixed $value, string $column): string
    {
        return $this->encryption->decrypt((string) $value, 'inbound_messages.' . $column);
    }
}
