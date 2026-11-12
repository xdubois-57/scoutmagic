<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

/**
 * The opaque identifier every file is served under (issue #1109): the
 * `/files/{public_id}` in an address, never the sequential `files.id`.
 *
 * `files.id` is an AUTO_INCREMENT. With it in the address, anybody above
 * a file's `role_min` reached every other file at that floor by counting
 * — every child's photo thumbnail for any signed-in parent, every image
 * of an article for an anonymous visitor. `FileAccessGuard` stays the
 * boundary; the opacity closes the enumeration, so a visitor only reaches
 * files whose address appeared on a page they were allowed to see.
 *
 * - **What it is**: 16 bytes from `random_bytes()` (128 bits), base64url
 *   without padding — 22 characters of `[A-Za-z0-9_-]`. Never derived
 *   from `files.id`, never written to the journal.
 * - **Where it comes from**: `FileRepository::create()` mints it with the
 *   row; rows that predate the column get one from a scheduled task
 *   (`Core\File\Task\BackfillFilePublicIdsHandler`), a slice per cron pass.
 * - **How addresses are built**: `urlFor()`, which `file_url()` in Twig
 *   calls. It still takes the numeric id every caller already has, and
 *   resolves it through the repository it was given, once per id per
 *   request — one indexed lookup per distinct file; `prime()` resolves a
 *   list in one query for a page that has its ids at hand.
 */
final class FilePublicIds
{
    /** 16 random bytes, base64url without padding. */
    public const LENGTH = 22;

    private const PATTERN = '/\A[A-Za-z0-9_-]{22}\z/';

    private static ?FileRepository $repository = null;

    /** @var array<int, ?string> */
    private static array $resolved = [];

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    /**
     * Whether $value can be a public id at all — checked before any query,
     * so a malformed segment costs nothing.
     */
    public static function isWellFormed(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * Wired once at boot (`public/index.php`). Resets what was resolved
     * under any earlier repository, which is what a test needs too.
     */
    public static function useRepository(?FileRepository $repository): void
    {
        self::$repository = $repository;
        self::$resolved = [];
    }

    /**
     * Resolve a page's ids in one query before rendering it.
     *
     * @param iterable<int|string|null> $fileIds
     */
    public static function prime(iterable $fileIds): void
    {
        if (self::$repository === null) {
            return;
        }

        $missing = [];
        foreach ($fileIds as $fileId) {
            $id = (int) $fileId;
            if ($id > 0 && !array_key_exists($id, self::$resolved)) {
                $missing[$id] = $id;
            }
        }

        if ($missing === []) {
            return;
        }

        $found = self::$repository->publicIdsFor(array_values($missing));
        foreach ($missing as $id) {
            self::$resolved[$id] = $found[$id] ?? null;
        }
    }

    /**
     * The public id of file $fileId, or null when there is no such file or
     * it has none yet.
     */
    public static function forFileId(int $fileId): ?string
    {
        if ($fileId <= 0) {
            return null;
        }

        self::prime([$fileId]);

        return self::$resolved[$fileId] ?? null;
    }

    /**
     * The numeric id of the file served under $publicId, or null for none
     * or a malformed value — for code that reads stored addresses back
     * (an article's body images). Remembered for the request, both ways.
     */
    public static function fileIdOf(string $publicId): ?int
    {
        if (self::$repository === null || !self::isWellFormed($publicId)) {
            return null;
        }

        $known = array_search($publicId, self::$resolved, true);
        if (is_int($known)) {
            return $known;
        }

        $id = self::$repository->findByPublicId($publicId)?->id;
        if ($id !== null) {
            self::$resolved[$id] = $publicId;
        }

        return $id;
    }

    /**
     * The address of file $fileId: `/files/{public_id}`, plus `/{variant}`
     * when given. Empty for no file.
     *
     * Falls back to the numeric address while a file has no public id yet
     * (before the backfill has reached it, or with no repository wired):
     * the numeric route still answers during the transition.
     */
    public static function urlFor(int|string|null $fileId, ?string $variant = null): string
    {
        $id = (int) $fileId;
        if ($id <= 0) {
            return '';
        }

        $suffix = $variant !== null ? '/' . $variant : '';

        return '/files/' . (self::forFileId($id) ?? (string) $id) . $suffix;
    }
}
