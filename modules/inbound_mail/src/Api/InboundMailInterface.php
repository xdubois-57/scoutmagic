<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * What another module may do with the mail this module collected (§7.11).
 *
 * **Every method is scoped to one consumer, and to references that
 * consumer's caller supplies.** There is deliberately no `findAll()`, no
 * `findByMailbox()` and no `search()`: a manager who may open a booking
 * must not thereby gain a window onto the unit's whole mailbox, and the
 * way to make that impossible is to never offer the query. A consumer id
 * is passed on every call for the same reason — `rental` asking for
 * `finance`'s messages gets nothing, rather than getting them because it
 * knew a reference.
 *
 * `findForTriage()` is the one method that returns messages the caller did
 * not name a reference for, and it is still not a general read: what it
 * adds comes from the **mailbox configuration** — a box the superadmin
 * declared this consumer may read in full — and from nowhere else. A
 * consumer cannot widen its own scope through this interface; only the
 * configuration screen can, and it says in as many words what it is doing.
 * The Chef d'Unité's own unscoped view lives inside `inbound_mail`
 * (`Service\GeneralMailboxService`) and is not reachable from here.
 *
 * Consumed the §7.5 way: a nullable dependency the consumer degrades
 * without.
 */
interface InboundMailInterface
{
    /**
     * The messages attached to one business object, oldest first.
     *
     * @return InboundMessage[]
     */
    public function findForReference(string $consumerId, string $businessReference): array;

    /**
     * One message, but only if it really belongs to that reference —
     * an id alone is never enough. A consumer that has an id from
     * somewhere else gets null rather than somebody else's mail.
     */
    public function findOneForReference(string $consumerId, string $businessReference, int $messageId): ?InboundMessage;

    /**
     * Associate a message with one of this consumer's business objects,
     * because a person said so.
     *
     * `LinkOrigin::MANUAL`, always: the origin answers « comment ce
     * rattachement a-t-il été fait », and a human decision presented as a
     * heuristic makes every later reader trust it less than they should.
     *
     * **Idempotent** — two people orienting one message towards the same
     * object produce one association and neither sees an error. It does
     * not remove any other association either: a message can legitimately
     * belong to a stay and to an invoice at once, and « rattacher ici » is
     * not « retirer de là ».
     *
     * The caller is responsible for having checked that the user may reach
     * the reference (§7.7) — this module cannot know a consumer's
     * authorisation rules, which is exactly why the check stays in the
     * consumer's controller.
     *
     * @return bool whether an association was created (false when it
     *   already existed)
     */
    public function attach(
        string $consumerId,
        string $businessReference,
        int $messageId,
        ?int $userAccountId = null
    ): bool;

    /**
     * The mail this consumer's own users may sort — the business triage
     * list (§8.58, IT-07).
     *
     * **Still scoped**, and that is why it may live on this contract at
     * all: the caller passes the references the requester can actually
     * reach, and this module never invents one. What it adds on top comes
     * from the mailbox configuration alone — a box the superadmin declared
     * this consumer may read in full contributes everything it holds, and
     * a box they did not contributes nothing.
     *
     * Propositions are included alongside associations, because a
     * proposition exists to be confirmed or dismissed by somebody who
     * knows, and a list showing only what the module was already sure
     * about would hide exactly the messages that need a human.
     *
     * @param string[] $ownReferences references the requester may manage
     * @return InboundMessage[] newest first, bounded
     */
    public function findForTriage(string $consumerId, array $ownReferences, int $limit = 50): array;

    /**
     * This consumer's still-standing propositions on a set of messages,
     * keyed by message id — what a triage screen renders next to each row
     * without querying inside its own loop.
     *
     * Another module's propositions on the same message are deliberately
     * absent: they are not this screen's business, and showing them would
     * leak one module's guesses into another module's audience.
     *
     * @param int[] $messageIds
     * @return array<int, MessageCandidate[]>
     */
    public function findCandidatesFor(string $consumerId, array $messageIds): array;

    /**
     * Confirm one of this consumer's own propositions, as a person.
     *
     * The association is recorded with `LinkOrigin::MANUAL` rather than
     * the heuristic that produced the proposition (D20): once somebody has
     * read the message and said yes, presenting their decision as a guess
     * would make every later reader trust it less than they should.
     *
     * Scoped like everything else here — a proposition whose reference is
     * not among `$ownReferences` is refused rather than confirmed, so a
     * screen cannot be talked into filing a message under an object its
     * user may not reach.
     *
     * @param string[] $ownReferences
     */
    public function confirmCandidate(
        string $consumerId,
        array $ownReferences,
        int $messageId,
        int $candidateId,
        ?int $userAccountId = null
    ): bool;

    /**
     * Set one of this consumer's own propositions aside, for good (A3).
     *
     * Dismissing protects nothing: a message the module no longer proposes
     * anything about is a message the retention may remove, or « écarter »
     * would quietly mean « conserver ».
     *
     * @param string[] $ownReferences
     */
    public function dismissCandidate(
        string $consumerId,
        array $ownReferences,
        int $messageId,
        int $candidateId
    ): bool;

    /**
     * Detach a message: it leaves **this** business object, and nothing
     * else happens to it.
     *
     * **It is not destroyed, and it used to be.** Detaching is almost
     * always a correction — the message was filed under the wrong booking —
     * and destroying it made the correction impossible to finish. It now
     * falls back into the unit's general mail, where only a chef d'unité
     * sees it and where the module's own retention removes it if nobody
     * re-orients it. A detached message keeps a floor of thirty days
     * whatever its age, so a mis-click has a window to be noticed.
     *
     * `$preserveFileIds` names the files the consumer has re-classified as
     * something of its own — §7.7. They are **released from the message**:
     * its attachment row stops naming them and says why, so the retention
     * purge cannot take a booking's signed contract away with the email it
     * arrived in. A consumer that names a file here becomes responsible for
     * it, `files.owner_id` included; only it knows what it did with them.
     *
     * @param int[] $preserveFileIds
     * @return bool false when the message does not belong to that reference
     */
    public function detach(
        string $consumerId,
        string $businessReference,
        int $messageId,
        array $preserveFileIds = []
    ): bool;

    /**
     * Move a message from one business object to another **within the same
     * consumer**. The caller is responsible for having checked that the
     * user may reach BOTH references (§7.7) — this module cannot know a
     * consumer's authorisation rules, which is exactly why the caller is
     * required to narrow the target list before offering it.
     *
     * @return bool false when the message does not belong to $fromReference
     */
    public function move(string $consumerId, string $fromReference, string $toReference, int $messageId): bool;

    /**
     * Release everything held for a business object — used by a consumer's
     * own retention policy (§7.10) and when the object itself is deleted.
     *
     * Each message leaves that object; the ones nothing else recognises are
     * destroyed with their attachments, and the ones another module also
     * recognises stay where they are.
     *
     * @return int the number of messages released from that object
     */
    public function purgeReference(string $consumerId, string $businessReference): int;

    /**
     * Whether any mailbox is configured and enabled at all. A consumer uses
     * this to decide whether to show a communications tab that would
     * otherwise always be empty.
     */
    public function isCollecting(): bool;

    /**
     * Whether that box is the one the operator declared to be **this
     * consumer's own** — `Api\MailboxPurpose::DEDICATED`.
     *
     * A consumer may legitimately read a signal more strongly on its own
     * address than on the unit's public one: `camps@unite.be` receives
     * nothing but camp mail, so a message there that states its dates may
     * become a stay, where the same message on the unit's shared address
     * may not. The arrival pass already gets this answer on
     * `Api\CandidateMessage::$mailboxDedicatedTo`; this is the same
     * question for the deferred pass, which works from a stored message.
     *
     * It exists because a consumer must not answer it for itself. Camps
     * used to, from a list of ids in its own settings, and the answer went
     * stale the day the configuration screen took the question over: a box
     * declared dedicated there stayed « not dedicated » for the module it
     * was dedicated to, and nothing anywhere said so.
     */
    public function isDedicatedTo(string $consumerId, int $mailboxId): bool;

    /**
     * The business object a message belongs to, found from the Message-IDs
     * a reply names — §7.6's second level, and the reason a reply carrying
     * no reference still lands on the right file.
     *
     * It lives here rather than in the consumer because the consumer has no
     * way to look inside this module's storage, and it is scoped to the
     * caller's own consumer id for the same reason everything else is.
     *
     * @param string[] $messageIds most specific first
     */
    public function findReferenceByThread(string $consumerId, int $mailboxId, array $messageIds): ?string;

    /**
     * What a non-superadmin may know about the configured mailboxes: a name
     * and whether it is working (§7.4). Never the host, the port or the
     * account — a manager choosing which box their module listens to needs
     * to recognise it, not to be able to reach it.
     *
     * @return array<int, array{name: string, state: string, is_enabled: bool}> keyed by mailbox id
     */
    public function listMailboxSummaries(): array;

    /**
     * The addresses of the enabled mailboxes this consumer is allowed to
     * analyse — the boxes a diagnostic probe should be sent to
     * (roadmap IT-27).
     *
     * **This is the one method that hands out an account address**, and it
     * is a deliberate exception to `listMailboxSummaries()`'s rule right
     * above. The difference is what the answer is for: a summary is shown
     * to a manager picking a box, where the address adds nothing and gives
     * away something; this is a *destination*, and a probe that cannot be
     * addressed is not a probe. It answers for one consumer, about boxes
     * that consumer already reads, and its own caller is expected to be an
     * authenticated one — publishing it on an open route would be handing
     * out mailbox addresses to anybody.
     *
     * An address is organisational (`design.md` §2.6), never a member's.
     * A disabled box is absent: writing to one nothing synchronises would
     * produce a « jamais reçu » that says nothing about the mail path.
     *
     * @return list<string>
     */
    public function probeAddressesFor(string $consumerId): array;
}
