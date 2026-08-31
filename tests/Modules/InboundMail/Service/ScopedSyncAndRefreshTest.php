<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MailboxScope;
use Modules\InboundMail\Api\ReadMode;
use Modules\InboundMail\Client\FakeMailboxClient;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\AnalysisResultApplier;
use Modules\InboundMail\Service\AttachmentPolicy;
use Modules\InboundMail\Service\MailboxClientFactory;
use Modules\InboundMail\Service\MailboxErrorFormatter;
use Modules\InboundMail\Service\MailboxScopeService;
use Modules\InboundMail\Service\MailboxSyncService;
use Modules\InboundMail\Service\ManualRefreshService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use Modules\InboundMail\Service\MessageContentSanitizer;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * The configuration screen is a setting, not a suggestion.
 *
 * A module that a box was not opened to is never handed its mail at all —
 * rather than handed it and trusted to ignore it, which would leave the
 * decision in the module's hands and make the screen advisory. And
 * « Rafraîchir maintenant » is behind a lock, because it runs inside a
 * request and two clicks would race each other on the cursor.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ScopedSyncAndRefreshTest extends TestCase
{
    private \PDO $pdo;
    private InboundMailboxRepository $mailboxes;
    private InboundMessageRepository $messages;
    private MessageConsumerRegistry $registry;
    private MailboxScopeService $scopes;
    private FakeMailboxClient $client;
    private int $mailboxId;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->mailboxes = new InboundMailboxRepository($this->pdo, $encryption);
        $this->messages = new InboundMessageRepository($this->pdo, $encryption);
        $this->registry = new MessageConsumerRegistry();
        $this->scopes = new MailboxScopeService($this->mailboxes, $this->registry);
        $this->client = new FakeMailboxClient();
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic-scoped-sync-' . bin2hex(random_bytes(4));

        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, module_id, setting_type, label, description)
             VALUES (?, \'\', ?, \'text\', \'x\', \'x\')'
        );
        $stmt->execute([ManualRefreshService::SETTING_LOCK, 'inbound_mail']);

        $this->mailboxId = $this->mailboxes->create(
            'Unité',
            ProviderType::FAKE,
            'imap.test',
            993,
            'ssl',
            'contact@unite.be',
            'secret',
            [],
            true
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->storagePath . '/*') ?: [] as $directory) {
            @rmdir($directory);
        }
        @rmdir($this->storagePath);
    }

    // ── The scope decides who is even asked ─────────────────────────────

    public function testAModuleTheBoxWasNotOpenedToIsNeverOfferedTheMessage(): void
    {
        $offered = [];
        $this->registry->register($this->recorder('rental', $offered));
        $this->registry->register($this->recorder('camps', $offered));

        $this->scopes->saveSharedScopes($this->mailboxId, [
            'rental' => ['analyze' => true, 'read' => 'relevant'],
            'camps' => ['analyze' => false, 'read' => 'none'],
        ]);

        $this->addMessage(10);
        $this->sync();

        $this->assertSame(['rental'], array_keys($offered));
    }

    public function testTheMessageIsStillStoredForAModuleThatWasNotAsked(): void
    {
        // Scoping decides who sorts the mail, never whether the unit keeps
        // it: a box nobody sorts is exactly what the general mailbox is for.
        $this->registry->register($this->recorder('camps', $ignored));
        $this->scopes->saveSharedScopes($this->mailboxId, []);

        $this->addMessage(10);
        $this->sync();

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inbound_messages')->fetchColumn());
    }

    public function testADedicatedBoxAsksItsOwnModuleAndNobodyElse(): void
    {
        $offered = [];
        $this->registry->register($this->recorder('rental', $offered));
        $this->registry->register($this->recorder('camps', $offered));

        $this->scopes->saveDedicated($this->mailboxId, 'camps');

        $this->addMessage(10);
        $this->sync();

        $this->assertSame(['camps'], array_keys($offered));
    }

    public function testAnAssociationIsStillMadeByAnAuthorisedModule(): void
    {
        $this->registry->register(new FakeMessageConsumer(
            id: 'rental',
            onAnalyze: static fn(): AnalysisResult => AnalysisResult::linkedTo(
                'rental',
                'LOC-2027-0042',
                LinkOrigin::REFERENCE
            )
        ));
        $this->scopes->saveSharedScopes($this->mailboxId, [
            'rental' => ['analyze' => true, 'read' => 'relevant'],
        ]);

        $this->addMessage(10);
        $this->sync();

        $this->assertCount(1, $this->messages->findForReference('rental', 'LOC-2027-0042'));
    }

    public function testAModuleShutOutMakesNoAssociationEvenIfItWouldHave(): void
    {
        $this->registry->register(new FakeMessageConsumer(
            id: 'rental',
            onAnalyze: static fn(): AnalysisResult => AnalysisResult::linkedTo(
                'rental',
                'LOC-2027-0042',
                LinkOrigin::REFERENCE
            )
        ));
        $this->scopes->saveSharedScopes($this->mailboxId, [
            'rental' => ['analyze' => false, 'read' => 'none'],
        ]);

        $this->addMessage(10);
        $this->sync();

        $this->assertSame([], $this->messages->findForReference('rental', 'LOC-2027-0042'));
    }

    // ── « Rafraîchir maintenant » ───────────────────────────────────────

    public function testRefreshingReadsTheBoxAndSaysWhatItFound(): void
    {
        $this->registry->register(new FakeMessageConsumer('rental'));
        $this->addMessage(10);

        $outcome = $this->refreshService()->refresh(new \DateTimeImmutable('2027-07-12 10:00:00'));

        $this->assertTrue($outcome['ok']);
        $this->assertStringContainsString('1 message(s) lu(s)', $outcome['message']);
    }

    public function testASecondRefreshWhileOneIsRunningIsRefused(): void
    {
        // Two clicks a second apart would open two IMAP sessions on the
        // same box and race each other on the cursor — and the loser's
        // write moves it backwards.
        $now = new \DateTimeImmutable('2027-07-12 10:00:00');
        $this->settingRepository()->updateValue(
            'inbound_mail',
            ManualRefreshService::SETTING_LOCK,
            $now->format('Y-m-d H:i:s')
        );

        $outcome = $this->refreshService()->refresh($now);

        $this->assertFalse($outcome['ok']);
        $this->assertStringContainsString('déjà en cours', $outcome['message']);
    }

    public function testTheLockIsReleasedOnceTheRefreshIsDone(): void
    {
        $this->registry->register(new FakeMessageConsumer('rental'));
        $service = $this->refreshService();
        $now = new \DateTimeImmutable('2027-07-12 10:00:00');

        $service->refresh($now);

        $this->assertFalse($service->isLocked($now));
    }

    public function testAStaleLockLeftByARequestThatDiedExpires(): void
    {
        // max_execution_time kills a request mid-synchronisation and the
        // lock is never cleared. A permanently locked button is a feature
        // that silently stopped existing.
        $this->settingRepository()->updateValue(
            'inbound_mail',
            ManualRefreshService::SETTING_LOCK,
            '2027-07-12 10:00:00'
        );

        $service = $this->refreshService();

        $this->assertTrue($service->isLocked(new \DateTimeImmutable('2027-07-12 10:05:00')));
        $this->assertFalse($service->isLocked(new \DateTimeImmutable('2027-07-12 10:20:00')));
    }

    public function testAnUnreadableLockValueNeverBlocksTheButtonForever(): void
    {
        $this->settingRepository()->updateValue('inbound_mail', ManualRefreshService::SETTING_LOCK, 'pas une date');

        $this->assertFalse($this->refreshService()->isLocked(new \DateTimeImmutable()));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * A consumer that records every message it is offered, so a test can
     * assert on who was ASKED rather than on who answered — the difference
     * between a setting and a suggestion.
     *
     * @param array<string, int> $offered
     */
    private function recorder(string $id, ?array &$offered): FakeMessageConsumer
    {
        $offered ??= [];

        return new FakeMessageConsumer(
            id: $id,
            onAnalyze: static function () use ($id, &$offered): AnalysisResult {
                $offered[$id] = ($offered[$id] ?? 0) + 1;

                return AnalysisResult::nothing();
            }
        );
    }

    private function addMessage(int $uid): void
    {
        $this->client->addRawMessage('INBOX', $uid, InboundMailTestHelper::rawMessage([
            'From' => 'Jeanne Martin <jeanne@example.be>',
            'To' => 'contact@unite.be',
            'Subject' => 'Bonjour',
            'Message-ID' => '<msg-' . $uid . '@example.be>',
            'Date' => 'Mon, 12 Jul 2027 09:30:00 +0200',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ], 'Bonjour'));
    }

    private function clientFactory(): MailboxClientFactory
    {
        $factory = new MailboxClientFactory();
        $factory->register(ProviderType::FAKE, $this->client);

        return $factory;
    }

    private function syncService(): MailboxSyncService
    {
        return new MailboxSyncService(
            $this->mailboxes,
            $this->messages,
            $this->registry,
            new MessageContentSanitizer(new HtmlSanitizer()),
            new AttachmentPolicy(),
            new MailboxErrorFormatter(),
            $this->clientFactory(),
            new AnalysisResultApplier($this->messages),
            null,
            null,
            null,
            $this->scopes
        );
    }

    private function sync(): void
    {
        $mailbox = $this->mailboxes->findById($this->mailboxId);
        $this->assertNotNull($mailbox);

        $this->syncService()->syncMailbox($mailbox, new \DateTimeImmutable('2027-07-12 10:00:00'));
    }

    private function refreshService(): ManualRefreshService
    {
        $repository = $this->settingRepository();

        return new ManualRefreshService(
            fn(): MailboxSyncService => $this->syncService(),
            new SettingService($repository),
            $repository
        );
    }

    /**
     * The lock row exists on a real installation because module.json
     * declares it, and `updateValue()` updates a row rather than creating
     * one. Seeded once, in setUp(), so a test that writes a lock does not
     * find it wiped by the next call.
     */
    private function settingRepository(): SettingRepository
    {
        return new SettingRepository($this->pdo);
    }
}
