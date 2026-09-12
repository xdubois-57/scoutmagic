<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Maintenance\Remote\GoogleDriveClient;
use Core\Maintenance\Remote\GoogleDriveTarget;
use Core\Maintenance\Remote\RemoteBackupConnection;
use Core\Maintenance\Remote\RemoteBackupException;
use Core\Security\AuthSession;
use Core\Security\SessionStore;
use Twig\Environment;

/**
 * Raccorder ce site à un espace de stockage distant.
 *
 * **Its own controller, rather than five more methods on
 * `MaintenanceController`.** That class is already past every PHPMD
 * threshold the project measures, and this feature is a self-contained
 * conversation with a third party: credentials in, a browser round trip
 * through Google, a connection out. The Maintenance page renders the
 * block; nothing else here belongs to it.
 *
 * **The callback is an authenticated route like every other.** It arrives
 * as a redirect from Google rather than from a form, which is exactly why
 * it is worth saying: an unauthenticated `/callback` that writes a refresh
 * token is a route where anybody who can craft a URL decides which Google
 * account this site backs up to. It sits behind the same `admin` floor as
 * the page that starts the flow, and the `state` parameter is checked
 * against the session on top of that.
 */
final class RemoteBackupController extends AbstractController
{
    private const STATE_SESSION_KEY = 'remote_backup_oauth_state';

    public function __construct(
        Environment $twig,
        private readonly RemoteBackupConnection $connection,
        private readonly JournalService $journalService,
        private readonly GoogleDriveClient $client = new GoogleDriveClient()
    ) {
        parent::__construct($twig);
    }

    /**
     * POST — the unit's own OAuth client credentials.
     *
     * @param array<string, string> $params
     */
    public function saveCredentials(Request $request, array $params): Response
    {
        $guard = $this->guardCsrf($request, '/config/maintenance');
        if ($guard !== null) {
            return $guard;
        }

        $clientId = trim((string) $request->getBody('client_id', ''));
        $clientSecret = trim((string) $request->getBody('client_secret', ''));
        if ($clientId === '' || $clientSecret === '') {
            FlashMessage::set('error', 'Renseignez l\'identifiant et le secret du client OAuth.');

            return $this->redirect('/config/maintenance#remote-backup');
        }

        try {
            $this->connection->saveCredentials($clientId, $clientSecret);
        } catch (RemoteBackupException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/config/maintenance#remote-backup');
        }

        $this->journalService->log(
            'core',
            'remote_backup_credentials_saved',
            'security',
            'Identifiants du client OAuth de la destination hors site enregistrés',
            [],
            AuthSession::getUserAccountId()
        );
        FlashMessage::set('success', 'Identifiants enregistrés. Vous pouvez maintenant raccorder le compte.');

        return $this->redirect('/config/maintenance#remote-backup');
    }

    /**
     * GET — leaves for Google's consent screen.
     *
     * @param array<string, string> $params
     */
    public function connect(Request $request, array $params): Response
    {
        if (!$this->connection->hasCredentials()) {
            FlashMessage::set('error', 'Enregistrez d\'abord l\'identifiant et le secret du client OAuth.');

            return $this->redirect('/config/maintenance#remote-backup');
        }

        // Random, single-use, and held in the session: this is what makes
        // the callback below refuse a URL somebody else composed.
        //
        // Through SessionStore, never `$_SESSION` directly:
        // public/index.php closes the session early, so a direct write is
        // silently lost — which here would mean the callback never finding
        // a state to compare against, and every raccordement being refused
        // for a reason nobody could see (ARCHITECTURE.md §8.20).
        $state = bin2hex(random_bytes(16));
        SessionStore::set(self::STATE_SESSION_KEY, $state);

        return $this->redirect($this->client->authorizationUrl(
            $this->connection->clientId(),
            $this->redirectUri($request),
            $state
        ));
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
        // Single-use: removed before anything is decided, so a state that
        // was replayed cannot match a second time.
        SessionStore::remove(self::STATE_SESSION_KEY);

        $state = (string) $request->getQuery('state', '');
        if ($expected === '' || !hash_equals($expected, $state)) {
            FlashMessage::set('error', 'Le retour de Google n\'a pas pu être vérifié. Recommencez le raccordement.');

            return $this->redirect('/config/maintenance#remote-backup');
        }

        // The operator pressed « Annuler » on Google's screen. Not an
        // error to shout about, and emphatically not one to journal as a
        // security event.
        $error = (string) $request->getQuery('error', '');
        if ($error !== '') {
            FlashMessage::set('error', 'Le raccordement a été annulé : Google n\'a pas accordé l\'autorisation.');

            return $this->redirect('/config/maintenance#remote-backup');
        }

        $code = (string) $request->getQuery('code', '');
        if ($code === '') {
            FlashMessage::set('error', 'Google n\'a renvoyé aucun code d\'autorisation.');

            return $this->redirect('/config/maintenance#remote-backup');
        }

        try {
            $tokens = $this->client->exchangeCode(
                $this->connection->clientId(),
                $this->connection->clientSecret(),
                $this->redirectUri($request),
                $code
            );
            $about = $this->client->about($tokens['access_token']);
            $folderId = $this->client->ensureFolder($tokens['access_token'], RemoteBackupConnection::FOLDER_NAME);

            $this->connection->saveConnection($tokens['refresh_token'], $about['account'], $folderId);
        } catch (RemoteBackupException $e) {
            $this->connection->recordFailure($e->getMessage());
            $this->journalService->log(
                'core',
                'remote_backup_connect_failed',
                'warning',
                'Raccordement de la destination hors site refusé',
                // A user-facing sentence by construction: RemoteBackupException
                // is a UserFacingException and the provider's own words
                // travel as the cause.
                ['error' => $e->getMessage()],
                AuthSession::getUserAccountId()
            );
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/config/maintenance#remote-backup');
        }

        $this->journalService->log(
            'core',
            'remote_backup_connected',
            'security',
            'Destination hors site raccordée : ' . $this->connection->account(),
            [],
            AuthSession::getUserAccountId()
        );
        FlashMessage::set('success', 'Compte Google Drive raccordé.');

        return $this->redirect('/config/maintenance#remote-backup');
    }

    /**
     * POST — writes a witness file and deletes it.
     *
     * @param array<string, string> $params
     */
    public function test(Request $request, array $params): Response
    {
        $guard = $this->guardCsrfJson($request);
        if ($guard !== null) {
            return $guard;
        }

        $check = (new GoogleDriveTarget($this->connection, $this->client))->testConnection();

        return $this->json([
            'success' => $check->ok,
            'message' => $check->message,
            'needs_reauthorisation' => $check->needsReauthorisation,
            'account' => $check->account,
            'quota_used' => $check->quota?->usedBytes,
            'quota_limit' => $check->quota?->limitBytes,
        ]);
    }

    /**
     * POST — forgets the account and the credentials.
     *
     * @param array<string, string> $params
     */
    public function disconnect(Request $request, array $params): Response
    {
        $guard = $this->guardCsrf($request, '/config/maintenance');
        if ($guard !== null) {
            return $guard;
        }

        $account = $this->connection->account();

        try {
            $this->connection->disconnect();
        } catch (RemoteBackupException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/config/maintenance#remote-backup');
        }

        $this->journalService->log(
            'core',
            'remote_backup_disconnected',
            'security',
            'Destination hors site déraccordée' . ($account !== '' ? ' : ' . $account : ''),
            [],
            AuthSession::getUserAccountId()
        );
        FlashMessage::set('success', 'Compte distant déraccordé. Ce site ne peut plus écrire chez Google.');

        return $this->redirect('/config/maintenance#remote-backup');
    }

    /**
     * The address Google must send the browser back to.
     *
     * Built from `base_url` when the site knows its own address and from
     * the request otherwise, because the value has to match the one
     * registered in the Google project CHARACTER FOR CHARACTER — Google
     * refuses the exchange on any difference, including a trailing slash.
     * The screen shows the same string so the operator can paste it rather
     * than retype it.
     */
    public function redirectUri(Request $request): string
    {
        if ($this->connection->baseUrl() !== '') {
            return $this->connection->redirectUri();
        }

        // A site that has not been told its own address yet — the state a
        // fresh installation is in before anyone saves the general
        // settings. Answering from the request keeps the flow usable
        // there; it is the screen's own displayed value that the operator
        // registers with Google, and that one comes from `base_url` too,
        // so the two only differ on a site where neither is settled.
        $scheme = ($request->getServer('HTTPS', '') !== '' && $request->getServer('HTTPS', '') !== 'off')
            ? 'https'
            : 'http';

        return $scheme . '://' . (string) $request->getServer('HTTP_HOST', 'localhost')
            . RemoteBackupConnection::REDIRECT_PATH;
    }
}
