<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Api;

/**
 * "Turn this list of people into a mail-merge draft I can go and write."
 *
 * Published by the PROVIDER in its own Api namespace (ARCHITECTURE.md
 * §7.5): a consumer takes it as a **nullable** dependency and degrades to
 * "the feature is not offered" when mass_mail is disabled — never to an
 * error, and never by testing ModuleManager::getEnabledModuleIds() itself.
 * The composition root is the only place that asks which modules are on.
 *
 * Deliberately says nothing about where the rows came from. A form's
 * responses are the first consumer, but nothing here names news, an
 * article or a form — a second consumer needs no change to this contract.
 *
 * **The authorization is mass_mail's own and is built here, not passed
 * in.** The caller hands over who is acting (role and address); which
 * section they may send from, and which lists they may target, is a rule
 * only mass_mail knows (Service\SenderAuthorization). A caller that could
 * supply the authorization itself could grant itself one.
 */
interface MassMailDraftInterface
{
    /**
     * Creates the audience and the draft that references it, and returns
     * the URL of the composition screen to send the user to. Nothing is
     * sent: the result is a draft like any other.
     *
     * $columns is the audience's column headers, in display order, and
     * every key of every row's $values must be one of them — they become
     * the merge variables offered in the composer.
     *
     * **One row is one communication**, exactly like one line of an
     * imported file (ARCHITECTURE.md §8.61): rows are never merged, so two
     * rows with the same address are two messages, each with its own
     * values. A row with neither a member nor an address is dropped.
     *
     * A row MAY carry a `member_id` (`members.id`), and then has exactly
     * the meaning of a « Tiers » in an imported file: its messages go to
     * the addresses known for that member, its `email` is ignored and may
     * be left out, and the row appears in that member's history. Without
     * one, the row goes to its `email` alone and belongs to no member —
     * even when the address happens to be a member's. Choosing between the
     * two is the caller's to decide: a form's respondent answered with one
     * precise address and gets no `member_id`; a payment reminder for a
     * child is about that child and gets one.
     *
     * So a row is ONE person: a payment reminder writes one row per
     * child (one per receivable), each with that child's `member_id` and
     * values, rather than one row per household listing its children —
     * a family of three gets three messages per address, and each child
     * the reminder on their own page. Nothing in a row has to repeat for
     * a variable number of people, so a body never needs one block per
     * child.
     *
     * $bodyHtml is the draft's starting BODY, sanitized on the way in
     * like any other. It is optional because a caller that has nothing
     * useful to say leaves the composer empty; a caller whose message has
     * a fixed shape (a reminder's first name, amount, structured
     * communication) writes it here with the row's own variables —
     * `{{Prénom}}`, `{{Montant}}` — substituted per row by
     * Service\MergeRenderer like a typed body.
     *
     * @param string $label      what this audience is, for the composer's own listing
     * @param string $subject    the draft's starting subject line
     * @param string[] $columns  column headers, in order
     * @param list<array{email?: string, member_id?: ?int, values: array<string, string>}> $rows
     *        one row per person: `values` keyed by $columns, `member_id` for a row about a
     *        member (its `email` is then ignored), `email` otherwise
     * @param string $actorRole  the acting account's role, as Core\Security\Role's string value
     * @param string $actorEmail the acting account's address, for resolving its own sections
     * @param ?int $actorAccountId the acting account, recorded as the audience's importer and the
     *        draft's author — null when no account acts
     * @param ?string $bodyHtml  the draft's starting body, or null for an empty composer
     * @return string the draft's edit URL
     *
     * @throws \Modules\MassMail\Api\MassMailException when the actor may not send at all,
     *         or when there is no usable recipient
     */
    public function createMergeDraft(
        string $label,
        string $subject,
        array $columns,
        array $rows,
        string $actorRole,
        string $actorEmail,
        ?int $actorAccountId,
        ?string $bodyHtml = null
    ): string;
}
