<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\Security\EncryptionService;
use Modules\InboundMail\Client\FakeMailboxClient;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Mailbox\SyncState;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Service\MailboxAdminService;
use Modules\InboundMail\Service\MailboxClientFactory;
use Modules\InboundMail\Service\MailboxErrorFormatter;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * The superadmin configuration surface (§7.4).
 *
 * Two rules carry most of this file: **several boxes can be configured at
 * once**, and **no secret ever comes back out**. The second is easy to
 * break by kindness — a "show current password" affordance, a "keep it
 * simple" save that clears the field — so the behaviour around a blank
 * password field is pinned explicitly.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailboxAdminServiceTest extends TestCase
{
    private \PDO $pdo;
    private InboundMailboxRepository $repository;
    private MailboxAdminService $service;
    private FakeMailboxClient $client;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->repository = new InboundMailboxRepository($this->pdo, $encryption);
        $this->client = new FakeMailboxClient();

        $factory = new MailboxClientFactory();
        $factory->register(ProviderType::IMAP, $this->client);
        $factory->register(ProviderType::FAKE, $this->client);

        $this->service = new MailboxAdminService($this->repository, $factory, new MailboxErrorFormatter());
    }

    private function createMailbox(string $name = 'Locations', string $password = 'un-mot-de-passe'): int
    {
        return $this->service->create(
            $name,
            'imap.test',
            993,
            'ssl',
            strtolower($name) . '@unite.be',
            $password,
            ['INBOX'],
            true
        );
    }

    // ── Several boxes at once (§7.4) ────────────────────────────────────

    public function testSeveralMailboxesCanBeConfiguredAtOnce(): void
    {
        $this->createMailbox('Locations');
        $this->createMailbox('Secretariat');
        $this->createMailbox('Tresorerie');

        $this->assertCount(3, $this->service->listMailboxes());
    }

    public function testEachMailboxKeepsItsOwnCursor(): void
    {
        // One box feeding several modules and one module reading several
        // boxes both fall out of this: nothing ties a cursor to anything
        // but its own box and folder.
        $first = $this->createMailbox('Locations');
        $second = $this->createMailbox('Secretariat');

        $this->repository->saveCursor($this->repository->findCursor($first, 'INBOX')->forUidValidity(1)->advancedTo(40));
        $this->repository->saveCursor($this->repository->findCursor($second, 'INBOX')->forUidValidity(1)->advancedTo(7));

        $this->assertSame(40, $this->repository->findCursor($first, 'INBOX')->lastUid);
        $this->assertSame(7, $this->repository->findCursor($second, 'INBOX')->lastUid);
    }

    public function testANewMailboxHasNeverSyncedRatherThanLookingHealthy(): void
    {
        $id = $this->createMailbox();

        $mailbox = $this->repository->findById($id);
        $this->assertNotNull($mailbox);
        $this->assertSame(SyncState::NEVER, $mailbox->syncState);
        $this->assertNull($mailbox->lastSyncedAt);
    }

    // ── No secret ever comes back out (§7.4) ────────────────────────────

    public function testTheListingCarriesNoPassword(): void
    {
        $this->createMailbox(password: 'un-mot-de-passe');

        $rendered = print_r($this->service->listMailboxes(), true);

        $this->assertStringNotContainsString('un-mot-de-passe', $rendered);
    }

    public function testEvenAVarDumpOfTheCredentialsBlanksTheSecret(): void
    {
        // A stack trace rendered while a connection is failing prints
        // constructor arguments — which is exactly where a password would
        // otherwise surface first.
        $id = $this->createMailbox(password: 'un-mot-de-passe');
        $credentials = $this->repository->findCredentials($id);
        $this->assertNotNull($credentials);

        $this->assertStringNotContainsString('un-mot-de-passe', print_r($credentials, true));
        $this->assertSame('un-mot-de-passe', $credentials->password, 'It must still be usable.');
    }

    public function testABlankPasswordOnSaveKeepsTheStoredOne(): void
    {
        // The page cannot show the current password, so an operator editing
        // the port has no way to retype it. A save that cleared it would
        // break the box on the next run for a reason nothing on screen
        // would explain.
        $id = $this->createMailbox(password: 'un-mot-de-passe');

        $this->service->update($id, 'Locations', 'imap.test', 143, 'starttls', 'locations@unite.be', '', ['INBOX'], true);

        $credentials = $this->repository->findCredentials($id);
        $this->assertNotNull($credentials);
        $this->assertSame('un-mot-de-passe', $credentials->password);

        $mailbox = $this->repository->findById($id);
        $this->assertNotNull($mailbox);
        $this->assertSame(143, $mailbox->port, 'The rest of the edit must still have applied.');
    }

    public function testANonBlankPasswordOnSaveReplacesIt(): void
    {
        $id = $this->createMailbox(password: 'ancien');

        $this->service->update($id, 'Locations', 'imap.test', 993, 'ssl', 'locations@unite.be', 'nouveau', ['INBOX'], true);

        $credentials = $this->repository->findCredentials($id);
        $this->assertNotNull($credentials);
        $this->assertSame('nouveau', $credentials->password);
    }

    public function testWhatANonSuperadminMayKnowIsTheNameAndTheStateOnly(): void
    {
        $this->createMailbox();

        $summaries = $this->service->listPublicSummaries();
        $summary = reset($summaries);

        $this->assertIsArray($summary);
        $this->assertSame(['name', 'state', 'is_enabled'], array_keys($summary));
    }

    // ── TLS is not negotiable (§7.5) ────────────────────────────────────

    public function testAnUnencryptedConnectionCannotBeConfigured(): void
    {
        // An allowlist decides, so a hand-crafted POST asking for a plain
        // session gets SSL rather than what it asked for.
        $id = $this->service->create('X', 'imap.test', 143, 'none', 'x@unite.be', 'mdp', [], true);

        $mailbox = $this->repository->findById($id);
        $this->assertNotNull($mailbox);
        $this->assertSame('ssl', $mailbox->encryption);
    }

    public function testStarttlsIsAcceptedBecauseItIsARealEncryptedMode(): void
    {
        $id = $this->service->create('X', 'imap.test', 143, 'STARTTLS', 'x@unite.be', 'mdp', [], true);

        $mailbox = $this->repository->findById($id);
        $this->assertNotNull($mailbox);
        $this->assertSame('starttls', $mailbox->encryption);
    }

    // ── Connection test ─────────────────────────────────────────────────

    public function testASuccessfulTestRecordsSuccessOnTheBox(): void
    {
        $id = $this->createMailbox();
        $this->client->addRawMessage('INBOX', 1, "From: a@b\r\n\r\nx");

        $result = $this->service->testConnection($id, new \DateTimeImmutable('2027-07-12 10:00:00'));

        $this->assertTrue($result['ok']);
        $this->assertSame(['INBOX'], $result['folders']);

        $mailbox = $this->repository->findById($id);
        $this->assertNotNull($mailbox);
        $this->assertSame(SyncState::OK, $mailbox->syncState);
    }

    public function testAFailedTestLeavesTheSameTraceAFailedSyncWould(): void
    {
        // An operator should not have to work out which of the two produced
        // the error they are reading.
        $id = $this->createMailbox();
        $this->client->failNextConnect(new \RuntimeException('SSL certificate verify failed'));

        $result = $this->service->testConnection($id, new \DateTimeImmutable('2027-07-12 10:00:00'));

        $this->assertFalse($result['ok']);
        $mailbox = $this->repository->findById($id);
        $this->assertNotNull($mailbox);
        $this->assertSame(SyncState::ERROR, $mailbox->syncState);
        $this->assertSame($result['message'], $mailbox->lastError);
    }

    public function testAFailedTestNeverEchoesTheCredential(): void
    {
        $id = $this->createMailbox(password: 'un-mot-de-passe');
        $this->client->failNextConnect(new \RuntimeException('LOGIN rejected: un-mot-de-passe'));

        $result = $this->service->testConnection($id, new \DateTimeImmutable('2027-07-12 10:00:00'));

        $this->assertStringNotContainsString('un-mot-de-passe', $result['message']);
    }

    public function testTestingAnUnknownBoxSaysSoRatherThanFailing(): void
    {
        $result = $this->service->testConnection(999, new \DateTimeImmutable());

        $this->assertFalse($result['ok']);
        $this->assertSame('Boîte introuvable.', $result['message']);
    }

    // ── Lifecycle ───────────────────────────────────────────────────────

    public function testDisablingKeepsTheConfigurationAndTheCursor(): void
    {
        // Disabling is how an operator stops a misbehaving box without
        // losing where it got to.
        $id = $this->createMailbox();
        $this->repository->saveCursor($this->repository->findCursor($id, 'INBOX')->forUidValidity(1)->advancedTo(40));

        $this->service->setEnabled($id, false);

        $mailbox = $this->repository->findById($id);
        $this->assertNotNull($mailbox);
        $this->assertFalse($mailbox->isEnabled);
        $this->assertSame(40, $this->repository->findCursor($id, 'INBOX')->lastUid);
    }

    public function testADisabledBoxIsNotAmongTheOnesASyncWouldPoll(): void
    {
        $enabled = $this->createMailbox('Locations');
        $this->service->setEnabled($this->createMailbox('Secretariat'), false);

        $ids = array_map(static fn($m) => $m->id, $this->repository->findEnabled());

        $this->assertSame([$enabled], $ids);
    }

    public function testDeletingRemovesTheBox(): void
    {
        $id = $this->createMailbox();

        $this->service->delete($id);

        $this->assertNull($this->repository->findById($id));
    }

    // ── Folder parsing ──────────────────────────────────────────────────

    public function testFoldersAreParsedOnePerLine(): void
    {
        $this->assertSame(
            ['INBOX', 'INBOX/Locations'],
            MailboxAdminService::parseFolders("INBOX\r\n  INBOX/Locations  \n\n")
        );
    }

    public function testAnEmptyFolderListMeansInboxRatherThanNothing(): void
    {
        // "None" would be a box that silently collects nothing.
        $id = $this->service->create('X', 'imap.test', 993, 'ssl', 'x@unite.be', 'mdp', [], true);

        $mailbox = $this->repository->findById($id);
        $this->assertNotNull($mailbox);
        $this->assertSame([], $mailbox->folders);
        $this->assertSame(['INBOX'], $mailbox->watchedFolders());
    }

    public function testADuplicateFolderIsListedOnce(): void
    {
        $this->assertSame(['INBOX'], MailboxAdminService::parseFolders("INBOX\nINBOX"));
    }

    // ── Test from form parameters (pre-save) ────────────────────────────

    public function testFromParamsSucceedsWithoutSavingAnything(): void
    {
        $this->client->addRawMessage('INBOX', 1, "From: a@b\r\n\r\nx");
        $this->client->addRawMessage('Sent', 2, "From: a@b\r\n\r\ny");

        $result = $this->service->testConnectionFromParams(
            'imap.test', 993, 'ssl', 'user@test', 'secret', null, new \DateTimeImmutable()
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(['INBOX', 'Sent'], $result['folders']);
        // Nothing was persisted — the mailbox table is empty.
        $this->assertCount(0, $this->service->listMailboxes());
    }

    public function testFromParamsReusesStoredPasswordWhenBlank(): void
    {
        $id = $this->createMailbox(password: 'stored-secret');
        $this->client->addRawMessage('INBOX', 1, "From: a@b\r\n\r\nx");

        $result = $this->service->testConnectionFromParams(
            'imap.test', 993, 'ssl', 'locations@unite.be', '', $id, new \DateTimeImmutable()
        );

        $this->assertTrue($result['ok']);
    }

    public function testFromParamsRecordsSuccessOnAnExistingBox(): void
    {
        $id = $this->createMailbox();
        $this->client->addRawMessage('INBOX', 1, "From: a@b\r\n\r\nx");

        $now = new \DateTimeImmutable('2027-08-01 12:00:00');
        $this->service->testConnectionFromParams('imap.test', 993, 'ssl', 'locations@unite.be', '', $id, $now);

        $mailbox = $this->repository->findById($id);
        $this->assertNotNull($mailbox);
        $this->assertSame(SyncState::OK, $mailbox->syncState);
    }

    public function testFromParamsRecordsFailureOnAnExistingBox(): void
    {
        $id = $this->createMailbox();
        $this->client->failNextConnect(new \RuntimeException('timeout'));

        $now = new \DateTimeImmutable('2027-08-01 12:00:00');
        $this->service->testConnectionFromParams('imap.test', 993, 'ssl', 'locations@unite.be', '', $id, $now);

        $mailbox = $this->repository->findById($id);
        $this->assertNotNull($mailbox);
        $this->assertSame(SyncState::ERROR, $mailbox->syncState);
    }

    public function testFromParamsDoesNotRecordAnythingForANewBox(): void
    {
        $this->client->addRawMessage('INBOX', 1, "From: a@b\r\n\r\nx");

        $this->service->testConnectionFromParams(
            'imap.test', 993, 'ssl', 'user@test', 'secret', null, new \DateTimeImmutable()
        );

        // No rows created or updated.
        $this->assertCount(0, $this->service->listMailboxes());
    }

    public function testFromParamsRequiresPasswordForANewBox(): void
    {
        $result = $this->service->testConnectionFromParams(
            'imap.test', 993, 'ssl', 'user@test', '', null, new \DateTimeImmutable()
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('Le mot de passe est obligatoire.', $result['message']);
    }

    public function testFromParamsReturnsNotFoundForBogusExistingId(): void
    {
        $result = $this->service->testConnectionFromParams(
            'imap.test', 993, 'ssl', 'user@test', '', 99999, new \DateTimeImmutable()
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('Boîte introuvable.', $result['message']);
    }
}
