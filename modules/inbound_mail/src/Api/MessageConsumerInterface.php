<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * How another module recognises the mail that concerns it (§7.6, and the
 * ARCHITECTURE.md §7.6 "module extended by a module" pattern).
 *
 * **This is what keeps `inbound_mail` free of any consumer's logic.** The
 * module knows how to reach a mailbox, parse MIME and store what arrives;
 * it has no idea what a booking reference looks like, and it must not — a
 * `[LOC-2027-0001]` in a subject means nothing here.
 *
 * **Everybody analyses; nobody wins.** The sync loop asks every registered
 * consumer and applies all of their answers. The old contract asked them
 * in order and stopped at the first that said yes, which made "one email
 * is both a booking's correspondence and an invoice" unrepresentable and
 * left no way to record a guess at all.
 *
 * **Analysis happens once, on arrival.** There is no automatic re-analysis
 * of a message already stored, ever: propositions appearing and
 * disappearing as modules are updated, with nobody able to say why, is
 * worse than none. Enabling a module on a box that has been collecting for
 * months is served by an explicit, manual "réanalyser le courrier
 * conservé" instead.
 */
interface MessageConsumerInterface
{
    /**
     * A stable id for this consumer, used to scope every later API call.
     * The module id is the obvious choice ('rental').
     */
    public function consumerId(): string;

    /**
     * The module's own name, in French, as a screen shows it — « Locations
     * », « Camps », « Finances ».
     *
     * Asked of the consumer rather than looked up in the module registry,
     * for the same reason as everything else here: `inbound_mail` knows
     * nothing about any consumer, and a registry lookup would make the
     * configuration screen depend on a module manifest for a module that
     * might not be the one registering the consumer.
     */
    public function displayName(): string;

    /**
     * What this consumer makes of a message **as it arrives**, from its
     * headers, its text and its attachments' metadata.
     *
     * Return links for what it is sure of and propositions for what it is
     * not; `AnalysisResult::nothing()` for "not mine", which is the
     * ordinary answer. Ambiguity — several objects matching equally — is
     * a proposition at best and silence at worst, never a link: attaching
     * a renter's email to whichever of their two bookings sorted first is
     * worse than not attaching it at all, because the manager reading the
     * wrong file has no way to know it is the wrong file.
     *
     * **`CandidateMessage` carries attachment metadata only — never the
     * bytes.** Reading a PDF here would blow through `max_execution_time`
     * on shared hosting mid-synchronisation, and a synchronisation that
     * dies leaves the cursor unmoved: the same doomed run would then
     * repeat on every tick. Content analysis belongs in `analyzeStored()`.
     */
    public function analyze(CandidateMessage $message): AnalysisResult;

    /**
     * A **second, optional pass**, run by a scheduled task after the
     * message and its attachments are on disk — never inside the
     * synchronisation loop.
     *
     * This is where anything expensive belongs: extracting a PDF's text,
     * reading an amount off an invoice. A consumer with nothing to add
     * returns `AnalysisResult::nothing()`, which is a complete answer and
     * the common one.
     */
    public function analyzeStored(InboundMessage $message): AnalysisResult;

    /**
     * Called once, after an association has actually been written.
     *
     * This is where a consumer does what it wants with the message's
     * attachments — `rental` turns them into booking documents (§7.8) —
     * and it has to be here rather than in `analyze()`, which runs
     * *before* anything is stored and therefore has no ids to point at.
     *
     * **Whatever this does is beside the point of the synchronisation.** A
     * consumer that throws here has already had its message stored, and
     * the run carries on: one module's bookkeeping failing must not cost
     * the unit the rest of its mail. A no-op is a perfectly good
     * implementation.
     */
    public function onLinked(InboundMessage $message, MessageLink $link): void;

    /**
     * Called once, after an association has been removed — a message
     * detached, or moved to another of this consumer's objects.
     *
     * **Its absence was a real bug**: reassigning a message in `rental`
     * left the `RentalDocument` rows it had created hanging off the old
     * booking, where the manager of the new one could not see them and the
     * manager of the old one could not explain them. Whatever `onLinked()`
     * created, this is where it is undone.
     *
     * Fails the same way `onLinked()` does: not at all.
     */
    public function onUnlinked(InboundMessage $message, MessageLink $link): void;

    /**
     * Whether this requester may read what belongs to one of this
     * consumer's business objects — an attachment of a message associated
     * with it.
     *
     * **`inbound_mail` does not know who may read what, and must not learn.**
     * Whether a given intendant manages the booking a contract arrived for
     * is a `rental` question with a `rental` answer; asking it here is the
     * only way to gate an attachment without this module acquiring a copy
     * of every consumer's authorisation rules. Access is granted as soon as
     * **one** consumer associated with the message says yes.
     *
     * `$role` is the requester's role name (`Core\Security\Role`'s value —
     * 'intendant', 'chief', 'admin', …), passed as a string so this
     * contract stays a plain value contract. `$linkedMemberIds` mirrors
     * `Core\File\FileAccessGuard`'s own: the persistent `members.id` values
     * the session is linked to.
     *
     * **A consumer that cannot answer says no.** Throwing is treated as a
     * refusal, never as an error worth failing a download over — and never
     * as a grant.
     *
     * @param array<int, int> $linkedMemberIds
     */
    public function canRead(string $businessReference, array $linkedMemberIds, string $role): bool;

    /**
     * The signals this consumer proposes on, in French, one short phrase
     * each — « référence explicite dans l'objet », « adresse de
     * l'expéditeur pendant le séjour ».
     *
     * **There is deliberately no central rule about how strong a
     * proposition must be.** The discipline belongs to each consumer, and
     * the price of that freedom is saying publicly what it does: this list
     * is shown on the mailbox configuration screen, so a superadmin reads
     * what a module will do with a shared box before opening it to that
     * module rather than trusting a threshold nobody can see.
     *
     * @return string[]
     */
    public function describeEvidence(): array;

    /**
     * Who, concretely, will see the mail this consumer sorts — « les
     * gestionnaires de biens », « le staff d'unité ». Shown next to the
     * scope choice on the configuration screen.
     */
    public function triageAudienceLabel(): string;

    /**
     * How many real people that is, **for the scout year in effect** —
     * never an estimate and never a figure frozen at some past moment.
     *
     * Opening a mailbox to a module is opening it to those people, and the
     * warning that says so is the only guard-rail on that choice: it has
     * to be exact or it is worse than absent.
     */
    public function triageAudienceCount(): int;
}
