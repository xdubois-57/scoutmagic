<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Mail;

use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Finance\Api\ExpenseReceiptInterface;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Service\IbanNormalizer;
use Modules\Finance\Service\ReceiptService;
use Modules\Finance\Service\TreasurerScope;
use Modules\Finance\Service\TreasurerScopeService;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Api\ReferenceDirectory;
use Modules\InboundMail\Api\ReferenceSuggestion;

/**
 * An invoice arriving by email, offered as a receipt on the account it
 * names (§8.58's consumer contract, §8.70's receipts).
 *
 * **An attachment of a receipt type is the price of admission**, and
 * nothing here happens without one: a PDF, a photo. However much a message
 * talks about money, there is nothing to file without a document.
 *
 * Given one, three rules run in order, and the order is the design:
 *
 * 1. **Exactly one of the unit's own IBANs in the text** → a proposition
 *    on that account, confirmed by a treasurer before anything is filed.
 *    An IBAN is matched through its blind index, so nothing is decrypted
 *    to answer the question; zero matches and two matches both mean
 *    silence, because an email quoting two of the unit's accounts is
 *    almost always a transfer between them.
 * 2. **The sender animates exactly one staff, and that staff has exactly
 *    one active account** → the receipt is filed there, unattended. An
 *    animateur photographing a receipt and sending it in is the ordinary
 *    case, and asking somebody to confirm what the unit's own membership
 *    data already says would be asking for the sake of asking.
 * 3. **Nothing places it, on a box dedicated to this module** → the
 *    receipt is kept with no account at all, in a sorting pile its
 *    treasurers work through. On the unit's public address this stops at
 *    silence instead: a photo in a parent's message is not a receipt.
 *
 * The IBAN wins over the sender deliberately. It is a statement about the
 * MONEY, made in the document's own covering text; the sender is a
 * statement about a person, and a person can be wrong about which account
 * an expense belongs to in a way an IBAN cannot.
 *
 * **Rule 2 files without anybody confirming, and the address behind it is
 * not authenticated.** A `From:` header is forgeable. What bounds that is
 * the shape of what can be won: a **document** filed on an account —
 * never an amount, never a movement, never a euro — by an address that
 * still has to resolve to a real member of this unit animating exactly one
 * staff. Every rule this module runs is published on the configuration
 * screen (`describeEvidence()`), which the superadmin reads before opening
 * a mailbox to it — that publication is the whole point of making each
 * consumer say what it does.
 *
 * **Strictly additive.** Nothing in the finance module changed for this:
 * the consumer reaches finance through `Api\ExpenseReceiptInterface`,
 * which already existed for the federation-invoice flow, and through
 * `Service\AccountVisibility`, which already answers "may this role see
 * this account". A consumer that needed finance to change would have been
 * a consumer built at the wrong layer.
 */
class FinanceMessageConsumer implements MessageConsumerInterface, ReferenceDirectory, \Modules\InboundMail\Api\PropositionListener
{
    public const CONSUMER_ID = 'finance';

    /** `account-{id}` — never a bare id, which would collide with another module's. */
    public const REFERENCE_PREFIX = 'account-';

    /**
     * The sorting pile: a receipt this module is sure is its business and
     * cannot place.
     *
     * A business reference rather than "no link at all", because the link
     * is what makes the deposit happen exactly once. `AnalysisResultApplier`
     * calls `onLinked()` only for an association that was actually new, so
     * a folder re-read after a UIDVALIDITY reset recognises the message it
     * already has and does not file the same receipt a second time. Without
     * a reference there would be no association, therefore no callback, and
     * therefore no receipt at all.
     */
    public const REFERENCE_UNKNOWN = self::REFERENCE_PREFIX . 'unknown';

    /**
     * What a receipt can be — **the receipt store's own list, not a copy
     * of it**.
     *
     * It used to be a copy, and the copy had drifted: it accepted
     * `image/heic` and `image/webp`, which `Service\ReceiptService`
     * refuses (it keeps every receipt renderable as a thumbnail, and a
     * browser draws neither). So a HEIC photo — an iPhone's default — was
     * claimed here, refused there, and the refusal was swallowed by the
     * `catch` in onLinked(): the message showed as filed and no receipt
     * existed.
     *
     * Referencing the store means the two cannot disagree again. Still
     * narrower than what `inbound_mail` keeps: a spreadsheet is a
     * document, and an accounting receipt it is not.
     */
    public const RECEIPT_MIME_TYPES = ReceiptService::ALLOWED_MIME_TYPES;

    public function __construct(
        private AccountRepository $accounts,
        private TreasurerScopeService $treasurerScopeService,
        private \PDO $pdo,
        private EncryptionService $encryption,
        private int $scoutYearId,
        /**
         * How a confirmed proposition becomes a receipt. Null — finance's
         * own receipt surface unavailable — degrades to "the proposition
         * is made and confirming it associates the message, but files
         * nothing", which is visible rather than silent.
         */
        private ?ExpenseReceiptInterface $receipts = null,
        /**
         * Resolves the confirming user into the (role, members) pair
         * finance's authorisation needs. Null means no receipt is ever
         * stored: this module refuses to invent an actor, because the
         * account check is built from one.
         *
         * @var (\Closure(int): ?array{role: string, member_ids: int[]})|null
         */
        private ?\Closure $resolveActor = null,
        /**
         * Reads an attachment's bytes back out of encrypted storage.
         * Null — no reader wired — means the association is made and no
         * receipt is filed, which is the same visible degradation as no
         * actor.
         *
         * @var (\Closure(int): ?string)|null
         */
        private ?\Closure $readFile = null,
        /**
         * « Cette adresse anime-t-elle un seul staff ? ». Null leaves the
         * IBAN as the only signal — which is what this consumer was before
         * the resolver existed, and a complete behaviour rather than a
         * broken one.
         */
        private ?SenderStaffAccountResolver $senderStaff = null,
        /**
         * Reads the original sender out of a forwarded body. Null simply
         * means a forwarded message is judged on its real `From:`, which
         * is the trustworthy half of the signal anyway.
         */
        private ?ForwardedSenderExtractor $forwardedSender = null,
        /**
         * Who tells the treasurers that a receipt waits for them
         * (`Mail\FinanceMailNotifier`). Null: nobody is told, and the
         * proposition still waits in « Courrier à trier ».
         */
        private ?FinanceMailNotifier $notifier = null
    ) {
    }

    /**
     * A receipt proposed towards accounts of this module: the treasurers
     * are told (`Api\PropositionListener`).
     *
     * @param \Modules\InboundMail\Api\MessageCandidate[] $candidates
     */
    public function onProposed(InboundMessage $message, array $candidates): void
    {
        if ($this->notifier === null) {
            return;
        }

        $labels = [];
        foreach ($candidates as $candidate) {
            $label = $this->describeReference($candidate->businessReference);
            if ($label !== null && !in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        $this->notifier->proposed($labels);
    }

    public function consumerId(): string
    {
        return self::CONSUMER_ID;
    }

    public function displayName(): string
    {
        return 'Finances';
    }

    // ── Api\ReferenceDirectory: the accounts as a treasurer names them ──

    /**
     * @return ReferenceSuggestion[]
     */
    public function searchReferences(string $query, int $limit = 10): array
    {
        $needle = \Core\Service\TextNormalizerService::fold(trim($query));
        if ($needle === '') {
            return [];
        }

        $suggestions = [];
        foreach ($this->accounts->findAllOrdered() as $account) {
            if ($account->status !== Account::STATUS_ACTIVE) {
                continue;
            }

            $isExact = self::referenceFor($account->id) === trim($query);
            if ($isExact || str_contains(\Core\Service\TextNormalizerService::fold($account->name), $needle)) {
                $suggestion = new ReferenceSuggestion(self::referenceFor($account->id), $account->name, 'Compte');
                $isExact ? array_unshift($suggestions, $suggestion) : $suggestions[] = $suggestion;
            }
        }

        // The sorting pile is a place too: « je ne sais pas quel compte »
        // is an answer a chief may give, and it is where the treasury
        // sorts.
        if (trim($query) === self::REFERENCE_UNKNOWN || str_contains('compte inconnu', $needle)) {
            $suggestions[] = new ReferenceSuggestion(
                self::REFERENCE_UNKNOWN,
                'Compte inconnu',
                'La pile que la trésorerie trie'
            );
        }

        return array_slice($suggestions, 0, max(1, $limit));
    }

    public function referenceUrl(string $businessReference): ?string
    {
        if ($businessReference === self::REFERENCE_UNKNOWN) {
            return '/finance/receipts?account_id=unassigned';
        }

        $accountId = self::accountIdFromReference($businessReference);

        return $accountId !== null && $this->accounts->findById($accountId) !== null
            ? '/finance/receipts?account_id=' . $accountId
            : null;
    }

    public static function referenceFor(int $accountId): string
    {
        return self::REFERENCE_PREFIX . $accountId;
    }

    public static function accountIdFromReference(string $reference): ?int
    {
        if (!str_starts_with($reference, self::REFERENCE_PREFIX) || $reference === self::REFERENCE_UNKNOWN) {
            return null;
        }

        $id = (int) substr($reference, strlen(self::REFERENCE_PREFIX));

        return $id > 0 ? $id : null;
    }

    /**
     * Everything this module needs is already on arrival: the attachment
     * metadata says whether there is a receipt-shaped file, and the IBAN
     * is in the text.
     *
     * Reading the PDF itself would be the obvious next step and is
     * deliberately not taken: it is `analyzeStored()`'s territory, it
     * costs an extraction per message, and the account is named in the
     * covering email far more often than inside the invoice.
     */
    public function analyze(CandidateMessage $message): AnalysisResult
    {
        if (!$message->hasAttachmentOfType(self::RECEIPT_MIME_TYPES)) {
            return AnalysisResult::nothing();
        }

        // The IBAN first, and it wins outright. It is a statement about the
        // MONEY, made inside the document's own covering text; the sender
        // is a statement about a person, and a person can be wrong about
        // which account an expense belongs to in a way an IBAN cannot.
        $account = $this->theOneAccountNamed(self::readableText($message));
        $staffAccount = $this->accountOfTheSendersStaff($message);

        if ($account !== null) {
            // An association rather than a proposition in two cases, and
            // only those: the box is the treasury's own — the operator has
            // said everything arriving here is this module's business,
            // and the worst outcome is a receipt on the wrong account,
            // which « Changer de compte » corrects — or the sender's own
            // section says the same account the IBAN does, two independent
            // statements agreeing.
            if ($message->mailboxIsDedicatedTo(self::CONSUMER_ID)
                || ($staffAccount !== null && $staffAccount->id === $account->id)
            ) {
                return AnalysisResult::linkedTo(self::CONSUMER_ID, self::referenceFor($account->id), LinkOrigin::IBAN);
            }

            return AnalysisResult::proposing(new MessageCandidate(
                businessReference: self::referenceFor($account->id),
                label: $account->name,
                evidenceType: 'iban_in_body',
                explanation: 'Le message porte une pièce jointe et cite l\'IBAN de ce compte. '
                    . 'C\'est un signal faible : rien n\'est enregistré tant qu\'un trésorier ne l\'a pas confirmé.'
            ));
        }

        if ($staffAccount !== null) {
            return AnalysisResult::linkedTo(self::CONSUMER_ID, self::referenceFor($staffAccount->id), LinkOrigin::SENDER);
        }

        // Nothing places it. On the unit's PUBLIC address that is where
        // this stops: a photo attached to a parent's message is not a
        // receipt, and turning every one of them into something a treasurer
        // must sort would bury the real ones within a week. On a box the
        // unit declared to be its treasury's, the operator has already said
        // that everything arriving here is this module's business — so the
        // document is kept, unplaced, rather than lost.
        if ($message->mailboxIsDedicatedTo(self::CONSUMER_ID)) {
            return AnalysisResult::linkedTo(self::CONSUMER_ID, self::REFERENCE_UNKNOWN, LinkOrigin::ATTACHMENT);
        }

        return AnalysisResult::nothing();
    }

    /**
     * The account of the staff this message's sender animates — the real
     * `From:` first, and only then the address a forwarded body names.
     *
     * That order is the whole safety of it. An animateur forwarding a
     * supplier's receipt to the treasury address **is** the `From:`, so the
     * common case never reaches the body at all; the body is consulted only
     * when the real sender resolved to nobody, which is when it can add
     * something. It stays untrusted text either way, and what it can win is
     * bounded by what follows it: the address must resolve to a member who
     * animates exactly one staff holding exactly one active account
     * (`SenderStaffAccountResolver`), so an invented address wins nothing.
     */
    private function accountOfTheSendersStaff(CandidateMessage $message): ?Account
    {
        if ($this->senderStaff === null) {
            return null;
        }

        $account = $this->senderStaff->resolve($message->fromEmail);
        if ($account !== null) {
            return $account;
        }

        $forwarded = $this->forwardedSender?->extract($message->bodyText, $message->bodyHtml);

        return $forwarded === null ? null : $this->senderStaff->resolve($forwarded);
    }

    /**
     * Nothing on the deferred pass.
     *
     * Reading an invoice's own text would need an extraction per message
     * and would answer a question the covering email usually answers
     * already. Adding it later is a change to this class alone.
     */
    public function analyzeStored(InboundMessage $message): AnalysisResult
    {
        return AnalysisResult::nothing();
    }

    /**
     * A treasurer confirmed: the attachments become receipts on that
     * account.
     *
     * **Only when there is an actor.** `$link->createdByUserAccountId` is
     * set when a person made the association and null when a machine did;
     * finance's authorisation is built from the actor, and inventing one
     * here would be this module granting itself an account it may not
     * touch. No actor therefore means the association is made and no
     * receipt is filed — visible on the screen, rather than silent.
     */
    public function onLinked(InboundMessage $message, MessageLink $link): void
    {
        $unattributed = $link->businessReference === self::REFERENCE_UNKNOWN;
        $accountId = $unattributed ? null : self::accountIdFromReference($link->businessReference);

        if ((!$unattributed && $accountId === null) || $this->receipts === null || $this->readFile === null) {
            return;
        }

        // Who is filing this, if anybody. A person confirming a proposition
        // has finance's authorization built from THEM; an association this
        // module made on its own has nobody, and takes the unattended route
        // instead (Api\ExpenseReceiptInterface::storeUnattendedReceipt()).
        $actor = $link->createdByUserAccountId !== null && $this->resolveActor !== null
            ? ($this->resolveActor)($link->createdByUserAccountId)
            : null;

        // A person made the association but could not be resolved into a
        // role and members: nothing is filed. Inventing an actor would be
        // this module granting itself an account it may not touch — and
        // falling through to the unattended route would be worse still,
        // since that one exists for associations NOBODY made.
        if ($link->createdByUserAccountId !== null && $actor === null) {
            return;
        }

        foreach ($message->attachments as $attachment) {
            if (!in_array($attachment->mimeType, self::RECEIPT_MIME_TYPES, true)) {
                continue;
            }

            // Scoped to one attachment when the association is
            // attachment-level, which is what a proposition about a single
            // invoice in a message carrying three files means.
            if ($link->attachmentId !== 0 && $link->attachmentId !== $attachment->id) {
                continue;
            }

            $content = ($this->readFile)($attachment->fileId);
            if ($content === null || $content === '') {
                continue;
            }

            try {
                if ($actor === null) {
                    $this->receipts->storeUnattendedReceipt(
                        $content,
                        $attachment->mimeType,
                        $attachment->filename,
                        $accountId
                    );
                    continue;
                }

                $this->receipts->storeReceipt(
                    $content,
                    $attachment->mimeType,
                    $attachment->filename,
                    (int) $accountId,
                    null,
                    null,
                    $actor['role'],
                    $actor['member_ids'],
                    $link->createdByUserAccountId
                );
            } catch (\Throwable) {
                // Finance refused the account, or the bytes could not be
                // read. Neither is worth failing the association over: the
                // message is on the account either way, and a treasurer
                // can attach the file by hand from the receipts screen.
                continue;
            }
        }
    }

    /**
     * Nothing to take back.
     *
     * A receipt is an accounting document. Once a treasurer has filed one,
     * detaching the email it arrived in is a statement about the mail, not
     * about the books — and quietly deleting a receipt because somebody
     * tidied a mailbox is exactly the kind of surprise §8.70 exists to
     * prevent. The receipts screen is where a receipt is removed.
     */
    public function onUnlinked(InboundMessage $message, MessageLink $link): void
    {
    }

    /**
     * @param array<int, int> $linkedMemberIds
     */
    public function canRead(string $businessReference, array $linkedMemberIds, string $role): bool
    {
        // The same questions the finance screens ask — deliberately,
        // because a message filed against an account and the account's own
        // movements must not have two different rules, and neither must a
        // message in the sorting pile and the receipt it produced.
        $visibility = new AccountVisibility(
            TreasurerScope::forSession($this->treasurerScopeService, $linkedMemberIds, $this->scoutYearId)
        );

        if ($businessReference === self::REFERENCE_UNKNOWN) {
            return $visibility->isUnassignedReceiptVisibleTo(Role::fromString($role));
        }

        $accountId = self::accountIdFromReference($businessReference);
        if ($accountId === null) {
            return false;
        }

        return $visibility->isVisibleTo($this->accounts->findById($accountId), Role::fromString($role));
    }

    public function describeReference(string $businessReference): ?string
    {
        if ($businessReference === self::REFERENCE_UNKNOWN) {
            return 'compte inconnu';
        }

        $accountId = self::accountIdFromReference($businessReference);
        if ($accountId === null) {
            return null;
        }

        // Null for an account that has since been deleted: the screen then
        // shows the raw reference, which is more honest than a name for
        // something that is gone.
        return $this->accounts->findById($accountId)?->name;
    }

    /**
     * @return string[]
     */
    public function describeEvidence(): array
    {
        return [
            'pièce jointe de type reçu (PDF, image) ET IBAN d\'un compte de l\'unité cité dans le message '
                . '— signal faible, toujours une proposition',
            'pièce jointe de type reçu ET adresse d\'un animateur d\'un seul staff (expéditeur, ou expéditeur '
                . 'd\'origine d\'un message transféré) — le reçu est classé directement sur le compte de ce '
                . 'staff, sans confirmation ; une adresse d\'expéditeur n\'est jamais authentifiée',
            'sur une boîte dédiée aux finances uniquement : pièce jointe de type reçu que rien ne permet '
                . 'de rattacher — le reçu est conservé sans compte, à trier par la trésorerie',
        ];
    }

    public function triageAudienceLabel(): string
    {
        return 'la trésorerie et le staff d\'unité';
    }

    /**
     * Who would actually see this module's mail: whoever may see at least
     * one account, which the treasurer scope already answers.
     *
     * Exact rather than estimated — the warning that shows this figure is
     * the only guard-rail on opening a shared mailbox to a module.
     */
    public function triageAudienceCount(): int
    {
        // Staff d'unité and above. A section treasurer holds a chief or
        // admin function too (`TreasurerScopeService` requires it before
        // the badge even counts), so they are already in this figure —
        // counting them again through the badge would inflate the number
        // the superadmin is asked to weigh.
        $stmt = $this->pdo->query(
            'SELECT COUNT(DISTINCT my.member_id)
               FROM member_years my
               JOIN member_functions mf ON mf.member_year_id = my.id
               JOIN functions f ON mf.function_id = f.id
               JOIN scout_years sy ON sy.id = my.scout_year_id
              WHERE sy.is_current = 1 AND my.is_active = 1
                AND f.role IN (\'chief\', \'admin\', \'superadmin\')'
        );

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * The single account whose IBAN the text names — or null, which covers
     * both "none" and "more than one".
     *
     * Two matches is silence rather than two propositions: an email
     * quoting two of the unit's accounts is almost always a transfer
     * between them, and a transfer's receipt belongs to neither side by
     * default.
     */
    private function theOneAccountNamed(string $text): ?Account
    {
        $found = [];

        foreach ($this->ibansIn($text) as $iban) {
            // Through the blind index, so an IBAN is never decrypted to
            // answer the question — the same route the statement import
            // takes to verify a source IBAN (§8.69).
            $account = $this->accounts->findByIbanBlindIndex(
                $this->encryption->blindIndex($iban, 'finance_iban')
            );
            if ($account !== null && $account->status === Account::STATUS_ACTIVE) {
                $found[$account->id] = $account;
            }
        }

        return count($found) === 1 ? array_values($found)[0] : null;
    }

    /**
     * The subject, the text body and the text of the HTML body.
     *
     * The HTML part too, because a message written on a phone or in
     * Outlook often has no text part worth the name — and the IBAN a
     * supplier pastes into it was invisible to a search of `bodyText`
     * alone, while the very same class already fell back to the HTML to
     * find a forwarded sender.
     */
    private static function readableText(CandidateMessage $message): string
    {
        $html = $message->bodyHtml === ''
            ? ''
            : html_entity_decode(
                strip_tags(preg_replace('/<[^>]+>/', ' ', $message->bodyHtml) ?? ''),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

        return $message->subject . "\n" . $message->bodyText . "\n" . $html;
    }

    /**
     * Every IBAN-shaped run in the text, normalised.
     *
     * A pattern rather than a parser: what comes back is checked against
     * the unit's own accounts, so a false positive costs a lookup that
     * finds nothing rather than a wrong answer.
     *
     * @return string[]
     */
    private function ibansIn(string $text): array
    {
        // What a bank site or a `&nbsp;` in HTML puts between the groups
        // — a no-break space, a narrow one, a figure space — and the
        // hyphen some people type instead: all read as the plain space
        // the pattern below expects. Before this, `BE68 5390 0754 7034`
        // copied from a banking site gave zero matches.
        $text = preg_replace('/[\x{00A0}\x{202F}\x{2007}]/u', ' ', $text) ?? $text;
        $text = preg_replace('/(?<=[A-Za-z0-9])-(?=[A-Za-z0-9]{2,4}\b)/', ' ', $text) ?? $text;

        // Single spaces only, and never a newline: a character class that
        // included `\s` would run past the end of the IBAN and swallow the
        // start of the next line, turning a perfectly good match into an
        // invalid one. Groups of two to four are how an IBAN is written
        // when it is written for a human to read.
        $pattern = '/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{2,4}){2,8}\b/i';
        $ibans = [];
        $offset = 0;

        while (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            [$raw, $at] = $match[0];
            $offset = $at + strlen($raw);

            // The greedy run swallows whatever short token follows on the
            // same line — « … 7034 BIC », or the « vers » between two
            // accounts in « de BE92 … vers BE71 … » — so the trailing
            // groups are dropped one at a time until what is left is a
            // valid IBAN, and the scan resumes right after it rather than
            // after everything the run swallowed.
            $groups = preg_split('/ /', trim($raw)) ?: [];
            while ($groups !== []) {
                $candidate = implode(' ', $groups);
                $normalized = IbanNormalizer::normalize($candidate);
                if (IbanNormalizer::isValidFullIban($normalized)) {
                    $ibans[$normalized] = $normalized;
                    $offset = $at + strlen($candidate);
                    break;
                }
                if (!IbanNormalizer::looksLikeFullIban($normalized)) {
                    break;
                }
                array_pop($groups);
            }
        }

        return array_values($ibans);
    }
}
