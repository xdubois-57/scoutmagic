<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * The module's write contract for the import of an old site's requests
 * (chantier « Migration de données » #1142, lot H — ARCHITECTURE.md §7.5):
 * how `data_migration` files a request that is still being processed,
 * with its history, without any side effect.
 *
 * **What it never does**: send the acknowledgement or the unit alert
 * (Service\RegistrationService::submit()), plan or send a decision e-mail
 * (Service\RequestDecisionService::apply()), send a secondary address's
 * confirmation (Service\SecondaryEmailService::addEmail()), or hand out
 * the tracking link. A native tracking token is created, as for every
 * request, and kept unsent; the old site's token is never taken over.
 *
 * **What it keeps**: everything else a request filed here gets — the
 * module's validation (the public form's rules), encryption at rest, blind
 * indexes, the journal (an id and a status, never a name). Once written,
 * the request is an ordinary one: same lists, same decisions, same
 * automatic acceptance and Desk reconciliation, no « Importé » mark.
 *
 * A decision the old site had taken — accepted, refused, or accepted and
 * already encoded in Desk — had been told to the family there: it is
 * recorded « Communiquée par l'ancien site » (D2), which makes it visible
 * on the family's tracking page at once, with no e-mail and no false
 * « envoyé ».
 *
 * write() runs inside the caller's transaction when there is one, and
 * opens none of its own otherwise beyond the module's usual ones.
 */
interface RequestImportWriter
{
    /**
     * Why $request cannot be filed as it is, as short French sentences
     * naming the field — never its value (D8): « Champ obligatoire
     * manquant : téléphone ». Empty when it can. Reads, never writes.
     *
     * @return list<string>
     */
    public function problems(ImportedRequest $request): array;

    /**
     * Whether the scout year already holds a live request of the same
     * child (same name and birth date) — the « doublon possible » the
     * request list will flag, exactly as for a request filed twice on the
     * public form. Never a reason to refuse or merge.
     */
    public function looksLikeExistingRequest(ImportedRequest $request): bool;

    /**
     * Files $request with no side effect and answers its new id.
     *
     * @throws \InvalidArgumentException when problems() is not empty
     */
    public function write(ImportedRequest $request): int;
}
