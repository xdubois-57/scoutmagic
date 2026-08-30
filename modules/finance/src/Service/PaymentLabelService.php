<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Member\MemberService;
use Core\Pdf\DocumentPdfService;
use Core\Service\TextNormalizerService;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Campaign;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Twig\Environment;

/**
 * A campaign's payment labels: an A4 sheet of 3 × 9 rectangles to cut out,
 * one per receivable that still asks for something.
 *
 * **Why a sheet of paper at all**, next to the QR screen and the mail-merge
 * reminder that already exist: a unit meeting is where the treasurer
 * actually catches the families. A sheet cut into twenty-seven labels is
 * handed out at the door in two minutes, goes home in a coat pocket, and
 * is paid the same evening from a phone. The mail reaches the parent who
 * reads their mail; this reaches the one who does not.
 *
 * **The amount is the outstanding balance, never the initial amount** —
 * the same rule, for the same reason, as Controller\ReceivableQrController
 * and the QR screen: printing 45 € on a receivable of 45 € with 20 €
 * already in would manufacture a 20 € surplus, which is the exact problem
 * the reconciliation screen exists to clear up. A settled or abandoned
 * receivable therefore produces **no label**, rather than a label for
 * 0 € that a bank would refuse.
 *
 * **The payment details are repeated as text beside the QR**, exactly as
 * `views/receivable_qr.html.twig` does and for the same reason: a QR that
 * refuses to scan — a bad print, a folded label, a phone with no scanner
 * — must never leave somebody with no way to pay. What is repeated is the
 * IBAN and the communication, both of which the payer has to type; the
 * beneficiary is not, because it is the same on every label of the sheet
 * and the IBAN already identifies it.
 *
 * **The EPC payload and the QR come from Service\SepaQrCodeService**,
 * the one place either is built. A second EPC builder for the printed
 * case would be one season away from disagreeing with the screen one.
 *
 * The geometry below is the sheet's contract with a pair of scissors, and
 * every number in it is derived rather than chosen — see the constants.
 */
class PaymentLabelService
{
    // ── the grid ────────────────────────────────────────────────────────

    public const COLUMNS = 3;
    public const ROWS_PER_PAGE = 9;

    /** 3 × 9. The pagination is decided here, in PHP, never left to dompdf's flow. */
    public const LABELS_PER_PAGE = self::COLUMNS * self::ROWS_PER_PAGE;

    /** A4, in millimetres. */
    private const PAGE_WIDTH_MM = 210.0;
    private const PAGE_HEIGHT_MM = 297.0;

    /** The margin the sheet is printed with, on all four sides. */
    public const PAGE_MARGIN_MM = 6.0;

    /**
     * (210 − 2 × 6) ÷ 3 = 66 exactly.
     */
    public const LABEL_WIDTH_MM = (self::PAGE_WIDTH_MM - 2 * self::PAGE_MARGIN_MM) / self::COLUMNS;

    /**
     * The cutting line's own width. It is hairline on purpose — a scissor
     * line printed half a millimetre thick is half a millimetre of black
     * down the edge of every label — and it is a constant because dompdf
     * ADDS it to the height of every row it collapses, so the grid has to
     * pay for it out of the label rather than out of the page.
     */
    public const CUTTING_LINE_MM = 0.1;

    /**
     * (297 − 2 × 6) ÷ 9 = 31.666…, which is where the sheet's nominal
     * 31.7 mm comes from — less the ten collapsed cutting lines the nine
     * rows share between them, which dompdf adds to the table's height on
     * top of the cells' own. 31.555 mm is what is actually printed.
     *
     * That subtraction is not fussiness, and it was measured rather than
     * reasoned: at a flat 31.7 mm the rows measure 285.3 mm against the
     * 285 mm the margins leave, and even at 31.567 — the naive
     * "one line per row" allowance — a nine-row page still spills onto a
     * tenth. The symptom is a blank page between every two sheets of
     * labels, which nobody notices until a hundred of them come out of a
     * printer.
     */
    public const LABEL_HEIGHT_MM = (self::PAGE_HEIGHT_MM - 2 * self::PAGE_MARGIN_MM
        - (self::ROWS_PER_PAGE + 1) * self::CUTTING_LINE_MM) / self::ROWS_PER_PAGE;

    /** White inside each cutting line, on all four sides of a label. */
    public const LABEL_PADDING_MM = 2.0;

    /** The QR's printed side. */
    public const QR_SIZE_MM = 18.0;

    /**
     * White between the text column and the QR — the code's quiet zone on
     * that side. Together with LABEL_PADDING_MM on the other three, no
     * content comes within 2 mm of the code, which is more than the four
     * modules ISO/IEC 18004 asks for at any payload length this label can
     * carry (Service\SepaQrCodeService::PRINT_MARGIN_PX says the other
     * half of this).
     */
    public const QR_GUTTER_MM = 2.5;

    /** What is left for the name, the amount, the IBAN and the communication. */
    public const TEXT_WIDTH_MM = self::LABEL_WIDTH_MM
        - 2 * self::LABEL_PADDING_MM
        - self::QR_SIZE_MM
        - self::QR_GUTTER_MM;

    // ── the name's font size ────────────────────────────────────────────

    /**
     * The descent, in points. A name is **never truncated** — a label
     * that says "Vandenbroucke-Duja…" is handed to the wrong family — so
     * the size comes down instead, and the last step is a second line.
     *
     * @var float[]
     */
    public const NAME_FONT_SIZES_PT = [8.0, 7.5, 7.0, 6.5, 6.0];

    /**
     * Below this the name stops being readable across a hall, so the
     * ladder stops and the name wraps instead of shrinking further.
     */
    public const NAME_FONT_FLOOR_PT = 6.0;

    /**
     * Average advance width of a **bold** DejaVu Sans character, as a
     * fraction of the font size.
     *
     * **dompdf measures no text at render time**, so nothing downstream
     * can tell us whether a name fitted — the decision has to be made
     * here, from the character count, before a single glyph is placed.
     *
     * 0.68 is measured rather than guessed: run against dompdf's own
     * `FontMetrics::getTextWidth()`, real Belgian, Flemish and Congolese
     * names in this face average between 0.61 em ("Bogaert Ludwig") and
     * 0.75 em ("MWAMBA Grace", where a Desk export capitalises the family
     * name), and 0.68 sits at the top of that range rather than in the
     * middle. An early version used 0.6 on the assumption that lowercase
     * dominates; it does not, and "Mwamba Tshilombo Grace" measured
     * 41.74 mm against the 41.5 mm it was told it had.
     *
     * Erring wide costs at most one step of the ladder on a name that
     * would have fitted one size up. Erring narrow costs a second line —
     * never a truncation, since nothing forces the name onto one line
     * (see styleSheet()), which is what makes an imperfect estimate
     * survivable at all.
     */
    private const AVERAGE_CHAR_WIDTH_EM = 0.68;

    /** 1 pt = 1/72 in = 0.3527… mm. */
    private const MM_PER_POINT = 25.4 / 72;

    public function __construct(
        private CampaignRowRepository $rows,
        private ExpectedReceivableRepository $receivables,
        private ReceivableAllocationService $allocations,
        private AccountRepository $accountRepository,
        private MemberService $members,
        private SepaQrCodeService $sepaQrCode,
        private DocumentPdfService $pdf,
        private Environment $twig
    ) {
    }

    // ── what goes on the sheet ──────────────────────────────────────────

    /**
     * The labels a campaign produces, in the order the campaign screen
     * lists its lines — by family name, accent-insensitively — so a
     * treasurer holding the sheet and the screen is looking at the same
     * order, and a household's children are on consecutive labels.
     *
     * A line produces nothing at all when it has nothing left to ask for:
     * settled, abandoned, or orphaned by a receivable that is no longer
     * there (there would be no communication to print, and a label with
     * no communication is a payment nobody can attribute).
     *
     * @return PaymentLabel[]
     */
    public function labelsFor(Campaign $campaign): array
    {
        $rows = $this->rows->findByCampaignId($campaign->id);
        if ($rows === []) {
            return [];
        }

        $receivablesByRowId = $this->receivables->findBySourceReferenceIds(
            CampaignService::SOURCE_MODULE,
            array_map(static fn($row): int => $row->id, $rows)
        );

        // refreshAndSettle() rather than a plain read, for the same
        // reason the QR screen does it: the amount about to be PRINTED
        // is the one thing here that must never be stale. Paper cannot
        // be corrected after the fact.
        $settlements = $this->allocations->refreshAndSettle(array_values($receivablesByRowId));

        $names = [];
        foreach ($this->members->findDirectoryForYear($campaign->scoutYearId) as $entry) {
            $names[$entry->memberId] = [
                'display' => trim($entry->lastName . ' ' . $entry->firstName) !== ''
                    ? trim($entry->lastName . ' ' . $entry->firstName)
                    : $entry->displayName,
                'last' => $entry->lastName,
                'first' => $entry->firstName,
            ];
        }

        $labels = [];
        foreach ($rows as $row) {
            $receivable = $receivablesByRowId[$row->id] ?? null;
            $settlement = $receivable !== null ? ($settlements[$receivable->id] ?? null) : null;
            if ($receivable === null || $settlement === null) {
                continue;
            }
            if ($settlement->isWaived() || $settlement->amountRemainingCents() <= 0) {
                continue;
            }

            $name = $names[$row->memberId] ?? null;
            $displayName = $name['display'] ?? ('Membre #' . $row->memberId);

            $labels[] = [
                'sort_last' => TextNormalizerService::fold((string) ($name['last'] ?? '')),
                'sort_first' => TextNormalizerService::fold((string) ($name['first'] ?? '')),
                'label' => new PaymentLabel(
                    $receivable->id,
                    $displayName,
                    $settlement->amountRemainingCents(),
                    $receivable->communication,
                    self::nameFontSizePt($displayName),
                    self::nameLines($displayName)
                ),
            ];
        }

        usort($labels, static function (array $a, array $b): int {
            $byLast = strcmp($a['sort_last'], $b['sort_last']);

            return $byLast !== 0 ? $byLast : strcmp($a['sort_first'], $b['sort_first']);
        });

        return array_map(static fn(array $entry): PaymentLabel => $entry['label'], $labels);
    }

    /**
     * The sheet, as PDF bytes.
     *
     * @throws FinanceException when the campaign's account cannot be paid
     *         into, or when there is nothing left to print
     */
    public function renderPdf(Campaign $campaign): string
    {
        $account = $this->accountRepository->findById($campaign->accountId);
        if ($account === null || $account->iban === null || $account->iban === '') {
            throw new FinanceException(
                "Le compte de cette campagne n'a pas d'IBAN configuré : les étiquettes"
                . " n'auraient aucun moyen de paiement à imprimer."
            );
        }

        $labels = $this->labelsFor($campaign);
        if ($labels === []) {
            throw new FinanceException(
                "Aucune étiquette à imprimer : toutes les créances de cette campagne sont réglées ou abandonnées."
            );
        }

        $beneficiary = $account->holderName ?? $account->name;
        $iban = IbanNormalizer::normalize($account->iban);

        $withQr = array_map(
            fn(PaymentLabel $label): PaymentLabel => $label->withQrDataUri(
                'data:image/png;base64,' . base64_encode($this->sepaQrCode->generatePrintPng(
                    $beneficiary,
                    $iban,
                    null,
                    $label->amountCents,
                    $label->communication
                ))
            ),
            $labels
        );

        $body = $this->twig->render('@finance/pdf/payment_labels.html.twig', [
            // Grouped for READING only — this one is typed by hand off a
            // sheet of paper, which is exactly the case IbanNormalizer::
            // format() exists for (ARCHITECTURE.md §8.72). It must never
            // travel further than the page.
            'iban_display' => IbanNormalizer::format($iban),
            'pages' => self::paginate($withQr),
            'columns' => self::COLUMNS,
        ]);

        return $this->pdf->generateBare($body, self::PAGE_MARGIN_MM . 'mm', $this->styleSheet());
    }

    // ── the two rules that must be provably right ───────────────────────

    /**
     * Twenty-seven per page, and the remainder on a last, shorter sheet.
     *
     * Done here rather than by letting dompdf flow the rows, because a
     * flowed table decides its own page breaks from heights that are
     * fractions of a millimetre apart — and the first symptom of getting
     * that wrong is a blank page between every two sheets.
     *
     * @param PaymentLabel[] $labels
     * @return array<int, PaymentLabel[]>
     */
    public static function paginate(array $labels): array
    {
        return $labels === [] ? [] : array_chunk(array_values($labels), self::LABELS_PER_PAGE);
    }

    /**
     * The largest size of the ladder at which the name still fits on one
     * line of TEXT_WIDTH_MM — or the floor, when none of them does.
     */
    public static function nameFontSizePt(string $name): float
    {
        foreach (self::NAME_FONT_SIZES_PT as $size) {
            if (mb_strlen($name) <= self::maxCharactersAt($size)) {
                return $size;
            }
        }

        return self::NAME_FONT_FLOOR_PT;
    }

    /**
     * How many lines the name is allowed to take: two only once the
     * ladder has bottomed out, never as a first resort.
     */
    public static function nameLines(string $name): int
    {
        return mb_strlen($name) <= self::maxCharactersAt(self::NAME_FONT_FLOOR_PT) ? 1 : 2;
    }

    /**
     * How many characters of DejaVu Sans fit across TEXT_WIDTH_MM at a
     * given point size. Floored: half a character over is over.
     */
    public static function maxCharactersAt(float $sizePt): int
    {
        return (int) floor(self::TEXT_WIDTH_MM / ($sizePt * self::MM_PER_POINT * self::AVERAGE_CHAR_WIDTH_EM));
    }

    // ── the sheet's stylesheet ──────────────────────────────────────────

    /**
     * Kept in PHP rather than in the view so the geometry has exactly one
     * home: every length below is one of the constants above, and a
     * template free to write its own millimetres is a template free to
     * disagree with the pagination.
     *
     * The borders ARE the cutting lines, so they are collapsed (one line
     * between two labels, never two) and hairline-thin: a scissor line
     * printed half a millimetre thick is half a millimetre of black down
     * the edge of every label.
     *
     * **The QR is floated, not put in a second table cell.** A nested
     * `table-layout: fixed` inside a fixed-height cell is what the first
     * version of this sheet used, and dompdf grows the OUTER row past its
     * declared height when it meets one — nine rows of a page became ten,
     * every second sheet came out blank, and no amount of shaving the row
     * height fixed it because the height was not what was being ignored.
     * A float costs one rule and reproduces the same two columns.
     *
     * **The name is never clipped and never held on one line by force.**
     * `white-space: nowrap` with `overflow: hidden` was the first attempt
     * and is exactly the wrong tool: the descent is an ESTIMATE (see
     * AVERAGE_CHAR_WIDTH_EM), so a name the estimate got slightly wrong
     * would be cut off mid-word — the one outcome this whole mechanism
     * exists to avoid. Nothing forces the line: the ladder is what makes
     * a name fit on one, and wrapping is what happens when it cannot,
     * which is the fallback the 6 pt floor already prescribes. All
     * `nameLines` decides is the leading, tighter on the two-line case
     * where the room is scarcest — a rule that can only ever remove
     * height, never add it.
     *
     * **The three type sizes are a measured budget, not a taste.** The
     * amount is the biggest thing on the label because it is what the
     * payer checks; the name is the descent ladder above; the payment
     * details are the smallest because they are copied rather than read.
     * 12 pt / 6 pt is what leaves the worst case still inside its 27.555
     * mm — a two-line name at the 6 pt floor above a 31-character IBAN
     * that wraps, which is five lines of details. At 13 pt / 6.5 pt that
     * case grows the row and the page loses its ninth row of labels.
     */
    private function styleSheet(): string
    {
        $sheetWidth = self::mm(self::LABEL_WIDTH_MM * self::COLUMNS);
        $labelWidth = self::mm(self::LABEL_WIDTH_MM);
        $labelHeight = self::mm(self::LABEL_HEIGHT_MM);
        $line = self::mm(self::CUTTING_LINE_MM);
        $padding = self::mm(self::LABEL_PADDING_MM);
        $innerHeight = self::mm(self::LABEL_HEIGHT_MM - 2 * self::LABEL_PADDING_MM);
        $textWidth = self::mm(self::TEXT_WIDTH_MM);
        $qr = self::mm(self::QR_SIZE_MM);

        return <<<CSS
            table.sheet { border-collapse: collapse; table-layout: fixed; width: {$sheetWidth}; }
            table.sheet td.label { width: {$labelWidth}; height: {$labelHeight};
                                   padding: 0; margin: 0; border: {$line} solid #000; vertical-align: top; }
            div.inner { padding: {$padding}; height: {$innerHeight}; overflow: hidden; }
            div.qr { float: right; width: {$qr}; height: {$qr}; }
            div.qr img { width: {$qr}; height: {$qr}; display: block; }
            div.text { width: {$textWidth}; }
            p { margin: 0; padding: 0; }
            p.name { font-weight: bold; }
            p.name.one-line { line-height: 1.1; }
            p.name.two-lines { line-height: 1; }
            p.amount { font-size: 12pt; font-weight: bold; line-height: 1.1; margin-top: 0.5mm; }
            p.pay { font-size: 6pt; line-height: 1.15; margin-top: 0.6mm; word-wrap: break-word; }
            p.pay span.key { color: #555; }
            div.page-break { page-break-after: always; }
            CSS;
    }

    /**
     * A length as CSS, to the micron — a derived height is
     * 31.555555555555557 in PHP, and a stylesheet is far easier to read,
     * and to compare against this class, when it says 31.555mm.
     *
     * **Truncated, never rounded.** Rounding a height UP is how nine rows
     * that fit in 285 mm on paper stop fitting in the renderer; a micron
     * of unused page costs nothing, a micron of overflow costs a page.
     */
    private static function mm(float $millimetres): string
    {
        $truncated = floor($millimetres * 1000) / 1000;

        return rtrim(rtrim(number_format($truncated, 3, '.', ''), '0'), '.') . 'mm';
    }
}
