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
     * Rows are **deduplicated by address** by the implementation: two
     * people who answered with the same address are one recipient, and
     * the first row wins.
     *
     * $bodyHtml is the draft's starting BODY, sanitized on the way in
     * like any other. It is optional because a caller that has nothing
     * useful to say leaves the composer empty, and mandatory in practice
     * for anything whose body is not the same for everybody: a payment
     * reminder carries one block per receivable, and the number of blocks
     * is what the caller knows and the composer cannot guess. Such a body
     * uses sections ({{#Colonne}} … {{/Colonne}}, Service\MergeRenderer)
     * so a household with one child does not receive the empty blocks the
     * household with three needs.
     *
     * @param string $label      what this audience is, for the composer's own listing
     * @param string $subject    the draft's starting subject line
     * @param string[] $columns  column headers, in order
     * @param list<array{email: string, values: array<string, string>}> $rows
     * @param string $actorRole  the acting account's role, as Core\Security\Role's string value
     * @param string $actorEmail the acting account's address, for resolving its own sections
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
