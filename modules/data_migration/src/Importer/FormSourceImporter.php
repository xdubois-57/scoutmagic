<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

/**
 * An import whose source is not a deposited ZIP but a form of its own —
 * the ICS calendar (an address, a destination calendar, a date).
 *
 * Only step 1 differs: the shared page renders {@see sourceFormTemplate()}
 * instead of the drop zone, posts it to the module's `…/source` route, and
 * {@see acceptSource()} turns it into a run. Analysis, preview,
 * confirmation, execution and report stay the module's, unchanged.
 */
interface FormSourceImporter extends Importer
{
    /**
     * A namespaced template (`@data_migration/…` or the importer's own
     * module) rendering the fields of step 1 and its submit button (« Lire
     * le calendrier »): the shared page draws the `<form>` around it, posts
     * it to `…/source` and adds the CSRF token.
     */
    public function sourceFormTemplate(): string;

    /**
     * What that template needs (the calendars the user may write to…).
     *
     * @return array<string, mixed>
     */
    public function sourceFormContext(int $userAccountId): array;

    /**
     * Validates the posted fields. May fetch the source and write it into
     * `$workspace` (a path under storage/temp the module purges with the
     * run); returns what the run keeps.
     *
     * @param array<string, mixed> $fields the POST body, CSRF token included
     *
     * @throws SourceRejectedException with a French sentence for the page
     */
    public function acceptSource(array $fields, string $workspace, int $userAccountId): AcceptedSource;
}
