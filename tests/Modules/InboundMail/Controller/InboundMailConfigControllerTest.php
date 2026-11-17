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
use Modules\InboundMail\Client\FakeMailboxClient;
use Modules\InboundMail\Controller\InboundMailConfigController;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\MailboxAdminService;
use Modules\InboundMail\Service\MailboxClientFactory;
use Modules\InboundMail\Service\MailboxErrorFormatter;
use Modules\InboundMail\Service\MailProviderRecognizer;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\RequestWithInput;
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
    private FakeMailboxClient $client;
    private int $mailboxId;

    /** @var array<string, list<string>> domain => its mail exchangers */
    private array $mx = [];

    /** @var list<string> the domains whose MX the screen asked for */
    private array $mxAsked = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->mailboxes = new InboundMailboxRepository($this->pdo, $encryption);
        $this->registry = new MessageConsumerRegistry();
        $this->registry->register(new FakeMessageConsumer('rental'));
        $this->registry->register(new FakeMessageConsumer('camps'));

        // Every connection the screen opens lands here, never on a network.
        $this->client = new FakeMailboxClient();
        $factory = new MailboxClientFactory();
        $factory->register(ProviderType::IMAP, $this->client);

        $adminService = new MailboxAdminService(
            $this->mailboxes,
            $factory,
            new MailboxErrorFormatter(),
            new InboundMessageRepository($this->pdo, $encryption),
            new SettingService(new SettingRepository($this->pdo))
        );

        $this->controller = new InboundMailConfigController(
            $this->twig(),
            $adminService,
            new JournalService(new JournalRepository($this->pdo)),
            $this->registry,
            null,
            new MailProviderRecognizer(function (string $domain): array {
                $this->mxAsked[] = $domain;

                return $this->mx[$domain] ?? [];
            })
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
        $response = $this->save([
            'id' => (string) $this->mailboxId,
            'username' => 'contact@unite.be',
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => '',
        ]);

        $this->assertFalse($this->mailbox()->isDedicated());
        $this->assertSame(200, $response->getStatusCode(), 'the form comes back, nothing saved');
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

        $body = $this->save([
            'id' => (string) $this->mailboxId,
            'host' => 'imap.autre',
            'username' => 'contact@unite.be',
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'rental',
        ])->getBody();

        $this->assertStringContainsString('Ce module a déjà son adresse réservée : locations@unite.be', $body);
        $this->assertSame([], $this->client->calls, 'refused before any connection');
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
     * A box reserved to a module switched off since: the form still offers
     * that module, selected, so an edit of the host does not silently hand
     * the box to the first module in the list or make it the general box.
     */
    public function testTheFormKeepsTheModuleOfABoxReservedToAModuleSwitchedOff(): void
    {
        $this->mailboxes->setPurpose($this->mailboxId, MailboxPurpose::DEDICATED, 'sos_staff');

        $body = $this->controller->editMailbox($this->get('/config/courrier-entrant'), $this->id())->getBody();

        $this->assertMatchesRegularExpression('/<option value="sos_staff"\s+selected/', $body);
        $this->assertDoesNotMatchRegularExpression('/id="mailbox-purpose-dedicated"[^>]*disabled/', $body);
    }

    public function testSavingABoxReservedToAModuleSwitchedOffKeepsItsReservation(): void
    {
        $this->mailboxes->setPurpose($this->mailboxId, MailboxPurpose::DEDICATED, 'sos_staff');

        $this->save([
            'id' => (string) $this->mailboxId,
            'username' => 'contact@unite.be',
            'host' => 'imap.autre.test',
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'sos_staff',
        ]);

        $this->assertSame('success', FlashMessage::get()['type'] ?? null);
        $this->assertSame('sos_staff', $this->mailbox()->dedicatedTo);
        $this->assertSame('imap.autre.test', $this->mailbox()->host);
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

        $this->assertStringNotContainsString('contact@unite.be', $row, 'no address in the journal');
        $this->assertStringContainsString('#' . $this->mailboxId, (string) $entry['description']);
        $this->assertSame($this->mailboxId, json_decode((string) $entry['context'], true)['mailbox_id']);
        $this->assertStringContainsString('dedicated', $row);
        $this->assertStringNotContainsString('imap.test', $row);
    }

    public function testCreatingABoxJournalsItsIdAndNotItsAddress(): void
    {
        $this->save(['username' => 'nouvelle@unite.be', 'password' => 'mdp']);
        $id = $this->findByAddress('nouvelle@unite.be')->id;

        $entry = $this->pdo->query(
            "SELECT * FROM event_log WHERE event_type = 'inbound_mailbox_created'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($entry);

        $this->assertStringNotContainsString('nouvelle@unite.be', (string) json_encode($entry));
        $this->assertSame($id, json_decode((string) $entry['context'], true)['mailbox_id']);
    }

    /**
     * Two boxes reserved to one module from before the unique index: the
     * edit form still offers the box its own module — or an unrelated save
     * would quietly hand the box to another — and the unchanged submit is
     * refused before any write, the box keeping its module.
     */
    public function testAnEditOfADuplicateReservationKeepsItsModuleAndIsRefused(): void
    {
        $other = $this->reservedBox('locations@unite.be');
        $this->pdo->prepare('UPDATE inbound_mailboxes SET purpose = ?, dedicated_to = ? WHERE id IN (?, ?)')
            ->execute([MailboxPurpose::DEDICATED->value, 'rental', $this->mailboxId, $other]);

        $form = $this->controller->editMailbox($this->get('/x'), $this->id())->getBody();
        $this->assertStringContainsString('value="rental"', $form);
        $this->assertStringNotContainsString('(module désactivé)', $form);

        $body = $this->save([
            'id' => (string) $this->mailboxId,
            'host' => 'imap.autre',
            'username' => 'contact@unite.be',
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'rental',
        ])->getBody();

        $this->assertStringContainsString('Ce module a déjà son adresse réservée', $body);
        $this->assertSame('rental', $this->mailbox()->dedicatedTo);
        $this->assertSame('imap.test', $this->mailbox()->host, 'nothing was written');
    }

    /**
     * A reservation the unique index refuses after the check passed — a
     * save that raced this one, simulated by a row holding the key alone.
     * The journal records the purpose the box kept, not the one asked.
     */
    public function testARefusedReservationIsNotJournalledAsTheRequestedPurpose(): void
    {
        $other = $this->reservedBox('locations@unite.be');
        $this->pdo->prepare(
            'UPDATE inbound_mailboxes SET purpose = ?, dedicated_to = NULL, dedication_key = ? WHERE id = ?'
        )->execute([MailboxPurpose::SHARED->value, 'rental', $other]);

        $this->save([
            'id' => (string) $this->mailboxId,
            'username' => 'contact@unite.be',
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'rental',
        ]);

        $this->assertSame('error', FlashMessage::get()['type'] ?? null);
        $this->assertFalse($this->mailbox()->isDedicated());
        $entry = $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'inbound_mailbox_updated'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($entry);
        $this->assertSame(MailboxPurpose::SHARED->value, json_decode((string) $entry['context'], true)['purpose']);
    }

    // ── « Vérifier et enregistrer » (D10) ───────────────────────────────

    /** The connection is opened before anything is written. */
    public function testANewAddressIsVerifiedThenSaved(): void
    {
        $response = $this->save(['username' => 'unite@unite.be', 'password' => 'mdp']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(['connect', 'listFolders', 'disconnect'], $this->client->calls);
        $box = $this->findByAddress('unite@unite.be');
        $this->assertTrue($box->isEnabled, 'an address added is read: there is no « Synchroniser » box to tick');
        $flash = FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null);
        $this->assertStringContainsString('Adresse vérifiée et enregistrée', $flash['message'] ?? '');
    }

    /**
     * An address that does not connect is not saved; the form comes back
     * with every field as typed, the password emptied, and a sentence.
     */
    public function testAFailedVerificationSavesNothingAndKeepsTheFieldsButThePassword(): void
    {
        $this->client->failNextConnect(new \RuntimeException('authentication failed'));

        $response = $this->save([
            'username' => 'locations@unite.be',
            'password' => 'le-mot-de-passe-refuse',
            'host' => 'imap.locations.test',
            'port' => '143',
            'encryption' => 'starttls',
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'camps',
            'folders' => "Factures",
        ]);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->mailboxes->findAll(), 'nothing was created');
        $this->assertStringContainsString("La connexion a échoué. Rien n&#039;est enregistré.", $body);
        $this->assertStringContainsString('Le serveur a refusé l&#039;authentification', $body);
        $this->assertStringContainsString('value="locations@unite.be"', $body);
        $this->assertStringContainsString('value="imap.locations.test"', $body);
        $this->assertStringContainsString('value="143"', $body);
        $this->assertMatchesRegularExpression('/<option value="starttls"\s+selected/', $body);
        $this->assertMatchesRegularExpression('/<option value="camps"\s+selected/', $body);
        $this->assertMatchesRegularExpression('/id="mailbox-purpose-dedicated"[^>]*checked/', $body);
        $this->assertStringContainsString('Factures', $body);
        $this->assertStringNotContainsString('le-mot-de-passe-refuse', $body);
        $this->assertNull(FlashMessage::get(), 'the outcome is on the form, not in a flash');
    }

    public function testAFailedVerificationOfAnEditLeavesTheBoxAsItWas(): void
    {
        $this->client->failNextConnect(new \RuntimeException('timeout'));

        $this->save([
            'id' => (string) $this->mailboxId,
            'username' => 'contact@unite.be',
            'host' => 'imap.ailleurs.test',
            'password' => 'nouveau',
        ]);

        $this->assertSame('imap.test', $this->mailbox()->host);
        $this->assertSame('secret', $this->mailboxes->findCredentials($this->mailboxId)?->password);
    }

    /**
     * The box is disabled from the list while its edit is being verified:
     * the edit must not write back the enabled state it read before.
     */
    public function testAnEditDoesNotUndoADisableMadeDuringItsVerification(): void
    {
        $this->client->onConnect(function (): void {
            $this->mailboxes->setEnabled($this->mailboxId, false);
        });

        $this->save([
            'id' => (string) $this->mailboxId,
            'username' => 'contact@unite.be',
            'host' => 'imap.nouveau.test',
        ]);

        $this->assertSame('imap.nouveau.test', $this->mailbox()->host, 'the edit was saved');
        $this->assertFalse($this->mailbox()->isEnabled, 'and the box stayed disabled');
    }

    /**
     * A box in error, then an edit whose new settings connect: the red
     * « Échec » was about the settings replaced, and goes. Nothing was
     * read, so the last relève's date stays what it was.
     */
    public function testAVerifiedEditClearsTheFailureTheListShowed(): void
    {
        $this->mailboxes->recordSuccess($this->mailboxId, new \DateTimeImmutable('2026-11-01 08:00:00'));
        $this->mailboxes->recordFailure(
            $this->mailboxId,
            'Le serveur a refusé l\'authentification.',
            new \DateTimeImmutable()
        );
        $this->assertSame('error', $this->mailbox()->syncState->value);

        $this->save(['id' => (string) $this->mailboxId, 'username' => 'contact@unite.be', 'password' => 'nouveau']);

        $box = $this->mailbox();
        $this->assertSame('ok', $box->syncState->value);
        $this->assertNull($box->lastError);
        $this->assertNull($box->lastErrorAt);
        $this->assertSame('2026-11-01 08:00:00', $box->lastSyncedAt?->format('Y-m-d H:i:s'));
        $failingSince = $this->pdo->query('SELECT failing_since FROM inbound_mailboxes WHERE id = ' . $this->mailboxId);
        $this->assertNotFalse($failingSince);
        $this->assertNull($failingSince->fetchColumn() ?: null);
        $list = $this->controller->index($this->get('/config/courrier-entrant'), [])->getBody();
        $this->assertStringNotContainsString('Corrigez ensuite le réglage en cause', $list);
        $this->assertStringNotContainsString('Échec', $list);
    }

    /** Never relevée, in error, then verified: back to « Pas encore relevée ». */
    public function testAVerifiedEditOfABoxNeverReadLeavesItNeverRead(): void
    {
        $this->mailboxes->recordFailure($this->mailboxId, 'Le serveur n\'a pas répondu.', new \DateTimeImmutable());

        $this->save(['id' => (string) $this->mailboxId, 'username' => 'contact@unite.be']);

        $this->assertSame('never', $this->mailbox()->syncState->value);
        $this->assertNull($this->mailbox()->lastSyncedAt);
    }

    /** A refused verification leaves the failure as it was. */
    public function testAFailedVerificationLeavesTheFailureInPlace(): void
    {
        $this->mailboxes->recordFailure($this->mailboxId, 'Le serveur n\'a pas répondu.', new \DateTimeImmutable());
        $this->client->failNextConnect(new \RuntimeException('timeout'));

        $this->save(['id' => (string) $this->mailboxId, 'username' => 'contact@unite.be']);

        $this->assertSame('error', $this->mailbox()->syncState->value);
        $this->assertNotNull($this->mailbox()->lastError);
    }

    /** On an edit, an empty password verifies with the stored one and keeps it. */
    public function testAnEditWithAnEmptyPasswordKeepsTheStoredOne(): void
    {
        $this->save([
            'id' => (string) $this->mailboxId,
            'username' => 'contact@unite.be',
            'host' => 'imap.nouveau.test',
            'password' => '',
        ]);

        $this->assertSame('imap.nouveau.test', $this->mailbox()->host);
        $this->assertSame('secret', $this->mailboxes->findCredentials($this->mailboxId)?->password);
        $this->assertContains('connect', $this->client->calls);
    }

    /** « Désactiver » is the list's job: an edit keeps the box as it was. */
    public function testAnEditKeepsADisabledBoxDisabled(): void
    {
        $this->mailboxes->setEnabled($this->mailboxId, false);

        $this->save(['id' => (string) $this->mailboxId, 'username' => 'contact@unite.be']);

        $this->assertFalse($this->mailbox()->isEnabled);
    }

    public function testANewAddressWithoutAPasswordIsRefusedBeforeAnyConnection(): void
    {
        $body = $this->save(['username' => 'unite@unite.be', 'password' => ''])->getBody();

        $this->assertStringContainsString('Indiquez le mot de passe d&#039;application', $body);
        $this->assertSame([], $this->client->calls);
        $this->assertCount(1, $this->mailboxes->findAll());
    }

    /** Other folders are read in addition to the inbox, never instead of it (D9). */
    public function testOtherFoldersAreReadAlongsideTheInbox(): void
    {
        $this->save(['username' => 'unite@unite.be', 'password' => 'mdp', 'folders' => "Factures\nINBOX"]);
        $this->save(['username' => 'seule@unite.be', 'password' => 'mdp', 'folders' => '']);

        $this->assertSame(['INBOX', 'Factures'], $this->findByAddress('unite@unite.be')->folders);
        $this->assertSame([], $this->findByAddress('seule@unite.be')->folders);
    }

    // ── The usage proposed, the modules offered (D5, D6) ────────────────

    public function testTheFirstAddressIsProposedAsTheGeneralMail(): void
    {
        $this->mailboxes->delete($this->mailboxId);

        $body = $this->controller->newMailbox($this->get('/x'), [])->getBody();

        $this->assertMatchesRegularExpression('/id="mailbox-purpose-shared"[^>]*checked/', $body);
        $this->assertStringContainsString('Proposé par défaut pour la première adresse.', $body);
    }

    /** Once a general address exists, the next one is proposed as reserved — still a default. */
    public function testOnceAGeneralAddressExistsTheNextIsProposedAsReserved(): void
    {
        $body = $this->controller->newMailbox($this->get('/x'), [])->getBody();

        $this->assertMatchesRegularExpression('/id="mailbox-purpose-dedicated"[^>]*checked/', $body);
        $this->assertDoesNotMatchRegularExpression('/id="mailbox-purpose-shared"[^>]*checked/', $body);
        $this->assertMatchesRegularExpression('/contact@unite\.be\s+l(&#039;|\x27)est déjà\./', $body);
    }

    /** With no module left to reserve to, the general mail stays the proposal. */
    public function testWithNoModuleToOfferTheGeneralMailStaysTheProposal(): void
    {
        $this->mailboxes->setPurpose($this->reservedBox('loc@unite.be'), MailboxPurpose::DEDICATED, 'rental');
        $this->mailboxes->setPurpose($this->reservedBox('camps@unite.be'), MailboxPurpose::DEDICATED, 'camps');

        $body = $this->controller->newMailbox($this->get('/x'), [])->getBody();

        $this->assertMatchesRegularExpression('/id="mailbox-purpose-shared"[^>]*checked/', $body);
        $this->assertMatchesRegularExpression('/id="mailbox-purpose-dedicated"[^>]*disabled/', $body);
        $this->assertStringNotContainsString('name="dedicated_to"', $body);
    }

    public function testTheFormAsksTheFieldsInTheRoadmapsOrder(): void
    {
        $body = $this->controller->newMailbox($this->get('/x'), [])->getBody();

        $positions = array_map(static fn(string $needle): int|false => strpos($body, $needle), [
            'id="mailbox-username"',
            'id="mailbox-password"',
            'id="mailbox-host"',
            'id="mailbox-port"',
            'id="mailbox-encryption"',
            'name="purpose"',
            'id="mailbox-dedicated-to"',
            'Dossiers lus (facultatif)',
            'Vérifier et enregistrer',
        ]);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertStringNotContainsString('Synchroniser cette boîte', $body);
        $this->assertStringNotContainsString('Tester la connexion', $body);
    }

    // ── Provider recognition (D8) ───────────────────────────────────────

    public function testTheProviderEndpointRecognisesAnAddressByItsDomain(): void
    {
        $data = $this->recognize('contact.unite@gmail.com');

        $this->assertTrue($data['recognized']);
        $this->assertSame('imap.gmail.com', $data['provider']['host']);
        $this->assertSame(993, $data['provider']['port']);
        $this->assertSame('ssl', $data['provider']['encryption']);
        $this->assertSame('gmail', $data['provider']['password_help']);
    }

    public function testTheProviderEndpointRecognisesAnOwnDomainByItsMx(): void
    {
        $this->mx['unite.be'] = ['unite-be.mail.protection.outlook.com.'];

        $data = $this->recognize('locations@unite.be');

        $this->assertSame('Microsoft 365', $data['provider']['label']);
        $this->assertSame('outlook.office365.com', $data['provider']['host']);
    }

    public function testTheProviderEndpointSaysSoWhenItDoesNotKnow(): void
    {
        $this->assertSame(['recognized' => false, 'provider' => null], $this->recognize('unite@exemple-hebergeur.be'));
    }

    public function testTheProviderEndpointRefusesARequestWithoutItsToken(): void
    {
        $path = '/config/courrier-entrant/fournisseur';
        $request = new RequestWithInput('POST', $path, [], [], [], [], (string) json_encode([
            'address' => 'a@gmail.com',
            '_csrf_token' => 'pas-le-bon',
        ]));

        $this->assertSame(403, $this->controller->recognizeProvider($request, [])->getStatusCode());
    }

    /** A form rendered again prefills an empty server from the address, and shows the app-password help. */
    public function testARefusedFormPrefillsTheSettingsOfARecognisedProvider(): void
    {
        $body = $this->save(['username' => 'contact.unite@gmail.com', 'password' => '', 'host' => '', 'port' => ''])
            ->getBody();

        $this->assertStringContainsString('value="imap.gmail.com"', $body);
        $this->assertStringContainsString('Gmail reconnu', $body);
        $this->assertMatchesRegularExpression('/data-password-help="gmail"\s*>/', $body);
        $this->assertStringContainsString('https://myaccount.google.com/apppasswords', $body);
    }

    /**
     * A page render never waits on the DNS: the form is prefilled from the
     * domain alone, and the script asks the endpoint for the MX afterwards.
     */
    public function testRenderingTheFormNeverLooksUpAnMx(): void
    {
        $this->mx['unite.be'] = ['unite-be.mail.protection.outlook.com'];

        $edit = $this->controller->editMailbox($this->get('/x'), $this->id())->getBody();
        $refused = $this->save(['username' => 'locations@unite.be', 'password' => '', 'host' => ''])->getBody();

        $this->assertSame([], $this->mxAsked);
        $this->assertStringNotContainsString('Microsoft 365 reconnu', $edit);
        $this->assertMatchesRegularExpression('/id="mailbox-host"[^>]*value=""/', $refused);
    }

    /** A port and an encryption the operator chose survive, even with the server left empty. */
    public function testARefusedFormPrefillsOnlyTheEmptySettings(): void
    {
        $body = $this->save([
            'username' => 'contact.unite@gmail.com',
            'password' => '',
            'host' => '',
            'port' => '143',
            'encryption' => 'starttls',
        ])->getBody();

        $this->assertStringContainsString('value="imap.gmail.com"', $body);
        $this->assertStringContainsString('value="143"', $body);
        $this->assertMatchesRegularExpression('/<option value="starttls"\s+selected/', $body);
    }

    /**
     * Microsoft refuses IMAP password logins: the form says so instead of
     * sending the operator after an app password that would not work.
     */
    public function testAMicrosoftAddressIsToldThatAPasswordLoginIsRefused(): void
    {
        $body = $this->save(['username' => 'unite@outlook.com', 'password' => '', 'host' => ''])->getBody();

        $this->assertStringContainsString('value="outlook.office365.com"', $body);
        $this->assertMatchesRegularExpression('/data-password-help="outlook"\s*>/', $body);
        $this->assertMatchesRegularExpression('/Microsoft refuse en général la connexion\s+IMAP/', $body);
        $this->assertMatchesRegularExpression('/seul un connecteur OAuth\s+fonctionnerait/', $body);
    }

    /** Unknown: server, port and encryption stay empty, with a sentence. */
    public function testAnUnknownProviderLeavesTheSettingsEmpty(): void
    {
        $body = $this->save([
            'username' => 'unite@exemple-hebergeur.be',
            'password' => '',
            'host' => '',
            'port' => '',
            'encryption' => '',
        ])
            ->getBody();

        $this->assertStringContainsString('Indiquez le serveur IMAP', $body);
        $this->assertMatchesRegularExpression('/id="mailbox-host"[^>]*value=""/', $body);
        $this->assertMatchesRegularExpression('/<option value=""\s+selected/', $body);
        $this->assertMatchesRegularExpression('/id="mailbox-provider-unknown"\s*>/', $body);
        $this->assertMatchesRegularExpression('/data-password-help="gmail"\s+hidden/', $body);
    }

    // ── « Dossiers lus » (D9) ───────────────────────────────────────────

    /** The server's special folders are never offered, and the sent one is named apart. */
    public function testTheFolderListOffersNoSpecialFolder(): void
    {
        $this->client->markAttributes('INBOX');
        $this->client->markAttributes('Factures', '\\HasNoChildren');
        $this->client->markAttributes('[Gmail]', '\\Noselect', '\\HasChildren');
        foreach (['Junk', 'Trash', 'Drafts', 'All', 'Flagged', 'Important'] as $special) {
            $this->client->markAttributes('[Gmail]/' . $special, '\\' . $special);
        }
        $this->client->markSent('[Gmail]/Messages envoyés');

        $response = $this->controller->testConnectionFromForm(new RequestWithInput(
            'POST',
            '/config/courrier-entrant/test-connexion',
            [],
            [],
            [],
            [],
            (string) json_encode([
                'host' => 'imap.gmail.com',
                'username' => 'contact.unite@gmail.com',
                'password' => 'mdp',
                '_csrf_token' => $this->token(),
            ])
        ), []);
        $data = json_decode($response->getBody(), true);

        $this->assertTrue($data['success']);
        $this->assertSame(['Factures'], $data['folders']);
        $this->assertSame('[Gmail]/Messages envoyés', $data['sent_folder']);
    }

    /** A seed box must read its junk folder (Core\Mail\Feedback\Seed): there, and only there, it is offered. */
    public function testTheJunkFolderIsOfferedToASeedBoxOnly(): void
    {
        $this->client->markAttributes('INBOX');
        $this->client->markAttributes('Spam', '\\Junk');

        $folders = function (string $dedicatedTo): array {
            $response = $this->controller->testConnectionFromForm(new RequestWithInput(
                'POST',
                '/config/courrier-entrant/test-connexion',
                [],
                [],
                [],
                [],
                (string) json_encode([
                    'host' => 'imap.test',
                    'username' => 'temoin@gmail.com',
                    'password' => 'mdp',
                    'dedicated_to' => $dedicatedTo,
                    '_csrf_token' => $this->token(),
                ])
            ), []);

            return json_decode($response->getBody(), true)['folders'];
        };

        $this->assertSame([], $folders(''));
        $this->assertSame([], $folders('rental'));
        $this->assertSame(['Spam'], $folders('core_mail_seed'));
    }

    // ── The list of addresses ───────────────────────────────────────────

    public function testTheListShowsNoHostNorRefreshNorTestButton(): void
    {
        $body = $this->controller->index($this->get('/config/courrier-entrant'), [])->getBody();

        $this->assertStringNotContainsString('imap.test', $body);
        // A port-shaped needle: the page's random CSRF token can contain
        // the bare digits.
        $this->assertDoesNotMatchRegularExpression('/[^0-9a-f]993[^0-9a-f]/', $body);
        $this->assertStringNotContainsString('Rafraîchir maintenant', $body);
        $this->assertStringNotContainsString('/rafraichir', $body);
        $this->assertStringNotContainsString('Tester la connexion', $body);
        $this->assertStringContainsString('href="/aide/courrier-entrant"', $body);
        $this->assertStringContainsString('Ajouter une adresse', $body);
        $this->assertStringContainsString('Pas encore relevée', $body);
        foreach (['Modifier', 'Désactiver', 'Supprimer'] as $action) {
            $this->assertStringContainsString($action, $body);
        }
    }

    /** A failing box says why, and what to do about it. */
    public function testAFailingBoxSaysWhatToDo(): void
    {
        $this->pdo->prepare(
            "UPDATE inbound_mailboxes SET sync_state = 'error', last_error = ?, last_error_at = ? WHERE id = ?"
        )->execute(['Le serveur a refusé l\'authentification.', date('Y-m-d H:i:s', time() - 7200), $this->mailboxId]);

        $body = $this->controller->index($this->get('/config/courrier-entrant'), [])->getBody();

        $this->assertStringContainsString('Échec il y a 2 heures', $body);
        $this->assertStringContainsString('Corrigez ensuite le réglage en cause avec « Modifier ».', $body);
    }

    /** @return array<string, mixed> */
    private function recognize(string $address): array
    {
        $path = '/config/courrier-entrant/fournisseur';
        $request = new RequestWithInput('POST', $path, [], [], [], [], (string) json_encode([
            'address' => $address,
            '_csrf_token' => $this->token(),
        ]));

        return json_decode($this->controller->recognizeProvider($request, [])->getBody(), true);
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
            'folders' => '',
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
