<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Journal;

class JournalService
{
    /** The widths of event_log's columns (schema/core.sql), in characters. */
    public const DESCRIPTION_MAX = 500;
    public const CATEGORY_MAX = 50;
    public const TYPE_MAX = 100;

    /** Written in place of a context that cannot be encoded at all. */
    private const UNENCODABLE_CONTEXT = '{"context_unencodable":true}';

    public function __construct(private JournalRepository $repository)
    {
    }

    /**
     * Log an event.
     *
     * @param string      $category    'core' or module_id
     * @param string      $type        e.g. 'login_success', 'import_desk'
     * @param string      $level       'info', 'security' or 'error' —
     *                                 the values of event_log.level.
     *                                 'error' is what Core\Http\ErrorHandler
     *                                 writes for an uncaught throwable.
     * @param string      $description Human-readable short description (no personal data!).
     *                                 Stored in a VARCHAR(500): this method cuts
     *                                 it to fit, by characters (issue #1438).
     * @param array<string, mixed> $context Optional JSON context
     * @param int|null    $userId      User account ID, null for system actions
     */
    public function log(
        string $category,
        string $type,
        string $level,
        string $description,
        array $context = [],
        ?int $userId = null
    ): void {
        // The one door every journal line goes through, so it is the one
        // place that makes a line fit (issue #1438). A description longer
        // than its VARCHAR(500) is refused by MySQL/MariaDB in strict mode
        // — SQLite, which the suite runs on, stores anything — and a byte
        // that is not UTF-8 makes json_encode() fail on every engine. Cut
        // by characters, never bytes: half an « é » is refused too.
        $description = self::fit($description, self::DESCRIPTION_MAX);
        $category = self::fit($category, self::CATEGORY_MAX);
        $type = self::fit($type, self::TYPE_MAX);

        $contextJson = null;
        if (!empty($context)) {
            // A JsonSerializable whose jsonSerialize() throws escapes
            // json_encode() whatever its flags: the line is still written,
            // with a marker in place of the context.
            try {
                $contextJson = self::encodeContext($context);
            } catch (\Throwable) {
                $contextJson = self::UNENCODABLE_CONTEXT;
            }
        }

        // Writing the journal never fails its caller. The caller is often
        // reporting a failure of its own — SchedulerRunner's catch block
        // was — and an exception from here replaced that failure with one
        // about the journal, and stopped whatever loop it was in.
        try {
            $this->repository->insert($category, $type, $level, $description, $contextJson, $userId, self::currentIp());
        } catch (\Throwable $e) {
            error_log(sprintf(
                'ScoutMagic journal: could not write %s/%s (%s): %s',
                $category,
                $type,
                $e::class,
                self::fit($e->getMessage(), 300)
            ));
        }
    }

    /**
     * $text made valid UTF-8 and at most $max characters long, an ellipsis
     * marking a cut.
     */
    /**
     * The context as JSON, its invalid bytes replaced; the marker when it
     * cannot be encoded at all. A JsonSerializable value whose
     * jsonSerialize() throws makes this throw too, which PHPStan cannot see
     * in json_encode()'s signature: the caller catches it.
     *
     * @param array<string, mixed> $context
     */
    private static function encodeContext(array $context): string
    {
        $encoded = json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded !== false ? $encoded : self::UNENCODABLE_CONTEXT;
    }

    public static function fit(string $text, int $max): string
    {
        $text = mb_scrub($text, 'UTF-8');
        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
    }

    /**
     * The newest entry of one event type, or null.
     *
     * @return array<string, mixed>|null
     */
    public function latest(string $category, string $type): ?array
    {
        return $this->repository->findLatestEvent($category, $type);
    }

    /**
     * Best-effort client IP for the current request. Null on CLI / when unavailable.
     */
    private static function currentIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /**
     * Clean up old entries based on retention setting.
     */
    public function cleanup(int $retentionDays): int
    {
        return $this->repository->deleteOlderThan($retentionDays);
    }
}
