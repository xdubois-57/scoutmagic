<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

/**
 * What a file WOULD do to a list, before anything is written.
 *
 * The counts are the sentence the chief reads and confirms —
 * « 12 adresses ajoutées, 284 inchangées, 5 supprimées, 1 désinscrite
 * conservée » — and $addresses is what the confirmation carries back,
 * the uploaded file having been deleted the moment this was built.
 */
final class ListAddressImportPreview
{
    /**
     * @param array<int, array{name: ?string, email: string}> $addresses
     * @param array{added: int, unchanged: int, removed: int, kept_unsubscribed: int} $summary
     * @param string[] $errors lines that will simply not be imported — the rest still can be
     * @param int $duplicates lines naming an address another line already named
     */
    public function __construct(
        public readonly array $addresses,
        public readonly array $summary,
        public readonly array $errors,
        public readonly int $duplicates
    ) {
    }
}
