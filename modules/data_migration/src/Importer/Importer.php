<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

/**
 * One import of the data migration module: what is specific to one kind
 * of data, and nothing else.
 *
 * The module owns the rest — the deposit, the archive's safety, the
 * manifest, the analysis loop, the preview, the confirmation, the
 * execution in slices, the idempotence, the report. An import supplies
 * three things:
 *
 * - its **analyser**: {@see items()} reads the source in a stable order,
 *   and {@see inspect()} says, without writing anything, what would become
 *   of one item;
 * - its **preview**: {@see previewNotes()} turns what the analysis
 *   counted into the notes « Ce que l'import fera » shows, and
 *   {@see effects()} lists what the import will and will not do;
 * - its **writer**: {@see write()} creates one item in its target module,
 *   through that module's `Api\` contract, without any side effect (no
 *   e-mail, no notification), and says what it created.
 *
 * {@see write()} runs inside a transaction the module opens, together with
 * the correspondence row that makes a re-run skip the item — so an item is
 * either fully imported and recorded, or not at all. It must therefore not
 * open or commit a transaction of its own on the shared connection (use
 * `Core\Database\TransactionScope`, which joins the caller's). Right
 * before it, {@see inspect()} is asked again: the site may have changed
 * since the analysis.
 *
 * An import is registered by ONE line in the composition root
 * (`ImporterRegistry::add()`), and is offered only while its
 * {@see requiredModule()} is enabled — on the home page, at its address, at
 * every AJAX endpoint and for every slice.
 */
interface Importer
{
    /** The import's address segment, stable for ever: `photos`, `galeries`. */
    public function key(): string;

    /** The module that must be enabled for this import to exist, or null for a core capability. */
    public function requiredModule(): ?string;

    /** French title: « Photos des membres ». */
    public function title(): string;

    /** French one-sentence description, on the home page and under the title. */
    public function description(): string;

    /** French plural noun the counts are written with: « photos », « albums ». */
    public function unitLabel(): string;

    /**
     * The archive this import reads, or null when its source is a form of
     * its own — it then implements {@see FormSourceImporter}.
     */
    public function archiveFormat(): ?ArchiveFormat;

    /**
     * The items of the source, in a STABLE order, starting at position
     * `$from` (0-based). Keys are positions: the module stores the next one
     * as its cursor and asks again from there on the next slice, so the
     * same source must always yield the same item at the same position.
     *
     * An item that cannot even be read (a malformed line) is yielded too,
     * as {@see SourceItem::unreadable()}: it is counted as ignored with its
     * reason, never silently skipped.
     *
     * @return iterable<int, SourceItem>
     */
    public function items(ImportSource $source, int $from): iterable;

    /** What would become of this item. Reads, never writes. */
    public function inspect(SourceItem $item, ImportContext $context): ItemVerdict;

    /**
     * Creates the item in its target module, with no side effect, and
     * says what was created. Called only after {@see inspect()} answered
     * « import », inside the module's transaction. Throwing counts the
     * item as a technical error and the import carries on.
     */
    public function write(SourceItem $item, ImportContext $context): WrittenTarget;

    /**
     * Notes for the preview, from what the analysis counted.
     *
     * @return list<PreviewNote>
     */
    public function previewNotes(AnalysisSummary $summary): array;

    /**
     * « Ce que l'import fera » — French sentences, shown in the preview
     * and again in the confirmation dialog.
     *
     * @return list<string>
     */
    public function effects(): array;
}
