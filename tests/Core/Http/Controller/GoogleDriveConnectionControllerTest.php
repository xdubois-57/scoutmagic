<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\GoogleDriveConnectionController;
use Core\Http\Request;
use Core\Journal\JournalService;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\SessionStore;
use Core\Storage\Location\Backend\Drive\GoogleDriveClient;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\GoogleDriveSecret;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\StorageLocationConsumerRegistry;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationService;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The OAuth round trip, decision by decision.
 *
 * **Every one of these is a refusal or a redirect**, which is what a
 * connection flow is: the browser leaves, comes back carrying something,
 * and this class decides whether that something is trustworthy. Google is
 * behind a faked transport; the session, the settings and the location row
 * are real, because the questions being asked are about them.
 *
 * `RemoteBackupRbacTest` covers who may reach these methods at all. What
 * is asserted here is what happens once they are reached.
 */
final class GoogleDriveConnectionControllerTest extends TestCase
{
    private \PDO $pdo;
    private StorageLocationRepository $locations;
    private StorageLocationService $locationService;
    private RemoteBackupSettingsDouble $settings;
    private RecordingJournalRepository $journal;
    private int $locationId;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->locations = new StorageLocationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->locationService = new StorageLocationService(
            $this->locations,
            new StorageBackendFactory($this->locations, sys_get_temp_dir()),
            new StorageLocationConsumerRegistry()
        );
        $this->settings = new RemoteBackupSettingsDouble(['base_url' => 'https://unite.example']);

        $this->locationId = $this->locations->create(
            StorageLocationType::GoogleDrive,
            'Google Drive',
            new GoogleDriveLocationConfig(),
            null
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
    }

    public function testSavingCredentialsStoresThemAndSaysSo(): void
    {
        $response = $this->controller()->saveCredentials($this->postRequest([
            'drive_client_id' => '  client-1  ',
            'drive_client_secret' => ' secret-1 ',
        ]), ['id' => (string) $this->locationId]);

        $this->assertSame(302, $response->getStatusCode());
        // Trimmed: a value pasted from Google's console carries whitespace
        // often enough, and Google refuses a client id that has any.
        $this->assertSame('client-1', $this->config()->clientId);
        $this->assertSame('secret-1', $this->secret()->clientSecret);
    }

    public function testHalfEnteredCredentialsAreRefusedRatherThanStored(): void
    {
        $this->controller()->saveCredentials($this->postRequest([
            'drive_client_id' => 'client-1',
            'drive_client_secret' => '',
        ]), ['id' => (string) $this->locationId]);

        $this->assertSame('', $this->config()->clientId);
        $this->assertFalse($this->secret()->hasClientSecret());
    }

    /**
     * **A location of another type is not a Drive card.** The route takes
     * an id, and an id is whatever the browser sends: without this, a
     * POST naming the site's local folder would write a Google
     * configuration record over it and lose the path every photograph is
     * stored under.
     */
    public function testALocationThatIsNotADriveOneIsNotFound(): void
    {
        $localId = $this->locations->create(
            StorageLocationType::Local,
            'Disque du serveur',
            new LocalLocationConfig('gallery'),
            null
        );

        $response = $this->controller()->saveCredentials($this->postRequest([
            'drive_client_id' => 'client-1',
            'drive_client_secret' => 'secret-1',
        ]), ['id' => (string) $localId]);

        $this->assertSame(404, $response->getStatusCode());
        $local = $this->locations->findById($localId);
        $this->assertNotNull($local);
        $this->assertSame('gallery', $local->config->toArray()['path'] ?? null);
    }

    public function testTheFlowRefusesToStartBeforeTheCredentialsExist(): void
    {
        $response = $this->controller()->connect($this->getRequest(), ['id' => (string) $this->locationId]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/config/stockage/emplacements', $response->getHeaders()['Location']);
        $this->assertNull(SessionStore::get('storage_google_oauth_state'));
    }

    /**
     * Leaving for Google carries the narrow scope, the redirect the
     * project registered, and a state this session will recognise.
     */
    public function testStartingTheFlowLeavesForGoogleWithAStateThisSessionCanRecognise(): void
    {
        $this->saveCredentials();

        $response = $this->controller()->connect($this->getRequest(), ['id' => (string) $this->locationId]);

        $this->assertSame(302, $response->getStatusCode());
        $location = $response->getHeaders()['Location'];
        $this->assertStringStartsWith('https://accounts.google.com/', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(GoogleDriveClient::SCOPE, $query['scope'] ?? null);
        $this->assertSame(
            'https://unite.example/config/stockage/google/retour',
            $query['redirect_uri'] ?? null
        );
        $this->assertNotSame('', (string) ($query['state'] ?? ''));
        $this->assertSame($query['state'], SessionStore::get('storage_google_oauth_state'));
        $this->assertSame(
            (string) $this->locationId,
            SessionStore::get('storage_google_oauth_location'),
            'the answer would come back with nothing saying which location it belongs to'
        );
    }

    /**
     * **A callback nobody started is refused.**
     *
     * This is the whole reason the state exists: without it, a URL
     * composed by somebody else — sent to an administrator, or simply
     * guessed — would graft a stranger's Google account onto this site's
     * storage.
     */
    public function testACallbackWithNoMatchingStateIsRefusedAndNothingIsStored(): void
    {
        $this->saveCredentials();
        SessionStore::set('storage_google_oauth_state', 'the-real-state');
        SessionStore::set('storage_google_oauth_location', (string) $this->locationId);

        $response = $this->controller()->callback($this->callbackRequest('forged', 'c'), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->secret()->hasGrant());
    }

    /** And a state is single-use: replaying the same one does not work twice. */
    public function testAStateCannotBeUsedTwice(): void
    {
        $this->saveCredentials();
        SessionStore::set('storage_google_oauth_state', 'st4te');
        SessionStore::set('storage_google_oauth_location', (string) $this->locationId);

        $this->controller($this->googleAnsweringHappily())->callback($this->callbackRequest('st4te', 'code-1'), []);
        $this->assertTrue($this->secret()->hasGrant());

        $this->controller()->disconnect($this->postRequest([]), ['id' => (string) $this->locationId]);
        $this->saveCredentials();
        $this->controller($this->googleAnsweringHappily())->callback($this->callbackRequest('st4te', 'code-2'), []);

        $this->assertFalse($this->secret()->hasGrant(), 'a replayed state connected the site a second time');
    }

    /**
     * The operator pressed « Annuler » on Google's screen. Not an error to
     * shout about, and nothing to store.
     */
    public function testARefusedConsentComesBackWithoutStoringAnything(): void
    {
        $this->saveCredentials();
        $this->armState();

        $response = $this->controller()->callback(
            new Request('GET', '/config/stockage/google/retour', ['state' => 'st4te', 'error' => 'access_denied'], [], [], []),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->secret()->hasGrant());
    }

    public function testACallbackWithoutACodeIsRefused(): void
    {
        $this->saveCredentials();
        $this->armState();

        $this->controller()->callback($this->callbackRequest('st4te', ''), []);

        $this->assertFalse($this->secret()->hasGrant());
    }

    /** The happy path, end to end: token, account, folder, date. */
    public function testASuccessfulCallbackStoresTheGrantTheAccountAndTheFolder(): void
    {
        $this->saveCredentials();
        $this->armState();

        $response = $this->controller($this->googleAnsweringHappily())
            ->callback($this->callbackRequest('st4te', 'code-1'), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('refresh-abc', $this->secret()->refreshToken);
        $this->assertSame('unite@example.org', $this->secret()->account);
        $this->assertSame('folder-1', $this->config()->folderId);
        $this->assertNotSame('', $this->config()->connectedAt);
        // The client id survives the grant being written over it.
        $this->assertSame('client-1', $this->config()->clientId);

        // **And the journal names nobody.** The address is a real
        // person's, so the entry says what happened and not who it
        // happened to — the precedent SECURITY.md sets for the mail
        // probe, « le journal compte les boîtes et n'en nomme aucune ».
        $this->assertStringNotContainsString(
            'unite@example.org',
            $this->journal->textOf('storage_location_connected')
        );
    }

    /**
     * A refusal from Google leaves the location unconnected and says why,
     * in a sentence written for a person — with Google's own words in the
     * journal, which is what « consultez le journal » has to lead to.
     */
    public function testAGoogleRefusalIsReportedWithoutConnectingAnything(): void
    {
        $this->saveCredentials();
        $this->armState();

        $client = new GoogleDriveClient(fn (): array => ['status' => 400, 'body' => '{"error":"invalid_grant"}']);
        $this->controller($client)->callback($this->callbackRequest('st4te', 'code-1'), []);

        $this->assertFalse($this->secret()->hasGrant());
        $this->assertStringContainsString(
            'invalid_grant',
            $this->journal->textOf('storage_location_connect_failed')
        );
    }

    /**
     * **The location the answer belongs to can disappear between the two
     * halves of the flow**, and the callback must say so rather than
     * write a grant into a row that is not there.
     */
    public function testACallbackForALocationThatHasGoneIsRefused(): void
    {
        $this->saveCredentials();
        $this->armState();
        $this->locations->delete($this->locationId);

        $response = $this->controller($this->googleAnsweringHappily())
            ->callback($this->callbackRequest('st4te', 'code-1'), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($this->locations->findById($this->locationId));
    }

    public function testDisconnectingForgetsTheGrantAndTheCredentialsButKeepsTheLocation(): void
    {
        $this->saveCredentials();
        $this->armState();
        $this->controller($this->googleAnsweringHappily())->callback($this->callbackRequest('st4te', 'code-1'), []);

        $response = $this->controller()->disconnect($this->postRequest([]), ['id' => (string) $this->locationId]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->secret()->hasGrant());
        $this->assertFalse($this->secret()->hasClientSecret());
        $this->assertSame('', $this->secret()->account);
        $this->assertNotNull(
            $this->locations->findById($this->locationId),
            'disconnecting an account removed the declaration itself'
        );
    }

    /**
     * Without a valid CSRF token none of the writing endpoints do
     * anything — the row is a credential store, and a form posted from
     * elsewhere must not reach it.
     */
    public function testTheWritingEndpointsRefuseARequestWithoutAValidToken(): void
    {
        $this->saveCredentials();
        $_POST = [];

        $bare = new Request('POST', '/config/stockage/emplacements/1/google/deraccordement', [], [], [], []);
        $this->controller()->disconnect($bare, ['id' => (string) $this->locationId]);
        $this->assertTrue($this->secret()->hasClientSecret(), 'a request with no CSRF token cleared the credentials');

        $this->controller()->saveCredentials(
            new Request('POST', '/x', [], ['drive_client_id' => 'autre', 'drive_client_secret' => 'y'], [], []),
            ['id' => (string) $this->locationId]
        );
        $this->assertSame('client-1', $this->config()->clientId);
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
     * back. And it disagreed with the screen, which renders the same
     * address with no such fallback: the operator was told to register a
     * bare path Google's console refuses, while the flow sent an absolute
     * URL. Google answers that with `redirect_uri_mismatch` and neither
     * side says why.
     */
    public function testASiteThatDoesNotKnowItsAddressIsToldSoRatherThanGuessing(): void
    {
        $this->settings->values['base_url'] = '';
        $this->saveCredentials();

        $response = $this->controller()->connect(
            new Request('GET', '/x', [], [], [], ['HTTPS' => 'on', 'HTTP_HOST' => 'attaquant.example']),
            ['id' => (string) $this->locationId]
        );

        // Back to the page, not off to Google: nothing was started.
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/config/stockage/emplacements', $response->getHeaders()['Location'] ?? '');
        $this->assertNull(
            SessionStore::get('storage_google_oauth_state'),
            'a raccordement was begun on an address a request header chose'
        );
    }

    /** And the screen shows no address to register rather than a bare path. */
    public function testTheScreenShowsNothingToRegisterUntilTheSiteKnowsItsAddress(): void
    {
        $this->assertSame('', GoogleDriveConnectionController::redirectUriFor(''));
        $this->assertSame(
            'https://unite.example/config/stockage/google/retour',
            GoogleDriveConnectionController::redirectUriFor('https://unite.example/')
        );
    }

    // ————— Plumbing —————

    private function saveCredentials(): void
    {
        $this->controller()->saveCredentials($this->postRequest([
            'drive_client_id' => 'client-1',
            'drive_client_secret' => 'secret-1',
        ]), ['id' => (string) $this->locationId]);
    }

    private function armState(): void
    {
        SessionStore::set('storage_google_oauth_state', 'st4te');
        SessionStore::set('storage_google_oauth_location', (string) $this->locationId);
    }

    private function config(): GoogleDriveLocationConfig
    {
        $location = $this->locations->findById($this->locationId);
        $config = $location?->config;

        return $config instanceof GoogleDriveLocationConfig ? $config : new GoogleDriveLocationConfig();
    }

    private function secret(): GoogleDriveSecret
    {
        return GoogleDriveSecret::fromStorage($this->locations->getSecret($this->locationId));
    }

    private function controller(?GoogleDriveClient $client = null): GoogleDriveConnectionController
    {
        $this->journal = new RecordingJournalRepository();

        return new GoogleDriveConnectionController(
            new Environment(new ArrayLoader([])),
            $this->locations,
            $this->locationService,
            $this->settings,
            new JournalService($this->journal),
            $client ?? new GoogleDriveClient(fn (): array => ['status' => 500, 'body' => '{}'])
        );
    }

    /** Google saying yes to everything: token, account, folder, witness. */
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
                return ['status' => 200, 'body' => '{"id":"temoin-1"}'];
            }
            if ($method === 'POST') {
                return ['status' => 200, 'body' => '{"id":"folder-1"}'];
            }
            if ($method === 'DELETE') {
                return ['status' => 204, 'body' => ''];
            }

            return ['status' => 200, 'body' => '{"files":[{"id":"folder-1","name":"x","size":"1"}]}'];
        });
    }

    /** @param array<string, string> $body */
    private function postRequest(array $body): Request
    {
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;

        return new Request('POST', '/config/stockage/emplacements/1/google/x', [], $body + ['_csrf_token' => $token], [], []);
    }

    private function getRequest(): Request
    {
        return new Request('GET', '/config/stockage/emplacements/1/google/raccordement', [], [], [], []);
    }

    private function callbackRequest(string $state, string $code): Request
    {
        return new Request(
            'GET',
            '/config/stockage/google/retour',
            ['state' => $state, 'code' => $code],
            [],
            [],
            []
        );
    }
}
