<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

/**
 * An import with something to say in the report about the items it DID
 * write — what it had to leave out of them (issue #1259: the attachments
 * an imported e-mail was imported without).
 *
 * The module counts, during the execution, the tags of every item it
 * wrote ({@see ItemVerdict::tagged()}), the same tags the analysis counts
 * for {@see Importer::previewNotes()}; this turns those counts into notes
 * written in the past, under the report's tiles. The preview's own notes
 * are not reused: they speak of an import still to come.
 *
 * Optional, and separate from {@see Importer} so that adding it changed no
 * existing import.
 */
interface ReportingImporter extends Importer
{
    /**
     * @param array<string, int> $tags tag => how many times the written items
     *        carried it (an item tagged twice counts twice, as at the analysis)
     * @return list<PreviewNote>
     */
    public function reportNotes(array $tags): array;
}
