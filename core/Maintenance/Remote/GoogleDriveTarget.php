<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Remote;

/**
 * Google Drive as a place to leave backups.
 *
 * Sits between {@see GoogleDriveClient}, which knows HTTP and nothing
 * else, and {@see RemoteBackupConnection}, which knows where the
 * credentials are kept. Its own job is the part neither of them can do
 * alone: turning a stored refresh token into a usable access token, and
 * turning a withdrawn grant into a state the site can display.
 *
 * **The access token is never stored.** It lives for an hour, and a
 * `settings` row holding one would be a credential on the generic
 * settings page for no benefit — the refresh costs a single request, and a
 * backup send makes dozens. It is cached in memory for the life of the
 * object, which covers exactly the one operation a caller performs.
 */
final class GoogleDriveTarget implements RemoteBackupTarget
{
    private const WITNESS_NAME = 'scoutmagic-test.txt';

    private string $accessToken = '';

    public function __construct(
        private readonly RemoteBackupConnection $connection,
        private readonly GoogleDriveClient $client
    ) {
    }

    public function upload(string $localPath, string $remoteName): string
    {
        return $this->client->uploadFile($this->accessToken(), $this->folderId(), $localPath, $remoteName);
    }

    /** @return RemoteFile[] */
    public function list(): array
    {
        return $this->client->listFiles($this->accessToken(), $this->folderId());
    }

    public function delete(string $remoteId): void
    {
        $this->client->deleteFile($this->accessToken(), $remoteId);
    }

    public function quota(): ?RemoteQuota
    {
        return $this->client->about($this->accessToken())['quota'];
    }

    /**
     * The provider's own words, for the journal and never for the screen.
     *
     * `UserFacingException` forbids showing them — they are English and
     * name internals — so they travel as the exception's cause. Reading
     * them back is what turns that cause from decoration into a
     * diagnosis: the difference between a mistyped client secret and a
     * withdrawn authorisation is invisible in the French sentence, and it
     * is the difference this feature turns on.
     */
    private function detailOf(\Throwable $error): string
    {
        return (string) $error->getPrevious()?->getMessage();
    }

    /**
     * Writes a witness file and deletes it again.
     *
     * Both deletions are in a `finally` — the local temporary file and
     * the remote witness. A test that wrote its witness and then failed
     * anywhere after would otherwise leave one behind on every press, and
     * the operator would find a Drive folder filling with the evidence of
     * their own troubleshooting.
     */
    public function testConnection(): RemoteConnectionCheck
    {
        // **Before anything is recorded, and the two silences are not one
        // silence.** This route is reachable whenever an administrator is,
        // so a test with no usable grant must answer rather than write.
        // What makes the call impossible is the missing refresh token —
        // not the label stored beside it — so that is what is read here;
        // the state only decides which of the two sentences is true.
        //
        // Keeping them apart is the whole point. A site that was never
        // connected would otherwise be recorded as « à reconnecter » about
        // a connection that never existed, and a site genuinely waiting to
        // be reconnected — whose token `markNeedsReauthorisation()` has
        // deliberately dropped — would have Google's own reason replaced
        // by « aucun compte », with the flag that tells the page to reload
        // itself left false.
        if ($this->connection->refreshToken() === '') {
            if ($this->connection->state() === RemoteBackupConnection::STATE_NEEDS_REAUTH) {
                $reason = $this->connection->lastError();

                return RemoteConnectionCheck::revoked($reason !== ''
                    ? $reason
                    : 'Google n\'accepte plus l\'autorisation de ce site : il faut le reconnecter.');
            }

            return RemoteConnectionCheck::failure('Aucun compte Google Drive n\'est raccordé à ce site.');
        }

        $witness = null;
        $remoteId = null;

        try {
            $about = $this->client->about($this->accessToken());
            $folderId = $this->folderId();

            $witness = tempnam(sys_get_temp_dir(), 'sm_drive_');
            if ($witness === false) {
                return RemoteConnectionCheck::failure('Ce serveur n\'a pas pu créer le fichier témoin du test.');
            }
            file_put_contents($witness, 'ScoutMagic — test de raccordement ' . date('c') . "\n");

            $remoteId = $this->client->uploadFile($this->accessToken(), $folderId, $witness, self::WITNESS_NAME);

            $this->connection->clearFailure();

            return RemoteConnectionCheck::success($about['account'], $about['quota']);
        } catch (RemoteBackupException $e) {
            // The state, not just the message: an operator who reads
            // « reconnectez le compte » must find the page agreeing with
            // that sentence the next time they open it.
            //
            // Recording it can itself fail — a site whose `secrets.enc`
            // has become unreadable cannot drop a token — and this method
            // promises never to throw. The operator still gets the
            // diagnosis, which is what they pressed the button for.
            if ($e->needsReauthorisation) {
                try {
                    $this->connection->markNeedsReauthorisation($e->getMessage());
                } catch (\Throwable) {
                    // The answer below is the deliverable, not the bookkeeping.
                }

                return RemoteConnectionCheck::revoked($e->getMessage(), $this->detailOf($e));
            }

            try {
                $this->connection->recordFailure($e->getMessage());
            } catch (\Throwable) {
                // Same.
            }

            return RemoteConnectionCheck::failure($e->getMessage(), $this->detailOf($e));
        } catch (\Throwable $e) {
            // **The contract is "never throws", and only this makes it
            // true.** Everything above that talks to Google raises
            // `RemoteBackupException`, but the calls that write down what
            // happened talk to this site's own `settings`, and a database
            // that has gone away raises something else entirely. Without
            // this branch, pressing a diagnostic button on a site with a
            // sick database answers with a stack trace instead of a
            // diagnosis — and the witness on the operator's Drive would
            // survive the request that created it.
            //
            // The site's own failure is not reported as Google's: nothing
            // here says the account is at fault, and no state is written,
            // because writing state is the very thing that just failed.
            return RemoteConnectionCheck::failure(
                'Le test n\'a pas pu être mené à son terme sur ce serveur. Le raccordement n\'est pas en cause ; '
                . 'consultez le journal du site.',
                // Bound and carried, because the sentence above sends the
                // operator to the journal: a message promising an entry
                // that nobody writes is worse than no message.
                $e->getMessage()
            );
        } finally {
            if (is_string($witness)) {
                @unlink($witness);
            }
            // **The remote one too, and here rather than in the `try`.**
            // A witness uploaded and then not removed — because the token
            // expired between the two calls, because Google answered 503 —
            // would stay in the operator's Drive, and every press of the
            // button would leave another. Guarded, because this method
            // promises never to throw: a witness left behind is a small
            // untidiness, an exception out of a diagnostic button is not.
            if (is_string($remoteId)) {
                try {
                    $this->client->deleteFile($this->accessToken(), $remoteId);
                } catch (\Throwable) {
                    // Nothing useful to tell the operator about it.
                }
            }
        }
    }

    /**
     * The folder this application writes into, created on first use.
     *
     * Looked up rather than assumed: an operator may have emptied their
     * trash, and under `drive.file` a folder this application cannot see
     * is a folder that no longer exists as far as it is concerned.
     *
     * @throws RemoteBackupException
     */
    private function folderId(): string
    {
        $known = $this->connection->folderId();
        if ($known !== '') {
            return $known;
        }

        return $this->client->ensureFolder($this->accessToken(), RemoteBackupConnection::FOLDER_NAME);
    }

    /**
     * @throws RemoteBackupException
     */
    private function accessToken(): string
    {
        if ($this->accessToken !== '') {
            return $this->accessToken;
        }

        $refreshToken = $this->connection->refreshToken();
        if ($refreshToken === '') {
            // `of()`, not `revoked()`: nothing was withdrawn. Marking this
            // as a revocation would have a caller record a state the site
            // was never in — see {@see testConnection()}.
            throw RemoteBackupException::of(
                'Aucun compte Google Drive n\'est raccordé à ce site.'
            );
        }

        $fresh = $this->client->refreshAccessToken(
            $this->connection->clientId(),
            $this->connection->clientSecret(),
            $refreshToken
        );
        $this->accessToken = $fresh['access_token'];

        return $this->accessToken;
    }
}
