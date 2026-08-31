<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

/**
 * "Keep this document as a receipt on one of the unit's accounts."
 *
 * Published by the PROVIDER in its own Api namespace (ARCHITECTURE.md
 * §7.5): a consumer takes it as a **nullable** dependency and degrades to
 * "the option is not offered" when finance is disabled — never to an error,
 * and never by asking `ModuleManager` itself. The composition root is the
 * only place that knows which modules are on.
 *
 * Deliberately says nothing about where the document came from. A
 * federation invoice is the first consumer; nothing here names it.
 *
 * **The authorization is finance's own and is built here.** The caller
 * hands over who is acting — a role and the members that login reaches —
 * and `Service\AccountVisibility` decides which accounts that is
 * (ARCHITECTURE.md §8.69): `role_min_view`, and, for an account attached to
 * a section, being that section's treasurer. A caller able to supply the
 * decision could grant itself one.
 *
 * The file is stored the way every other receipt is — encrypted at rest
 * through `Core\File\EncryptedFileStorageService`, owned by its account so
 * `/files/{id}` answers the same question the screens do (§8.70). There is
 * deliberately no second storage path for a consumer to use: a module that
 * wants a document kept confidentially asks for a receipt, or keeps
 * nothing.
 */
interface ExpenseReceiptInterface
{
    /**
     * The active accounts this actor may attach a receipt to, in the
     * finance module's own listing order (by name).
     *
     * @param int[] $actorLinkedMemberIds the members.id this login reaches
     * @return array<int, string> account id => name, for a picker
     */
    public function receiptAccounts(string $actorRole, array $actorLinkedMemberIds): array;

    /**
     * Stores $content as a receipt on $accountId and returns the id of the
     * stored FILE — what makes the document reachable at `/files/{id}`,
     * under that account's own rule (§8.70). The receipt row's own id is
     * deliberately not what comes back: finance offers no page for a
     * single receipt, so an id no consumer can turn into a link would be
     * a reference to nothing.
     *
     * The account is re-checked against the same predicate
     * {@see receiptAccounts()} filters on: a picker is UI, never the
     * boundary (SECURITY.md §3).
     *
     * @param int[] $actorLinkedMemberIds
     * @throws \Modules\Finance\Api\FinanceException when the actor may not use
     *         that account, the account is unknown, or the type is refused
     */
    public function storeReceipt(
        string $content,
        string $mimeType,
        string $originalFilename,
        int $accountId,
        ?float $suggestedAmount,
        ?string $suggestedDate,
        string $actorRole,
        array $actorLinkedMemberIds,
        ?int $uploadedBy
    ): int;

    /**
     * Stores a receipt that **nobody asked for** — one that arrived on its
     * own, in a mailbox the unit opened to a consuming module.
     *
     * `$accountId` null files it on no account at all: the unit's sorting
     * pile, visible to its treasurers and its chef d'unité
     * ({@see \Modules\Finance\Service\AccountVisibility::isUnassignedReceiptVisibleTo()}),
     * who move it onto an account by hand. That is what a document with no
     * IBAN, sent from an address animating no single staff, is worth: too
     * much to throw away, not enough to file.
     *
     * **There is no actor, and that is the whole difficulty.** Every other
     * route into finance builds its authorization from the person acting;
     * this one has nobody. What stands in their place is an earlier, human
     * decision of the same weight: a superadmin opened that mailbox to
     * that module on the scope screen, having read what the module says it
     * files (`MessageConsumerInterface::describeEvidence()`). Nothing here
     * grants a *session* anything — the receipt lands where the visibility
     * rules already say it lands, and whoever may see it decides what
     * happens next.
     *
     * **Not a general-purpose door.** A caller wanting a receipt filed on
     * behalf of somebody uses {@see storeReceipt()}, which checks that
     * somebody against the account. This one exists for the unattended
     * path and takes no account it was not able to justify: null, or one
     * the consumer resolved from the unit's own data.
     *
     * @return int the id of the stored FILE, as {@see storeReceipt()}
     * @throws \Modules\Finance\Api\FinanceException when the account is
     *         unknown, or the type is refused
     */
    public function storeUnattendedReceipt(
        string $content,
        string $mimeType,
        string $originalFilename,
        ?int $accountId
    ): int;
}
