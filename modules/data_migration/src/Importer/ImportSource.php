<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

use Core\Archive\ArchiveRejectedException;
use Core\Archive\OpenedArchive;
use Modules\DataMigration\Manifest\Manifest;

/**
 * The source of one run, as an import reads it: the archive (opened under
 * its format's limits, its manifest already checked and every member's
 * SHA-256 verified before the analysis began), or a form source's kept
 * parameters and fetched file.
 */
final class ImportSource
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        private ?OpenedArchive $archive,
        public readonly ?Manifest $manifest,
        public readonly array $parameters = [],
        public readonly ?string $filePath = null
    ) {
    }

    /**
     * @throws \LogicException for a form source, which has no archive
     */
    public function archive(): OpenedArchive
    {
        return $this->archive ?? throw new \LogicException('This source is not an archive.');
    }

    /**
     * The decoded JSON lines of a member, by position, from `$from`. A line
     * that is not a JSON object comes back as null, so the import can yield
     * it as {@see SourceItem::unreadable()} at its own position.
     *
     * @return \Generator<int, array<string, mixed>|null>
     *
     * @throws ArchiveRejectedException
     */
    public function jsonLines(string $member, int $from = 0): \Generator
    {
        foreach ($this->archive()->lines($member, $from) as $position => $line) {
            $decoded = json_decode($line, true);
            yield $position => is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
        }
    }

    /**
     * One JSON member decoded as an object, or null.
     *
     * @return array<string, mixed>|null
     *
     * @throws ArchiveRejectedException
     */
    public function jsonObject(string $member, int $maxBytes = 4194304): ?array
    {
        $decoded = json_decode($this->archive()->read($member, $maxBytes), true);

        return is_array($decoded) && ($decoded === [] || !array_is_list($decoded)) ? $decoded : null;
    }
}
