<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Support;

use Core\Archive\ArchiveLimits;
use Modules\DataMigration\Importer\AnalysisSummary;
use Modules\DataMigration\Importer\ArchiveFormat;
use Modules\DataMigration\Importer\ImportContext;
use Modules\DataMigration\Importer\Importer;
use Modules\DataMigration\Importer\ImportSource;
use Modules\DataMigration\Importer\ItemVerdict;
use Modules\DataMigration\Importer\PreviewNote;
use Modules\DataMigration\Importer\SourceItem;
use Modules\DataMigration\Importer\WrittenTarget;

/**
 * An import that writes nothing real: what lot A's shared machinery needs
 * to be tested before any of the seven imports exists.
 *
 * Its format `scoutmagic-test` 1.x reads `items.jsonl`, one object per
 * line: `id` (string), `name` (empty → ignored), `kind` (optional, default
 * `thing`), `year` (optional, a scout year label — D5), `ambiguous`
 * (optional, true → ambiguous). Every item it writes lands in
 * `test_written`, a table it creates itself, so that a test can count what
 * really happened against the correspondence table — and it writes it on
 * the module's own connection, inside the transaction the module opened.
 */
final class TestImporter implements Importer
{
    public const FORMAT = 'scoutmagic-test';

    /** @var list<string> source ids whose write throws, to test technical errors */
    public array $failingIds = [];

    /** @var (\Closure(SourceItem): void)|null runs at the start of inspect() — something happening meanwhile */
    public ?\Closure $onInspect = null;

    /** @var (\Closure(SourceItem): void)|null runs at the start of write() */
    public ?\Closure $onWrite = null;

    public function __construct(
        private \PDO $pdo,
        private string $key = 'essai',
        private ?string $requiredModule = null,
        private ?ArchiveLimits $limits = null
    ) {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS test_written (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_id VARCHAR(190) NOT NULL)'
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function requiredModule(): ?string
    {
        return $this->requiredModule;
    }

    public function title(): string
    {
        return 'Éléments d\'essai';
    }

    public function description(): string
    {
        return 'Un import d\'essai qui n\'écrit que dans une table de test.';
    }

    public function unitLabel(): string
    {
        return 'éléments';
    }

    public function archiveFormat(): ?ArchiveFormat
    {
        return new ArchiveFormat(
            self::FORMAT,
            1,
            $this->limits ?? new ArchiveLimits(20, 1048576, 4194304, ['json', 'jsonl'])
        );
    }

    public function items(ImportSource $source, int $from): iterable
    {
        foreach ($source->jsonLines('items.jsonl', $from) as $position => $row) {
            if ($row === null) {
                yield $position => SourceItem::unreadable('thing', 'ligne ' . ($position + 1), 'Ligne illisible');
                continue;
            }
            $kind = is_string($row['kind'] ?? null) ? $row['kind'] : 'thing';
            yield $position => new SourceItem($kind, SourceItem::idFrom($row['id'] ?? null) ?? '', $row);
        }
    }

    public function inspect(SourceItem $item, ImportContext $context): ItemVerdict
    {
        if ($this->onInspect !== null) {
            ($this->onInspect)($item);
        }
        if (($item->data['name'] ?? '') === '') {
            return ItemVerdict::ignored('Sans nom');
        }
        if (($item->data['ambiguous'] ?? false) === true) {
            return ItemVerdict::ambiguous('Ressemble à un élément existant');
        }

        $verdict = ItemVerdict::import()->tagged('named');

        return is_string($item->data['year'] ?? null) ? $verdict->needingScoutYear($item->data['year']) : $verdict;
    }

    public function write(SourceItem $item, ImportContext $context): WrittenTarget
    {
        if ($this->onWrite !== null) {
            ($this->onWrite)($item);
        }
        $this->pdo->prepare('INSERT INTO test_written (source_id) VALUES (?)')->execute([$item->sourceId]);
        if (in_array($item->sourceId, $this->failingIds, true)) {
            throw new \RuntimeException('Simulated failure after a partial write.');
        }

        return new WrittenTarget('test_thing', (string) $this->pdo->lastInsertId());
    }

    public function previewNotes(AnalysisSummary $summary): array
    {
        return [PreviewNote::info($summary->tag('named') . ' éléments portent un nom.')];
    }

    public function effects(): array
    {
        return ['Aucun courriel n\'est envoyé.'];
    }
}
