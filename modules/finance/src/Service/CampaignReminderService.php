<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Member\MemberService;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Campaign;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Repository\ExpectedReceivable;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\MassMail\Api\MassMailDraftInterface;

/**
 * Turns a campaign's outstanding receivables into a mail-merge **draft**.
 *
 * **Nothing is ever sent from here.** The draft carries its audience, a
 * starting text, the payment details and the QR of each receivable; the
 * treasurer reads it, edits it, and sends it from the mail-merge screen
 * like any other. That is also why the "familles notifiées" mark is a
 * separate, explicit gesture: the mail leaves by hand, so the site cannot
 * know when the request actually went out, and a notification fired at
 * draft time would announce a message nobody has written yet.
 *
 * **One row per receivable, attached to its child** (issue #999). Each
 * row carries the child's `member_id`, so mass_mail treats it exactly as
 * an imported line with a « Tiers »: its messages go to that child's
 * eligible addresses, and the reminder appears on THAT child's page. A
 * family of three children sharing one address therefore receives three
 * mails, one per child — never one mail grouping them, which is what
 * used to put a reminder on nobody's page.
 *
 * **"One transfer per child" now holds by construction.** Every mail
 * carries exactly one payment block — a first name, an amount, a
 * communication, a QR — so it can only invite one transfer, the one that
 * makes the payment identifiable when it lands. The instruction still
 * says its own reason, because the rule unexplained reads as bureaucracy.
 * And no child is ever left out: the body used to carry one section per
 * child ({{#Prénom N}}) up to a cap, and the children beyond it were
 * dropped without a word.
 *
 * Who is reminded has not changed: a receivable still due whose child has
 * an address in Desk. Which addresses the mail actually goes to is no
 * longer decided here — that is mass_mail's rule for a member, the same
 * as for any other publipostage line.
 *
 * **The QR is a URL, and the body repeats everything it encodes in
 * text.** Many mail clients block remote images by default, so the parent
 * sees an empty frame until they allow them: an image that does not load
 * must never leave somebody without a way to pay.
 *
 * mass_mail is an optional dependency (ARCHITECTURE.md §7.5): with the
 * module disabled the button simply is not offered and the campaign works
 * exactly as before.
 */
class CampaignReminderService
{
    /**
     * The audience's payment columns. Imported columns follow them in
     * the composer; these calculated values win on a matching name.
     */
    public const COLUMNS = ['Prénom', 'Montant', 'Communication', 'QR', 'Bénéficiaire', 'IBAN'];

    public function __construct(
        private CampaignRowRepository $rows,
        private ExpectedReceivableRepository $receivables,
        private ReceivableAllocationService $allocations,
        private AccountRepository $accountRepository,
        private MemberService $members,
        private ReceivableQrTokenService $qrTokens,
        private string $baseUrl,
        private ?MassMailDraftInterface $massMailDraft = null
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->massMailDraft !== null;
    }

    /**
     * Creates the draft and returns the composer's URL.
     *
     * @throws FinanceException when there is nothing to remind anybody
     *         about, or when the mail-merge module is not there
     */
    public function createDraft(Campaign $campaign, string $actorRole, string $actorEmail, ?int $actorAccountId): string
    {
        if ($this->massMailDraft === null) {
            throw new FinanceException("Le module de publipostage n'est pas activé.");
        }

        $account = $this->accountRepository->findById($campaign->accountId);
        if ($account === null || $account->iban === null || $account->iban === '') {
            throw new FinanceException(
                "Le compte de cette campagne n'a pas d'IBAN configuré : le rappel n'aurait aucun moyen de paiement à "
                    . "donner."
            );
        }

        $demands = $this->buildDemands($campaign);
        if ($demands === []) {
            throw new FinanceException("Aucune créance à rappeler : soit tout est réglé, soit aucune adresse n'est "
                . "connue.");
        }

        $beneficiary = $account->holderName ?? $account->name;
        $iban = IbanNormalizer::format(IbanNormalizer::normalize($account->iban));

        $rows = [];
        $columns = self::COLUMNS;
        $calculatedColumns = array_fill_keys(
            array_map(static fn(string $column): string => mb_strtolower(trim($column)), self::COLUMNS),
            true
        );
        foreach ($demands as $demand) {
            // The renderer matches column names without case or padding.
            $mergeData = array_filter(
                $demand['merge_data'],
                static fn(int|string $column): bool => !isset($calculatedColumns[mb_strtolower(trim((string) $column))]),
                ARRAY_FILTER_USE_KEY
            );
            // No `email`: with a member_id, mass_mail sends to that
            // member's addresses and would ignore one given here anyway.
            $rows[] = [
                'member_id' => $demand['member_id'],
                'values' => [
                    'Prénom' => $demand['name'],
                    'Montant' => self::euros($demand['amount_cents']),
                    'Communication' => $demand['communication'],
                    'QR' => $this->qrTokens->urlFor($demand['receivable_id'], $this->baseUrl),
                    'Bénéficiaire' => $beneficiary,
                    'IBAN' => $iban,
                ] + $mergeData,
            ];
            $columns = array_values(array_unique([...$columns, ...array_map('strval', array_keys($mergeData))]));
        }

        $emptyValues = array_fill_keys($columns, '');
        foreach ($rows as $index => $row) {
            // A known column without a value must render empty, not as a token.
            $rows[$index]['values'] += $emptyValues;
        }

        return $this->massMailDraft->createMergeDraft(
            'Rappel — ' . $campaign->label,
            'Rappel — ' . $campaign->label,
            $columns,
            $rows,
            $actorRole,
            $actorEmail,
            $actorAccountId,
            $this->draftBody()
        );
    }

    /**
     * The starting body, offered to the composer.
     *
     * Public so a test can read the promises it makes without going
     * through the mail-merge module: one payment block, the payment
     * details in text under the QR, and the reason for asking one
     * transfer per child. It holds no section ({{#…}}): every row fills
     * every variable, so nothing in it is ever conditional.
     */
    public function draftBody(): string
    {
        return '<p>Bonjour,</p>'
            . '<p>Il reste {{Montant}} à régler pour {{Prénom}}.</p>'
            . '<p><img src="{{QR}}" alt="QR de paiement" width="200" height="200"></p>'
            . '<p>Bénéficiaire : {{Bénéficiaire}}<br>'
            . 'IBAN : {{IBAN}}<br>'
            . 'Communication : {{Communication}}<br>'
            . 'Montant : {{Montant}}</p>'
            . '<p><strong>Un virement par enfant, s\'il vous plaît</strong>, avec cette communication : c\'est ce '
            . "qui nous permet d'identifier chaque paiement à son arrivée. Si vous avez plusieurs enfants dans "
            . "l'unité, chacun reçoit son propre e-mail.</p>"
            . "<p>Si le code ne s'affiche pas, votre logiciel de messagerie bloque les images : les "
            . 'informations sous le code suffisent pour faire le virement à la main.</p>'
            . '<p>Merci, et bonne route.</p>';
    }

    /**
     * The receivables still due, one entry each, in the campaign's order.
     *
     * Never grouped by address and never capped: one receivable is one
     * row of the audience, so a family of seven gets seven reminders and
     * none of the seven is left out.
     *
     * @return list<array{member_id: int, name: string, amount_cents: int, communication: string, receivable_id: int, merge_data: array<string, string>}>
     */
    private function buildDemands(Campaign $campaign): array
    {
        $rows = $this->rows->findByCampaignId($campaign->id);
        if ($rows === []) {
            return [];
        }

        $receivablesByRowId = $this->receivables->findBySourceReferenceIds(
            CampaignService::SOURCE_MODULE,
            array_map(static fn($row): int => $row->id, $rows)
        );
        $settlements = $this->allocations->refreshAndSettle(array_values($receivablesByRowId));

        $memberIds = array_map(static fn($row): int => $row->memberId, $rows);
        $emails = $this->members->findEmailsByMemberIds($memberIds, $campaign->scoutYearId);

        $names = [];
        foreach ($this->members->findDirectoryForYear($campaign->scoutYearId) as $entry) {
            $names[$entry->memberId] = trim($entry->firstName) !== '' ? $entry->firstName : $entry->displayName;
        }

        $demands = [];
        foreach ($rows as $row) {
            $receivable = $receivablesByRowId[$row->id] ?? null;
            $settlement = $receivable !== null ? ($settlements[$receivable->id] ?? null) : null;
            if ($receivable === null || $settlement === null) {
                continue;
            }
            // Nothing left to ask for: settled, or abandoned. A reminder
            // sent to somebody who has paid is worse than no reminder.
            if ($settlement->amountRemainingCents() <= 0 || $settlement->isWaived()) {
                continue;
            }

            // The same rule as before about WHO is reminded: a child with
            // no address in Desk is left out. The address itself is not
            // passed on — the member_id decides where the mail goes.
            $email = $emails[$row->memberId] ?? null;
            if ($email === null || trim($email) === '') {
                continue;
            }

            $demands[] = [
                'member_id' => $row->memberId,
                'name' => $names[$row->memberId] ?? ('Membre #' . $row->memberId),
                'amount_cents' => $settlement->amountRemainingCents(),
                'communication' => $receivable->communication,
                'receivable_id' => $receivable->id,
                'merge_data' => $row->mergeData,
            ];
        }

        return $demands;
    }

    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}
