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
 * **One autonomous block per receivable, never a table.** A table of
 * three lines invites one transfer for the total; three separate blocks —
 * a first name, an amount, a communication, a QR — invite three
 * transfers, which is what makes each payment identifiable when it lands.
 * The instruction says its own reason, because "one transfer per child"
 * with no explanation reads as bureaucracy.
 *
 * The body is a template with one section per block
 * ({{#Prénom 2}}…{{/Prénom 2}}, Service\MergeRenderer) rather than a
 * fixed number of blocks: a household with one child must not receive the
 * empty blocks the household with three needs. The merge values are
 * escaped on render — deliberately, they are the audience's data — which
 * is exactly why the variable part has to live in the template.
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
    /** Beyond this, the body would be a wall of blocks nobody reads. */
    private const MAX_BLOCKS = 6;

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

        $recipients = $this->buildRecipients($campaign);
        if ($recipients === []) {
            throw new FinanceException("Aucune créance à rappeler : soit tout est réglé, soit aucune adresse n'est "
                . "connue.");
        }

        $maxBlocks = 0;
        foreach ($recipients as $recipient) {
            $maxBlocks = max($maxBlocks, count($recipient['demands']));
        }

        $columns = $this->columns($maxBlocks);
        $rows = [];
        foreach ($recipients as $email => $recipient) {
            $rows[] = [
                'email' => (string) $email,
                'values' => $this->valuesFor($recipient, $maxBlocks, $account->holderName ?? $account->name,
                    IbanNormalizer::format(IbanNormalizer::normalize($account->iban))),
            ];
        }

        return $this->massMailDraft->createMergeDraft(
            'Rappel — ' . $campaign->label,
            'Rappel — ' . $campaign->label,
            $columns,
            $rows,
            $actorRole,
            $actorEmail,
            $actorAccountId,
            $this->draftBody($maxBlocks)
        );
    }

    /**
     * The starting body, offered to the composer.
     *
     * Public so the controller can hand it over and so a test can read
     * the promises it makes without going through the mail-merge module:
     * one block per receivable, the payment details in text under each
     * QR, and the reason for asking one transfer per child.
     */
    public function draftBody(int $maxBlocks): string
    {
        $blocks = '';
        for ($index = 1; $index <= max(1, $maxBlocks); $index++) {
            $blocks .= '{{#Prénom ' . $index . '}}'
                . '<p>———</p>'
                . '<p><strong>{{Prénom ' . $index . '}} — {{Montant ' . $index . '}}</strong></p>'
                . '<p><img src="{{QR ' . $index . '}}" alt="QR de paiement" width="200" height="200"></p>'
                . '<p>Bénéficiaire : {{Bénéficiaire}}<br>'
                . 'IBAN : {{IBAN}}<br>'
                . 'Communication : {{Communication ' . $index . '}}<br>'
                . 'Montant : {{Montant ' . $index . '}}</p>'
                . '{{/Prénom ' . $index . '}}';
        }

        return '<p>Bonjour,</p>'
            . '<p>Il reste {{Total}} à régler pour votre famille.</p>'
            . $blocks
            . '<p>———</p>'
            . '<p><strong>Un virement par demande, s\'il vous plaît</strong> — c\'est ce qui nous permet '
            . "d'identifier chaque paiement à son arrivée.</p>"
            . "<p>Si le code ne s'affiche pas, votre logiciel de messagerie bloque les images : les "
            . 'informations sous chaque code suffisent pour faire le virement à la main.</p>'
            . '<p>Merci, et bonne route.</p>';
    }

    /**
     * Recipients keyed by address, each with the receivables that address
     * is responsible for.
     *
     * Grouped by ADDRESS rather than by member because that is what a
     * mail-merge audience is: one row per address, deduplicated by the
     * mail-merge module itself. A parent of three receives one mail
     * carrying three blocks, never three mails.
     *
     * @return array<
     *     string,
     *     array{
     *         demands: array<int, array{name: string, amount_cents: int, communication: string, receivable_id: int}>,
     *         total: int
     *     }
     * >
     */
    private function buildRecipients(Campaign $campaign): array
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

        $recipients = [];
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

            $email = $emails[$row->memberId] ?? null;
            if ($email === null) {
                continue;
            }

            $key = strtolower(trim($email));
            $recipients[$key] ??= ['demands' => [], 'total' => 0];
            if (count($recipients[$key]['demands']) >= self::MAX_BLOCKS) {
                continue;
            }

            $recipients[$key]['demands'][] = [
                'name' => $names[$row->memberId] ?? ('Membre #' . $row->memberId),
                'amount_cents' => $settlement->amountRemainingCents(),
                'communication' => $receivable->communication,
                'receivable_id' => $receivable->id,
            ];
            $recipients[$key]['total'] += $settlement->amountRemainingCents();
        }

        return $recipients;
    }

    /**
     * @return string[]
     */
    private function columns(int $maxBlocks): array
    {
        $columns = ['Bénéficiaire', 'IBAN', 'Total'];
        for ($index = 1; $index <= max(1, $maxBlocks); $index++) {
            $columns[] = 'Prénom ' . $index;
            $columns[] = 'Montant ' . $index;
            $columns[] = 'Communication ' . $index;
            $columns[] = 'QR ' . $index;
        }

        return $columns;
    }

    /**
     * @param array{
     *     demands: array<int, array{name: string, amount_cents: int, communication: string, receivable_id: int}>,
     *     total: int
     * } $recipient
     * @return array<string, string>
     */
    private function valuesFor(array $recipient, int $maxBlocks, string $beneficiary, string $iban): array
    {
        $values = [
            'Bénéficiaire' => $beneficiary,
            'IBAN' => $iban,
            'Total' => self::euros($recipient['total']),
        ];

        for ($index = 1; $index <= max(1, $maxBlocks); $index++) {
            $demand = $recipient['demands'][$index - 1] ?? null;
            // An absent block leaves EVERY one of its columns empty: the
            // section keyed on "Prénom N" is what removes it, and a
            // stray value in a sibling column would be rendered nowhere
            // but would still show up in the composer's preview.
            $values['Prénom ' . $index] = $demand['name'] ?? '';
            $values['Montant ' . $index] = $demand !== null ? self::euros($demand['amount_cents']) : '';
            $values['Communication ' . $index] = $demand['communication'] ?? '';
            $values['QR ' . $index] = $demand !== null
                ? $this->qrTokens->urlFor($demand['receivable_id'], $this->baseUrl)
                : '';
        }

        return $values;
    }

    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}
