<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Remote;

/**
 * Google Drive over HTTP, by hand.
 *
 * **Why not `google/apiclient`.** What this feature needs from Google is a
 * form-encoded `POST` to refresh a token, a `PUT` carrying a
 * `Content-Range` header, and three `GET`s. The official library brings
 * Guzzle, PSR-7, PSR-18, a service-definition generator and some hundreds
 * of classes to reach that. `ARCHITECTURE.md` §1 asks a dependency to
 * justify itself, and this codebase already answers this exact question
 * three times over — `Core\Maintenance\GitHubReleaseClient`,
 * `Modules\SosStaff\Provider\Ovh\OvhApiClient` and the Anthropic provider
 * in `llm_connector` are all hand-written HTTP clients for third-party
 * APIs. This adds no Composer dependency.
 *
 * **The transport is injectable**, exactly as `OvhApiClient`'s is, and for
 * the same reason: none of this can be exercised against the real service
 * in a test suite — it would need a Google account, a project and a
 * published consent screen. Production leaves it null and gets
 * {@see defaultTransport()}.
 *
 * **What this class does NOT do**: decide anything. It has no idea where
 * the refresh token is kept, when it was last used, or what a backup is.
 * That is {@see GoogleDriveTarget} and {@see RemoteBackupConnection}.
 */
final class GoogleDriveClient
{
    /**
     * **`drive.file`, and never `drive`.**
     *
     * Two separate reasons, either of which would be enough.
     *
     * `drive.file` grants access only to files this application itself
     * created. A unit connecting its personal Google account is handing
     * over a key; this is the difference between a key to one drawer and a
     * key to the house, and the drawer is all a backup destination ever
     * needs.
     *
     * It is also a NON-SENSITIVE scope, which means Google does not
     * require the verification procedure — an annual security assessment
     * costing thousands of euros, which no scout unit will ever undergo.
     * Asking for `drive` would make this feature unusable in practice
     * while also making it far more dangerous, which is a rare combination.
     */
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    /**
     * How long a refresh token survives while the project's consent screen
     * is still in "Testing".
     *
     * Google expires these after seven days, silently. It is the single
     * most common way an integration of this kind dies: the backups stop
     * after a week and nobody notices until the day they are needed. The
     * screen says so in as many words, and this constant is where that
     * number comes from so the two cannot drift.
     */
    public const TESTING_TOKEN_LIFETIME_DAYS = 7;

    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD_BASE = 'https://www.googleapis.com/upload/drive/v3';
    private const TIMEOUT = 30;

    /** Each `PUT` of a resumable upload. Google requires a multiple of 256 KiB. */
    private const UPLOAD_CHUNK_BYTES = 8 * 1024 * 1024;

    /**
     * @param (\Closure(string, string, array<string, string>, ?string): array{status: int, body: string})|null $transport
     */
    public function __construct(private ?\Closure $transport = null)
    {
    }

    /**
     * Where to send the operator's browser to ask for consent.
     *
     * `access_type=offline` with `prompt=consent` is what makes Google
     * return a REFRESH token rather than an hour-long access token: this
     * application has to work at three in the morning with nobody at the
     * keyboard, and `prompt=consent` is required because Google issues the
     * refresh token only on a first grant — without it, an operator
     * reconnecting an account they had already authorised would come back
     * with nothing to store and no error to explain it.
     */
    public function authorizationUrl(string $clientId, string $redirectUri, string $state): string
    {
        return self::AUTH_ENDPOINT . '?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Trades the one-shot code the browser came back with for a refresh
     * token.
     *
     * @return array{refresh_token: string, access_token: string, expires_in: int}
     * @throws RemoteBackupException
     */
    public function exchangeCode(string $clientId, string $clientSecret, string $redirectUri, string $code): array
    {
        $decoded = $this->postToken([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        $refreshToken = (string) ($decoded['refresh_token'] ?? '');
        if ($refreshToken === '') {
            // Reached when Google recognised an existing grant and reissued
            // only an access token — which `prompt=consent` above exists to
            // prevent. Saying so plainly beats storing an empty string and
            // failing a week later.
            throw RemoteBackupException::of(
                'Google n\'a pas fourni de jeton de rafraîchissement. Révoquez l\'accès de cette application dans '
                . 'votre compte Google, puis recommencez le raccordement.'
            );
        }

        return [
            'refresh_token' => $refreshToken,
            'access_token' => (string) ($decoded['access_token'] ?? ''),
            'expires_in' => (int) ($decoded['expires_in'] ?? 0),
        ];
    }

    /**
     * @return array{access_token: string, expires_in: int}
     * @throws RemoteBackupException
     */
    public function refreshAccessToken(string $clientId, string $clientSecret, string $refreshToken): array
    {
        $decoded = $this->postToken([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        $accessToken = (string) ($decoded['access_token'] ?? '');
        if ($accessToken === '') {
            throw RemoteBackupException::of('Google a répondu sans jeton d\'accès utilisable.');
        }

        return ['access_token' => $accessToken, 'expires_in' => (int) ($decoded['expires_in'] ?? 3600)];
    }

    /**
     * The account and its storage quota.
     *
     * @return array{account: string, quota: ?RemoteQuota}
     * @throws RemoteBackupException
     */
    public function about(string $accessToken): array
    {
        $decoded = $this->apiJson(
            'GET',
            self::API_BASE . '/about?fields=user(emailAddress),storageQuota(limit,usage)',
            $accessToken
        );

        $user = is_array($decoded['user'] ?? null) ? $decoded['user'] : [];
        $storage = is_array($decoded['storageQuota'] ?? null) ? $decoded['storageQuota'] : [];

        // `limit` is ABSENT, not zero, on an account with unlimited
        // storage — reading it as 0 would report a full disk to somebody
        // who has no disk to fill.
        $quota = isset($storage['limit'])
            ? new RemoteQuota((int) ($storage['usage'] ?? 0), (int) $storage['limit'])
            : null;

        return ['account' => (string) ($user['emailAddress'] ?? ''), 'quota' => $quota];
    }

    /**
     * Creates a folder and answers with its id, or finds the one this
     * application created before.
     *
     * Under `drive.file` the search can only ever see this application's
     * own files, so "is there already one" is a question about this
     * application's history and not about the operator's Drive.
     *
     * @throws RemoteBackupException
     */
    public function ensureFolder(string $accessToken, string $name): string
    {
        $query = sprintf(
            "mimeType='application/vnd.google-apps.folder' and trashed=false and name='%s'",
            str_replace("'", "\\'", $name)
        );
        $found = $this->apiJson(
            'GET',
            self::API_BASE . '/files?' . http_build_query(['q' => $query, 'fields' => 'files(id)', 'pageSize' => 1]),
            $accessToken
        );
        $files = is_array($found['files'] ?? null) ? $found['files'] : [];
        if (isset($files[0]['id'])) {
            return (string) $files[0]['id'];
        }

        $created = $this->apiJson(
            'POST',
            self::API_BASE . '/files?fields=id',
            $accessToken,
            (string) json_encode(['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder']),
            'application/json'
        );
        $id = (string) ($created['id'] ?? '');
        if ($id === '') {
            throw RemoteBackupException::of('Le dossier de destination n\'a pas pu être créé sur Google Drive.');
        }

        return $id;
    }

    /**
     * @return RemoteFile[]
     * @throws RemoteBackupException
     */
    public function listFiles(string $accessToken, string $folderId): array
    {
        $query = sprintf("'%s' in parents and trashed=false", str_replace("'", "\\'", $folderId));
        $decoded = $this->apiJson(
            'GET',
            self::API_BASE . '/files?' . http_build_query([
                'q' => $query,
                'fields' => 'files(id,name,size,createdTime)',
                'orderBy' => 'createdTime desc',
                'pageSize' => 100,
            ]),
            $accessToken
        );

        $files = [];
        foreach (is_array($decoded['files'] ?? null) ? $decoded['files'] : [] as $entry) {
            if (!is_array($entry) || !isset($entry['id'])) {
                continue;
            }
            $files[] = new RemoteFile(
                (string) $entry['id'],
                (string) ($entry['name'] ?? ''),
                (int) ($entry['size'] ?? 0),
                (string) ($entry['createdTime'] ?? '')
            );
        }

        return $files;
    }

    /** @throws RemoteBackupException */
    public function deleteFile(string $accessToken, string $fileId): void
    {
        $response = $this->send(
            'DELETE',
            self::API_BASE . '/files/' . rawurlencode($fileId),
            ['Authorization' => 'Bearer ' . $accessToken]
        );

        // 404 is success here: the caller asked for the file to be gone.
        if ($response['status'] === 404 || ($response['status'] >= 200 && $response['status'] < 300)) {
            return;
        }

        throw $this->errorFor($response, 'La suppression du fichier distant a échoué.');
    }

    /**
     * Sends a local file in pieces and answers with its Drive id.
     *
     * **Resumable, and streamed from disk.** A portable backup is the size
     * of the whole site; holding it in a PHP string to post it would make
     * the peak memory of a send depend on how much the unit has stored,
     * on exactly the shared hosting this feature is for. Google's
     * resumable protocol is also the only one that survives a connection
     * dropped halfway, which over a domestic upstream link is the ordinary
     * case rather than the exception.
     *
     * @throws RemoteBackupException
     */
    public function uploadFile(string $accessToken, string $folderId, string $localPath, string $remoteName): string
    {
        $size = @filesize($localPath);
        if ($size === false) {
            throw RemoteBackupException::of('Le fichier à envoyer est introuvable sur ce serveur.');
        }

        $session = $this->send(
            'POST',
            self::UPLOAD_BASE . '/files?uploadType=resumable&fields=id',
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type' => 'application/zip',
                'X-Upload-Content-Length' => (string) $size,
            ],
            (string) json_encode(['name' => $remoteName, 'parents' => [$folderId]])
        );
        if ($session['status'] < 200 || $session['status'] >= 300) {
            throw $this->errorFor($session, 'Google Drive a refusé de commencer l\'envoi.');
        }
        $sessionUrl = $session['location'] ?? '';
        if ($sessionUrl === '') {
            throw RemoteBackupException::of('Google Drive n\'a pas indiqué où envoyer le fichier.');
        }

        return $this->putChunks($sessionUrl, $localPath, $size);
    }

    /**
     * @throws RemoteBackupException
     */
    private function putChunks(string $sessionUrl, string $localPath, int $size): string
    {
        $handle = @fopen($localPath, 'rb');
        if ($handle === false) {
            throw RemoteBackupException::of('Le fichier à envoyer n\'a pas pu être lu sur ce serveur.');
        }

        try {
            $offset = 0;
            while ($offset < $size) {
                $chunk = (string) fread($handle, self::UPLOAD_CHUNK_BYTES);
                $length = strlen($chunk);
                if ($length === 0) {
                    throw RemoteBackupException::of('La lecture du fichier à envoyer s\'est interrompue.');
                }

                $response = $this->send('PUT', $sessionUrl, [
                    'Content-Length' => (string) $length,
                    'Content-Range' => sprintf('bytes %d-%d/%d', $offset, $offset + $length - 1, $size),
                ], $chunk);

                // 308 is Google saying "keep going" — the ordinary answer
                // to every piece but the last, and emphatically not an
                // error despite being outside the 2xx range.
                if ($response['status'] === 308) {
                    $offset += $length;
                    continue;
                }
                if ($response['status'] >= 200 && $response['status'] < 300) {
                    $decoded = json_decode($response['body'], true);
                    $id = is_array($decoded) ? (string) ($decoded['id'] ?? '') : '';
                    if ($id === '') {
                        throw RemoteBackupException::of('Google Drive a accepté le fichier sans en donner l\'identifiant.');
                    }

                    return $id;
                }

                throw $this->errorFor($response, 'L\'envoi vers Google Drive a échoué.');
            }
        } finally {
            fclose($handle);
        }

        throw RemoteBackupException::of('L\'envoi vers Google Drive s\'est terminé sans confirmation.');
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     * @throws RemoteBackupException
     */
    private function postToken(array $form): array
    {
        $response = $this->send(
            'POST',
            self::TOKEN_ENDPOINT,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($form)
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw $this->errorFor($response, 'Google a refusé la demande de jeton.');
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw RemoteBackupException::of('La réponse de Google est illisible.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     * @throws RemoteBackupException
     */
    private function apiJson(
        string $method,
        string $url,
        string $accessToken,
        ?string $body = null,
        ?string $contentType = null
    ): array {
        $headers = ['Authorization' => 'Bearer ' . $accessToken];
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        $response = $this->send($method, $url, $headers, $body);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw $this->errorFor($response, 'Google Drive a refusé la requête.');
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw RemoteBackupException::of('La réponse de Google Drive est illisible.');
        }

        return $decoded;
    }

    /**
     * Turns a refused response into the right kind of refusal.
     *
     * **The distinction that matters is "come back and reconnect" versus
     * "try again later"**, and Google spells the first one in two
     * different places: `invalid_grant` on the token endpoint when the
     * refresh token has been revoked or has expired after seven days of
     * a testing consent screen, and a bare 401 on the API when the access
     * token is no longer honoured. Both mean the same thing to the
     * operator.
     *
     * The provider's own body never reaches the message — it is English,
     * it names internals, and `UserFacingException` forbids it. It travels
     * as the cause, to the journal.
     *
     * @param array{status: int, body: string, location?: string} $response
     */
    private function errorFor(array $response, string $fallback): RemoteBackupException
    {
        $detail = new \RuntimeException('Google responded ' . $response['status'] . ': ' . substr($response['body'], 0, 500));

        if ($response['status'] === 401 || str_contains($response['body'], 'invalid_grant')) {
            return RemoteBackupException::revoked(
                'Google n\'accepte plus l\'autorisation de ce site. Reconnectez le compte Drive depuis cette page.',
                $detail
            );
        }
        if ($response['status'] === 403 && str_contains($response['body'], 'storageQuotaExceeded')) {
            return RemoteBackupException::of(
                'Le compte Google Drive raccordé n\'a plus assez d\'espace libre.',
                $detail
            );
        }

        return RemoteBackupException::of($fallback, $detail);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, location?: string}
     * @throws RemoteBackupException
     */
    private function send(string $method, string $url, array $headers, ?string $body = null): array
    {
        $transport = $this->transport ?? self::defaultTransport();

        return $transport($method, $url, $headers, $body);
    }

    /**
     * The real network, used whenever no transport was injected.
     *
     * cURL rather than `file_get_contents()`, unlike
     * `GitHubReleaseClient`: this one needs the `Location` header of the
     * resumable-session response, and a 308 that the stream wrapper
     * reports as a failure rather than as a status.
     *
     * @return \Closure(string, string, array<string, string>, ?string): array{status: int, body: string, location?: string}
     */
    public static function defaultTransport(): \Closure
    {
        return static function (string $method, string $url, array $headers, ?string $body): array {
            $handle = curl_init($url);
            if ($handle === false) {
                throw RemoteBackupException::of('Impossible d\'initialiser la requête vers Google.');
            }

            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }

            $location = '';
            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_HEADERFUNCTION => static function ($_handle, string $header) use (&$location): int {
                    if (stripos($header, 'location:') === 0) {
                        $location = trim(substr($header, 9));
                    }

                    return strlen($header);
                },
            ];
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($handle, $options);

            $responseBody = curl_exec($handle);
            if ($responseBody === false) {
                $error = curl_error($handle);
                curl_close($handle);

                // cURL's text is English and names TLS internals — carried
                // as the cause, never shown.
                throw RemoteBackupException::of(
                    'Google n\'a pas pu être contacté. Vérifiez que ce serveur peut sortir vers Internet.',
                    new \RuntimeException($error)
                );
            }
            \assert(is_string($responseBody));

            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);

            return ['status' => $status, 'body' => $responseBody, 'location' => $location];
        };
    }
}
