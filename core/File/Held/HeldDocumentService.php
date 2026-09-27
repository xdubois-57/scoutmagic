<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File\Held;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalService;

/**
 * Puts a file aside for the installed application, and hands it back
 * through one of two keys (issue #502).
 *
 * **Why a file is put aside at all.** In the installed application the
 * window has no address bar and no back button; a navigation that ends on
 * a file leaves it there, and on iOS the only way out is to kill the app.
 * InstalledAppFileInterceptor therefore never lets such a response reach
 * the window: it hands the bytes to {@see hold()} and shows a viewer page
 * instead. The viewer needs the file to still exist a moment later, and a
 * document generated from a form cannot simply be asked for again.
 *
 * **Why two keys.** The app does not share its session with the phone's
 * browser, so « Ouvrir dans le navigateur » needs an address that works
 * without one: the BROWSER key, served once, for five minutes, to whoever
 * holds it. « Télécharger » runs inside the app, where the session exists;
 * it gets its own APPLICATION key, bound to that session, so that it never
 * spends the browser's single use. Both are 256 random bits, and only their
 * SHA-256 is stored.
 *
 * **Both keys exist before the click.** iOS refuses a `window.open()` that
 * follows a wait, so the viewer cannot ask for an address when the button
 * is pressed: the address is minted with the page and merely opened.
 *
 * The file itself is stored encrypted through
 * {@see EncryptedFileStorageService}, under an owner type no
 * FileOwnershipChecker supports: /files/{id} refuses it to everyone, a
 * super-administrator included (FileAccessGuard is fail-closed on an
 * unknown owner type). SECURITY.md § 6 lists this route as a deliberate
 * exception and says how far it goes.
 */
class HeldDocumentService
{
    public const OWNER_TYPE = 'held_document';

    public const BROWSER_ROUTE_PREFIX = '/document/';
    public const APP_ROUTE_PREFIX = '/document/telecharger/';

    /** How long « Ouvrir dans le navigateur » works — a tap away, in practice. */
    public const BROWSER_LIFETIME_MINUTES = 5;

    /** How long the viewer page's own buttons work, and when the purge may take the file. */
    public const LIFETIME_MINUTES = 30;

    /**
     * The largest file put aside. It is encrypted in one piece
     * (EncryptedFileStorageService), so the whole of it is in memory twice
     * at the peak — plain and encrypted. Half of the smallest realistic
     * memory_limit, the same bound Core\Storage\Location\Protection\
     * ProtectedCopier uses. Above it, the viewer says so rather than
     * stranding the window (InstalledAppFileInterceptor).
     */
    public const MAX_BYTES = 64 * 1024 * 1024;

    /** Where the encrypted files live, under the site's storage/. */
    public const DIRECTORY = 'held-documents';

    public function __construct(
        private readonly HeldDocumentRepository $documents,
        private readonly EncryptedFileStorageService $storage,
        private readonly FileRepository $files,
        private readonly JournalService $journal
    ) {
    }

    /**
     * Stores $content and mints its two keys.
     *
     * @param string $sessionId the session the application key is bound to
     */
    public function hold(
        string $content,
        string $mimeType,
        string $name,
        string $sessionId,
        ?int $userAccountId,
        \DateTimeImmutable $now
    ): HeldDocument {
        $browserToken = bin2hex(random_bytes(32));
        $appToken = bin2hex(random_bytes(32));

        $fileId = $this->storage->store(
            $content,
            $mimeType,
            $name,
            self::DIRECTORY,
            'superadmin',
            null,
            $userAccountId,
            null,
            // Owned from the first instant, so /files/{id} never sees it
            // unowned; 0 until the row exists, then the row's id.
            self::OWNER_TYPE,
            0
        );

        try {
            $id = $this->documents->create(
                $fileId,
                self::hash($browserToken),
                self::hash($appToken),
                self::hash($sessionId),
                $now,
                $now->modify('+' . self::BROWSER_LIFETIME_MINUTES . ' minutes'),
                $now->modify('+' . self::LIFETIME_MINUTES . ' minutes')
            );
            $this->storage->assignOwner($fileId, self::OWNER_TYPE, $id);
        } catch (\Throwable $e) {
            // No row points at the file: nothing would ever purge it.
            $this->storage->delete($fileId);
            throw $e;
        }

        $this->journal->log(
            'core',
            'held_document_created',
            'info',
            'Document mis de côté pour l\'application installée',
            ['held_document_id' => $id, 'mime_type' => $mimeType, 'size_bytes' => strlen($content)],
            $userAccountId
        );

        return new HeldDocument($id, $browserToken, $appToken, $name, $mimeType, strlen($content));
    }

    /**
     * The document a browser key opens — once. Unknown, malformed, expired
     * and already used all answer null, alike.
     */
    public function openInBrowser(string $token, \DateTimeImmutable $now): ?OpenedDocument
    {
        if (!self::isWellFormed($token)) {
            return null;
        }

        $row = $this->documents->claimForBrowser(self::hash($token), $now);
        if ($row === null) {
            return null;
        }

        return $this->open($row, 'browser');
    }

    /**
     * The document an application key opens, for the session it was minted
     * for. Any number of times until it expires: the preview and the
     * download both use it.
     */
    public function openInApp(string $token, string $sessionId, \DateTimeImmutable $now): ?OpenedDocument
    {
        if (!self::isWellFormed($token) || $sessionId === '') {
            return null;
        }

        $row = $this->documents->findForApp(self::hash($token), self::hash($sessionId), $now);
        if ($row === null) {
            return null;
        }

        return $this->open($row, 'app');
    }

    /**
     * Deletes every expired document, opened or not: file first, then row.
     *
     * @return int how many were deleted
     */
    public function purgeExpired(\DateTimeImmutable $now): int
    {
        $count = 0;
        foreach ($this->documents->findExpired($now) as $row) {
            $this->storage->delete($row['file_id']);
            $this->documents->delete($row['id']);
            $count++;
        }

        return $count;
    }

    /**
     * @param array{id: int, file_id: int} $row
     */
    private function open(array $row, string $channel): ?OpenedDocument
    {
        $file = $this->files->findById($row['file_id']);
        if ($file === null) {
            return null;
        }

        try {
            $content = $this->storage->retrieve($row['file_id']);
        } catch (\RuntimeException) {
            return null;
        }

        // The id and the channel, nothing else: the key is the key, and
        // a journal line is no place for one.
        $this->journal->log(
            'core',
            'held_document_opened',
            'info',
            'Document mis de côté ouvert',
            ['held_document_id' => $row['id'], 'channel' => $channel]
        );

        return new OpenedDocument($content, $file->originalName, $file->mimeType);
    }

    private static function isWellFormed(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
