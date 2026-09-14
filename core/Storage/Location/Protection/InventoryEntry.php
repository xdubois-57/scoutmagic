<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Protection;

use Core\Service\DateInput;

/**
 * What the destination's inventory knows about one copied file.
 *
 * **`absentFromSourceSince` is an attribute of the FILE, not of the pass**
 * (D13). A pass that finds a file at the destination and not at the source
 * writes the date here and deletes nothing; the countdown then lives beside
 * the file it concerns, in the destination, which is what makes it
 * insensitive to any restore of this site's database. A counter kept in a
 * table would go backwards the day somebody restored last month's backup,
 * and a file deleted by mistake three weeks ago would get its grace period
 * again — or, worse the other way, a file whose countdown had been
 * restarted would be purged while an administrator believed it protected.
 */
final class InventoryEntry
{
    public function __construct(
        public readonly int $sizeBytes,
        public readonly ?string $md5 = null,
        public readonly ?string $copiedAt = null,
        public readonly ?string $absentFromSourceSince = null,
        /**
         * The upload session a partial copy can be resumed with, for the
         * backends that need one.
         *
         * **Local and WebDAV need nothing here**: the copy is written
         * under `key.part` and the size of that partial object IS the
         * resume offset — the state is the file. S3 and Drive refuse to
         * work that way and mint an identifier of their own, which is
         * information about a file of this location and therefore belongs
         * beside that file's entry rather than in a table of operations.
         */
        public readonly ?string $resumeSession = null,
        /**
         * When a pass last saw this key AT THE SOURCE.
         *
         * **This is what makes the sweep resumable without a second
         * store, and it is why it lives here rather than in a table.** A
         * pass stamps every key its listing meets; once the listing has
         * finished, whatever still carries an older stamp is what the
         * source no longer has. Keeping that set in the database instead
         * would mean holding a hundred thousand keys of working state —
         * and would put the decision to delete somebody's files on a
         * table that a restore can move backwards, which is exactly what
         * D12 puts the inventory in the destination to avoid.
         */
        public readonly ?string $lastSeenAt = null
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            sizeBytes: (int) ($raw['size'] ?? 0),
            md5: self::text($raw, 'md5'),
            copiedAt: self::text($raw, 'copied_at'),
            absentFromSourceSince: self::text($raw, 'absent_from_source_since'),
            resumeSession: self::text($raw, 'resume_session'),
            lastSeenAt: self::text($raw, 'last_seen_at')
        );
    }

    /**
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return [
            'size' => $this->sizeBytes,
            'md5' => $this->md5,
            'copied_at' => $this->copiedAt,
            'absent_from_source_since' => $this->absentFromSourceSince,
            'resume_session' => $this->resumeSession,
            'last_seen_at' => $this->lastSeenAt,
        ];
    }

    /**
     * Whether this entry describes a copy that finished.
     *
     * A partial one — a `.part` object with a resume session and no copy
     * date — is in the inventory precisely so the next pass can pick it
     * up, and must never be counted as a file that is protected.
     */
    public function isComplete(): bool
    {
        return $this->copiedAt !== null && $this->resumeSession === null;
    }

    /**
     * Whether the grace period has run out for a file the source no longer
     * has.
     *
     * False for a file the source still has, whatever the dates say: the
     * countdown only means anything once something started it.
     */
    public function graceExpired(int $gracePeriodDays, \DateTimeImmutable $now): bool
    {
        if ($this->absentFromSourceSince === null) {
            return false;
        }

        $since = self::parseDate($this->absentFromSourceSince);
        if ($since === null) {
            // An unreadable date is not a licence to delete. The next pass
            // writes a fresh one and the countdown starts again, which
            // costs disk; the alternative costs somebody their files.
            return false;
        }

        return $since->modify('+' . $gracePeriodDays . ' days') <= $now;
    }

    public function withAbsentSince(?string $timestamp): self
    {
        return new self(
            sizeBytes: $this->sizeBytes,
            md5: $this->md5,
            copiedAt: $this->copiedAt,
            absentFromSourceSince: $timestamp,
            resumeSession: $this->resumeSession,
            lastSeenAt: $this->lastSeenAt
        );
    }

    /**
     * Stamped as seen at the source by the pass that is running.
     *
     * **Seeing a key also clears its absence**, in one move rather than
     * two: a file that came back — restored from the site's own backup,
     * re-uploaded by whoever deleted it — must not keep a countdown from
     * the day it went missing, or it would be purged from the copy while
     * sitting in plain view at the source.
     */
    public function seenAt(string $timestamp): self
    {
        return new self(
            sizeBytes: $this->sizeBytes,
            md5: $this->md5,
            copiedAt: $this->copiedAt,
            absentFromSourceSince: null,
            resumeSession: $this->resumeSession,
            lastSeenAt: $timestamp
        );
    }

    /**
     * Whether a pass that started at $passStartedAt has met this key.
     *
     * An entry with no stamp at all has never been met by a pass that
     * records them — which is the state every entry written before this
     * field existed is in, and the honest answer for it is « not seen »,
     * since the sweep that follows only ever MARKS, never deletes.
     */
    public function seenBy(string $passStartedAt): bool
    {
        return $this->lastSeenAt !== null && $this->lastSeenAt >= $passStartedAt;
    }

    /**
     * A date out of the inventory document, or null.
     *
     * **Through DateInput rather than the raw constructor**, which fails
     * in both directions at once: it throws on a malformed string and
     * answers *now* for an empty one. The second is the dangerous half
     * here — an entry whose absence date silently became today would have
     * its grace period restart every night, so a file deleted at the
     * source months ago would never leave the copy, and nothing would say
     * why. ISO 8601 is what {@see toArray()} writes, so that is what is
     * read back.
     */
    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        return DateInput::iso($value) ?? DateInput::fromStorage($value);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function text(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
