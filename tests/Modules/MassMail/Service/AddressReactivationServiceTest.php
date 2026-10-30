<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Config\ScoutYearService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Member\AddressReactivationRegistry;
use Core\Member\EmailAddressReactivationService;
use Core\Member\MemberEmailException;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberEmailService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\MassMail\Repository\ContactRepository;
use Modules\MassMail\Repository\ListBlock;
use Modules\MassMail\Repository\ListBlockRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Service\AddressReactivationService;
use Modules\MassMail\Service\ListBlockService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

#[\PHPUnit\Framework\Attributes\Group('database')]
class AddressReactivationServiceTest extends TestCase
{
    protected \PDO $pdo;
    private EncryptionService $encryption;
    private MemberEmailRepository $members;
    private ContactRepository $contacts;
    private SuppressedAddressRepository $suppressed;
    private AddressReactivationRegistry $registry;
    private EmailAddressReactivationService $service;
    private JournalService $journal;
    private int $first;
    private int $second;
    private int $pending;
    private int $other;
    private int $groupA;
    private int $groupB;
    private int $actor;

    protected function database(): \PDO
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($pdo);
        return $pdo;
    }

    protected function setUp(): void
    {
        $this->pdo = $this->database();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->members = new MemberEmailRepository($this->pdo, $this->encryption);
        $this->contacts = new ContactRepository($this->pdo, $this->encryption);
        $this->suppressed = new SuppressedAddressRepository($this->pdo);
        $this->journal = new JournalService(new JournalRepository($this->pdo));
        $this->registry = new AddressReactivationRegistry();
        // The same object is handed to core BEFORE the module registers itself.
        $this->service = new EmailAddressReactivationService($this->members, $this->journal, $this->registry);
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([
            $this->encryption->encrypt('admin@example.org', 'user_accounts.email'),
            $this->encryption->blindIndex('admin@example.org', 'email'),
        ]);
        $this->actor = (int) $this->pdo->lastInsertId();
        $ids = [];
        foreach (['FIRST', 'SECOND', 'PENDING'] as $deskId) {
            $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
            $stmt->execute([$deskId]);
            $ids[] = (int) $this->pdo->lastInsertId();
        }
        $this->first = $this->members->create($ids[0], 'shared@example.org', 'manual', 'valid', null, null);
        $this->second = $this->members->create($ids[1], 'shared@example.org', 'desk', 'valid', null, null);
        $this->members->markInactive($this->first);
        $this->members->markInactive($this->second);
        $this->pending = $this->members->create($ids[2], 'shared@example.org', 'manual', 'pending', null, null);
        $this->other = $this->members->create($ids[0], 'other@example.org', 'manual', 'inactive', null, null);
        $groups = [];
        foreach (['Liste A', 'Liste B'] as $name) {
            $stmt = $this->pdo->prepare('INSERT INTO mass_mail_lists (name, description) VALUES (?, ?)');
            $stmt->execute([$name, 'Description']);
            $groups[] = (new ListBlockRepository($this->pdo))->create(
                (int) $this->pdo->lastInsertId(), ListBlock::KIND_CONTACT_GROUP, 'Contacts', null
            );
        }
        [$this->groupA, $this->groupB] = $groups;
        $this->contacts->create($this->groupA, 'Premier contact', 'shared@example.org');
        $this->contacts->create($this->groupB, 'Second contact', 'SHARED@example.org');
        $this->contacts->create($this->groupA, 'Autre contact', 'other@example.org');
        $this->contacts->unsubscribeEverywhere('shared@example.org');
        $this->contacts->unsubscribeEverywhere('other@example.org');
        $this->suppressed->suppress('shared@example.org');
        $this->suppressed->suppress('other@example.org');
    }

    private function enableModule(
        ?ContactRepository $contacts = null,
        ?SuppressedAddressRepository $suppressed = null
    ): void
    {
        $this->registry->provide(new AddressReactivationService(
            $this->pdo, $contacts ?? $this->contacts, $suppressed ?? $this->suppressed, $this->journal
        ));
    }

    public function testMemberReactivationRestoresSharedMembersAndAllContactGroupsTogether(): void
    {
        $this->enableModule();
        $mail = $this->createMock(MailService::class);
        $mail->expects($this->never())->method('send');
        $memberService = new MemberEmailService(
            $this->members, $mail, $this->createStub(EmailTemplateRenderer::class), $this->journal,
            $this->createStub(SectionService::class), $this->createStub(MemberService::class),
            $this->createStub(ScoutYearService::class), '', '', reactivation: $this->service
        );
        $member = $this->members->findById($this->first);
        $memberService->reactivateEmail($member->memberId, $this->first, $this->actor);
        $this->assertRestored();
        $this->assertSame(0, $this->service->reactivate('shared@example.org', $this->actor));
        $events = $this->pdo->query('SELECT event_type, user_account_id, context FROM event_log')->fetchAll();
        $this->assertCount(3, $events);
        foreach ($events as $event) {
            $this->assertSame($this->actor, (int) $event['user_account_id']);
            $this->assertStringNotContainsString('@', (string) $event['context']);
            $this->assertStringNotContainsString('Premier contact', (string) $event['context']);
            $this->assertStringNotContainsString('Second contact', (string) $event['context']);
        }
    }

    public function testAnAlreadyClearedHashDoesNotHideContactUnsubscribeFlags(): void
    {
        $this->enableModule();
        $this->suppressed->reactivate('shared@example.org');
        $this->assertSame(4, $this->service->reactivate('shared@example.org', $this->actor));
        $this->assertRestored();
    }

    public function testAnExternalOnlyAddressAndALaterReimportBecomeEligibleAgain(): void
    {
        $this->enableModule();
        $this->contacts->create($this->groupA, 'Extérieur', 'external@example.org');
        $this->contacts->unsubscribeEverywhere('external@example.org');
        $this->suppressed->suppress('external@example.org');
        $this->assertSame(2, $this->service->reactivate(' EXTERNAL@example.org ', $this->actor));
        $this->assertFalse($this->suppressed->isSuppressed('external@example.org'));
        $this->contacts->replaceForBlock($this->groupA, [
            ['name' => 'Extérieur corrigé', 'email' => 'external@example.org'],
        ]);
        $contacts = array_filter($this->contacts->findActiveForList($this->listFor($this->groupA)),
            fn($contact) => $contact->email === 'external@example.org');
        $this->assertCount(1, $contacts);
        $this->assertTrue($this->suppressed->isSuppressed('shared@example.org'));
    }

    public function testAFreeAddressWithNoMemberOrContactCanAlsoBeRestored(): void
    {
        $this->enableModule();
        $this->suppressed->suppress('free@example.org');
        $this->assertSame(1, $this->service->reactivate('free@example.org', $this->actor));
        $this->assertSame([], $this->suppressed->filterSuppressed(['FREE@example.org']));
    }

    public function testFailureAfterContactWritesRollsBackAllStoresAndNoSuccessJournalSurvives(): void
    {
        $failing = new class($this->pdo, $this->encryption) extends ContactRepository {
            public function reactivateEverywhere(string $email): int
            {
                parent::reactivateEverywhere($email);
                throw new \PDOException('deliberate failure');
            }
        };
        $this->enableModule($failing);
        try {
            $this->service->reactivate('shared@example.org', $this->actor);
            $this->fail('A partial restoration must not be reported as success.');
        } catch (MemberEmailException $error) {
            $this->assertInstanceOf(\PDOException::class, $error->getPrevious());
        }
        $this->assertStillInactive();
        $events = $this->pdo->query('SELECT event_type FROM event_log')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['email_address_reactivation_failed'], $events);
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testFailureAfterDeletingSuppressionRollsBackTheDeletionToo(): void
    {
        $failing = new class($this->pdo) extends SuppressedAddressRepository {
            public function reactivate(string $email): bool
            {
                parent::reactivate($email);
                throw new \PDOException('deliberate failure');
            }
        };
        $this->enableModule(null, $failing);
        try {
            $this->service->reactivate('shared@example.org', $this->actor);
            $this->fail('The failed deletion must roll back.');
        } catch (MemberEmailException) {
            $this->assertStillInactive();
        }
    }

    public function testAReactivationJournalFailureRollsBackAllThreeStores(): void
    {
        $this->enableModule();
        $repository = new class($this->pdo) extends JournalRepository {
            public function insert(string $category, string $type, string $level, string $description,
                ?string $contextJson, ?int $userId, ?string $ipAddress = null): void
            {
                parent::insert($category, $type, $level, $description, $contextJson, $userId, $ipAddress);
                if ($type === 'member_email_reactivated') {
                    throw new \PDOException('deliberate journal failure');
                }
            }
        };
        $service = new EmailAddressReactivationService(
            $this->members, new JournalService($repository), $this->registry
        );
        try {
            $service->reactivate('shared@example.org', $this->actor);
            $this->fail('A failed audit must roll back the address restoration.');
        } catch (MemberEmailException) {
            $this->assertStillInactive();
        }
        $this->assertSame(['email_address_reactivation_failed'],
            $this->pdo->query('SELECT event_type FROM event_log')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testAFailedFailureJournalPreservesTheRetryMessageAndOriginalError(): void
    {
        $this->enableModule();
        $original = new \PDOException('deliberate reactivation audit failure');
        $repository = new class($this->pdo, $original) extends JournalRepository {
            /** @var list<string> */
            public array $attempts = [];

            public function __construct(\PDO $pdo, private \PDOException $original)
            {
                parent::__construct($pdo);
            }

            public function insert(string $category, string $type, string $level, string $description,
                ?string $contextJson, ?int $userId, ?string $ipAddress = null): void
            {
                $this->attempts[] = $type;
                if ($type === 'email_address_reactivation_failed') {
                    throw new \PDOException('deliberate failure audit outage');
                }
                parent::insert($category, $type, $level, $description, $contextJson, $userId, $ipAddress);
                throw $this->original;
            }
        };
        $service = new EmailAddressReactivationService(
            $this->members, new JournalService($repository), $this->registry
        );
        $caught = null;
        try {
            $service->reactivate('shared@example.org', $this->actor);
        } catch (MemberEmailException $error) {
            $caught = $error;
        }
        $this->assertSame(
            ["L'adresse n'a pas pu être réactivée. Réessayez plus tard.", $original,
                ['member_email_reactivated', 'email_address_reactivation_failed']],
            [$caught?->getMessage(), $caught?->getPrevious(), $repository->attempts]
        );
        $this->assertStillInactive();
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM event_log');
        $count->execute();
        $this->assertSame(0, (int) $count->fetchColumn());
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testUnsubscribeCommitsTheSameStoresBeforeSendingAnyNotification(): void
    {
        $this->enableModule();
        $this->service->reactivate('shared@example.org', $this->actor);
        $mail = $this->createMock(MailService::class);
        $mail->expects($this->once())->method('send')->with($this->identicalTo('shared@example.org'))
            ->willReturnCallback(function (string $to): void {
                $this->assertSame('shared@example.org', $to);
                $this->assertFalse($this->pdo->inTransaction(), 'Notifications must wait for commit.');
                $this->assertStillInactive();
            });
        $controller = $this->unsubscribeController($this->memberEmailService($mail));
        $response = $controller->unsubscribe(new \Core\Http\Request('POST', '/mass-mail/unsubscribe/1',
            [], ['token' => 'valid'], [], []), ['id' => '1']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStillInactive();
    }

    public function testAnInterruptedUnsubscribeRollsBackAndDoesNotSendItsQueuedNotification(): void
    {
        $this->enableModule();
        $this->service->reactivate('shared@example.org', $this->actor);
        $this->pdo->exec('DELETE FROM event_log');
        $mail = $this->createMock(MailService::class);
        $mail->expects($this->never())->method('send');
        $real = $this->memberEmailService($mail);
        $failing = $this->createMock(MemberEmailService::class);
        $failing->expects($this->once())->method('unsubscribe')->willReturnCallback(
            function (int $id, ?callable $defer) use ($real): void {
                $real->unsubscribe($id, $defer);
                throw new \PDOException('deliberate interruption before commit');
            }
        );
        try {
            $this->unsubscribeController($failing)->unsubscribe(new \Core\Http\Request(
                'POST', '/mass-mail/unsubscribe/1', [], ['token' => 'valid'], [], []), ['id' => '1']);
            $this->fail('An interrupted unsubscribe cannot appear complete.');
        } catch (\PDOException) {
            $this->assertRestored();
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM event_log')->fetchColumn());
    }

    private function memberEmailService(MailService $mail): MemberEmailService
    {
        $years = $this->createStub(ScoutYearService::class);
        $years->method('getCurrentYear')->willReturn(['id' => 1]);
        $twig = $this->createStub(\Twig\Environment::class);
        $twig->method('render')->willReturn('<p>Confirmation</p>');
        return new MemberEmailService($this->members, $mail,
            \Tests\Core\Mail\Template\EmailTemplateRendererFactory::overTestDatabase($this->pdo, $twig),
            $this->journal, $this->createStub(SectionService::class), $this->createStub(MemberService::class),
            $years, '', '', reactivation: $this->service);
    }

    private function unsubscribeController(
        MemberEmailService $members
    ): \Modules\MassMail\Controller\UnsubscribeController
    {
        $delivery = new \Modules\MassMail\Repository\Delivery(1, 1, null, 'shared@example.org',
            $this->first, 'sent', null, '2026-01-01 00:00:00', 1, '2026-01-01 00:00:00');
        $deliveries = $this->createStub(\Modules\MassMail\Repository\DeliveryRepository::class);
        $deliveries->method('verifyUnsubscribeToken')->willReturn($delivery);
        $twig = $this->createStub(\Twig\Environment::class);
        $twig->method('render')->willReturn('<p>Terminé</p>');
        return new \Modules\MassMail\Controller\UnsubscribeController($twig, $deliveries, $members,
            $this->suppressed, new ListBlockService(new ListBlockRepository($this->pdo), $this->contacts,
                new \Modules\MassMail\Repository\MailingListRepository($this->pdo), $this->journal));
    }

    public function testRestorationDoesNotLiftBounceBlocks(): void
    {
        $this->enableModule();
        $bounces = new \Core\Mail\Feedback\Bounce\BounceStateRepository($this->pdo, $this->encryption);
        $now = new \DateTimeImmutable();
        $bounces->recordSend('shared@example.org', $now->modify('-1 hour'), true);
        $state = $bounces->record('shared@example.org',
            \Core\Mail\Feedback\Bounce\BounceCategory::NoSuchAddress,
            \Core\Mail\Feedback\Bounce\BounceSeverity::Permanent, '5.1.1', $now);
        $this->assertNotNull($state);
        $bounces->block($state->id, $now);
        $this->service->reactivate('shared@example.org', $this->actor);
        $this->assertRestored();
        $this->assertTrue((new \Core\Mail\Feedback\Bounce\BounceService($bounces))
            ->isBlocked('shared@example.org'));
    }

    public function testAParentTransactionCanRollBackTheWholeOperationIncludingTheJournal(): void
    {
        $this->enableModule();
        $this->pdo->beginTransaction();
        $this->service->reactivate('shared@example.org', $this->actor);
        $this->assertTrue($this->pdo->inTransaction());
        $this->pdo->rollBack();
        $this->assertStillInactive();
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM event_log')->fetchColumn());
    }

    public function testAnAbsentModuleRestoresCoreRowsWithoutReadingModuleTables(): void
    {
        $this->assertSame(2, $this->service->reactivate('shared@example.org', $this->actor));
        $this->assertTrue($this->members->findById($this->first)->isValid());
        $this->assertTrue($this->members->findById($this->second)->isValid());
        $this->assertTrue($this->suppressed->isSuppressed('shared@example.org'));
    }

    public function testTheModuleRefusesAnUnsharedTransactionAndADuplicateProvider(): void
    {
        $provider = new AddressReactivationService($this->pdo, $this->contacts, $this->suppressed, $this->journal);
        try {
            $provider->reactivateAddress('shared@example.org', $this->actor);
            $this->fail('A non-atomic write must be refused.');
        } catch (\LogicException) {
            $this->assertStillInactive();
        }
        $this->enableModule();
        $this->expectException(\LogicException::class);
        $this->registry->provide($provider);
    }

    private function listFor(int $group): int
    {
        return (new ListBlockRepository($this->pdo))->findById($group)->listId;
    }

    private function assertRestored(): void
    {
        $this->assertTrue($this->members->findById($this->first)->isValid());
        $this->assertTrue($this->members->findById($this->second)->isValid());
        $this->assertNull($this->members->findById($this->first)->deactivatedAt);
        $this->assertTrue($this->members->findById($this->pending)->isPending());
        $this->assertTrue($this->members->findById($this->other)->isInactive());
        $this->assertFalse($this->suppressed->isSuppressed('shared@example.org'));
        $this->assertTrue($this->suppressed->isSuppressed('other@example.org'));
        foreach ([$this->groupA, $this->groupB] as $group) {
            foreach ($this->contacts->findForBlock($group) as $contact) {
                $this->assertSame($contact->email === 'other@example.org', $contact->isUnsubscribed());
            }
        }
    }

    private function assertStillInactive(): void
    {
        $this->assertTrue($this->members->findById($this->first)->isInactive());
        $this->assertTrue($this->members->findById($this->second)->isInactive());
        $this->assertNotNull($this->members->findById($this->first)->deactivatedAt);
        $this->assertTrue($this->suppressed->isSuppressed('shared@example.org'));
        foreach ($this->contacts->findForBlock($this->groupB) as $contact) {
            $this->assertTrue($contact->isUnsubscribed());
        }
    }
}
