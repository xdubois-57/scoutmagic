<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Debug;

/**
 * A few minutes during which EVERY request journals its RequestTimeline —
 * opened by a superadmin from Configuration > Support, so that « le site
 * est lent » can be measured by the person who sees it, on the pages they
 * see it on, and shipped to the support with the next ticket
 * (Core\Support\Collector\RequestTimelinesCollector).
 *
 * The window is a flag file whose modification time is its expiry. That
 * is the whole state, and it is deliberately not a setting: the check
 * runs on every request of the site, before the database is connected,
 * before the settings exist, and the day the window is open is the day
 * the site is slow. One `filemtime()` on an absent file costs 2 µs and
 * the ordinary case — no window — does no other work at all. A file
 * whose time has passed is simply closed; nothing has to run to close it,
 * so a window nobody stopped cannot stay open.
 *
 * A second file counts the requests recorded, one byte each, and caps
 * them: a busy site during a fifteen-minute window would otherwise write
 * thousands of journal rows.
 *
 * What the window records is the server's time only — see
 * docs/chantiers/CHANTIER-performance.md §6 for what it cannot see.
 */
final class MeasurementWindow
{
    public const DEFAULT_MINUTES = 5;
    public const MAX_MINUTES = 15;
    public const MAX_REQUESTS = 500;

    public function __construct(
        private readonly string $flagPath,
        private readonly int $maxRequests = self::MAX_REQUESTS,
    ) {
    }

    /** Where the flag lives for an installation: alongside the other request-time caches. */
    public static function flagPathIn(string $storagePath): string
    {
        return $storagePath . '/temp/measure_until';
    }

    /**
     * The one question every request asks. One stat, no read.
     */
    public function isOpen(?int $now = null): bool
    {
        $expires = @filemtime($this->flagPath);

        return $expires !== false && $expires > ($now ?? time());
    }

    /**
     * Opens (or re-opens, restarting the count) the window for at most
     * MAX_MINUTES from now.
     *
     * @return \DateTimeImmutable when it closes
     */
    public function open(int $minutes = self::DEFAULT_MINUTES, ?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        $minutes = max(1, min(self::MAX_MINUTES, $minutes));
        $now ??= new \DateTimeImmutable();
        $expiresAt = $now->modify('+' . $minutes . ' minutes');

        $directory = dirname($this->flagPath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }
        // The content is for a reader (the Support page, the collector);
        // the modification time is for the request-time check.
        file_put_contents($this->flagPath, (string) json_encode([
            'opened_at' => $now->getTimestamp(),
            'expires_at' => $expiresAt->getTimestamp(),
        ]), LOCK_EX);
        touch($this->flagPath, $expiresAt->getTimestamp());
        @unlink($this->counterPath());
        clearstatcache(true, $this->flagPath);
        clearstatcache(true, $this->counterPath());

        return $expiresAt;
    }

    /**
     * Closes the window now. The count of what was recorded is kept, so
     * the Support page can still say how much the next archive carries.
     */
    public function close(): void
    {
        @unlink($this->flagPath);
        clearstatcache(true, $this->flagPath);
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        if (!$this->isOpen()) {
            return null;
        }
        $expires = @filemtime($this->flagPath);

        return $expires === false ? null : (new \DateTimeImmutable('@' . $expires));
    }

    public function openedAt(): ?\DateTimeImmutable
    {
        $content = @file_get_contents($this->flagPath);
        if ($content === false) {
            return null;
        }
        $decoded = json_decode($content, true);
        $openedAt = is_array($decoded) ? ($decoded['opened_at'] ?? null) : null;
        if (!is_int($openedAt)) {
            return null;
        }

        // A Unix timestamp this class wrote itself, never a stored
        // date string: the '@' form neither parses prose nor answers
        // "now" for an empty value.
        return new \DateTimeImmutable('@' . $openedAt);
    }

    /**
     * Counts one recorded request; false once the cap is reached, and the
     * caller then journals nothing for this one.
     */
    public function recordRequest(): bool
    {
        if ($this->recordedRequests() >= $this->maxRequests) {
            return false;
        }
        @file_put_contents($this->counterPath(), '.', FILE_APPEND | LOCK_EX);
        clearstatcache(true, $this->counterPath());

        return true;
    }

    /** How many requests the current (or last) window recorded. */
    public function recordedRequests(): int
    {
        $size = @filesize($this->counterPath());

        return $size === false ? 0 : (int) $size;
    }

    private function counterPath(): string
    {
        return $this->flagPath . '.count';
    }
}
