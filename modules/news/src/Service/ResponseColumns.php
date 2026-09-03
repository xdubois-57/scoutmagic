<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormResponse;
use Modules\News\Repository\NewsForm;

/**
 * **One definition of a form's response columns**, consumed by the XLSX
 * export and by the mail-merge variables (ARCHITECTURE.md §8.71quater).
 *
 * There used to be two. `FormController::responseColumns()` built the
 * merge variables and claimed in its own docblock that the two surfaces
 * « cannot describe the same form differently » — which had already
 * stopped being true: the export carried « Montant attendu », « Montant
 * reçu », « Communication structurée » and « Statut paiement », and the
 * variables did not.
 *
 * The argument for leaving them out — *accounting figures, not something
 * to insert in a mail to the respondent* — was too broad. It holds for a
 * chief writing « rendez-vous samedi à 18h ». It does not hold for a
 * payment reminder, where the amount still owed **is** the message. So
 * every column is now a variable like any other, and there is one list to
 * add the next one to.
 *
 * Every value is French and readable by a family: a switch answers
 * « Oui »/« Non », a payment status « Payé »/« Partiel »/« Non payé »,
 * an amount « 45,00 € ». Nothing here can render an internal code.
 */
class ResponseColumns
{
    /** The first column, and the mail merge's address column. */
    public const CONTACT = 'Contact';
    public const AMOUNT_DUE = 'Montant attendu';
    public const AMOUNT_RECEIVED = 'Montant reçu';
    public const STRUCTURED_COMMUNICATION = 'Communication structurée';
    public const PAYMENT_STATUS = 'Statut paiement';
    public const TICKET_REFERENCE = 'Référence du billet';
    public const TICKET_STATE = 'État du billet';
    public const TICKET_USED_AT = "Heure d'entrée";
    public const TICKET_QR = 'QR du billet';

    public function __construct(
        private ?ExpectedReceivableInterface $expectedReceivable = null,
        private ?TicketQrTokenService $ticketQrTokens = null,
        private string $baseUrl = ''
    ) {
    }

    /**
     * The ordered columns for one form: the contact address, then every
     * input field in the form's own order, then the payment block when
     * the form expects money, then the ticket block when it delivers one.
     *
     * A non-input field (a title, a paragraph) carries no answer and is
     * skipped by the same `isNonInput()` rule both surfaces used before.
     *
     * @param FormField[] $fields
     * @return list<ResponseColumn>
     */
    public function forForm(array $fields, NewsForm $form): array
    {
        $columns = [new ResponseColumn(self::CONTACT, 'contact')];

        foreach ($fields as $field) {
            if ($field->isNonInput()) {
                continue;
            }
            $columns[] = new ResponseColumn(
                (string) $field->label,
                'field',
                $field->id,
                ResponseColumn::KIND_TEXT,
                $field->fieldType
            );
        }

        if ($this->hasPayment($fields)) {
            $columns[] = new ResponseColumn(self::AMOUNT_DUE, 'amount_due', null, ResponseColumn::KIND_AMOUNT_DUE);
            $columns[] = new ResponseColumn(self::AMOUNT_RECEIVED, 'amount_received', null,
                ResponseColumn::KIND_AMOUNT_RECEIVED);
            $columns[] = new ResponseColumn(self::STRUCTURED_COMMUNICATION, 'structured_communication');
            $columns[] = new ResponseColumn(self::PAYMENT_STATUS, 'payment_status');
        }

        if ($form->issuesTicket) {
            $columns[] = new ResponseColumn(self::TICKET_REFERENCE, 'ticket_reference');
            $columns[] = new ResponseColumn(self::TICKET_STATE, 'ticket_state');
            $columns[] = new ResponseColumn(self::TICKET_USED_AT, 'ticket_used_at');
            // The QR travels as an absolute URL, which is what a mail
            // client fetches and what a re-imported export hands straight
            // back to the composer (§24.4). It is the one column whose
            // reason to exist is the message rather than the spreadsheet,
            // and it is in both for exactly that reason: an export that
            // could not be re-imported as-is would break the rule the
            // exports are written to.
            $columns[] = new ResponseColumn(self::TICKET_QR, 'ticket_qr');
        }

        return $columns;
    }

    /**
     * A form expects money when it prices at least one field AND the
     * finance module is there to have recorded a receivable. Without the
     * module there is nothing to report and the block simply does not
     * exist — on either surface.
     *
     * @param FormField[] $fields
     */
    public function hasPayment(array $fields): bool
    {
        return $this->expectedReceivable !== null
            && array_filter($fields, static fn(FormField $f): bool => $f->isPriced()) !== [];
    }

    /**
     * One column's value for one response, as text.
     *
     * @param array<int, string> $answers the response's answers, by field id
     */
    public function valueFor(ResponseColumn $column, FormResponse $response, array $answers): string
    {
        $payment = $this->paymentOf($response);

        return match ($column->source) {
            'contact' => $response->contactEmail,
            'field' => $this->fieldValue($column, $answers),
            'amount_due' => $payment !== null ? self::money($payment['amount_due']) : '',
            'amount_received' => self::money($payment['amount_received'] ?? 0),
            'structured_communication' => (string) ($response->structuredCommunication ?? ''),
            'payment_status' => self::statusLabel($payment['status'] ?? null),
            'ticket_reference' => $response->hasTicket()
                ? TicketService::format((string) $response->ticketReference)
                : '',
            'ticket_state' => $response->isTicketUsed() ? 'Entré' : 'Non venu',
            'ticket_used_at' => (string) ($response->ticketUsedAt ?? ''),
            'ticket_qr' => $this->ticketQrUrl($response),
            default => '',
        };
    }

    /**
     * The receivable's figures for the export's two numeric cells, in
     * cents — null when the form expects no payment or this response
     * carries no receivable.
     *
     * @return array{amount_due: int, amount_received: int, status: string}|null
     */
    public function paymentOf(FormResponse $response): ?array
    {
        if ($response->receivableId === null || $this->expectedReceivable === null) {
            return null;
        }

        return $this->expectedReceivable->getReceivableStatus($response->receivableId);
    }

    /**
     * The label a family reads for a payment state. Never a status code:
     * this column now travels in a mail to the respondent, and « partial »
     * would be an English internal word landing in a French sentence.
     */
    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'paid' => 'Payé',
            'partial' => 'Partiel',
            default => 'Non payé',
        };
    }

    /**
     * Cents as the site writes an amount everywhere else — the PHP twin
     * of Twig's `|money_cents`.
     */
    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }

    /**
     * @param array<int, string> $answers
     */
    private function fieldValue(ResponseColumn $column, array $answers): string
    {
        if ($column->fieldId === null) {
            return '';
        }

        $value = (string) ($answers[$column->fieldId] ?? '');

        return $column->fieldType === FormField::TYPE_SWITCH
            ? ($value === '1' ? 'Oui' : 'Non')
            : $value;
    }

    private function ticketQrUrl(FormResponse $response): string
    {
        if (!$response->hasTicket()) {
            return '';
        }

        // An absolute URL rather than an inline image: a mail-merge body
        // is sanitized, and the sanitizer refuses `data:`. Same mechanism
        // as finance's payment reminder.
        return (string) ($this->ticketQrTokens?->urlFor((string) $response->ticketReference, $this->baseUrl) ?? '');
    }
}
