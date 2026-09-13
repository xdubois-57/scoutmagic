<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\RemoteBackupController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\Remote\GoogleDriveClient;
use Core\Maintenance\Remote\RemoteBackupConnection;
use Core\Security\CsrfGuard;
use Core\Security\SecretManager;
use Core\Security\SessionStore;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The OAuth round trip, decision by decision.
 *
 * **Every one of these is a refusal or a redirect**, which is what a
 * connection flow is: the browser leaves, comes back carrying something,
 * and this class decides whether that something is trustworthy. Google is
 * behind a faked transport; the session, the settings and the secrets are
 * real, because the questions being asked are about them.
 *
 * `RemoteBackupRbacTest` covers who may reach these methods at all. What
 * is asserted here is what happens once they are reached.
 */
final class RemoteBackupControllerTest extends TestCase
{
    private string $base;
    private RemoteBackupConnection $connection;
    private RemoteBackupSettingsDouble $settings;
    private RecordingJournalRepository $journal;
    private \Core\Maintenance\Remote\RemotePassphrase $passphrase;
    private SecretManager $secrets;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];

        $this->base = sys_get_temp_dir() . '/remote_ctrl_' . uniqid();
        @mkdir($this->base . '/keys', 0700, true);
        @mkdir($this->base . '/config', 0700, true);
        $secrets = new SecretManager($this->base . '/keys/master.key', $this->base . '/config/secrets.enc');
        $secrets->generateMasterKey();
        $secrets->writeSecrets([]);
        $this->secrets = $secrets;

        $this->settings = new RemoteBackupSettingsDouble([
            'base_url' => 'https://unite.example',
            RemoteBackupConnection::STATE_SETTING => RemoteBackupConnection::STATE_DISCONNECTED,
        ]);
        $this->connection = new RemoteBackupConnection($this->settings, $secrets);
        $this->passphrase = new \Core\Maintenance\Remote\RemotePassphrase($this->settings, $secrets);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        foreach (['/keys/master.key', '/config/secrets.enc'] as $file) {
            @unlink($this->base . $file);
        }
        @rmdir($this->base . '/keys');
        @rmdir($this->base . '/config');
        @rmdir($this->base);
    }

    public function testSavingCredentialsStoresThemAndSaysSo(): void
    {
        $response = $this->controller()->saveCredentials($this->postRequest([
            'client_id' => '  client-1  ',
            'client_secret' => ' secret-1 ',
        ]), []);

        $this->assertSame(302, $response->getStatusCode());
        // Trimmed: a value pasted from Google's console carries whitespace
        // often enough, and Google refuses a client id that has any.
        $this->assertSame('client-1', $this->connection->clientId());
        $this->assertSame('secret-1', $this->connection->clientSecret());
        $this->assertTrue($this->connection->hasCredentials());
    }

    public function testHalfEnteredCredentialsAreRefusedRatherThanStored(): void
    {
        $this->controller()->saveCredentials($this->postRequest([
            'client_id' => 'client-1',
            'client_secret' => '',
        ]), []);

        $this->assertFalse($this->connection->hasCredentials());
        $this->assertSame('', $this->connection->clientId());
    }

    public function testTheFlowRefusesToStartBeforeTheCredentialsExist(): void
    {
        $response = $this->controller()->connect(new Request('GET', '/config/maintenance/remote/connect', [], [], [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/config/maintenance#remote-backup', $response->getHeaders()['Location']);
    }

    /**
     * Leaving for Google carries the narrow scope, the redirect the
     * project registered, and a state this session will recognise.
     */
    public function testStartingTheFlowLeavesForGoogleWithAStateThisSessionCanRecognise(): void
    {
        $this->connection->saveCredentials('client-1', 'secret-1');

        $response = $this->controller()->connect(new Request('GET', '/config/maintenance/remote/connect', [], [], [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $location = $response->getHeaders()['Location'];
        $this->assertStringStartsWith('https://accounts.google.com/', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(GoogleDriveClient::SCOPE, $query['scope'] ?? null);
        $this->assertSame('https://unite.example/config/maintenance/remote/callback', $query['redirect_uri'] ?? null);
        $this->assertNotSame('', (string) ($query['state'] ?? ''));
        $this->assertSame($query['state'], SessionStore::get('remote_backup_oauth_state'));
    }

    /**
     * **A callback nobody started is refused.**
     *
     * This is the whole reason the state exists: without it, a URL
     * composed by somebody else — sent to an administrator, or simply
     * guessed — would graft a stranger's Google account onto this site's
     * backups.
     */
    public function testACallbackWithNoMatchingStateIsRefusedAndNothingIsStored(): void
    {
        $this->connection->saveCredentials('client-1', 'secret-1');
        SessionStore::set('remote_backup_oauth_state', 'the-real-state');

        $response = $this->controller()->callback(
            new Request('GET', '/config/maintenance/remote/callback', ['state' => 'forged', 'code' => 'c'], [], [], []),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->connection->isConnected());
        $this->assertSame('', $this->connection->refreshToken());
    }

    /** And a state is single-use: replaying the same one does not work twice. */
    public function testAStateCannotBeUsedTwice(): void
    {
        $this->connection->saveCredentials('client-1', 'secret-1');
        SessionStore::set('remote_backup_oauth_state', 'st4te');

        $this->controller($this->googleAnsweringHappily())->callback($this->callbackRequest('st4te', 'code-1'), []);
        $this->assertTrue($this->connection->isConnected());

        $this->connection->disconnect();
        $this->connection->saveCredentials('client-1', 'secret-1');
        $this->controller($this->googleAnsweringHappily())->callback($this->callbackRequest('st4te', 'code-2'), []);

        $this->assertFalse($this->connection->isConnected(), 'a replayed state connected the site a second time');
    }

    /**
     * The operator pressed « Annuler » on Google's screen. Not an error to
     * shout about, and nothing to store.
     */
    public function testARefusedConsentComesBackWithoutStoringAnything(): void
    {
        $this->connection->saveCredentials('client-1', 'secret-1');
        SessionStore::set('remote_backup_oauth_state', 'st4te');

        $response = $this->controller()->callback(
            new Request('GET', '/config/maintenance/remote/callback', ['state' => 'st4te', 'error' => 'access_denied'], [], [], []),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->connection->isConnected());
    }

    public function testACallbackWithoutACodeIsRefused(): void
    {
        $this->connection->saveCredentials('client-1', 'secret-1');
        SessionStore::set('remote_backup_oauth_state', 'st4te');

        $this->controller()->callback($this->callbackRequest('st4te', ''), []);

        $this->assertFalse($this->connection->isConnected());
    }

    /** The happy path, end to end: token, account, folder, state. */
    public function testASuccessfulCallbackStoresTheGrantTheAccountAndTheFolder(): void
    {
        $this->connection->saveCredentials('client-1', 'secret-1');
        SessionStore::set('remote_backup_oauth_state', 'st4te');

        $response = $this->controller($this->googleAnsweringHappily())
            ->callback($this->callbackRequest('st4te', 'code-1'), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($this->connection->isConnected());
        $this->assertSame('refresh-abc', $this->connection->refreshToken());
        $this->assertSame('unite@example.org', $this->connection->account());
        $this->assertSame('folder-1', $this->connection->folderId());
        $this->assertSame(RemoteBackupConnection::STATE_CONNECTED, $this->connection->state());

        // **And the journal names nobody.** The address is a real
        // person's, so the entry says what happened and not who it
        // happened to — the precedent SECURITY.md sets for the mail
        // probe, « le journal compte les boîtes et n'en nomme aucune ».
        // Asserted on the entry itself, not only on the ratchet that
        // forbids the controller to read `account()`: the ratchet closes
        // the door this address came through, this closes the room.
        $this->assertStringNotContainsString(
            'unite@example.org',
            $this->journal->textOf('remote_backup_connected')
        );
    }

    /**
     * A refusal from Google leaves the site unconnected and says why, in
     * a sentence written for a person.
     */
    public function testAGoogleRefusalIsReportedWithoutConnectingAnything(): void
    {
        $this->connection->saveCredentials('client-1', 'secret-1');
        SessionStore::set('remote_backup_oauth_state', 'st4te');

        $client = new GoogleDriveClient(fn (): array => ['status' => 400, 'body' => '{"error":"invalid_grant"}']);
        $this->controller($client)->callback($this->callbackRequest('st4te', 'code-1'), []);

        $this->assertFalse($this->connection->isConnected());
        $this->assertStringContainsString('Reconnectez', $this->connection->lastError());
    }

    public function testTheTestButtonAnswersInJsonAndNamesTheAccount(): void
    {
        $this->connectSite();

        $response = $this->controller($this->googleAnsweringHappily())->test($this->jsonRequest(), []);
        $decoded = json_decode($response->getBody(), true);

        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['success'] ?? false, (string) ($decoded['message'] ?? ''));
        $this->assertSame('unite@example.org', $decoded['account'] ?? null);
        $this->assertFalse($decoded['needs_reauthorisation'] ?? true);
    }

    public function testTheTestButtonReportsAWithdrawnGrantAsSomethingToReconnect(): void
    {
        $this->connectSite();

        $client = new GoogleDriveClient(fn (): array => ['status' => 400, 'body' => '{"error":"invalid_grant"}']);
        $decoded = json_decode($this->controller($client)->test($this->jsonRequest(), [])->getBody(), true);

        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['success'] ?? true);
        $this->assertTrue($decoded['needs_reauthorisation'] ?? false);
        $this->assertSame(RemoteBackupConnection::STATE_NEEDS_REAUTH, $this->connection->state());
    }

    public function testDisconnectingForgetsEverything(): void
    {
        $this->connectSite();

        $response = $this->controller()->disconnect($this->postRequest([]), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->connection->isConnected());
        $this->assertFalse($this->connection->hasCredentials());
        $this->assertSame('', $this->connection->account());
    }

    /**
     * Without a valid CSRF token none of the writing endpoints do
     * anything — the connection is a credential store, and a form posted
     * from elsewhere must not reach it.
     */
    public function testTheWritingEndpointsRefuseARequestWithoutAValidToken(): void
    {
        $this->connectSite();
        $_POST = [];

        $bare = new Request('POST', '/config/maintenance/remote/disconnect', [], [], [], []);
        $this->controller()->disconnect($bare, []);
        $this->assertTrue($this->connection->isConnected(), 'a request with no CSRF token disconnected the site');

        $this->controller()->saveCredentials(
            new Request('POST', '/config/maintenance/remote/credentials', [], ['client_id' => 'x', 'client_secret' => 'y'], [], []),
            []
        );
        $this->assertNotSame('x', $this->connection->clientId());
    }

    /**
     * **A site that does not know its own address cannot start at all**,
     * and that refusal replaces a fallback built from `HTTP_HOST`.
     *
     * Two reasons, and either one is enough. The Host header is supplied
     * by whoever made the request — `InstallationProfile` says it in as
     * many words, « la SEULE source de l'adresse du site, ici comme
     * ailleurs : jamais HTTP_HOST » — and an OAuth redirect URI is the
     * last place to take an attacker's word for where to send a browser
     * back. And it disagreed with the screen, which renders
     * `RemoteBackupConnection::redirectUri()` and had no such fallback:
     * the operator was told to register a bare path Google's console
     * refuses, while the flow sent an absolute URL. Google answers that
     * with `redirect_uri_mismatch` and neither side says why.
     */
    public function testASiteThatDoesNotKnowItsAddressIsToldSoRatherThanGuessing(): void
    {
        $this->settings->values['base_url'] = '';
        $this->connection->saveCredentials('client-1', 'secret-1');

        $response = $this->controller()->connect(
            new Request('GET', '/x', [], [], [], ['HTTPS' => 'on', 'HTTP_HOST' => 'attaquant.example']),
            []
        );

        // Back to the page, not off to Google: nothing was started.
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/config/maintenance#remote-backup', $response->getHeaders()['Location'] ?? '');
        $this->assertNull(
            SessionStore::get('remote_backup_oauth_state'),
            'a raccordement was begun on an address a request header chose'
        );
    }

    /** And the screen shows no address to register rather than a bare path. */
    public function testTheScreenShowsNothingToRegisterUntilTheSiteKnowsItsAddress(): void
    {
        $this->settings->values['base_url'] = '';

        $this->assertSame('', $this->connection->redirectUri());

        $this->settings->values['base_url'] = 'https://unite.example/';

        $this->assertSame(
            'https://unite.example/config/maintenance/remote/callback',
            $this->connection->redirectUri()
        );
    }

    /**
     * **« Consultez le journal du site » has to lead somewhere.**
     *
     * Google's own answer is forbidden on screen — `UserFacingException`
     * keeps English internals away from the operator — so it travels as
     * the exception's cause. Nothing read it back: the cause was built,
     * attached, and dropped. A broken connection therefore produced one
     * generic French sentence and no record at all of what Google said,
     * in a feature whose whole logic turns on telling `invalid_client`
     * from `invalid_grant`.
     */
    public function testAFailedTestRecordsWhatGoogleActuallySaid(): void
    {
        $this->connectSite();

        $client = new GoogleDriveClient(fn (): array => [
            'status' => 401,
            'body' => '{"error":"invalid_client","error_description":"The OAuth client was not found."}',
        ]);
        $this->controller($client)->test($this->jsonRequest(), []);

        $recorded = $this->journal->textOf('remote_backup_test_failed');
        $this->assertNotSame('', $recorded, 'the operator was sent to a journal entry nobody wrote');
        $this->assertStringContainsString('invalid_client', $recorded);
    }

    /** And a test that works writes nothing: the button is pressable at will. */
    public function testASuccessfulTestDoesNotFillTheJournal(): void
    {
        $this->connectSite();

        $this->controller($this->googleAnsweringHappily())->test($this->jsonRequest(), []);

        $this->assertSame('', $this->journal->textOf('remote_backup_test_failed'));
    }

    /**
     * The same, on the raccordement path — where the consequence is
     * sharper still, since a wrong reading there deletes a valid grant.
     */
    public function testARefusedCallbackRecordsWhatGoogleActuallySaid(): void
    {
        $this->connection->saveCredentials('client-1', 'secret-1');
        SessionStore::set('remote_backup_oauth_state', 'st4te');

        $client = new GoogleDriveClient(fn (): array => ['status' => 400, 'body' => '{"error":"invalid_grant"}']);
        $this->controller($client)->callback($this->callbackRequest('st4te', 'code-1'), []);

        $this->assertStringContainsString(
            'invalid_grant',
            $this->journal->textOf('remote_backup_connect_failed')
        );
    }

    // ————— La phrase de passe —————

    /**
     * **Shown on demand, and that is the deliberate departure.** The
     * webhook secret is shown once and never again; this phrase opens
     * archives that already exist on a service this site may not be
     * around to reach, and it has to live in `secrets.enc` anyway so the
     * scheduled send can encrypt without a human. Hiding it from the
     * administrator protects nothing and guarantees that one day nobody
     * can open a year of uploads.
     */
    public function testThePhraseCanBeRevealedAndIsCreatedOnFirstAsking(): void
    {
        $this->connectSite();

        $response = $this->controller()->revealPassphrase($this->jsonRequest(), []);
        $payload = json_decode($response->getBody(), true);

        $this->assertTrue($payload['success']);
        $this->assertSame(
            $this->passphrase->stored(),
            $payload['passphrase'],
            'the screen was shown a phrase other than the one the sends encrypt with'
        );
        $this->assertNotSame('', $payload['passphrase']);
    }

    /**
     * Reading it is reading the key to every archive off this server, so
     * it leaves a security entry — without the phrase in it, which would
     * put the key in a log read on screen and carried in the support
     * archive.
     */
    public function testRevealingThePhraseIsJournaledWithoutThePhrase(): void
    {
        $this->connectSite();

        $response = $this->controller()->revealPassphrase($this->jsonRequest(), []);
        $phrase = json_decode($response->getBody(), true)['passphrase'];

        $recorded = $this->journal->textOf('remote_backup_passphrase_revealed');
        $this->assertNotSame('', $recorded, 'nothing recorded that somebody read the key to every remote archive');
        $this->assertStringNotContainsString($phrase, $recorded, 'the journal now carries the phrase itself');
    }

    /** No token, no phrase: it cannot be pulled out by a link. */
    public function testRevealingThePhraseNeedsTheCsrfToken(): void
    {
        $this->connectSite();
        // The superglobal cleared as well as the body: `isCsrfValid()`
        // accepts the token from either, so a test that only emptied the
        // body would prove nothing about a request that carries neither.
        unset($_POST['_csrf_token']);

        $request = new Request('POST', '/config/maintenance/remote/passphrase/reveal', [], [], [], []);
        $response = $this->controller()->revealPassphrase($request, []);

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame(
            '',
            $this->passphrase->stored(),
            'a refused request still created — and therefore could still have leaked — a phrase'
        );
    }

    /**
     * Regenerating draws a genuinely different phrase and moves the
     * generation on, which is what keeps the remote folder legible: the
     * number is in every file name, so an operator holding two phrases
     * can tell which opens which.
     */
    public function testRegeneratingChangesThePhraseAndAdvancesTheGeneration(): void
    {
        $this->connectSite();
        $first = $this->passphrase->current();
        $firstGeneration = $this->passphrase->generation();

        $this->controller()->regeneratePassphrase($this->postRequest([]), []);

        $this->assertNotSame($first, $this->passphrase->stored());
        $this->assertSame($firstGeneration + 1, $this->passphrase->generation());
    }

    /** And it is journaled with both numbers, never with either phrase. */
    public function testRegeneratingIsJournaledWithBothGenerationsAndNoPhrase(): void
    {
        $this->connectSite();
        $first = $this->passphrase->current();

        $this->controller()->regeneratePassphrase($this->postRequest([]), []);

        $recorded = $this->journal->textOf('remote_backup_passphrase_regenerated');
        $this->assertStringContainsString('"previous_generation":1', $recorded);
        $this->assertStringContainsString('"generation":2', $recorded);
        $this->assertStringNotContainsString($first, $recorded);
        $this->assertStringNotContainsString($this->passphrase->stored(), $recorded);
    }

    /** Without the token, nothing is drawn — the old archives stay open. */
    public function testRegeneratingNeedsTheCsrfToken(): void
    {
        $this->connectSite();
        $first = $this->passphrase->current();
        unset($_POST['_csrf_token']);

        $request = new Request('POST', '/config/maintenance/remote/passphrase/regenerate', [], [], [], []);
        $this->controller()->regeneratePassphrase($request, []);

        $this->assertSame($first, $this->passphrase->stored(), 'a request with no token still burned the phrase');
    }

    private function connectSite(): void
    {
        $this->connection->saveCredentials('client-1', 'secret-1');
        $this->connection->saveConnection('refresh-abc', 'unite@example.org', 'folder-1');
    }

    private function controller(?GoogleDriveClient $client = null): RemoteBackupController
    {
        $this->journal = new RecordingJournalRepository();

        return new RemoteBackupController(
            new Environment(new ArrayLoader([])),
            $this->connection,
            new JournalService($this->journal),
            $this->passphrase,
            $client ?? new GoogleDriveClient(fn (): array => ['status' => 500, 'body' => '{}'])
        );
    }

    /** Google saying yes to everything: token, account, folder, upload, delete. */
    private function googleAnsweringHappily(): GoogleDriveClient
    {
        return new GoogleDriveClient(function (string $method, string $url): array {
            if (str_contains($url, '/token')) {
                return ['status' => 200, 'body' => '{"refresh_token":"refresh-abc","access_token":"ya29.ok","expires_in":3599}'];
            }
            if (str_contains($url, '/about')) {
                return ['status' => 200, 'body' => '{"user":{"emailAddress":"unite@example.org"},"storageQuota":{"usage":"1","limit":"9"}}'];
            }
            if (str_contains($url, '/upload/')) {
                return ['status' => 200, 'body' => '{}', 'location' => 'https://upload.example/s1'];
            }
            if ($method === 'PUT') {
                return ['status' => 200, 'body' => '{"id":"witness-1"}'];
            }
            if ($method === 'POST') {
                return ['status' => 200, 'body' => '{"id":"folder-1"}'];
            }
            if ($method === 'DELETE') {
                return ['status' => 204, 'body' => ''];
            }

            return ['status' => 200, 'body' => '{"files":[{"id":"folder-1"}]}'];
        });
    }

    /** @param array<string, string> $body */
    private function postRequest(array $body): Request
    {
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;

        return new Request('POST', '/config/maintenance/remote/x', [], $body + ['_csrf_token' => $token], [], []);
    }

    private function jsonRequest(): Request
    {
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;

        return new Request('POST', '/config/maintenance/remote/test', [], ['_csrf_token' => $token], [], []);
    }

    private function callbackRequest(string $state, string $code): Request
    {
        return new Request(
            'GET',
            '/config/maintenance/remote/callback',
            ['state' => $state, 'code' => $code],
            [],
            [],
            []
        );
    }
}

/** The settings table, in an array — see `GoogleDriveTargetTest`. */
final class RemoteBackupSettingsDouble extends SettingService
{
    /** @param array<string, string> $values */
    public function __construct(public array $values = [])
    {
        parent::__construct(new \Core\Config\SettingRepository(new \PDO('sqlite::memory:')));
    }

    public function get(string $key, ?string $moduleId = null, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function setInternal(string $key, string $value, ?string $moduleId = null): void
    {
        $this->values[$key] = $value;
    }
}

/**
 * A journal that writes to an array.
 *
 * **It used to write nowhere, and that was a hole.** The controller
 * journals a security event on every outcome, and one rule about those
 * entries is a rule about personal data: the connected Google account is
 * the e-mail address of a real person, so it belongs in `secrets.enc`
 * and nowhere near a journal line that is read on screen and carried
 * into a diagnostic archive. A repository that discarded its arguments
 * asserted that rule by never looking — so the entries are kept, and the
 * test reads them.
 */
final class RecordingJournalRepository extends JournalRepository
{
    /** @var list<array{type: string, description: string, context: string}> */
    public array $entries = [];

    public function __construct()
    {
        parent::__construct(new \PDO('sqlite::memory:'));
    }

    public function insert(
        string $category,
        string $type,
        string $level,
        string $description,
        ?string $contextJson,
        ?int $userId,
        ?string $ipAddress = null
    ): void {
        $this->entries[] = ['type' => $type, 'description' => $description, 'context' => (string) $contextJson];
    }

    /** Everything one entry could show a human, in one string. */
    public function textOf(string $type): string
    {
        $text = '';
        foreach ($this->entries as $entry) {
            if ($entry['type'] === $type) {
                $text .= $entry['description'] . ' ' . $entry['context'];
            }
        }

        return $text;
    }
}
