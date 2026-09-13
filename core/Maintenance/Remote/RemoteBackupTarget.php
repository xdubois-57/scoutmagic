<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Remote;

/**
 * Somewhere off this server that a backup can be left.
 *
 * **Why an interface for one implementation.** Google Drive is the only
 * destination today, and a second one is not planned — so the interface
 * earns its place on two other grounds. The first is precedent: the
 * gallery already stores its renditions behind
 * `Modules\Gallery\Service\Storage\StorageBackendInterface`, with a local
 * disk and an S3 bucket behind it, and a destination for files is exactly
 * that shape of problem. The second is testability, which is not a
 * nicety here: everything below crosses the network to a service that
 * requires a Google account, a project and a consent screen. A suite that
 * could only exercise the real thing would exercise nothing —
 * `Core\Maintenance\GitHubReleaseClientInterface` exists in this codebase
 * for the same reason and is faked in every test that touches an update.
 *
 * **What this interface deliberately does not know.** Nothing here
 * mentions OAuth, tokens, folders or Drive: a target is *already*
 * connected by the time a caller holds one. Connecting is
 * {@see RemoteBackupConnection}'s business, and keeping the two apart is
 * what lets the scheduled send of IT-09 depend on this interface without
 * inheriting a consent screen.
 */
interface RemoteBackupTarget
{
    /**
     * Sends a local file and answers with the identifier the destination
     * gave it.
     *
     * The identifier is what {@see delete()} takes, and the only handle
     * this application keeps: a remote file is never addressed by its
     * name, which the destination may change, duplicate or reject.
     *
     * @throws RemoteBackupException
     */
    public function upload(string $localPath, string $remoteName): string;

    /**
     * What this application has left there, newest first.
     *
     * Only files this application itself created — see
     * {@see GoogleDriveTarget} for why that is enforced by the OAuth scope
     * rather than by a filter here.
     *
     * @return RemoteFile[]
     * @throws RemoteBackupException
     */
    public function list(): array;

    /** @throws RemoteBackupException */
    public function delete(string $remoteId): void;

    /**
     * How much room the destination says is left, or null when it will not
     * say — an account with no quota at all answers this way, and so does
     * a destination that simply has no such notion.
     *
     * @throws RemoteBackupException
     */
    public function quota(): ?RemoteQuota;

    /**
     * Proves the connection works by actually using it: a small witness
     * file is written and then deleted.
     *
     * **Writing, not reading.** A destination that answers `about` happily
     * may still refuse every write — a revoked grant, a full account, a
     * folder that no longer exists — and an operator who clicked "test"
     * and saw a green tick would learn that on the night their server
     * burned down. The round trip costs a few hundred bytes.
     *
     * Never throws: a failed test is an answer, and this method exists to
     * give the operator that answer rather than an error page.
     */
    public function testConnection(): RemoteConnectionCheck;
}
