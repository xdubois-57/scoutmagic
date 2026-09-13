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
 * Connecting this site to somewhere off-server that can hold its
 * backups.
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

        // Before Google is involved at all: the redirect URI is composed
        // from this site's own address, and there is no honest way to
        // compose it without one.
        $refusal = $this->refuseWithoutSiteAddress();
        if ($refusal !== null) {
            return $refusal;
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
            $this->connection->redirectUri(),
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
                $this->connection->redirectUri(),
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
                // Two halves, and both are needed. `error` is the French
                // sentence the operator was shown, so the journal and the
                // screen agree; `detail` is Google's own answer, which
                // `UserFacingException` forbids displaying and which
                // travels as the cause for exactly this purpose. Without
                // it the entry cannot tell a mistyped client secret from
                // a withdrawn authorisation — the one distinction this
                // feature turns on.
                ['error' => $e->getMessage(), 'detail' => (string) $e->getPrevious()?->getMessage()],
                AuthSession::getUserAccountId()
            );
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/config/maintenance#remote-backup');
        }

        // **Without the address.** It is the e-mail of a real person, and
        // AGENTS.md's security checklist keeps personal data out of the
        // journal — which is read on screen and travels in the support
        // archive. SECURITY.md's mail-probe intake sets the precedent:
        // « the journal counts mailboxes and names none of them ». The
        // Maintenance page shows the account to the administrator who
        // needs it, from a store that is encrypted at rest.
        $this->journalService->log(
            'core',
            'remote_backup_connected',
            'security',
            'Destination hors site raccordée',
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

        // **A failed test leaves a trace, a successful one does not.** The
        // button can be pressed any number of times and a journal full of
        // « le test a réussi » is a journal nobody reads. A failure is the
        // opposite: it is what the operator will be looking for later, and
        // `GoogleDriveTarget` writes no entry of its own — so if this does
        // not, « consultez le journal du site » points at nothing.
        //
        // Nobody is named: `$check->account` is only populated on success,
        // and this branch never runs then.
        if (!$check->ok) {
            $this->journalService->log(
                'core',
                'remote_backup_test_failed',
                'warning',
                'Test de la destination hors site échoué',
                ['error' => $check->message, 'detail' => $check->detail],
                AuthSession::getUserAccountId()
            );
        }

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
            'Destination hors site déraccordée',
            [],
            AuthSession::getUserAccountId()
        );
        FlashMessage::set('success', 'Compte distant déraccordé. Ce site ne peut plus écrire chez Google.');

        return $this->redirect('/config/maintenance#remote-backup');
    }

    /**
     * Refuses to start when this site does not know its own address.
     *
     * **There used to be a fallback here, built from the request's
     * `HTTP_HOST`, and removing it is the fix rather than a
     * simplification.** Two things were wrong with it. The Host header is
     * supplied by whoever made the request — `InstallationProfile` and
     * `BackgroundExecutionCollector` both say it in as many words,
     * « la SEULE source de l'adresse du site, ici comme ailleurs : jamais
     * HTTP_HOST » — and an OAuth redirect URI is the last place to take an
     * attacker's word for where a browser should be sent back to. And it
     * silently disagreed with the screen: the page renders
     * {@see RemoteBackupConnection::redirectUri()}, which has no fallback,
     * so on a site with no `base_url` the operator was told to register a
     * bare path Google's console will not even accept, while the flow sent
     * an absolute URL built from the header. Google answers that with
     * `redirect_uri_mismatch`, and nothing on either side says why.
     *
     * One spelling, then, which is what that method's docblock always
     * promised — and when there is none to spell, an answer the operator
     * can act on instead of a mismatch they cannot diagnose.
     */
    private function refuseWithoutSiteAddress(): ?Response
    {
        if ($this->connection->baseUrl() !== '') {
            return null;
        }

        FlashMessage::set('error', 'Ce site ne connaît pas encore sa propre adresse. Renseignez-la dans '
            . 'Configuration > Réglages (« Adresse du site ») avant de raccorder un compte Google : c\'est elle '
            . 'qui compose l\'adresse de redirection à déclarer chez Google.');

        return $this->redirect('/config/maintenance#remote-backup');
    }
}
