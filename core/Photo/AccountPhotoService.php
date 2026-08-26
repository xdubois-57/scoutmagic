<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

use Core\File\FileRepository;

/**
 * The photo of an identified login — the account's own face, set from
 * "Mon compte" and drawn by the shared avatar (`person_avatar()`,
 * Core\View\TwigFactory) wherever the site shows who is connected.
 *
 * The twin of MemberPhotoService, for the other half of this codebase's
 * identity model (SECURITY.md §2): a MEMBER is someone Desk knows about
 * and whose photo belongs to a scout year; a LOGIN is an email address,
 * and its photo is simply theirs until they change it. An account with
 * no photo is not a gap to fill — the avatar draws initials, which is
 * what most people will keep.
 *
 * Replacing a photo deletes the one it replaces, row and stored object
 * both: an account that changes its picture five times must not leave
 * five files behind, and nothing else ever points at the old one (the
 * table holds exactly one row per account).
 */
class AccountPhotoService
{
    public function __construct(
        private AccountPhotoRepository $repository,
        // Both optional so an installation wiring only the read path (a
        // page that shows avatars and never sets one) needs neither. With
        // no repository/storage path the replaced file is simply left
        // alone rather than the change failing.
        private ?FileRepository $fileRepository = null,
        private string $storagePath = ''
    ) {
    }

    /**
     * Resolutions for the lifetime of this instance, misses included —
     * the nav alone asks for the connected account's avatar three times
     * per page.
     *
     * @var array<int, int|null>
     */
    private array $resolved = [];

    public function resolveFileId(int $userAccountId): ?int
    {
        if ($userAccountId <= 0) {
            return null;
        }

        if (!array_key_exists($userAccountId, $this->resolved)) {
            $this->resolved[$userAccountId] = $this->repository->findFileId($userAccountId);
        }

        return $this->resolved[$userAccountId];
    }

    /**
     * @param int[] $userAccountIds
     * @return array<int, int> account id => file id — one query for a
     *         whole page of people (see the repository's own docblock)
     */
    public function resolveFileIds(array $userAccountIds): array
    {
        $found = $this->repository->findFileIdsByAccountIds($userAccountIds);
        foreach ($userAccountIds as $id) {
            $this->resolved[(int) $id] = $found[(int) $id] ?? null;
        }

        return $found;
    }

    public function setPhoto(int $userAccountId, int $fileId): void
    {
        $this->forget($this->repository->upsert($userAccountId, $fileId));
        unset($this->resolved[$userAccountId]);
    }

    public function removePhoto(int $userAccountId): void
    {
        $this->forget($this->repository->delete($userAccountId));
        unset($this->resolved[$userAccountId]);
    }

    /**
     * Deletes a file nothing points at any more — the stored object
     * first, then the row, the same order (and for the same reason) as
     * every other deletion in this codebase: a row pointing at a missing
     * file reads as a missing file and is recoverable, an orphaned file
     * on disk is never found again.
     */
    private function forget(?int $fileId): void
    {
        if ($fileId === null || $this->fileRepository === null) {
            return;
        }

        $record = $this->fileRepository->findById($fileId);
        if ($record === null) {
            return;
        }

        if ($this->storagePath !== '') {
            $path = $this->storagePath . '/' . $record->relativePath;
            if (is_file($path)) {
                @unlink($path);
            }

            // …and its derivatives, which are siblings of the original
            // rather than `files` rows of their own
            // (ImageVariantService::siblingRelativePath()) and would
            // otherwise be the thing that actually accumulates: an
            // avatar is only ever served through its thumb.
            $directory = dirname($path);
            $base = pathinfo($record->relativePath, PATHINFO_FILENAME);
            foreach (ImageVariantService::VARIANTS as $variant) {
                $derivative = $directory . '/' . $base . '.' . $variant . '.webp';
                if (is_file($derivative)) {
                    @unlink($derivative);
                }
            }
        }

        $this->fileRepository->delete($fileId);
    }
}
