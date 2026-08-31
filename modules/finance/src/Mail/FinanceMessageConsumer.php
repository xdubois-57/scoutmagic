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
use Modules\Finance\Service\TreasurerScope;
use Modules\Finance\Service\TreasurerScopeService;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;

/**
 * An invoice arriving by email, offered as a receipt on the account it
 * names (§8.58's consumer contract, §8.70's receipts).
 *
 * **This module never associates anything on its own.** Every answer here
 * is a *proposition*: a treasurer confirms it, and only then does anything
 * become a receipt. That is not caution for its own sake — a receipt is an
 * accounting document, and a wrong one is worse than a missing one because
 * it silently balances against the wrong account.
 *
 * **Two signals, and both are required.**
 *
 * 1. An **attachment** of a type a receipt can be — a PDF, an image, an
 *    office document. Without one there is nothing to file, however much
 *    the message talks about money.
 * 2. **Exactly one of the unit's own IBANs**, found in the message's text.
 *    That is what says *which* account, and this module refuses to guess:
 *    zero matches means silence, two matches means silence. An IBAN is
 *    matched through its blind index, so nothing is ever decrypted to
 *    answer the question.
 *
 * A weak signal, and the configuration screen says so out loud
 * (`describeEvidence()`). The superadmin reads that sentence before
 * opening a shared mailbox to this module, which is the whole point of
 * making each consumer publish what it proposes on.
 *
 * **Strictly additive.** Nothing in the finance module changed for this:
 * the consumer reaches finance through `Api\ExpenseReceiptInterface`,
 * which already existed for the federation-invoice flow, and through
 * `Service\AccountVisibility`, which already answers "may this role see
 * this account". A consumer that needed finance to change would have been
 * a consumer built at the wrong layer.
 */
class FinanceMessageConsumer implements MessageConsumerInterface
{
    public const CONSUMER_ID = 'finance';

    /** `account-{id}` — never a bare id, which would collide with another module's. */
    public const REFERENCE_PREFIX = 'account-';

    /**
     * What a receipt can be. Deliberately narrower than what
     * `inbound_mail` keeps: a spreadsheet is a document, and an accounting
     * receipt it is not.
     */
    public const RECEIPT_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/heic',
        'image/webp',
    ];

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
        private ?\Closure $readFile = null
    ) {
    }

    public function consumerId(): string
    {
        return self::CONSUMER_ID;
    }

    public function displayName(): string
    {
        return 'Finances';
    }

    public static function referenceFor(int $accountId): string
    {
        return self::REFERENCE_PREFIX . $accountId;
    }

    public static function accountIdFromReference(string $reference): ?int
    {
        if (!str_starts_with($reference, self::REFERENCE_PREFIX)) {
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

        $account = $this->theOneAccountNamed($message->subject . "\n" . $message->bodyText);
        if ($account === null) {
            return AnalysisResult::nothing();
        }

        return AnalysisResult::proposing(new MessageCandidate(
            businessReference: self::referenceFor($account->id),
            label: $account->name,
            evidenceType: 'iban_in_body',
            explanation: 'Le message porte une pièce jointe et cite l\'IBAN de ce compte. '
                . 'C\'est un signal faible : rien n\'est enregistré tant qu\'un trésorier ne l\'a pas confirmé.'
        ));
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
        $accountId = self::accountIdFromReference($link->businessReference);
        if ($accountId === null
            || $this->receipts === null
            || $this->resolveActor === null
            || $this->readFile === null
        ) {
            return;
        }

        if ($link->createdByUserAccountId === null) {
            return;
        }

        $actor = ($this->resolveActor)($link->createdByUserAccountId);
        if ($actor === null) {
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
                $this->receipts->storeReceipt(
                    $content,
                    $attachment->mimeType,
                    $attachment->filename,
                    $accountId,
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
        $accountId = self::accountIdFromReference($businessReference);
        if ($accountId === null) {
            return false;
        }

        // The same question the finance screens ask about that account —
        // deliberately, because a message filed against an account and the
        // account's own movements must not have two different rules.
        return (new AccountVisibility(
            TreasurerScope::forSession($this->treasurerScopeService, $linkedMemberIds, $this->scoutYearId)
        ))->isVisibleTo($this->accounts->findById($accountId), Role::fromString($role));
    }

    /**
     * @return string[]
     */
    public function describeEvidence(): array
    {
        return [
            'pièce jointe de type reçu (PDF, image) ET IBAN d\'un compte de l\'unité cité dans le message '
                . '— signal faible, toujours une proposition',
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
        // Single spaces only, and never a newline: a character class that
        // included `\s` would run past the end of the IBAN and swallow the
        // start of the next line, turning a perfectly good match into an
        // invalid one. Groups of two to four are how an IBAN is written
        // when it is written for a human to read.
        if (preg_match_all('/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{2,4}){2,8}\b/i', $text, $matches) === false) {
            return [];
        }

        $ibans = [];
        foreach ($matches[0] as $raw) {
            $normalized = IbanNormalizer::normalize($raw);
            if (IbanNormalizer::isValidFullIban($normalized)) {
                $ibans[$normalized] = $normalized;
            }
        }

        return array_values($ibans);
    }
}
