<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

/**
 * One member a form can name: who they are (`deskId`, the identity an
 * answer stores) and how they are shown (`label`, never a key).
 *
 * The label is `Core\Member\MemberProfile::getDisplayNameFull()` — the
 * Contact page's « Totem (Prénom Nom) » — and nothing else: two members
 * sharing a first name or a totem are two options with two Desk ids, and
 * a member renamed in Desk is the same answer under a new label.
 *
 * `memberYearId` is the annual record the label was read from. The
 * « Membre concerné » selector posts it, because that is what
 * `news_form_responses.member_year_id` stores; a field's answer never does.
 */
final class MemberOption
{
    public function __construct(
        public readonly int $memberYearId,
        public readonly string $deskId,
        public readonly string $label
    ) {
    }
}
