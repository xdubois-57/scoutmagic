<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormResponse;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\ResponseColumn;
use Modules\News\Service\ResponseColumns;
use PHPUnit\Framework\TestCase;

/**
 * The one definition of a form's response columns, read by the XLSX
 * export and by the mail-merge variables alike.
 *
 * These used to be two lists that a docblock claimed could not disagree
 * while they already did. What this file pins is the shape of the single
 * list and, above all, what every value READS LIKE — because a column
 * that used to be a treasurer's spreadsheet cell now travels in a mail to
 * a family.
 */
class ResponseColumnsTest extends TestCase
{
    public function testAPlainFormIsTheContactAddressAndItsInputFields(): void
    {
        $columns = (new ResponseColumns())->forForm(
            [$this->field(1, FormField::TYPE_SHORT_TEXT, 'Nom'), $this->field(2, FormField::TYPE_SWITCH, 'Végétarien')],
            $this->form(false)
        );

        $this->assertSame(['Contact', 'Nom', 'Végétarien'], $this->labels($columns));
    }

    /**
     * A block of explanatory text carries no answer, so it is no column
     * — the same `isNonInput()` rule both surfaces applied separately.
     */
    public function testANonInputFieldIsNotAColumn(): void
    {
        $columns = (new ResponseColumns())->forForm(
            [$this->field(1, FormField::TYPE_TEXT, 'Informations pratiques'), $this->field(2, FormField::TYPE_SHORT_TEXT, 'Nom')],
            $this->form(false)
        );

        $this->assertSame(['Contact', 'Nom'], $this->labels($columns));
    }

    public function testTheFourPaymentColumnsAreColumnsLikeAnyOther(): void
    {
        $columns = $this->withFinance()->forForm([$this->priced(1, 'Repas adulte')], $this->form(false));

        $this->assertSame(
            ['Contact', 'Repas adulte', 'Montant attendu', 'Montant reçu', 'Communication structurée', 'Statut paiement'],
            $this->labels($columns)
        );
    }

    /**
     * The payment block belongs to the FORM, not to the module: without
     * finance there is no receivable to report, so no column on either
     * surface rather than four empty ones.
     */
    public function testThereIsNoPaymentBlockWithoutTheFinanceModule(): void
    {
        $columns = (new ResponseColumns())->forForm([$this->priced(1, 'Repas adulte')], $this->form(false));

        $this->assertSame(['Contact', 'Repas adulte'], $this->labels($columns));
    }

    public function testATicketFormAddsItsFourTicketColumnsAfterTheMoney(): void
    {
        $columns = $this->withFinance()->forForm([$this->priced(1, 'Repas adulte')], $this->form(true));

        $this->assertSame(
            [
                'Contact', 'Repas adulte',
                'Montant attendu', 'Montant reçu', 'Communication structurée', 'Statut paiement',
                'Référence du billet', 'État du billet', "Heure d'entrée", 'QR du billet',
            ],
            $this->labels($columns)
        );
    }

    /**
     * The export needs two cells that are not text — a live formula and a
     * real number — and nothing else. Every other column is a string,
     * because a value starting with '=' would otherwise become a formula
     * in a staff member's spreadsheet.
     */
    public function testOnlyTheTwoAmountsAreAnythingButText(): void
    {
        $columns = $this->withFinance()->forForm([$this->priced(1, 'Repas adulte')], $this->form(true));

        $kinds = [];
        foreach ($columns as $column) {
            $kinds[$column->label] = $column->kind;
        }

        $this->assertSame(ResponseColumn::KIND_AMOUNT_DUE, $kinds['Montant attendu']);
        $this->assertSame(ResponseColumn::KIND_AMOUNT_RECEIVED, $kinds['Montant reçu']);
        unset($kinds['Montant attendu'], $kinds['Montant reçu']);
        $this->assertSame([ResponseColumn::KIND_TEXT], array_values(array_unique($kinds)));
    }

    public function testEveryValueIsSomethingAFamilyCanRead(): void
    {
        $service = $this->withFinance();
        $columns = $service->forForm(
            [$this->priced(1, 'Repas adulte'), $this->field(2, FormField::TYPE_SWITCH, 'Végétarien')],
            $this->form(false)
        );
        $response = $this->response();

        $values = [];
        foreach ($columns as $column) {
            $values[$column->label] = $service->valueFor($column, $response, [1 => '2', 2 => '1']);
        }

        $this->assertSame([
            'Contact' => 'famille@test.be',
            'Repas adulte' => '2',
            // Never « 1 »: a merge variable rendering a boolean as a digit
            // in a mail would be nonsense.
            'Végétarien' => 'Oui',
            'Montant attendu' => '30,00 €',
            'Montant reçu' => '12,50 €',
            'Communication structurée' => '+++100/0000/00011+++',
            // Never « partial »: the finance module's stored word is
            // English, and this column is read by the respondent.
            'Statut paiement' => 'Partiel',
        ], $values);
    }

    public function testAResponseWithNoReceivableReadsAsUnpaidRatherThanEmpty(): void
    {
        $service = $this->withFinance();
        $columns = $service->forForm([$this->priced(1, 'Repas adulte')], $this->form(false));
        $response = $this->response(receivableId: null);

        $values = [];
        foreach ($columns as $column) {
            $values[$column->label] = $service->valueFor($column, $response, [1 => '2']);
        }

        $this->assertSame('Non payé', $values['Statut paiement']);
        $this->assertSame('0,00 €', $values['Montant reçu']);
        $this->assertSame('', $values['Montant attendu']);
    }

    public function testAnUnusedTicketSaysSoRatherThanLeavingTheCellEmpty(): void
    {
        $service = new ResponseColumns();
        $columns = $service->forForm([], $this->form(true));

        $values = [];
        foreach ($columns as $column) {
            $values[$column->label] = $service->valueFor($column, $this->response(), []);
        }

        $this->assertSame('Non venu', $values['État du billet']);
        $this->assertSame('', $values["Heure d'entrée"]);
        // No ticket issued, so no reference and no QR — never a broken
        // link in a message.
        $this->assertSame('', $values['Référence du billet']);
        $this->assertSame('', $values['QR du billet']);
    }

    public function testAnAmountReadsTheWayTheSiteWritesOneEverywhereElse(): void
    {
        $this->assertSame('45,00 €', ResponseColumns::money(4500));
        $this->assertSame('1 234,56 €', ResponseColumns::money(123456));
        $this->assertSame('0,00 €', ResponseColumns::money(0));
    }

    // -----------------------------------------------------------------

    private function withFinance(): ResponseColumns
    {
        $receivables = $this->createMock(ExpectedReceivableInterface::class);
        $receivables->method('getReceivableStatus')->willReturn(
            ['amount_due' => 3000, 'amount_received' => 1250, 'status' => 'partial']
        );

        return new ResponseColumns($receivables);
    }

    /**
     * @param ResponseColumn[] $columns
     * @return string[]
     */
    private function labels(array $columns): array
    {
        return array_map(static fn (ResponseColumn $column): string => $column->label, $columns);
    }

    private function field(int $id, string $type, string $label): FormField
    {
        return new FormField($id, 1, 0, $type, $label, false, null, null, null, null, null);
    }

    private function priced(int $id, string $label): FormField
    {
        return new FormField($id, 1, 0, FormField::TYPE_NUMBER, $label, false, null, null, 100, 15.0, null);
    }

    private function form(bool $issuesTicket): NewsForm
    {
        return new NewsForm(
            1, 1, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, 'chief', false, $issuesTicket,
            null, null, null, null, '2026-01-01 09:00:00'
        );
    }

    private function response(?int $receivableId = 11): FormResponse
    {
        return new FormResponse(
            1, 1, null, null, 'famille@test.be', '+++100/0000/00011+++', $receivableId,
            '2026-03-01 10:00:00', null
        );
    }
}
