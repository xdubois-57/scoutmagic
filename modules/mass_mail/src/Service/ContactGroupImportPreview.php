<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

/**
 * What a file WOULD do to a contact group, before anything is written.
 *
 * The counts are the sentence the chief reads before « Enregistrer » —
 * « 4 ajoutés, 1 retiré, 32 inchangés, 2 doublons fusionnés » — and
 * $contacts is what the save carries back, the uploaded file having been
 * deleted the moment this was built.
 */
final class ContactGroupImportPreview
{
    /**
     * @param array<int, array{name: ?string, email: string}> $contacts
     * @param array{added: int, unchanged: int, removed: int, kept_unsubscribed: int} $summary
     * @param string[] $errors lines that will simply not be imported — the rest still can be
     * @param int $duplicates lines naming an address another line already named
     */
    public function __construct(
        public readonly array $contacts,
        public readonly array $summary,
        public readonly array $errors,
        public readonly int $duplicates
    ) {
    }
}
