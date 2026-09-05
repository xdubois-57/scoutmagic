<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Client;

use Modules\InboundMail\Client\ImapMailboxClient;
use Modules\InboundMail\Client\MailboxConnectionException;
use Modules\InboundMail\Mailbox\MailboxCredentials;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Mailbox\SyncState;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;

/**
 * The IMAP client, run rather than read.
 *
 * NonIntrusiveReadTest beside this one pins the client's most important
 * properties by reading its source — that it peeks rather than marks as
 * read, that it opens folders read-only, that certificate validation has
 * no off switch. Those are the right shape for a rule: they are absolute,
 * and a source-level check cannot be satisfied by a lucky code path.
 *
 * What no test ran was the code itself. Measured at 31 %, and the module
 * whose behaviour depends on a third party nobody controls — a mail host
 * that rejects a credential, drops a connection, or answers a folder
 * status in its own way.
 *
 * Two things are pinned here that only running can show:
 *
 * - **the cursor**, computed from the folder's status. It is what a later
 *   sync resumes from: one off in one direction re-reads the whole
 *   mailbox on every pass, one off in the other silently skips a message
 *   that will never be looked at again;
 * - **what a failure carries out of the library**. A mail library's own
 *   message routinely contains the account name and the server's verbatim
 *   rejection of the credential just tried. This client is allowed to
 *   report the exception's CLASS and nothing it wrote, because the value
 *   lands in a database column a page renders.
 *
 * @group database
 */
class ImapClientBehaviourTest extends TestCase
{
    // ── what a failure is allowed to say ──────────────────────────────

    public function testAServerThatRefusesTheCredentialsFailsAsAConnectionProblem(): void
    {
        $client = new ImapMailboxClient($this->managerThrowing(
            new \RuntimeException('LOGIN failed for chef@unite.be: invalid password "chene2026"')
        ));

        $this->expectException(MailboxConnectionException::class);

        $client->connect($this->mailbox(), $this->credentials());
    }

    /**
     * The password was in the library's message. It must not be in ours,
     * and neither must the account name: this value is stored and shown.
     */
    public function testTheLibrarysOwnWordsNeverReachTheMessageWeStore(): void
    {
        $client = new ImapMailboxClient($this->managerThrowing(
            new \RuntimeException('LOGIN failed for chef@unite.be: invalid password "chene2026"')
        ));

        try {
            $client->connect($this->mailbox(), $this->credentials());
            $this->fail('The connection should have failed.');
        } catch (MailboxConnectionException $e) {
            $this->assertStringNotContainsString('chene2026', $e->getMessage());
            $this->assertStringNotContainsString('chef@unite.be', $e->getMessage());
            $this->assertSame('RuntimeException', $e->getMessage(), 'The class, and nothing it wrote.');
        }
    }

    /**
     * Not lost, either: the cause travels as $previous, where a stack
     * trace and the journal can still reach it.
     */
    public function testTheCauseIsKeptWhereOnlyAnOperatorCanSeeIt(): void
    {
        $cause = new \RuntimeException('LOGIN failed for chef@unite.be');
        $client = new ImapMailboxClient($this->managerThrowing($cause));

        try {
            $client->connect($this->mailbox(), $this->credentials());
            $this->fail('The connection should have failed.');
        } catch (MailboxConnectionException $e) {
            $this->assertSame($cause, $e->getPrevious());
        }
    }

    // ── nothing before a connection ───────────────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function readOperations(): array
    {
        return [
            'listing the folders' => ['listFolders'],
            'asking for a folder state' => ['folderState'],
            'fetching messages' => ['fetchSince'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('readOperations')]
    public function testReadingBeforeConnectingSaysSoRatherThanCrashing(string $operation): void
    {
        $client = new ImapMailboxClient();

        $this->expectException(MailboxConnectionException::class);

        match ($operation) {
            'listFolders' => $client->listFolders(),
            'folderState' => $client->folderState('INBOX'),
            default => $client->fetchSince('INBOX', 0, 10),
        };
    }

    public function testDisconnectingTwiceIsHarmless(): void
    {
        $client = new ImapMailboxClient();

        $client->disconnect();
        $client->disconnect();

        $this->expectNotToPerformAssertions();
    }

    // ── the cursor ────────────────────────────────────────────────────

    /**
     * `uidnext` is the UID the NEXT message will get, so the highest one
     * that exists is one below it. Reading it as the highest would skip
     * every newest message, for ever.
     */
    public function testTheHighestUidIsOneBelowTheNextOneTheServerWillHandOut(): void
    {
        $client = $this->connectedTo(['uidvalidity' => 12, 'uidnext' => 4211]);

        $state = $client->folderState('INBOX');

        $this->assertSame(4210, $state->highestUid);
    }

    public function testAnEmptyFolderHasNoHighestUidRatherThanAMinusOne(): void
    {
        $client = $this->connectedTo(['uidvalidity' => 12, 'uidnext' => 1]);

        $state = $client->folderState('INBOX');

        $this->assertSame(0, $state->highestUid);
    }

    /**
     * A server that renumbers its mailbox changes uidvalidity, and every
     * stored cursor becomes meaningless. Carrying it is what lets the
     * sync notice.
     */
    public function testTheValidityStampIsCarriedBackWithTheCursor(): void
    {
        $client = $this->connectedTo(['uidvalidity' => 987654, 'uidnext' => 10]);

        $state = $client->folderState('INBOX');

        $this->assertSame(987654, $state->uidValidity);
        $this->assertSame('INBOX', $state->folder);
    }

    /**
     * Some servers answer a status without these keys at all. Reading
     * them as absent must not put the cursor somewhere arbitrary.
     */
    public function testAStatusMissingItsKeysDoesNotInventACursor(): void
    {
        $client = $this->connectedTo([]);

        $state = $client->folderState('INBOX');

        $this->assertSame(0, $state->uidValidity);
        $this->assertSame(0, $state->highestUid);
    }

    public function testAFolderTheServerDoesNotKnowSaysSoPlainly(): void
    {
        $client = new ImapMailboxClient($this->managerReturning(new InertImapClient(null)));
        $client->connect($this->mailbox(), $this->credentials());

        $this->expectException(MailboxConnectionException::class);
        $this->expectExceptionMessage('Le dossier demandé est introuvable sur le serveur.');

        $client->folderState('Archives 2019');
    }

    // ── harness ───────────────────────────────────────────────────────

    private function managerThrowing(\Throwable $error): ClientManager
    {
        $manager = $this->createStub(ClientManager::class);
        $manager->method('make')->willThrowException($error);

        return $manager;
    }

    /**
     * Hand-written doubles rather than createStub(), and the reason is
     * the library: `Webklex\PHPIMAP\Client::__destruct()` calls
     * `disconnect()`, so a generated stub recurses into its own return-
     * value generator the moment PHP collects it. Two tiny subclasses
     * with an inert constructor and destructor say exactly what the
     * server answers and nothing else.
     *
     * @param array<string, mixed> $status what the server answers to EXAMINE
     */
    private function connectedTo(array $status): ImapMailboxClient
    {
        $client = new ImapMailboxClient($this->managerReturning(new InertImapClient(new InertFolder($status))));
        $client->connect($this->mailbox(), $this->credentials());

        return $client;
    }

    private function managerReturning(Client $client): ClientManager
    {
        $manager = $this->createStub(ClientManager::class);
        $manager->method('make')->willReturn($client);

        return $manager;
    }

    private function mailbox(): Mailbox
    {
        return new Mailbox(
            1,
            'Boîte de l\'unité',
            ProviderType::IMAP,
            'imap.example.be',
            993,
            'ssl',
            'chef@unite.be',
            ['INBOX'],
            true,
            SyncState::NEVER
        );
    }

    private function credentials(): MailboxCredentials
    {
        return new MailboxCredentials('chef@unite.be', 'chene2026');
    }
}

/**
 * A client that answers with one folder and does nothing on its way out.
 */
class InertImapClient extends Client
{
    public function __construct(private ?Folder $folder)
    {
    }

    public function __destruct()
    {
    }

    /**
     * The real one opens a socket. This one is already "connected".
     */
    public function connect(): Client
    {
        return $this;
    }

    public function getFolderByPath($folder_path, bool $utf7 = false, bool $soft_fail = false): ?Folder
    {
        return $this->folder;
    }

    public function disconnect(): Client
    {
        return $this;
    }
}

/**
 * A folder that answers EXAMINE with whatever the scenario says a server
 * answered, and nothing else.
 */
class InertFolder extends Folder
{
    /**
     * `$status` is the library's own public property, so the answer is
     * kept under a name of this class's own.
     *
     * @param array<string, mixed> $examineAnswer
     */
    public function __construct(private array $examineAnswer)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function examine(): array
    {
        return $this->examineAnswer;
    }
}
