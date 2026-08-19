<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Export;

use Core\Security\Role;

/**
 * The single, canonical, ordered list of every column the core member
 * export can produce. Any screen exporting members reads this list (via
 * MemberExportService) instead of redefining its own columns.
 *
 * Scope, deliberately: generic, administrative member-domain data only —
 * identity, contacts, address, scout-life (section/branch/function/year),
 * and the year-over-year movement classification. Never a module's own
 * data (finance transactions/receivables, event registrations, gallery
 * photos, retro content, ...) — those are a different domain each, with
 * their own access rules, and mixing them in here would turn a member
 * export into a database dump. A module wanting its own data in an export
 * builds its own sheet/columns from its own data, never by extending this
 * list.
 *
 * Every column has a minimum role (Core\Security\Role). A caller building
 * an export always filters this list against the ACTUAL exporting user's
 * role (never wider than what the calling page's own role_min already
 * requires) — see MemberExportService::build(). Two columns are
 * deliberately gated higher than the rest of this generic member data:
 * - leaving/leaving comment: mirrors the "Départs" page's own
 *   role_min ('chief') — an intendant cannot see this anywhere else in the
 *   app, so the export must not hand it to them either.
 * - handicap: GDPR special-category health data (SECURITY.md §5) — gated
 *   at 'admin', stricter than this generic export's own floor, as a
 *   deliberately conservative choice for a field this sensitive.
 *
 * Never included, by design (SECURITY.md/AGENTS.md): raw encrypted BLOBs,
 * blind indexes, hashes, tokens, or any other technical/internal value —
 * every resolver below reads only already-decrypted business values off
 * MemberExportRow.
 */
final class MemberExportColumns
{
    /**
     * @return MemberExportField[]
     */
    public static function all(): array
    {
        return [
            new MemberExportField('member_id', 'ID interne', Role::INTENDANT, MemberExportField::TYPE_INT, fn(MemberExportRow $r) => $r->memberId),
            new MemberExportField('desk_id', 'Identifiant Desk', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->deskId),
            new MemberExportField('first_name', 'Prénom', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->firstName),
            new MemberExportField('last_name', 'Nom', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->lastName),
            new MemberExportField('totem', 'Totem', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->totem),
            new MemberExportField('gender', 'Genre', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->gender),
            new MemberExportField('birth_date', 'Date de naissance', Role::INTENDANT, MemberExportField::TYPE_DATE, fn(MemberExportRow $r) => $r->birthDate),
            new MemberExportField('quali', 'Qualification', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->quali),

            new MemberExportField('emails', 'Email(s)', Role::INTENDANT, MemberExportField::TYPE_MULTI, fn(MemberExportRow $r) => $r->emails),
            new MemberExportField('phone', 'Téléphone', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->phone),
            new MemberExportField('mobile', 'GSM', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->mobile),
            new MemberExportField('street', 'Rue', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->street),
            new MemberExportField('number', 'Numéro', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->number),
            new MemberExportField('box', 'Boîte', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->box),
            new MemberExportField('postal_code', 'Code postal', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->postalCode),
            new MemberExportField('city', 'Localité', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->city),
            new MemberExportField('country', 'Pays', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->country),

            new MemberExportField('scout_year_label', 'Année scoute', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->scoutYearLabel),
            new MemberExportField('section_name', 'Section', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->sectionName),
            new MemberExportField('section_code', 'Code section', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->sectionCode),
            new MemberExportField('branch_name', 'Branche', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->branchName),
            new MemberExportField('role_bucket', 'Catégorie', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->roleBucketLabel),
            new MemberExportField('main_function', 'Fonction principale', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->functionLabels[0] ?? null),
            new MemberExportField('all_functions', 'Fonctions', Role::INTENDANT, MemberExportField::TYPE_MULTI, fn(MemberExportRow $r) => $r->functionLabels),
            new MemberExportField('is_active', 'Actif cette année', Role::INTENDANT, MemberExportField::TYPE_BOOL, fn(MemberExportRow $r) => $r->isActive),
            new MemberExportField('scout_year_offset', 'Décalage année scoute', Role::INTENDANT, MemberExportField::TYPE_INT, fn(MemberExportRow $r) => $r->scoutYearOffset),
            new MemberExportField('formation_level', 'Niveau de formation', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->formationLevel),
            new MemberExportField('supplementary_insurance', 'Assurance complémentaire', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->supplementaryInsurance),

            // Same access floor as the "Départs" page (role_min: chief) —
            // never wider here just because the export mechanism is core.
            new MemberExportField('leaving', 'Ne revient pas l\'an prochain', Role::CHIEF, MemberExportField::TYPE_BOOL, fn(MemberExportRow $r) => $r->leaving),
            new MemberExportField('leaving_comment', 'Motif de départ', Role::CHIEF, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->leavingComment),

            // GDPR special-category health data — deliberately gated
            // stricter than the rest of this generic member data.
            new MemberExportField('handicap', 'Situation de handicap', Role::ADMIN, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->handicap),

            new MemberExportField('movement_status', 'Situation vs année précédente', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->movementStatusLabel),
            new MemberExportField('previous_section', 'Ancienne section', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->previousSectionName),
            new MemberExportField('previous_branch', 'Ancienne branche', Role::INTENDANT, MemberExportField::TYPE_TEXT, fn(MemberExportRow $r) => $r->previousBranchName),
        ];
    }

    /**
     * @return MemberExportField[]
     */
    public static function forRole(Role $viewerRole): array
    {
        return array_values(array_filter(self::all(), fn(MemberExportField $f) => $viewerRole->hasAccess($f->minRole)));
    }
}
