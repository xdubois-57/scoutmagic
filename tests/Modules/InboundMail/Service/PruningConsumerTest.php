<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\PruningConsumerInterface;
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
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;
use Tests\Modules\InboundMail\Client\PruningFakeMailboxClient;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;
use Tests\DatabaseTestHelper;

/**
 * Removing a message from a remote mailbox — the one write this module
 * can make, and the two locks that hold it (roadmap IT-07).
 *
 * **The interesting cases are the refusals.** Deleting somebody's mail is
 * the gravest thing this module could be asked to do, so most of this
 * file is about the paths that must remove nothing: an ordinary consumer,
 * an ordinary client, a consumer that says no.
 *
 * @group database
 */
class PruningConsumerTest extends TestCase
{
    private \PDO $pdo;
    private MessageConsumerRegistry $registry;
    private int $mailboxId;
    private string $storagePath = '';

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $this->registry = new MessageConsumerRegistry();
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic-prune-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath . '/inbound', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/inbound/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->storagePath . '/inbound')) {
            rmdir($this->storagePath . '/inbound');
        }
        if (is_dir($this->storagePath)) {
            rmdir($this->storagePath);
        }
    }

    /**
     * A consumer that declares the pruning contract and answers as told.
     */
    private function pruningConsumer(bool $answer): object
    {
        return new class ($answer) extends FakeMessageConsumer implements PruningConsumerInterface {
            public int $asked = 0;

            public function __construct(private bool $answer)
            {
                parent::__construct(
                    id: 'core_mail_seed',
                    onAnalyze: static fn(CandidateMessage $m): AnalysisResult => AnalysisResult::nothing()
                );
            }

            public function shouldPruneAfterAnalysis(CandidateMessage $message): bool
            {
                $this->asked++;

                return $this->answer;
            }
        };
    }

    private function syncWith(FakeMailboxClient $client): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $mailboxRepository = new InboundMailboxRepository($this->pdo, $encryption);
        $messageRepository = new InboundMessageRepository($this->pdo, $encryption);

        $this->mailboxId = $mailboxRepository->create(
            'Témoin',
            ProviderType::FAKE,
            'imap.test',
            993,
            'ssl',
            'temoin@unite.be',
            'un-mot-de-passe',
            ['INBOX'],
            true
        );

        $client->addRawMessage('INBOX', 7, InboundMailTestHelper::rawMessage([
            'From' => 'expediteur@exemple.be',
            'Message-ID' => '<copie@unite.be>',
            'Subject' => 'Le camp de cet été',
        ], 'Bonjour'));

        $factory = new MailboxClientFactory();
        $factory->register(ProviderType::FAKE, $client);

        (new MailboxSyncService(
            $mailboxRepository,
            $messageRepository,
            $this->registry,
            new MessageContentSanitizer(new HtmlSanitizer()),
            new AttachmentPolicy(),
            new MailboxErrorFormatter(),
            $factory,
            new AnalysisResultApplier($messageRepository),
            new UploadHandler(new FileRepository($this->pdo), $this->storagePath)
        ))->syncAll(new \DateTimeImmutable('2026-09-20 10:00:00'));
    }

    /**
     * **Both locks open**: the consumer declared the contract and said
     * yes, and the client can delete. This is the one path that removes
     * anything.
     */
    public function testAMessageIsRemovedWhenBothHalvesAgree(): void
    {
        $this->registry->register($this->pruningConsumer(true));
        $client = new PruningFakeMailboxClient();

        $this->syncWith($client);

        $this->assertSame([['folder' => 'INBOX', 'uid' => 7]], $client->deleted);
    }

    /** The consumer says no: nothing goes. */
    public function testNothingIsRemovedWhenTheConsumerSaysNo(): void
    {
        $consumer = $this->pruningConsumer(false);
        $this->registry->register($consumer);
        $client = new PruningFakeMailboxClient();

        $this->syncWith($client);

        $this->assertSame(1, $consumer->asked, 'It must still be asked.');
        $this->assertSame([], $client->deleted);
    }

    /**
     * **An ordinary consumer is never even asked**, and this is the lock
     * that matters most: every consumer on this site but one is ordinary,
     * and none of them can remove anything however they behave.
     */
    public function testAnOrdinaryConsumerCannotRemoveAnything(): void
    {
        $this->registry->register(new FakeMessageConsumer(
            id: 'rental',
            onAnalyze: static fn(CandidateMessage $m): AnalysisResult => AnalysisResult::nothing()
        ));
        $client = new PruningFakeMailboxClient();

        $this->syncWith($client);

        $this->assertSame([], $client->deleted);
    }

    /**
     * **And a client that cannot write is the other half.** Most clients
     * cannot, on purpose — the reading contract has no vocabulary for it
     * — so a consumer asking to prune against one removes nothing and
     * raises nothing.
     */
    public function testAConsumerAskingAnOrdinaryClientRemovesNothing(): void
    {
        $consumer = $this->pruningConsumer(true);
        $this->registry->register($consumer);

        // The plain fake: reading contract only.
        $this->syncWith(new FakeMailboxClient());

        // Not even asked: there is nothing that could act on the answer.
        $this->assertSame(0, $consumer->asked);
        $this->assertSame(1, $this->countMessages(), 'And the message was still stored and analysed.');
    }

    private function countMessages(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM inbound_messages');
        $statement->execute();

        return (int) $statement->fetchColumn();
    }
}
