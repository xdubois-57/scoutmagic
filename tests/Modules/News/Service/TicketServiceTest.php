<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Core\Security\EncryptionService;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\TicketService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * The ticket a response IS: its reference, its QR, and the door's two
 * writes.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TicketServiceTest extends TestCase
{
    private \PDO $pdo;
    private FormResponseRepository $responses;
    private TicketService $service;
    private int $formId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->responses = new FormResponseRepository($this->pdo, $encryption);
        $this->service = new TicketService($this->responses);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$encryption->encrypt('chief@test.com', 'user_accounts.email'), $encryption->blindIndex('chief@test.com', 'email')]);
        $accountId = (int) $this->pdo->lastInsertId();

        $articleId = (new ArticleRepository($this->pdo))->create('Souper spaghetti', Article::VISIBILITY_PUBLIC, true, null, null, $accountId);
        $this->formId = (new FormRepository($this->pdo))->create(
            $articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, 'chief', false, null, true, '2026-03-14', 'Salle paroissiale'
        );
    }

    private function aResponse(string $email = 'famille@example.be'): int
    {
        return $this->responses->create($this->formId, null, null, $email, [], null, null);
    }

    // --- The reference's shape, which is the whole reason for the alphabet ---

    public function testAReferenceIsTenUppercaseAlphanumericCharacters(): void
    {
        $reference = $this->service->issueFor($this->responses->findById($this->aResponse()));

        $this->assertSame(10, strlen($reference));
        $this->assertMatchesRegularExpression('/\A[A-Z0-9]{10}\z/', $reference);
    }

    public function testAReferenceNeverCarriesAnAmbiguousLetter(): void
    {
        // `I` against `1` and `O` against `0`. This gets typed by hand at
        // the door every time the camera refuses, and a confusion there
        // costs more than a shorter alphabet.
        $this->assertStringNotContainsString('I', TicketService::ALPHABET);
        $this->assertStringNotContainsString('O', TicketService::ALPHABET);

        for ($i = 0; $i < 40; $i++) {
            $reference = $this->service->issueFor($this->responses->findById($this->aResponse('f' . $i . '@example.be')));
            $this->assertSame(strlen($reference), strspn($reference, TicketService::ALPHABET));
        }
    }

    public function testAReferenceNeverCarriesALowercaseLetter(): void
    {
        // One lowercase character tips the whole QR payload out of
        // alphanumeric mode into byte mode, which is what the version-1
        // code depends on.
        $this->assertSame(strtoupper(TicketService::ALPHABET), TicketService::ALPHABET);
    }

    public function testTwoResponsesNeverShareAReference(): void
    {
        $seen = [];
        for ($i = 0; $i < 30; $i++) {
            $seen[] = $this->service->issueFor($this->responses->findById($this->aResponse('f' . $i . '@example.be')));
        }

        $this->assertCount(30, array_unique($seen));
    }

    // --- Formatting and reading back ---

    public function testAReferenceIsShownGroupedFourFourTwo(): void
    {
        $this->assertSame('X7K2-9QMF-A3', TicketService::format('X7K29QMFA3'));
    }

    public function testCanonicalizeAcceptsEveryShapeTheDoorWillSee(): void
    {
        // Scanned with its dashes, typed without them, typed in lower
        // case, pasted with a stray space — all one reference.
        foreach (['X7K2-9QMF-A3', 'X7K29QMFA3', 'x7k2-9qmf-a3', '  X7K2 9QMF A3 '] as $input) {
            $this->assertSame('X7K29QMFA3', TicketService::canonicalize($input), $input);
        }
    }

    public function testCanonicalizeRefusesWhatIsNotAReferenceAtAll(): void
    {
        // Null rather than a miss, so the door can tell « ce n'est pas une
        // référence » from « cette référence ne correspond à rien » — two
        // different sentences in front of a queue.
        $this->assertNull(TicketService::canonicalize('Roskam'));
        $this->assertNull(TicketService::canonicalize('X7K2-9QMF'));
        $this->assertNull(TicketService::canonicalize(''));
        // Ten characters, but one of them is not in the alphabet.
        $this->assertNull(TicketService::canonicalize('X7K29QMFAO'));
    }

    public function testAReferenceResolvesToItsResponseWhateverShapeItArrivesIn(): void
    {
        $responseId = $this->aResponse();
        $reference = $this->service->issueFor($this->responses->findById($responseId));

        $this->assertSame($responseId, $this->service->findByReference($reference)?->id);
        $this->assertSame($responseId, $this->service->findByReference(TicketService::format($reference))?->id);
        $this->assertSame($responseId, $this->service->findByReference(strtolower($reference))?->id);
    }

    public function testAnUnknownReferenceResolvesToNothing(): void
    {
        $this->assertNull($this->service->findByReference('X7K2-9QMF-A3'));
    }

    // --- The QR itself ---

    public function testTheQrEncodesTheReferenceAloneAndFitsTheSmallestCode(): void
    {
        // Twenty-one modules is QR version 1. The equivalent URL, in byte
        // mode, would be version 3 — twenty-nine modules, each 38%
        // narrower at the same printed size. That difference is what
        // decides whether a code reads under a parish hall's lighting.
        $result = (new \Endroid\QrCode\Builder\Builder(
            writer: new \Endroid\QrCode\Writer\PngWriter(),
            data: TicketService::format('X7K29QMFA3'),
            errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::Medium,
            size: 400,
            margin: 10
        ))->build();

        $this->assertSame(21, $result->getMatrix()->getBlockCount());
    }

    public function testTheQrDataUriIsAPng(): void
    {
        $this->assertStringStartsWith('data:image/png;base64,', TicketService::qrDataUri('X7K29QMFA3'));
    }

    // --- Issuing, and never re-issuing ---

    public function testAResponseThatAlreadyHasATicketKeepsIt(): void
    {
        $responseId = $this->aResponse();
        $first = $this->service->issueFor($this->responses->findById($responseId));

        // A reference already sent in an e-mail is the only thing its
        // holder has. Lowering and raising the form's switch must never
        // invalidate what was promised.
        $second = $this->service->issueFor($this->responses->findById($responseId));

        $this->assertSame($first, $second);
    }

    public function testTheBackfillGivesEveryResponseWithoutOneAReference(): void
    {
        $ids = [$this->aResponse('a@example.be'), $this->aResponse('b@example.be'), $this->aResponse('c@example.be')];
        $this->service->issueFor($this->responses->findById($ids[0]));

        $issued = $this->service->backfillForForm($this->formId);

        // Only the two that lacked one — the third already had its ticket,
        // and re-issuing would post it a second copy.
        $this->assertCount(2, $issued);
        $this->assertEqualsCanonicalizing([$ids[1], $ids[2]], array_map(static fn ($r) => $r->id, $issued));
        foreach ($issued as $response) {
            $this->assertTrue($response->hasTicket());
        }
    }

    public function testTheBackfillIsIdempotent(): void
    {
        $this->aResponse('a@example.be');
        $this->service->backfillForForm($this->formId);

        $this->assertSame([], $this->service->backfillForForm($this->formId));
    }

    // --- The door's two writes ---

    public function testMarkingUsedRecordsTheMomentAndUnmarkingTakesItBack(): void
    {
        $responseId = $this->aResponse();
        $this->service->issueFor($this->responses->findById($responseId));

        $usedAt = $this->service->markUsed(
            $this->responses->findById($responseId),
            new \DateTimeImmutable('2026-03-14 19:42:00')
        );

        $this->assertSame('2026-03-14 19:42:00', $usedAt);
        $this->assertSame('2026-03-14 19:42:00', $this->responses->findById($responseId)?->ticketUsedAt);
        $this->assertTrue($this->responses->findById($responseId)?->isTicketUsed());

        // A scan by mistake, a validation made too early: the previous
        // site's own operation wrote true or false indifferently.
        $this->service->markUnused($this->responses->findById($responseId));

        $this->assertNull($this->responses->findById($responseId)?->ticketUsedAt);
    }

    public function testValidatingNeverTouchesTheReceivable(): void
    {
        // Paying and coming in are two distinct facts. The door shows the
        // payment state so the staff can ask; the reconciliation happens
        // cold, on the responses screen.
        $responseId = $this->responses->create($this->formId, null, null, 'famille@example.be', [], '+++123/4567/89412+++', 77);
        $this->service->issueFor($this->responses->findById($responseId));

        $this->service->markUsed($this->responses->findById($responseId));

        $after = $this->responses->findById($responseId);
        $this->assertSame(77, $after?->receivableId);
        $this->assertSame('+++123/4567/89412+++', $after?->structuredCommunication);
    }
}
