<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

/**
 * One object of the old site, as an import read it.
 *
 * `$kind` and `$sourceId` are, with the run's installation and format,
 * the deduplication key (D12, widened with the kind: an article and its
 * form may share an id). `$reference` is what the preview and the report
 * show, and **must never carry a person's name** (D8): the old site's
 * reference, a desk id, a place's name — never a member's.
 */
final class SourceItem
{
    public const MAX_ID_BYTES = 190;
    public const MAX_KIND_BYTES = 50;

    /**
     * @param array<string, mixed> $data what the import read, for its own use
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $sourceId,
        public readonly array $data = [],
        public readonly ?string $reference = null,
        public readonly ?string $unreadableReason = null
    ) {
        if ($kind === '' || strlen($kind) > self::MAX_KIND_BYTES) {
            throw new \InvalidArgumentException('A source kind is 1 to 50 bytes.');
        }
    }

    /**
     * An item the import could not read at all — a line that is not JSON,
     * an object without its identifier. Counted as ignored with `$reason`.
     */
    public static function unreadable(string $kind, string $reference, string $reason): self
    {
        return new self($kind, '', [], $reference, $reason);
    }

    public function isUnreadable(): bool
    {
        return $this->unreadableReason !== null;
    }

    /** What the screens show for this item. */
    public function shownReference(): string
    {
        $reference = $this->reference ?? $this->sourceId;
        if (trim($reference) === '') {
            return '(sans identifiant)';
        }

        return mb_strlen($reference) > 120 ? mb_substr($reference, 0, 117) . '…' : $reference;
    }

    /**
     * A source identifier as the conventions require it: a JSON string,
     * non-empty, at most 190 bytes. A number is refused rather than
     * converted — `007` and `7` are different identifiers, and a JSON
     * number has already lost the difference.
     */
    public static function idFrom(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_ID_BYTES) {
            return null;
        }

        return $value;
    }
}
