<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\MailboxPurpose;
use Modules\InboundMail\Controller\InboundMailConfigController;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\MailboxAdminService;
use Modules\InboundMail\Service\MailboxClientFactory;
use Modules\InboundMail\Service\MailboxErrorFormatter;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;
use Tests\TestTwig;
use Twig\Environment;

/**
 * The screen where a superadmin configures the boxes: their connection and
 * the one setting that decides who reads them — general mail, or reserved
 * to one module (§8.58).
 *
 * What a screen can get wrong on its own: what it renders about a box,
 * what it accepts as a purpose, and the rule that a module has at most one
 * reserved address (D5), which the server holds whatever the form offered.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InboundMailConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private InboundMailboxRepository $mailboxes;
    private MessageConsumerRegistry $registry;
    private InboundMailConfigController $controller;
    private int $mailboxId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->mailboxes = new InboundMailboxRepository($this->pdo, $encryption);
        $this->registry = new MessageConsumerRegistry();
        $this->registry->register(new FakeMessageConsumer('rental'));
        $this->registry->register(new FakeMessageConsumer('camps'));

        $adminService = new MailboxAdminService(
            $this->mailboxes,
            new MailboxClientFactory(),
            new MailboxErrorFormatter(),
            new InboundMessageRepository($this->pdo, $encryption),
            new SettingService(new SettingRepository($this->pdo))
        );

        $this->controller = new InboundMailConfigController(
            $this->twig(),
            $adminService,
            new JournalService(new JournalRepository($this->pdo)),
            $this->registry
        );

        $this->mailboxId = $this->mailboxes->create(
            ProviderType::IMAP,
            'imap.test',
            993,
            'ssl',
            'contact@unite.be',
            'secret',
            [],
            true
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');
        FlashMessage::get();
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    // ── What the screen shows ───────────────────────────────────────────

    public function testTheListNamesEachBoxByItsAddressAndUsageAndNeverItsPassword(): void
    {
        $this->mailboxes->setPurpose($this->reservedBox('locations@unite.be'), MailboxPurpose::DEDICATED, 'rental');

        $body = $this->controller->index($this->get('/config/courrier-entrant'), [])->getBody();

        $this->assertStringContainsString('contact@unite.be', $body);
        $this->assertStringContainsString("Courrier général de l&#039;unité", $body);
        $this->assertStringContainsString('locations@unite.be', $body);
        $this->assertStringContainsString('Réservée au module « Rental »', $body);
        $this->assertStringNotContainsString('secret', $body);
    }

    /** The per-module page is gone, and nothing on the list leads to it. */
    public function testTheListOffersNoScopePage(): void
    {
        $body = $this->controller->index($this->get('/config/courrier-entrant'), [])->getBody();

        $this->assertStringNotContainsString('/portee', $body);
        $this->assertStringNotContainsString('Portée', $body);
    }

    public function testTheFormAsksNoNameAndOffersBothUsages(): void
    {
        $body = $this->controller->newMailbox($this->get('/config/courrier-entrant/boites/nouvelle'), [])->getBody();

        $this->assertStringNotContainsString('name="name"', $body);
        $this->assertStringContainsString('name="purpose" value="shared"', $body);
        $this->assertStringContainsString('name="purpose" value="dedicated"', $body);
        $this->assertStringContainsString('value="rental"', $body);
        $this->assertStringContainsString('value="camps"', $body);
    }

    /**
     * A module that already has its address is not offered for another
     * box (D5) — but a box reserved to it keeps offering it, or its own
     * edit form could not be saved as it stands.
     */
    public function testTheFormOffersOnlyModulesWithoutAnAddressOfTheirOwn(): void
    {
        $locations = $this->reservedBox('locations@unite.be');
        $this->mailboxes->setPurpose($locations, MailboxPurpose::DEDICATED, 'rental');

        $other = $this->controller->editMailbox($this->get('/x'), $this->id())->getBody();
        $own = $this->controller->editMailbox($this->get('/x'), ['id' => (string) $locations])->getBody();

        $this->assertStringNotContainsString('value="rental"', $other);
        $this->assertStringContainsString('value="camps"', $other);
        $this->assertStringContainsString('value="rental"', $own);
    }

    // ── What the server accepts as a purpose ────────────────────────────

    /** A box added is general mail unless said otherwise, and at work at once. */
    public function testANewBoxIsGeneralMailByDefault(): void
    {
        $response = $this->save(['username' => 'unite@unite.be', 'password' => 'mdp']);

        $this->assertSame(302, $response->getStatusCode());
        $box = $this->findByAddress('unite@unite.be');
        $this->assertSame(MailboxPurpose::SHARED, $box->purpose);
        $this->assertNull($box->dedicatedTo);
        $this->assertSame('success', FlashMessage::get()['type'] ?? null);
    }

    public function testANewBoxCanBeReservedToAModule(): void
    {
        $this->save([
            'username' => 'camps@unite.be',
            'password' => 'mdp',
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'camps',
        ]);

        $box = $this->findByAddress('camps@unite.be');
        $this->assertTrue($box->isDedicated());
        $this->assertSame('camps', $box->dedicatedTo);
    }

    public function testAGeneralBoxIgnoresTheModuleTheFormAlsoSent(): void
    {
        // The select is submitted whatever the radio says; the server
        // reads it only for a reserved box.
        $this->save([
            'id' => (string) $this->mailboxId,
            'username' => 'contact@unite.be',
            'purpose' => MailboxPurpose::SHARED->value,
            'dedicated_to' => 'camps',
        ]);

        $this->assertFalse($this->mailbox()->isDedicated());
        $this->assertNull($this->mailbox()->dedicatedTo);
    }

    public function testAReservedBoxWithNoModuleNamedIsRefusedRatherThanSavedEmpty(): void
    {
        // Saving it would leave a box reserved to nobody, which reads on
        // every later screen as « réservée » while behaving as nothing.
        $this->save([
            'id' => (string) $this->mailboxId,
            'username' => 'contact@unite.be',
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => '',
        ]);

        $this->assertFalse($this->mailbox()->isDedicated());
        $this->assertSame('error', FlashMessage::get()['type'] ?? null);
    }

    public function testAModuleNobodyRegisteredCannotBeReservedTo(): void
    {
        $this->save([
            'id' => (string) $this->mailboxId,
            'username' => 'contact@unite.be',
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'module-inexistant',
        ]);

        $this->assertFalse($this->mailbox()->isDedicated());
    }

    /**
     * D5: a module has at most one reserved address. The second is refused
     * in a sentence that names the first, and nothing of the refused save
     * is written — the existing box stays as it was, a new one is not
     * created.
     */
    public function testASecondBoxReservedToTheSameModuleIsRefused(): void
    {
        $locations = $this->reservedBox('locations@unite.be');
        $this->mailboxes->setPurpose($locations, MailboxPurpose::DEDICATED, 'rental');

        $this->save([
            'id' => (string) $this->mailboxId,
            'host' => 'imap.autre',
            'username' => 'contact@unite.be',
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'rental',
        ]);

        $flash = FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('locations@unite.be', $flash['message'] ?? '');
        $this->assertFalse($this->mailbox()->isDedicated());
        $this->assertSame('imap.test', $this->mailbox()->host, 'nothing of the refused save is written');

        $this->save([
            'username' => 'chalet@unite.be',
            'password' => 'mdp',
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'rental',
        ]);
        $this->assertCount(2, $this->mailboxes->findAll(), 'no box is created for a refused purpose');
    }

    /** A disabled box still holds its module's place. */
    public function testADisabledReservedBoxStillHoldsItsModulesPlace(): void
    {
        $locations = $this->reservedBox('locations@unite.be', enabled: false);
        $this->mailboxes->setPurpose($locations, MailboxPurpose::DEDICATED, 'rental');

        $this->save([
            'id' => (string) $this->mailboxId,
            'username' => 'contact@unite.be',
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'rental',
        ]);

        $this->assertFalse($this->mailbox()->isDedicated());
    }

    /** Saving a reserved box as it stands is not a conflict with itself. */
    public function testAReservedBoxSavedAgainKeepsItsModule(): void
    {
        $this->mailboxes->setPurpose($this->mailboxId, MailboxPurpose::DEDICATED, 'rental');

        $this->save([
            'id' => (string) $this->mailboxId,
            'username' => 'contact@unite.be',
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'rental',
        ]);

        $this->assertSame('rental', $this->mailbox()->dedicatedTo);
        $this->assertSame('success', FlashMessage::get()['type'] ?? null);
    }

    /**
     * The unique index is what holds when two saves race past the check:
     * the database refuses the second reservation outright.
     */
    public function testTheDatabaseRefusesASecondReservationOnItsOwn(): void
    {
        $this->mailboxes->setPurpose($this->reservedBox('locations@unite.be'), MailboxPurpose::DEDICATED, 'rental');

        $this->expectException(\PDOException::class);
        $this->mailboxes->setPurpose($this->mailboxId, MailboxPurpose::DEDICATED, 'rental');
    }

    public function testSavingWithoutAValidTokenChangesNothing(): void
    {
        $request = new Request('POST', '/config/courrier-entrant/boites', [], [
            'id' => (string) $this->mailboxId,
            'host' => 'imap.test',
            'port' => '993',
            'username' => 'contact@unite.be',
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'camps',
            '_csrf_token' => 'pas-le-bon',
        ], [], []);

        $this->controller->save($request, []);

        $this->assertFalse($this->mailbox()->isDedicated());
    }

    public function testTheAddressAndTheHostAreBothRequired(): void
    {
        $this->save(['id' => (string) $this->mailboxId, 'username' => '', 'host' => 'imap.autre']);

        $this->assertSame('error', FlashMessage::get()['type'] ?? null);
        $this->assertSame('imap.test', $this->mailbox()->host);
    }

    /** The journal names the box by its address, its only name (D7), and never its host. */
    public function testTheJournalNamesTheBoxAndItsPurposeAndNotItsHost(): void
    {
        $this->save([
            'id' => (string) $this->mailboxId,
            'username' => 'contact@unite.be',
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'camps',
        ]);

        $entry = $this->pdo->query(
            "SELECT * FROM event_log WHERE event_type = 'inbound_mailbox_updated'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($entry);
        $row = json_encode($entry, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($row);

        $this->assertStringContainsString('contact@unite.be', $row);
        $this->assertStringContainsString('dedicated', $row);
        $this->assertStringNotContainsString('imap.test', $row);
    }

    // ── « Rafraîchir maintenant » ───────────────────────────────────────

    public function testTheRefreshButtonIsNotOfferedWhenNothingCanRunIt(): void
    {
        // Not offered rather than offered and inert: a button that does
        // nothing is worse than no button.
        $body = $this->controller->index($this->get('/config/courrier-entrant'), [])->getBody();

        $this->assertStringNotContainsString('Rafraîchir maintenant', $body);
    }

    public function testAskingForARefreshWithNoServiceBehindItIsNotFound(): void
    {
        $request = new Request('POST', '/config/courrier-entrant/rafraichir', [], [
            '_csrf_token' => $this->token(),
        ], [], []);

        $this->assertSame(404, $this->controller->refreshNow($request, [])->getStatusCode());
    }

    // ── Harness ─────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function id(): array
    {
        return ['id' => (string) $this->mailboxId];
    }

    private function mailbox(): \Modules\InboundMail\Mailbox\Mailbox
    {
        $mailbox = $this->mailboxes->findById($this->mailboxId);
        $this->assertNotNull($mailbox);

        return $mailbox;
    }

    private function get(string $path): Request
    {
        return new Request('GET', $path, [], [], [], []);
    }

    private function reservedBox(string $address, bool $enabled = true): int
    {
        return $this->mailboxes->create(
            ProviderType::IMAP,
            'imap.test',
            993,
            'ssl',
            $address,
            'secret',
            ['INBOX'],
            $enabled
        );
    }

    private function findByAddress(string $address): \Modules\InboundMail\Mailbox\Mailbox
    {
        foreach ($this->mailboxes->findAll() as $mailbox) {
            if ($mailbox->username === $address) {
                return $mailbox;
            }
        }

        $this->fail('No box ' . $address);
    }

    /**
     * The mailbox form, as the page posts it: every field, defaults filled.
     *
     * @param array<string, string> $fields
     */
    private function save(array $fields): \Core\Http\Response
    {
        $body = $fields + [
            'id' => '0',
            'host' => 'imap.test',
            'port' => '993',
            'encryption' => 'ssl',
            'password' => '',
            'folders' => 'INBOX',
            'is_enabled' => '1',
            'purpose' => MailboxPurpose::SHARED->value,
        ];
        $body['_csrf_token'] = $this->token();

        return $this->controller->save(new Request('POST', '/config/courrier-entrant/boites', [], $body, [], []), []);
    }

    private function token(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $token;
    }

    private function twig(): Environment
    {
        $twig = TestTwig::create(['inbound_mail']);

        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'superadmin');
        $twig->addGlobal('config_mode', true);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'test-nonce');

        return $twig;
    }
}
