<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Protection;

/**
 * What a destination holds for one source — the document, in memory.
 *
 * **Nothing in the database is authoritative about a copy** (D12). This
 * document is, and it lives in the destination beside the files it
 * describes. Two consequences follow, and both are the point:
 *
 * - **A restore of this site's database changes nothing about a copy.**
 *   Restoring to T0 does not move the inventory back, because it is at T2
 *   next to the bytes. The grace period of D13 therefore keeps counting
 *   from when the file actually went missing, not from what a table
 *   happened to say.
 * - **A copy found on a disk in three years describes itself.** It names
 *   the installation's source location, when each file was copied, and
 *   what each one hashed to — without which it is a folder of files whose
 *   provenance nobody can establish.
 *
 * **It is held whole in memory**, and that is a real bound worth stating:
 * a gallery of 100 000 media makes a document of some tens of megabytes
 * before compression. That is affordable because it is read once per pass
 * by a background task and never by a request serving a page — and because
 * the alternative, a line-oriented format read as a stream, buys nothing
 * until an installation is an order of magnitude past anything this
 * application has met.
 */
final class StorageInventory
{
    public const FORMAT = 'scoutmagic-protection-inventory';
    public const FORMAT_VERSION = 1;

    /**
     * @param array<string, InventoryEntry> $entries keyed by object key
     */
    private function __construct(
        public readonly int $sourceLocationId,
        public readonly string $sourceLabel,
        public readonly int $destinationLocationId,
        private array $entries = []
    ) {
    }

    public static function empty(
        int $sourceLocationId,
        string $sourceLabel,
        int $destinationLocationId
    ): self {
        return new self($sourceLocationId, $sourceLabel, $destinationLocationId);
    }

    /**
     * Reads a document back, or null when it is not one.
     *
     * **Null rather than an exception, and never a partially-read
     * document.** The caller's next move on null is to treat the
     * destination as holding nothing it can account for, which is the safe
     * reading: it copies again. A half-parsed inventory, by contrast, is
     * an inventory that under-reports what is at the destination — and
     * under-reporting is what makes a pass delete a file it should have
     * kept.
     */
    public static function fromJson(string $json): ?self
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        if (($decoded['format'] ?? null) !== self::FORMAT) {
            return null;
        }
        // A document from a LATER version is refused rather than read
        // partially: this code cannot know which of its fields changed
        // meaning, and guessing wrong deletes files.
        if ((int) ($decoded['format_version'] ?? 0) > self::FORMAT_VERSION) {
            return null;
        }

        $entries = [];
        foreach ((array) ($decoded['entries'] ?? []) as $key => $raw) {
            if (is_string($key) && $key !== '' && is_array($raw)) {
                $entries[$key] = InventoryEntry::fromArray($raw);
            }
        }

        return new self(
            (int) ($decoded['source_location_id'] ?? 0),
            (string) ($decoded['source_label'] ?? ''),
            (int) ($decoded['destination_location_id'] ?? 0),
            $entries
        );
    }

    public function toJson(\DateTimeImmutable $generatedAt): string
    {
        $entries = [];
        foreach ($this->entries as $key => $entry) {
            $entries[$key] = $entry->toArray();
        }
        // Sorted, so that two passes that changed nothing produce two
        // identical documents — which is what lets the store skip a write
        // rather than rewriting megabytes every night for nothing.
        ksort($entries);

        $json = json_encode([
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'source_location_id' => $this->sourceLocationId,
            'source_label' => $this->sourceLabel,
            'destination_location_id' => $this->destinationLocationId,
            'generated_at' => $generatedAt->format(\DateTimeInterface::ATOM),
            'entries' => $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json !== false ? $json : '';
    }

    /**
     * The document without its timestamp — what « has anything changed? »
     * is decided on.
     *
     * The generation date is excluded on purpose: it changes every pass by
     * definition, so comparing documents that carry it would find a
     * difference every night and rewrite the whole inventory every night.
     */
    public function fingerprint(): string
    {
        $entries = [];
        foreach ($this->entries as $key => $entry) {
            $entries[$key] = $entry->toArray();
        }
        ksort($entries);

        return hash('sha256', (string) json_encode($entries));
    }

    /**
     * @return array<string, InventoryEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function get(string $key): ?InventoryEntry
    {
        return $this->entries[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function put(string $key, InventoryEntry $entry): void
    {
        $this->entries[$key] = $entry;
    }

    public function remove(string $key): void
    {
        unset($this->entries[$key]);
    }

    /**
     * Every key the destination holds a COMPLETE copy of.
     *
     * @return list<string>
     */
    public function completeKeys(): array
    {
        $keys = [];
        foreach ($this->entries as $key => $entry) {
            if ($entry->isComplete()) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
