<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Core\Security\EncryptionService;
use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\ScanService;
use Modules\News\Service\TicketService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * What the door asks: which evenings can be held, who is expected, who
 * this ticket is, and how many have come in.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ScanServiceTest extends TestCase
{
    private \PDO $pdo;
    private ArticleRepository $articles;
    private FormRepository $forms;
    private FormFieldRepository $fields;
    private FormResponseRepository $responses;
    private TicketService $tickets;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->articles = new ArticleRepository($this->pdo);
        $this->forms = new FormRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->responses = new FormResponseRepository($this->pdo, $encryption);
        $this->tickets = new TicketService($this->responses);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$encryption->encrypt('chief@test.com', 'user_accounts.email'), $encryption->blindIndex('chief@test.com', 'email')]);
        $this->accountId = (int) $this->pdo->lastInsertId();
    }

    private function service(
        ?ExpectedReceivableInterface $receivables = null,
        ?\Modules\Finance\Api\EpcPayloadReaderInterface $epcReader = null
    ): ScanService {
        return new ScanService(
            $this->forms, $this->fields, $this->responses, $this->articles, $this->tickets,
            $receivables, $epcReader
        );
    }

    /**
     * @return array{0: int, 1: int} article id, form id
     */
    private function event(string $title, bool $issuesTicket = true, ?string $eventDate = null): array
    {
        $articleId = $this->articles->create($title, Article::VISIBILITY_PUBLIC, true, null, null, $this->accountId);
        $formId = $this->forms->create(
            $articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, 'chief', false, null, $issuesTicket, $eventDate, null
        );

        return [$articleId, $formId];
    }

    /**
     * A form with the two priced quantity fields a real dinner has, plus a
     * plain short text for the name.
     *
     * @return array{name: int, adults: int, children: int}
     */
    private function dinnerFields(int $formId): array
    {
        return [
            'name' => $this->fields->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null),
            'adults' => $this->fields->create($formId, 1, FormField::TYPE_NUMBER, 'Repas adulte', false, null, null, 100, 15.0, null),
            'children' => $this->fields->create($formId, 2, FormField::TYPE_NUMBER, 'Repas enfant', false, null, null, 100, 8.0, null),
        ];
    }

    private function booking(int $formId, array $values, string $email, bool $used = false): int
    {
        $id = $this->responses->create($formId, null, null, $email, $values, null, null);
        $this->tickets->issueFor($this->responses->findById($id));
        if ($used) {
            $this->tickets->markUsed($this->responses->findById($id), new \DateTimeImmutable('2026-03-14 19:42:00'));
        }

        return $id;
    }

    // --- Which evenings can be held ---

    public function testOnlyTicketedEventsWithAtLeastOneBookingAreOffered(): void
    {
        [, $ticketedEmpty] = $this->event('Marché sans réservation');
        [, $notTicketed] = $this->event('Réunion de section', false);
        [, $held] = $this->event('Souper spaghetti');
        $this->dinnerFields($held);
        $this->booking($held, [], 'a@test.com');
        $this->fields->create($notTicketed, 0, FormField::TYPE_SHORT_TEXT, 'Nom', false, null, null, null, null, null);
        $this->responses->create($notTicketed, null, null, 'b@test.com', [], null, null);

        $ids = array_column($this->service()->listControllableEvents(), 'form_id');

        // An event nobody booked has no door to hold; a form that issues
        // no ticket has no ticket to check.
        $this->assertSame([$held], $ids);
        $this->assertNotContains($ticketedEmpty, $ids);
        $this->assertNotContains($notTicketed, $ids);
    }

    public function testTheEventNearestTodayComesFirst(): void
    {
        [, $lastYear] = $this->event('Souper spaghetti 2025', true, '2025-03-15');
        [, $tonight] = $this->event('Souper spaghetti', true, '2026-03-14');
        [, $december] = $this->event('Marché de Noël', true, '2026-12-08');
        foreach ([$lastYear, $tonight, $december] as $formId) {
            $this->booking($formId, [], 'f' . $formId . '@test.com');
        }

        $ids = array_column(
            $this->service()->listControllableEvents('', new \DateTimeImmutable('2026-03-14')),
            'form_id'
        );

        $this->assertSame($tonight, $ids[0], 'on the evening of the 14th, the dinner of the 14th leads');
    }

    public function testAClosedRegistrationIsStillAControllableEvent(): void
    {
        // closes_at closes the REGISTRATIONS — a dinner on 14 March closes
        // its bookings on the 10th. Filtering on it would hide the event
        // on precisely the evening it is being controlled.
        $articleId = $this->articles->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $this->accountId);
        $formId = $this->forms->create(
            $articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, '2020-01-01', true, 'chief', false, null, true, '2026-03-14', null
        );
        $this->booking($formId, [], 'a@test.com');

        $this->assertSame([$formId], array_column($this->service()->listControllableEvents(), 'form_id'));
    }

    public function testTheEventSearchMatchesTitleAndPlace(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $this->booking($formId, [], 'a@test.com');

        $this->assertCount(1, $this->service()->listControllableEvents('spaghetti'));
        $this->assertCount(1, $this->service()->listControllableEvents('SOUPER'), 'case does not matter');
        $this->assertCount(0, $this->service()->listControllableEvents('barbecue'));
    }

    // --- The counters ---

    public function testSeatsAreCountedFromThePricedQuantityFields(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $f = $this->dinnerFields($formId);
        $this->booking($formId, [$f['name'] => 'Roskam', $f['adults'] => '2', $f['children'] => '2'], 'a@test.com', true);
        $this->booking($formId, [$f['name'] => 'Delvaux', $f['adults'] => '2', $f['children'] => '0'], 'b@test.com');

        $counters = $this->service()->counters($this->forms->findById($formId));

        // Four seats in, six sold, two still expected — and « Roskam » is
        // never counted as a seat, being a name rather than a quantity.
        $this->assertSame(6, $counters['sold']);
        $this->assertSame(4, $counters['entered']);
        $this->assertSame(2, $counters['expected']);
    }

    public function testTheSoldCounterReadsAWholeColumnRatherThanOneQueryPerBooking(): void
    {
        // The event picker runs this over every ticketed form on every
        // keystroke of its search. Per response it would be one query per
        // family per event per keystroke; per quantity field it is one.
        [, $formId] = $this->event('Souper spaghetti');
        $f = $this->dinnerFields($formId);
        for ($i = 0; $i < 5; $i++) {
            $this->booking($formId, [$f['name'] => 'F' . $i, $f['adults'] => '2', $f['children'] => '1'], 'f' . $i . '@test.com');
        }

        $this->assertSame(15, $this->service()->counters($this->forms->findById($formId))['sold']);
        $this->assertSame(15, $this->service()->listControllableEvents()[0]['seats']);
    }

    public function testAPlainNumberFieldWithNoPriceAndNoCapacityIsNotASeat(): void
    {
        // « Âge de l'enfant » is a number, and counting it would report
        // thirty-eight seats for one child.
        [, $formId] = $this->event('Activité');
        $age = $this->fields->create($formId, 0, FormField::TYPE_NUMBER, "Âge", false, null, null, null, null, null);
        $this->booking($formId, [$age => '38'], 'a@test.com');

        $this->assertSame(1, $this->service()->counters($this->forms->findById($formId))['sold']);
    }

    public function testAFormWithNoQuantityFieldCountsOneSeatPerBooking(): void
    {
        // A plain sign-up is one person coming. Reporting zero sold on a
        // form that clearly sold something would make the counters lie.
        [, $formId] = $this->event('Porte ouverte');
        $this->fields->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', false, null, null, null, null, null);
        $this->booking($formId, [], 'a@test.com');
        $this->booking($formId, [], 'b@test.com', true);

        $counters = $this->service()->counters($this->forms->findById($formId));

        $this->assertSame(2, $counters['sold']);
        $this->assertSame(1, $counters['entered']);
    }

    // --- The five verdicts ---

    public function testAValidTicketIsNamedAndItsSeatsListed(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $f = $this->dinnerFields($formId);
        $responseId = $this->booking($formId, [$f['name'] => 'Roskam', $f['adults'] => '2', $f['children'] => '2'], 'a@test.com');
        $reference = (string) $this->responses->findById($responseId)?->ticketReference;

        $verdict = $this->service()->verdictFor($this->forms->findById($formId), TicketService::format($reference));

        $this->assertSame(ScanService::STATUS_VALID, $verdict['status']);
        $this->assertSame('Roskam', $verdict['holder'], 'the name the form collected, not the address');
        $this->assertSame(4, $verdict['seat_total']);
        $this->assertSame(
            [['label' => 'Repas adulte', 'quantity' => '2'], ['label' => 'Repas enfant', 'quantity' => '2']],
            $verdict['seats']
        );
    }

    public function testAUsedTicketSaysWhenItWasUsed(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $f = $this->dinnerFields($formId);
        $responseId = $this->booking($formId, [$f['name'] => 'Meunier'], 'a@test.com', true);
        $reference = (string) $this->responses->findById($responseId)?->ticketReference;

        $verdict = $this->service()->verdictFor($this->forms->findById($formId), $reference);

        // What distinguishes a mis-scan from an attempted double entry.
        $this->assertSame(ScanService::STATUS_USED, $verdict['status']);
        $this->assertSame('2026-03-14 19:42:00', $verdict['used_at']);
    }

    public function testATicketForAnotherEventNamesThatEvent(): void
    {
        [, $tonight] = $this->event('Souper spaghetti', true, '2026-03-14');
        [, $december] = $this->event('Marché de Noël', true, '2026-12-08');
        $this->booking($tonight, [], 'a@test.com');
        $responseId = $this->booking($december, [], 'b@test.com');
        $reference = (string) $this->responses->findById($responseId)?->ticketReference;

        $verdict = $this->service()->verdictFor($this->forms->findById($tonight), $reference);

        // « Introuvable » would send somebody looking for a fault that does
        // not exist.
        $this->assertSame(ScanService::STATUS_OTHER_EVENT, $verdict['status']);
        $this->assertSame('Marché de Noël', $verdict['article_title']);
        $this->assertSame('2026-12-08', $verdict['event_date']);
    }

    public function testAnUnknownReferenceIsNotFound(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $this->booking($formId, [], 'a@test.com');

        $this->assertSame(
            ScanService::STATUS_NOT_FOUND,
            $this->service()->verdictFor($this->forms->findById($formId), 'X7K2-9QMF-A3')['status']
        );
    }

    public function testAFreeEventCarriesNoPaymentBlockAtAll(): void
    {
        // A ticketed event can be free. Showing a « payé/impayé » would
        // invite somebody to go looking for a receivable that was never
        // created.
        [, $formId] = $this->event('Porte ouverte');
        $responseId = $this->booking($formId, [], 'a@test.com');
        $reference = (string) $this->responses->findById($responseId)?->ticketReference;

        $verdict = $this->service()->verdictFor($this->forms->findById($formId), $reference);

        $this->assertNull($verdict['payment']);
        $this->assertFalse($this->service()->expectsPayment($this->forms->findById($formId)));
    }

    public function testAnUnpaidTicketCarriesItsAmountAndItsReceivable(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $f = $this->dinnerFields($formId);
        $responseId = $this->responses->create($formId, null, null, 'a@test.com', [$f['adults'] => '2'], '+++123/4567/89412+++', 55);
        $this->tickets->issueFor($this->responses->findById($responseId));
        $reference = (string) $this->responses->findById($responseId)?->ticketReference;

        $receivables = $this->createMock(ExpectedReceivableInterface::class);
        $receivables->expects($this->atLeastOnce())->method('getReceivableStatus')->with(55)
            ->willReturn(['amount_due' => 3000, 'amount_received' => 0, 'status' => 'unpaid']);

        $verdict = $this->service($receivables)->verdictFor($this->forms->findById($formId), $reference);

        $this->assertSame('unpaid', $verdict['payment']['status']);
        $this->assertSame(3000, $verdict['payment']['amount_due']);
        // The id the « faire payer maintenant » screen is opened with.
        $this->assertSame(55, $verdict['payment']['receivable_id']);
    }

    // --- Finding somebody who lost their ticket ---

    public function testTheSearchFindsAPersonByNameEmailOrReference(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $f = $this->dinnerFields($formId);
        $responseId = $this->booking($formId, [$f['name'] => 'Vandenbrande'], 'famille@example.be');
        $this->booking($formId, [$f['name'] => 'Delvaux'], 'autre@example.be');
        $form = $this->forms->findById($formId);
        $reference = (string) $this->responses->findById($responseId)?->ticketReference;

        // The QR fails more often than one expects — a mailbox nobody can
        // find, a flat phone, somebody who came in the place of whoever
        // booked. The previous site only searched by reference.
        $this->assertCount(1, $this->service()->searchResponses($form, 'vandenbrande'));
        $this->assertCount(1, $this->service()->searchResponses($form, 'famille@example'));
        $this->assertCount(1, $this->service()->searchResponses($form, $reference));
        $this->assertCount(2, $this->service()->searchResponses($form, 'example.be'));
        $this->assertSame([], $this->service()->searchResponses($form, 'Roskam'));
    }

    public function testTheSearchIgnoresAccentsAndCase(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $f = $this->dinnerFields($formId);
        $this->booking($formId, [$f['name'] => 'Frédérique Noël'], 'a@example.be');

        $this->assertCount(1, $this->service()->searchResponses($this->forms->findById($formId), 'frederique'));
        $this->assertCount(1, $this->service()->searchResponses($this->forms->findById($formId), 'NOEL'));
    }

    public function testAnEmptySearchMatchesNobody(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $this->booking($formId, [], 'a@example.be');

        $this->assertSame([], $this->service()->searchResponses($this->forms->findById($formId), '   '));
    }

    // --- The printable fallback ---

    public function testTheExpectedAttendeesAreListedAlphabeticallyWithTheirState(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $f = $this->dinnerFields($formId);
        $this->booking($formId, [$f['name'] => 'Roskam', $f['adults'] => '2'], 'a@test.com');
        $this->booking($formId, [$f['name'] => 'Delvaux', $f['adults'] => '1'], 'b@test.com');

        $rows = $this->service()->expectedAttendees($this->forms->findById($formId));

        $this->assertSame(['Delvaux', 'Roskam'], array_column($rows, 'label'));
        $this->assertMatchesRegularExpression('/\A[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{2}\z/', $rows[0]['reference']);
        $this->assertSame(1, $rows[0]['seat_total']);
    }

    public function testAResponseWithNoTicketStillAppearsOnThePrintedList(): void
    {
        // A response recorded before the switch was raised and not yet
        // backfilled: it has no reference, but the family is still coming.
        [, $formId] = $this->event('Souper spaghetti');
        $f = $this->dinnerFields($formId);
        $this->responses->create($formId, null, null, 'a@test.com', [$f['name'] => 'Herremans'], null, null);

        $rows = $this->service()->expectedAttendees($this->forms->findById($formId));

        $this->assertSame('Herremans', $rows[0]['label']);
        $this->assertSame('—', $rows[0]['reference']);
    }

    // --- IT-04: the scanner accepts two forms ---

    public function testATransferPayloadResolvesToTheTicketItBelongsTo(): void
    {
        // The confirmation of a paid event carries two codes, and under
        // the pressure of a queue somebody holds out the wrong one. The
        // answer is not to remove a code but to make the confusion
        // harmless — which is what lets the payment instructions travel
        // in the e-mail at all, and so get the transfer made in advance.
        [, $formId] = $this->event('Souper spaghetti');
        $responseId = $this->responses->create($formId, null, null, 'a@test.com', [], '+++123/4567/89412+++', 55);
        $this->tickets->issueFor($this->responses->findById($responseId));

        $payload = \Modules\Finance\Service\EpcPayload::build(
            'Unité SV025', 'BE71096123456769', null, 4600, '+++123/4567/89412+++'
        );
        $reader = new \Modules\Finance\Service\SepaQrCodeService();

        $verdict = $this->service(null, $reader)->verdictFor($this->forms->findById($formId), $payload);

        $this->assertSame(ScanService::STATUS_VALID, $verdict['status']);
        $this->assertSame($responseId, $verdict['response']->id);
    }

    public function testABareReferenceStillWinsOverAnythingElse(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $responseId = $this->booking($formId, [], 'a@test.com');
        $reference = (string) $this->responses->findById($responseId)?->ticketReference;
        $reader = new \Modules\Finance\Service\SepaQrCodeService();

        $this->assertSame(
            $responseId,
            $this->service(null, $reader)->findByScannedPayload($reference)?->id
        );
    }

    public function testWithoutTheFinanceModuleATransferPayloadSimplyResolvesToNothing(): void
    {
        // A site whose events are all free has no transfer QR to be
        // confused by in the first place.
        [, $formId] = $this->event('Porte ouverte');
        $this->responses->create($formId, null, null, 'a@test.com', [], '+++123/4567/89412+++', 55);

        $payload = "BCD\n002\n1\nSCT\n\nU\nBE71096123456769\nEUR46.00\n\n\n+++123/4567/89412+++";

        $this->assertNull($this->service()->findByScannedPayload($payload));
    }
}
