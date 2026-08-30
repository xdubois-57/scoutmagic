<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Pdf\DocumentPdfService;
use Core\Security\EncryptionService;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Campaign;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\CampaignService;
use Modules\Finance\Service\PaymentLabel;
use Modules\Finance\Service\PaymentLabelService;
use Modules\Finance\Service\SepaQrCodeService;
use Modules\Finance\Service\StructuredCommunicationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * The four things about a sheet of payment labels that must be provably
 * right without anybody looking at a PDF:
 *
 *  1. **What goes on it** — the outstanding balance, and nothing at all
 *     for a receivable that is settled or abandoned. A label claiming
 *     45 € on a receivable of 45 € with 20 € already in manufactures a
 *     20 € surplus, and on paper it cannot be taken back.
 *  2. **How the sheet is cut into pages** — twenty-seven per page, the
 *     remainder on a shorter last one.
 *  3. **How a long name is fitted** — the descent, and the second line at
 *     the floor. Never a truncation.
 *  4. **That the whole thing really produces a PDF** — one render, end to
 *     end, through the real dompdf.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PaymentLabelServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private CampaignRepository $campaigns;
    private CampaignRowRepository $rows;
    private ExpectedReceivableRepository $receivables;
    private TransactionRepository $transactions;
    private int $accountId;
    private int $scoutYearId;
    /** @var array<string, int> */
    private array $memberIds = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->campaigns = new CampaignRepository($this->pdo);
        $this->rows = new CampaignRowRepository($this->pdo, $this->encryption);
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $this->transactions = new TransactionRepository($this->pdo, $this->encryption);

        $this->accountId = (new AccountRepository($this->pdo, $this->encryption))->create(
            'Compte Unité',
            Account::TYPE_BANK,
            null,
            'BE71096123456769',
            'Unité SV025 Ottignies',
            'intendant'
        );
        $this->scoutYearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31', true);
    }

    // ── 1. the outstanding balance, and nothing else ────────────────────

    /**
     * The rule the whole feature stands on. Paper cannot be corrected
     * after the fact, so what is printed is what is still due.
     */
    public function testAPartiallyPaidReceivableIsLabelledForTheBalance(): void
    {
        $campaign = $this->campaignWith([['Timeo', 4500]]);
        $this->pay($campaign, 0, 20.00, 'Acompte');

        $labels = $this->service()->labelsFor($campaign);

        $this->assertCount(1, $labels);
        $this->assertSame(2500, $labels[0]->amountCents);
    }

    public function testASettledReceivableProducesNoLabelAtAll(): void
    {
        $campaign = $this->campaignWith([['Lucie', 4500], ['Timeo', 3825]]);
        $this->pay($campaign, 0, 45.00, 'Virement');

        $labels = $this->service()->labelsFor($campaign);

        $this->assertCount(1, $labels, 'Lucie has paid: she gets no label');
        $this->assertSame('Vandenbrande Timeo', $labels[0]->memberName);
        $this->assertSame(
            $this->receivableOf($campaign, 1)->id,
            $labels[0]->receivableId,
            'the surviving label is the unpaid one, not merely a label with the right name on it'
        );
    }

    /**
     * An abandoned receivable is settled without a cent entering the
     * account. Handing its family a label would be asking for money the
     * unit has already decided not to claim.
     */
    public function testAWaivedReceivableProducesNoLabelEither(): void
    {
        $campaign = $this->campaignWith([['Lucie', 4500], ['Timeo', 3825]]);
        $receivable = $this->receivableOf($campaign, 0);
        $this->receivables->setWaived($receivable->id, date('Y-m-d H:i:s'), 7);

        $labels = $this->service()->labelsFor($campaign);

        $this->assertCount(1, $labels);
        $this->assertSame($this->receivableOf($campaign, 1)->id, $labels[0]->receivableId);
        $this->assertSame('Vandenbrande Timeo', $labels[0]->memberName);
    }

    public function testACampaignWhereNothingIsOwedRefusesToPrintAnEmptySheet(): void
    {
        $campaign = $this->campaignWith([['Timeo', 3825]]);
        $this->pay($campaign, 0, 38.25, 'Virement');

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches('/Aucune étiquette/');
        $this->service()->renderPdf($campaign);
    }

    public function testAnAccountWithoutAnIbanRefusesRatherThanPrintingLabelsNobodyCanPay(): void
    {
        $this->pdo->exec("UPDATE finance_accounts SET iban = NULL WHERE id = {$this->accountId}");
        $campaign = $this->campaignWith([['Timeo', 3825]]);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches('/IBAN/');
        $this->service()->renderPdf($campaign);
    }

    /**
     * The sheet is handed out in the order the campaign screen lists its
     * lines — family name first — so a household's children are on
     * consecutive labels.
     */
    public function testLabelsComeOutSortedByFamilyName(): void
    {
        $campaign = $this->campaignWith([['Zoe', 1000], ['Amelie', 1000]]);

        $names = array_map(
            static fn(PaymentLabel $label): string => $label->memberName,
            $this->service()->labelsFor($campaign)
        );

        // Same family name for every fixture member, so the tie-break on
        // the first name is what is being read here.
        $this->assertSame(['Vandenbrande Amelie', 'Vandenbrande Zoe'], $names);
    }

    // ── 2. twenty-seven per page ────────────────────────────────────────

    public function testTwentySevenLabelsAreOnePageAndTwentyEightAreTwo(): void
    {
        $this->assertCount(1, PaymentLabelService::paginate($this->fakeLabels(27)));
        $this->assertCount(2, PaymentLabelService::paginate($this->fakeLabels(28)));
    }

    public function testAFullSheetIsNeverFollowedByAnEmptyOne(): void
    {
        $pages = PaymentLabelService::paginate($this->fakeLabels(54));

        $this->assertCount(2, $pages);
        $this->assertCount(27, $pages[0]);
        $this->assertCount(27, $pages[1]);
    }

    public function testTheRemainderLandsOnAShorterLastPage(): void
    {
        $pages = PaymentLabelService::paginate($this->fakeLabels(55));

        $this->assertCount(3, $pages);
        $this->assertCount(1, $pages[2]);
    }

    public function testNoLabelIsNoPageRatherThanOneEmptyPage(): void
    {
        $this->assertSame([], PaymentLabelService::paginate([]));
    }

    public function testTheGridIsThreeColumnsByNineRows(): void
    {
        $this->assertSame(27, PaymentLabelService::LABELS_PER_PAGE);
        $this->assertSame(3, PaymentLabelService::COLUMNS);
        $this->assertSame(9, PaymentLabelService::ROWS_PER_PAGE);
    }

    /**
     * The grid has to close on an A4 sheet, or the ninth row of every
     * page silently becomes a tenth page.
     */
    public function testTheGridFitsInsideAnA4SheetAtTheDeclaredMargins(): void
    {
        $width = PaymentLabelService::LABEL_WIDTH_MM * PaymentLabelService::COLUMNS;
        $height = PaymentLabelService::LABEL_HEIGHT_MM * PaymentLabelService::ROWS_PER_PAGE
            + (PaymentLabelService::ROWS_PER_PAGE + 1) * PaymentLabelService::CUTTING_LINE_MM;

        $this->assertEqualsWithDelta(210.0 - 2 * PaymentLabelService::PAGE_MARGIN_MM, $width, 0.001);
        $this->assertLessThanOrEqual(297.0 - 2 * PaymentLabelService::PAGE_MARGIN_MM, $height);
    }

    // ── 3. the name's descent, never a truncation ───────────────────────

    public function testAShortNameKeepsTheTopOfTheLadder(): void
    {
        $this->assertSame(8.0, PaymentLabelService::nameFontSizePt('Dupont Marie'));
        $this->assertSame(1, PaymentLabelService::nameLines('Dupont Marie'));
    }

    /**
     * One step down per widened name, in the declared order — never a
     * jump straight to the floor, and never a size off the ladder.
     */
    public function testTheSizeComesDownOneDeclaredStepAtATime(): void
    {
        $sizes = [];
        for ($length = 1; $length <= 60; $length++) {
            $sizes[] = PaymentLabelService::nameFontSizePt(str_repeat('a', $length));
        }

        $this->assertSame(PaymentLabelService::NAME_FONT_SIZES_PT, array_values(array_unique($sizes)));
        $this->assertSame($sizes, array_reverse(array_reverse($sizes)));
        foreach ($sizes as $index => $size) {
            if ($index > 0) {
                $this->assertLessThanOrEqual($sizes[$index - 1], $size, 'the ladder only ever descends');
            }
        }
    }

    /**
     * Each step is taken exactly at the character count that no longer
     * fits, which is what makes the descent reproducible rather than
     * approximately right. The floor is the one step with nothing below
     * it, and its own behaviour is pinned by the next test.
     */
    public function testEachStepIsTakenAtTheCharacterCountThatNoLongerFits(): void
    {
        foreach (PaymentLabelService::NAME_FONT_SIZES_PT as $size) {
            $fits = PaymentLabelService::maxCharactersAt($size);

            $this->assertSame($size, PaymentLabelService::nameFontSizePt(str_repeat('a', $fits)));

            if ($size === PaymentLabelService::NAME_FONT_FLOOR_PT) {
                continue;
            }

            $this->assertLessThan(
                $size,
                PaymentLabelService::nameFontSizePt(str_repeat('a', $fits + 1)),
                'one character too many has to cost a step'
            );
        }
    }

    /**
     * The floor is the point of the whole mechanism: below 6 pt the name
     * stops being readable, so it wraps rather than shrinking — and it is
     * never, ever cut.
     */
    public function testBelowTheFloorTheNameGoesOntoTwoLinesRatherThanShrinkingFurther(): void
    {
        $longest = str_repeat('a', PaymentLabelService::maxCharactersAt(PaymentLabelService::NAME_FONT_FLOOR_PT));

        $this->assertSame(6.0, PaymentLabelService::nameFontSizePt($longest));
        $this->assertSame(1, PaymentLabelService::nameLines($longest));

        $this->assertSame(6.0, PaymentLabelService::nameFontSizePt($longest . 'a'), 'the floor holds');
        $this->assertSame(2, PaymentLabelService::nameLines($longest . 'a'));
    }

    public function testAVeryLongNameStaysWholeOnTheLabel(): void
    {
        $campaign = $this->campaignWith([['Zoe', 1000]]);
        $veryLong = 'Vandenbroucke-Dubois De La Fontaine Marie-Christine';
        $this->renameMember('Zoe', $veryLong);

        $labels = $this->service()->labelsFor($campaign);

        $this->assertStringContainsString($veryLong, $labels[0]->memberName);
        $this->assertStringNotContainsString('…', $labels[0]->memberName);
        $this->assertSame(2, $labels[0]->nameLines);
        $this->assertSame(6.0, $labels[0]->nameFontSizePt);
    }

    /**
     * The descent counts CHARACTERS, so an accented name must not be
     * measured by its UTF-8 byte length — "Éléonore" is eight characters
     * and eleven bytes.
     */
    public function testAnAccentedNameIsMeasuredInCharactersNotBytes(): void
    {
        $this->assertSame(
            PaymentLabelService::nameFontSizePt('Eleonore Vandenbrande'),
            PaymentLabelService::nameFontSizePt('Éléonore Vandenbrande')
        );
    }

    // ── 4. it really produces a PDF ─────────────────────────────────────

    public function testTheSheetIsARealPdfCarryingOneQrPerLabel(): void
    {
        $campaign = $this->campaignWith([['Lucie', 4500], ['Timeo', 3825], ['Zoe', 1000]]);
        $this->pay($campaign, 0, 45.00, 'Virement');

        $pdf = $this->service()->renderPdf($campaign);

        $this->assertStringStartsWith('%PDF-', $pdf);
        // One embedded image per label. dompdf embeds one XObject per
        // distinct image, and every receivable carries its own structured
        // communication, so no two QR codes of a sheet are ever the same
        // bytes.
        $this->assertSame(2, preg_match_all('#/Subtype\s*/Image#', $pdf), 'one QR per unsettled receivable');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function service(): PaymentLabelService
    {
        return new PaymentLabelService(
            $this->rows,
            $this->receivables,
            FinanceTestHelper::allocationService($this->pdo, $this->encryption, $this->receivables),
            new AccountRepository($this->pdo, $this->encryption),
            new MemberService(new MemberYearRepository($this->pdo), $this->encryption, Connection::withPdo($this->pdo)),
            new SepaQrCodeService(),
            new DocumentPdfService(),
            $this->twig()
        );
    }

    /** @return PaymentLabel[] */
    private function fakeLabels(int $count): array
    {
        $labels = [];
        for ($index = 1; $index <= $count; $index++) {
            $labels[] = new PaymentLabel($index, 'Membre ' . $index, 1000, '+++000/0000/00000+++', 8.0, 1);
        }

        return $labels;
    }

    /**
     * @param array<int, array{0: string, 1: int}> $lines first name, amount in cents
     */
    private function campaignWith(array $lines): Campaign
    {
        $campaignId = $this->campaigns->create(
            'Cotisations 2025-2026',
            $this->scoutYearId,
            $this->accountId,
            null,
            'cotisations.xlsx',
            [],
            7
        );

        $sequence = 0;
        foreach ($lines as [$firstName, $amountCents]) {
            $sequence++;
            $memberId = $this->memberIds[$firstName] ??= $this->createMember($firstName);
            $rowId = $this->rows->create($campaignId, $memberId, $amountCents, $sequence, []);
            $this->receivables->create(
                CampaignService::SOURCE_MODULE,
                $rowId,
                $this->accountId,
                $amountCents,
                StructuredCommunicationService::format(str_pad((string) (1000000000 + $sequence), 10, '0', STR_PAD_LEFT)),
                null,
                $memberId
            );
        }

        $campaign = $this->campaigns->findById($campaignId);
        self::assertNotNull($campaign);

        return $campaign;
    }

    private function receivableOf(Campaign $campaign, int $index): \Modules\Finance\Repository\ExpectedReceivable
    {
        $rows = $this->rows->findByCampaignId($campaign->id);

        return $this->receivables->findBySource(CampaignService::SOURCE_MODULE, $rows[$index]->id)[0];
    }

    private function pay(Campaign $campaign, int $index, float $amount, string $prefix): void
    {
        $receivable = $this->receivableOf($campaign, $index);
        $this->transactions->create(
            $this->accountId,
            $this->scoutYearId,
            'REF-' . $index . '-' . $amount,
            '2026-02-18',
            $prefix . ' ' . $receivable->communication,
            $amount,
            null,
            null,
            'import',
            null
        );
    }

    private function createMember(string $firstName): int
    {
        $statement = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $statement->execute(['D-' . $firstName]);
        $memberId = (int) $this->pdo->lastInsertId();

        $statement = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)'
            . ' VALUES (?, ?, ?, ?, 1)'
        );
        $statement->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Vandenbrande', 'member_years.last_name'),
        ]);

        return $memberId;
    }

    private function renameMember(string $firstName, string $fullName): void
    {
        [$lastName, $newFirstName] = [substr($fullName, 0, (int) strrpos($fullName, ' ')), substr($fullName, (int) strrpos($fullName, ' ') + 1)];
        $statement = $this->pdo->prepare(
            'UPDATE member_years SET first_name_encrypted = ?, last_name_encrypted = ? WHERE member_id = ?'
        );
        $statement->execute([
            $this->encryption->encrypt($newFirstName, 'member_years.first_name'),
            $this->encryption->encrypt($lastName, 'member_years.last_name'),
            $this->memberIds[$firstName],
        ]);
    }

    private function twig(): Environment
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 4) . '/core/View/templates');
        $loader->addPath(dirname(__DIR__, 4) . '/modules/finance/views', 'finance');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $twig->addFilter(new TwigFilter(
            'money_cents',
            static fn($cents): string => $cents === null || $cents === '' ? '' : number_format(((int) $cents) / 100, 2, ',', ' ') . ' €'
        ));

        return $twig;
    }
}
