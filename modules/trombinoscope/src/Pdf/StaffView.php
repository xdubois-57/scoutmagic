<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Trombinoscope\Pdf;

/**
 * One animateur, as the printable document draws them — presentation-ready
 * and already filtered.
 *
 * The contacts setting (Controller\TrombinoscopeController::
 * SETTING_SHOW_CONTACTS) is applied when this object is BUILT, not when it
 * is rendered: with the setting off, $phone and $email are null and there
 * is nothing in the document, in the HTML behind it or in the PDF's text
 * layer for anybody to recover.
 */
class StaffView
{
    public function __construct(
        /** Totem, or first name when there is none (AGENTS.md § Display name convention). */
        public readonly string $displayName,
        /** « Prénom Nom », always shown beside the totem. */
        public readonly string $civilName,
        /** Two letters, drawn in a coloured disc when there is no photo. */
        public readonly string $initials,
        /** `data:image/jpeg;base64,…`, or null when this person has no photo. */
        public readonly ?string $photoDataUri,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly bool $isLead
    ) {
    }
}
