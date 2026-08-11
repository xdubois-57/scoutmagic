<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * Public contract for the module's own predefined mailing list
 * (ARCHITECTURE.md §7.5 — this module owns the capability, mass_mail
 * optionally consumes it, same shape as Modules\Finance\Service\
 * ReceiptExtractionService consuming llm_connector). mass_mail never
 * imports anything from this module beyond this one interface, and
 * degrades to "no such list" when the registration module is disabled.
 */
interface ExternalMailingListProvider
{
    /**
     * The list's identity — always named after the target scout year
     * (module spec: "une liste par année, jamais recyclée", so nothing
     * ever gets renamed or reused across years). mass_mail attaches its
     * own internal list_type/list_section_id bookkeeping around this;
     * the provider itself only describes what the list IS.
     *
     * @return array{label: string, description: string}
     */
    public function describeMailingList(): array;

    /**
     * The list's current member set — recomposed fresh on every call
     * (module spec: "recomposée dynamiquement à chaque envoi, jamais
     * figée à la création"), never cached. Only members with a real Desk
     * identity qualify (member_id must reference a genuine `members`
     * row) — see Service\ExternalMailingListService's own docblock for
     * why that means 'encoded' requests only, not 'accepted' ones.
     *
     * @return array<int, array{member_id: int, email: ?string}>
     */
    public function resolveMailingListMembers(): array;

    /**
     * The scout year every resolved member's profile actually lives
     * under — mass_mail's own multi-year selector (module addendum) never
     * applies to this list (module spec: it's always the fixed target
     * year, never re-scoped), so mass_mail asks for this explicitly
     * rather than looping resolveMailingListMembers() once per selected
     * year the way it does for its own list types.
     */
    public function targetScoutYearId(): int;
}
