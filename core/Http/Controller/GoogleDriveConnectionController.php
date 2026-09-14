<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\SettingService;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\SessionStore;
use Core\Storage\Location\Backend\Drive\DriveAccessException;
use Core\Storage\Location\Backend\Drive\GoogleDriveClient;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\GoogleDriveSecret;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationService;
use Core\Storage\Location\StorageLocationType;
use Twig\Environment;

/**
 * Putting a Google account behind a declared storage location.
 *
 * **It used to be `RemoteBackupController`'s job, and the move is the
 * point of IT-05.** Connecting a Google account was filed under
 * « sauvegarde hors site » because the off-site backup was the only thing
 * that could use one. A Drive folder is now a storage location like any
 * other, so the consent round trip belongs beside the declaration of that
 * location — on its own card, where the operator is already looking at
 * what the destination is and whether it works.
 *
 * **The Google consent screen is where this integration dies, silently.**
 * A project left in « Test » status hands out refresh tokens Google
 * withdraws after {@see GoogleDriveClient::TESTING_TOKEN_LIFETIME_DAYS}
 * days: everything works for a week, then nothing does, and the operator
 * finds out on the night they need a backup. The warning lives on this
 * location's card and is worded as a thing to go and change, not as a
 * note.
 *
 * **Its own controller, rather than five more methods on
 * `StorageConfigController`.** That class is already the largest in the
 * storage area, and this is a self-contained conversation with a third
 * party: credentials in, a browser round trip through Google, a grant
 * out. The location page renders the card; nothing else here belongs to
 * it.
 *
 * **The callback is an authenticated route like every other.** It arrives
 * as a redirect from Google rather than from a form, which is exactly why
 * it is worth saying: an unauthenticated callback that writes a refresh
 * token is a route where anybody who can craft a URL decides which Google
 * account this site writes to. It sits behind the same `superadmin` floor
 * as the page that starts the flow, and the `state` parameter is checked
 * against the session on top of that.
 */
final class GoogleDriveConnectionController extends AbstractController
{
    private const STATE_SESSION_KEY = 'storage_google_oauth_state';

    private const LOCATION_SESSION_KEY = 'storage_google_oauth_location';

    /**
     * Where Google sends the browser back — **one fixed path, with no
     * identifier in it**.
     *
     * It has to match the value registered in the operator's Google
     * project character for character, and a path carrying a location id
     * would stop matching the day they declared a second destination or
     * restored a database that renumbered the first. Which location the
     * answer belongs to travels in the session instead, beside the
     * single-use state.
     */
    public const REDIRECT_PATH = '/config/stockage/google/retour';

    private const LOCATIONS_URL = '/config/stockage/emplacements';

    public function __construct(
        Environment $twig,
        private readonly StorageLocationRepository $locations,
        private readonly StorageLocationService $locationService,
        private readonly SettingService $settings,
        private readonly JournalService $journalService,
        private readonly GoogleDriveClient $client = new GoogleDriveClient()
    ) {
        parent::__construct($twig);
    }

    /**
     * POST — records the OAuth client of the operator's own Google
     * project.
     *
     * The client id is stored in the clear half of the row (it travels in
     * the authorisation URL their browser follows, so it is public by
     * construction); the secret goes to the encrypted column, beside the
     * grant it will obtain.
     *
     * @param array<string, string> $params
     */
    public function saveCredentials(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::LOCATIONS_URL)) !== null) {
            return $guard;
        }

        $location = $this->driveLocation($params);
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        $clientId = trim((string) $request->getBody('drive_client_id', ''));
        $clientSecret = trim((string) $request->getBody('drive_client_secret', ''));
        if ($clientId === '' || $clientSecret === '') {
            FlashMessage::set('error', 'Renseignez l\'identifiant et le secret du client OAuth.');

            return $this->redirect(self::LOCATIONS_URL);
        }

        $config = $this->configOf($location);
        $this->locations->update(
            $location->id,
            $location->label,
            new GoogleDriveLocationConfig($clientId, $config->folderId, $config->connectedAt),
            $this->secretOf($location)->withClientSecret($clientSecret)->toStorage()
        );

        $this->journalService->log(
            'storage',
            'storage_location_credentials_changed',
            'security',
            "Identifiants du client OAuth de l'emplacement « {$location->label} » enregistrés",
            [],
            (int) AuthSession::getUserAccountId()
        );
        FlashMessage::set('success', 'Identifiants enregistrés. Vous pouvez maintenant raccorder le compte.');

        return $this->redirect(self::LOCATIONS_URL);
    }

    /**
     * GET — leaves for Google's consent screen.
     *
     * @param array<string, string> $params
     */
    public function connect(Request $request, array $params): Response
    {
        $location = $this->driveLocation($params);
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        $config = $this->configOf($location);
        $secret = $this->secretOf($location);
        if ($config->clientId === '' || !$secret->hasClientSecret()) {
            FlashMessage::set('error', 'Enregistrez d\'abord l\'identifiant et le secret du client OAuth.');

            return $this->redirect(self::LOCATIONS_URL);
        }

        // Before Google is involved at all: the redirect URI is composed
        // from this site's own address, and there is no honest way to
        // compose it without one.
        if ($this->baseUrl() === '') {
            FlashMessage::set(
                'error',
                'Ce site ne connaît pas encore sa propre adresse. Renseignez-la dans '
                . 'Configuration > Réglages (« Adresse du site ») avant de raccorder un compte Google : c\'est '
                . 'elle qui compose l\'adresse de redirection à déclarer chez Google.'
            );

            return $this->redirect(self::LOCATIONS_URL);
        }

        // Random, single-use, and held in the session: this is what makes
        // the callback below refuse a URL somebody else composed.
        //
        // Through SessionStore, never `$_SESSION` directly: public/index.php
        // closes the session early, so a direct write is silently lost —
        // which here would mean the callback never finding a state to
        // compare against, and every connection attempt being refused for
        // a reason nobody could see (ARCHITECTURE.md §8.20).
        $state = bin2hex(random_bytes(16));
        SessionStore::set(self::STATE_SESSION_KEY, $state);
        SessionStore::set(self::LOCATION_SESSION_KEY, (string) $location->id);

        return $this->redirect($this->client->authorizationUrl($config->clientId, $this->redirectUri(), $state));
    }

    /**
     * GET — where Google sends the browser back.
     *
     * @param array<string, string> $params
     */
    public function callback(Request $request, array $params): Response
    {
        $stored = SessionStore::get(self::STATE_SESSION_KEY);
        $expected = is_string($stored) ? $stored : '';
        $locationId = (int) (string) (SessionStore::get(self::LOCATION_SESSION_KEY) ?? '0');
        // Single-use: removed before anything is decided, so a state that
        // was replayed cannot match a second time.
        SessionStore::remove(self::STATE_SESSION_KEY);
        SessionStore::remove(self::LOCATION_SESSION_KEY);

        $state = (string) $request->getQuery('state', '');
        if ($expected === '' || !hash_equals($expected, $state)) {
            FlashMessage::set('error', 'Le retour de Google n\'a pas pu être vérifié. Recommencez le raccordement.');

            return $this->redirect(self::LOCATIONS_URL);
        }

        $location = $this->driveLocation(['id' => (string) $locationId]);
        if ($location === null) {
            FlashMessage::set('error', 'L\'emplacement à raccorder n\'existe plus. Recommencez le raccordement.');

            return $this->redirect(self::LOCATIONS_URL);
        }

        // The operator pressed « Annuler » on Google's screen. Not an
        // error to shout about, and emphatically not one to journal as a
        // security event.
        if ((string) $request->getQuery('error', '') !== '') {
            FlashMessage::set('error', 'Le raccordement a été annulé : Google n\'a pas accordé l\'autorisation.');

            return $this->redirect(self::LOCATIONS_URL);
        }

        $code = (string) $request->getQuery('code', '');
        if ($code === '') {
            FlashMessage::set('error', 'Google n\'a renvoyé aucun code d\'autorisation.');

            return $this->redirect(self::LOCATIONS_URL);
        }

        $config = $this->configOf($location);
        $secret = $this->secretOf($location);

        try {
            $tokens = $this->client->exchangeCode(
                $config->clientId,
                $secret->clientSecret,
                $this->redirectUri(),
                $code
            );
            $about = $this->client->about($tokens['access_token']);
            $folderId = $this->client->ensureFolder(
                $tokens['access_token'],
                GoogleDriveLocationConfig::FOLDER_NAME
            );

            $this->locations->update(
                $location->id,
                $location->label,
                new GoogleDriveLocationConfig($config->clientId, $folderId, date('c')),
                $secret->withGrant($tokens['refresh_token'], $about['account'])->toStorage()
            );
        } catch (DriveAccessException $e) {
            $this->journalService->log(
                'storage',
                'storage_location_connect_failed',
                'warning',
                "Raccordement de l'emplacement « {$location->label} » refusé",
                // Two halves, and both are needed. `error` is the French
                // sentence the operator was shown, so the journal and the
                // screen agree; `detail` is Google's own answer, which
                // `UserFacingException` forbids displaying and which
                // travels as the cause for exactly this purpose. Without
                // it the entry cannot tell a mistyped client secret from a
                // withdrawn authorisation — the one distinction this
                // feature turns on.
                ['error' => $e->getMessage(), 'detail' => (string) $e->getPrevious()?->getMessage()],
                (int) AuthSession::getUserAccountId()
            );
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::LOCATIONS_URL);
        }

        // **Without the address.** It is the e-mail of a real person, and
        // AGENTS.md's security checklist keeps personal data out of the
        // journal — which is read on screen and travels in the support
        // archive. SECURITY.md's mail-probe intake sets the precedent:
        // « the journal counts mailboxes and names none of them ». The
        // location's card shows the account to the administrator who needs
        // it, from a column that is encrypted at rest.
        $this->journalService->log(
            'storage',
            'storage_location_connected',
            'security',
            "Compte Google Drive raccordé à l'emplacement « {$location->label} »",
            [],
            (int) AuthSession::getUserAccountId()
        );

        // Exercised straight away rather than assumed: the grant may be
        // good and the folder still unreachable, and an operator who has
        // just been told « raccordé » should not have to press a second
        // button to find that out.
        $refreshed = $this->locations->findById($location->id);
        if ($refreshed !== null) {
            $this->locationService->checkNow($refreshed);
        }

        FlashMessage::set('success', 'Compte Google Drive raccordé.');

        return $this->redirect(self::LOCATIONS_URL);
    }

    /**
     * POST — forgets the account and the client credentials.
     *
     * Disconnecting is what an operator does when they are handing the
     * site on, or when they no longer want it able to write into their
     * Drive. Keeping the client secret « in case » would defeat the point
     * of the button. **The location itself is not deleted**, and neither
     * is anything in the folder: a location is a declaration, and removing
     * a grant must not remove somebody's archives.
     *
     * @param array<string, string> $params
     */
    public function disconnect(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::LOCATIONS_URL)) !== null) {
            return $guard;
        }

        $location = $this->driveLocation($params);
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        // An empty record rather than null: null is the repository's
        // « leave the secret alone », which is the opposite of what this
        // button means. The empty JSON document is present and says the
        // three values are gone.
        $this->locations->update(
            $location->id,
            $location->label,
            new GoogleDriveLocationConfig(),
            (string) json_encode(['client_secret' => '', 'refresh_token' => '', 'account' => ''])
        );

        $this->journalService->log(
            'storage',
            'storage_location_disconnected',
            'security',
            "Compte Google Drive déraccordé de l'emplacement « {$location->label} »",
            [],
            (int) AuthSession::getUserAccountId()
        );
        FlashMessage::set('success', 'Compte Google déraccordé. Ce site ne peut plus écrire dans ce dossier.');

        return $this->redirect(self::LOCATIONS_URL);
    }

    /**
     * The address Google must send the browser back to, spelled exactly
     * once.
     *
     * It has to match the value registered in the operator's Google
     * project CHARACTER FOR CHARACTER — Google refuses the exchange on any
     * difference, a trailing slash included. So the screen shows this
     * string and the callback builds from this string: two spellings would
     * be a failure nobody could diagnose from either of them.
     *
     * **Empty when this site does not know its own address**, rather than
     * a bare path. `base_url` is the only source for it — never the
     * request's `HTTP_HOST`, which the caller supplies and which has no
     * business composing an OAuth redirect URI. Returning the bare path
     * would put a value on screen that Google's console refuses outright,
     * under a sentence telling the operator to register it character for
     * character.
     */
    public function redirectUri(): string
    {
        return self::redirectUriFor($this->baseUrl());
    }

    /**
     * The same string, for the screen that has to print it.
     *
     * Static and public because the form where an operator reads the
     * address to register in Google's console is rendered by
     * {@see StorageConfigController}, and this method's whole reason for
     * existing is that the two must not spell it differently.
     */
    public static function redirectUriFor(string $baseUrl): string
    {
        $base = rtrim($baseUrl, '/');

        return $base === '' ? '' : $base . self::REDIRECT_PATH;
    }

    private function baseUrl(): string
    {
        return (string) ($this->settings->get('base_url') ?: '');
    }

    /**
     * @param array<string, string> $params
     */
    private function driveLocation(array $params): ?StorageLocation
    {
        $location = $this->locations->findById((int) ($params['id'] ?? 0));

        return $location !== null && $location->type === StorageLocationType::GoogleDrive ? $location : null;
    }

    private function configOf(StorageLocation $location): GoogleDriveLocationConfig
    {
        $config = $location->config;

        return $config instanceof GoogleDriveLocationConfig ? $config : new GoogleDriveLocationConfig();
    }

    private function secretOf(StorageLocation $location): GoogleDriveSecret
    {
        return GoogleDriveSecret::fromStorage($this->locations->getSecret($location->id));
    }
}
