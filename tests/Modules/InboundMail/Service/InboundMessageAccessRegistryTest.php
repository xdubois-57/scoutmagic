<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\File\EncryptedFileStorageService;
use Core\File\FileAccessGuard;
use Core\File\FileRepository;
use Core\Http\Controller\FileController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Photo\ImageVariantProcessor;
use Core\Photo\ImageVariantService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\InboundMessageAccessRegistry;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Who may download an inbound message's attachment.
 *
 * This file exists because of a real hole: attachments were written with
 * `role_min = 'intendant'` and **no owner at all**, so any intendant could
 * read any attachment of any watched mailbox by walking `/files/{id}` — a
 * rental contract, a camp's medical form, an invoice. The `role_min` floor
 * was never the partition; it only ever said "not the public".
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InboundMessageAccessRegistryTest extends TestCase
{
    private \PDO $pdo;
    private InboundMessageRepository $messages;
    private MessageConsumerRegistry $consumers;
    private InboundMessageAccessRegistry $registry;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);

        $this->messages = new InboundMessageRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->consumers = new MessageConsumerRegistry();
        $this->registry = new InboundMessageAccessRegistry($this->messages, $this->consumers);
    }

    public function testItAnswersForItsOwnOwnerTypeAndNoOther(): void
    {
        $this->assertTrue($this->registry->supports('inbound_message'));
        $this->assertFalse($this->registry->supports('camp_camp'));
        $this->assertFalse($this->registry->supports('rental_booking'));
    }

    // ── The partition itself ────────────────────────────────────────────

    public function testAnIntendantWithNoTieToTheBusinessObjectIsRefused(): void
    {
        $messageId = $this->storeMessage();
        $this->messages->addLink($messageId, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);
        $this->consumers->register($this->consumer('rental', answers: false));

        // The whole point: the role floor is satisfied, and it is still no.
        $this->assertFalse($this->registry->isAllowed($messageId, Role::INTENDANT, []));
    }

    public function testTheManagerOfTheBusinessObjectIsAllowed(): void
    {
        $messageId = $this->storeMessage();
        $this->messages->addLink($messageId, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);
        $this->consumers->register($this->consumer('rental', answers: true));

        $this->assertTrue($this->registry->isAllowed($messageId, Role::INTENDANT, []));
    }

    public function testOneConsumerSayingYesIsEnoughWhenSeveralAreAssociated(): void
    {
        $messageId = $this->storeMessage();
        $this->messages->addLink($messageId, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);
        $this->messages->addLink($messageId, 'finance', 'ACC-7', LinkOrigin::SENDER);
        $this->consumers->register($this->consumer('rental', answers: false));
        $this->consumers->register($this->consumer('finance', answers: true));

        $this->assertTrue($this->registry->isAllowed($messageId, Role::INTENDANT, []));
    }

    public function testTheQuestionIsPutWithTheAssociationsOwnBusinessReference(): void
    {
        $messageId = $this->storeMessage();
        $this->messages->addLink($messageId, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);
        $consumer = $this->consumer('rental', answers: true);
        $this->consumers->register($consumer);

        $this->registry->isAllowed($messageId, Role::INTENDANT, [12, 34]);

        $this->assertSame([['LOC-2027-0042', [12, 34], 'intendant']], $consumer->readQuestions);
    }

    // ── The two answers decided here rather than delegated ──────────────

    public function testAMessageNobodyIsAssociatedWithIsReadableByTheChiefOfUnitAlone(): void
    {
        $messageId = $this->storeMessage();

        $this->assertTrue($this->registry->isAllowed($messageId, Role::ADMIN, []));
        $this->assertFalse($this->registry->isAllowed($messageId, Role::CHIEF, []));
        $this->assertFalse($this->registry->isAllowed($messageId, Role::INTENDANT, []));
    }

    public function testTheChiefOfUnitReadsAnAssociatedMessageEveryConsumerRefuses(): void
    {
        // They read the whole box by design (§8.58's general mailbox). An
        // attachment visible on their screen but refusing to open would be
        // a broken page, not a protection.
        $messageId = $this->storeMessage();
        $this->messages->addLink($messageId, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);
        $this->consumers->register($this->consumer('rental', answers: false));

        $this->assertTrue($this->registry->isAllowed($messageId, Role::ADMIN, []));
    }

    // ── Failure modes, all of which must refuse ─────────────────────────

    public function testAConsumerThatThrowsIsARefusalRatherThanAGrant(): void
    {
        $messageId = $this->storeMessage();
        $this->messages->addLink($messageId, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);
        $this->consumers->register($this->consumer('rental', answers: false, throws: true));

        $this->assertFalse($this->registry->isAllowed($messageId, Role::INTENDANT, []));
    }

    public function testAnAssociationFromADisabledModuleGrantsNothing(): void
    {
        // Disabling a module never deletes its data (§7.3), and it must not
        // hand that data to somebody else either.
        $messageId = $this->storeMessage();
        $this->messages->addLink($messageId, 'camps', 'camp-3', LinkOrigin::SENDER);
        $this->consumers->register($this->consumer('rental', answers: true));

        $this->assertFalse($this->registry->isAllowed($messageId, Role::INTENDANT, []));
    }

    public function testAMessageThatNoLongerExistsGrantsNothingBelowTheChiefOfUnit(): void
    {
        $this->assertFalse($this->registry->isAllowed(4242, Role::INTENDANT, []));
        $this->assertFalse($this->registry->isAllowed(4242, Role::CHIEF, []));
    }

    // ── The consumer registry stays lazy on the web path ────────────────

    public function testAConsumerRegisteredAsAFactoryIsOnlyBuiltWhenItIsAsked(): void
    {
        $built = 0;
        $this->consumers->registerFactory('rental', function () use (&$built): MessageConsumerInterface {
            $built++;

            return $this->consumer('rental', answers: true);
        });

        // Nothing has asked yet: an ordinary page view must not assemble
        // the cross-module graph.
        $this->assertSame(0, $built);

        $messageId = $this->storeMessage();
        $this->messages->addLink($messageId, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);

        $this->assertTrue($this->registry->isAllowed($messageId, Role::INTENDANT, []));
        $this->assertTrue($this->registry->isAllowed($messageId, Role::INTENDANT, []));

        // And built once, not once per question.
        $this->assertSame(1, $built);
    }

    public function testAFactoryIsNeverBuiltForAnAssociationNobodyMade(): void
    {
        $built = 0;
        $this->consumers->registerFactory('camps', function () use (&$built): MessageConsumerInterface {
            $built++;

            return $this->consumer('camps', answers: true);
        });

        $messageId = $this->storeMessage();
        $this->messages->addLink($messageId, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);

        $this->assertFalse($this->registry->isAllowed($messageId, Role::INTENDANT, []));
        $this->assertSame(0, $built);
    }

    // ── Through the real guard, not just the checker ────────────────────

    public function testFileAccessGuardRefusesTheAttachmentOfSomebodyElsesBooking(): void
    {
        $messageId = $this->storeMessage();
        $this->messages->addLink($messageId, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);
        $this->consumers->register($this->consumer('rental', answers: false));

        $files = new FileRepository($this->pdo);
        $fileId = $files->create(
            'inbound_mail/attachments/x.pdf',
            'contrat.pdf',
            'application/pdf',
            1024,
            'intendant',
            'inbound_mail',
            null,
            false,
            null,
            InboundMessageAccessRegistry::OWNER_TYPE,
            $messageId
        );

        $denied = new FileAccessGuard($files, Role::INTENDANT, [], [$this->registry]);
        $this->assertNull($denied->check($fileId), 'The role_min floor must not be the partition.');

        $allowedConsumers = new MessageConsumerRegistry();
        $allowedConsumers->register($this->consumer('rental', answers: true));
        $allowed = new FileAccessGuard($files, Role::INTENDANT, [], [
            new InboundMessageAccessRegistry($this->messages, $allowedConsumers),
        ]);
        $this->assertNotNull($allowed->check($fileId));
    }

    /**
     * A refusal is recorded, and records nothing about the message.
     *
     * `file_access_denied` carries the file id and the IP, and that has to
     * stay true here of all places: the thing being refused is somebody
     * else's correspondence, and a journal line naming its subject, its
     * sender or its attachment's filename would put in the journal exactly
     * what the refusal was protecting (§7.9).
     */
    public function testARefusedDownloadIsJournalledWithoutAnythingFromTheMessage(): void
    {
        $messageId = $this->storeMessage();
        $this->messages->addLink($messageId, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);
        $this->consumers->register($this->consumer('rental', answers: false));

        $files = new FileRepository($this->pdo);
        $fileId = $files->create(
            'inbound_mail/attachments/secret.pdf',
            'contrat-signe-jeanne-martin.pdf',
            'application/pdf',
            1024,
            'intendant',
            'inbound_mail',
            null,
            false,
            null,
            InboundMessageAccessRegistry::OWNER_TYPE,
            $messageId
        );

        $storagePath = sys_get_temp_dir();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $controller = new FileController(
            new Environment(new ArrayLoader([])),
            new FileAccessGuard($files, Role::INTENDANT, [], [$this->registry]),
            $storagePath,
            new EncryptedFileStorageService($files, $encryption, $storagePath),
            new ImageVariantService($files, new ImageVariantProcessor(), $storagePath)
        );
        $controller->setJournalService(new JournalService(new JournalRepository($this->pdo)));

        $response = $controller->serve(
            new Request('GET', "/files/{$fileId}", [], [], [], []),
            ['id' => (string) $fileId]
        );

        $this->assertSame(403, $response->getStatusCode());

        $row = $this->pdo
            ->query("SELECT event_type, context FROM event_log WHERE event_type = 'file_access_denied'")
            ->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $context = (string) $row['context'];
        $this->assertStringContainsString('"file_id":' . $fileId, $context);
        $this->assertStringNotContainsString('contrat-signe-jeanne-martin', $context);
        $this->assertStringNotContainsString('jeanne@example.be', $context);
        $this->assertStringNotContainsString('LOC-2027-0042', $context);
        $this->assertStringNotContainsString('Contrat', $context);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function storeMessage(): int
    {
        static $uid = 10;

        return $this->messages->create(
            mailboxId: 1,
            folder: 'INBOX',
            uidValidity: 1,
            imapUid: ++$uid,
            messageId: 'msg-' . $uid . '@example.be',
            inReplyTo: null,
            subject: 'Contrat',
            fromEmail: 'jeanne@example.be',
            fromName: null,
            bodyText: 'Bonjour',
            bodyHtml: '',
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00')
        );
    }

    /**
     * A consumer that answers what it was told to, and remembers what it
     * was asked.
     */
    private function consumer(
        string $consumerId,
        bool $answers,
        bool $throws = false
    ): FakeMessageConsumer {
        return new FakeMessageConsumer(
            id: $consumerId,
            readAnswer: $answers,
            throwsOnRead: $throws
        );
    }
}
