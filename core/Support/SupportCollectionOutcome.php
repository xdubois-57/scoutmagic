<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support;

/**
 * What one collector achieved, as reported in `collection-status.json`.
 *
 * `unavailable` is a first-class outcome, not a softer `failed`: on chrooted
 * shared hosting several system collectors will report it on every single
 * run, forever, and that is the expected, correct answer — not a fault
 * anyone should be asked to investigate.
 */
final class SupportCollectionOutcome
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @param array<int, string> $notes
     */
    private function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly ?string $reason,
        public readonly int $durationMs,
        public readonly array $notes = []
    ) {
    }

    /**
     * @param array<int, string> $notes
     */
    public static function success(string $name, int $durationMs, array $notes = []): self
    {
        return new self($name, self::STATUS_SUCCESS, null, $durationMs, $notes);
    }

    /**
     * @param array<int, string> $notes
     */
    public static function failed(string $name, string $reason, int $durationMs, array $notes = []): self
    {
        return new self($name, self::STATUS_FAILED, $reason, $durationMs, $notes);
    }

    /**
     * @param array<int, string> $notes
     */
    public static function unavailable(string $name, string $reason, int $durationMs, array $notes = []): self
    {
        return new self($name, self::STATUS_UNAVAILABLE, $reason, $durationMs, $notes);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'status' => $this->status,
            'reason' => $this->reason,
            'duration_ms' => $this->durationMs,
        ];

        if ($this->notes !== []) {
            $data['notes'] = $this->notes;
        }

        return $data;
    }
}
