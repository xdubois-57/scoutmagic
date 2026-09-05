<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Pdf;

use Core\Member\Movement\MemberMovementStatus;

/**
 * The roll-call sheet's markup — everything dompdf is handed, and nothing
 * else. Separate from Core\Member\SectionRosterPdfService so what the
 * document says can be asserted as a string, with no PDF engine in the
 * way — the same split Modules\Trombinoscope\Pdf\TrombinoscopeHtmlBuilder
 * makes, and for the same reason.
 *
 * What this document is for decides every choice below: a passage
 * ceremony or a rentrée day, sheet in hand, names called out loud, and the
 * newcomers and the ones changing section to be spotted at a glance.
 *
 * Three dompdf facts shape the markup, and none of them is a preference:
 *
 * - **No flexbox and no grid.** Every arrangement here is a table.
 * - **A repeated header is a `<thead>`, and nothing else.** A section that
 *   runs onto a second page has to carry its name and its legend there —
 *   a detached sheet ends up in hands that never had the first one — and
 *   `<thead>` is the one mechanism dompdf offers for that.
 * - **`counter(pages)` does not resolve** (it renders as 0): dompdf does
 *   not know the total while it lays out. The footer therefore numbers
 *   pages without claiming a total. The mockup shows "page 1 / 2"; this
 *   is the one place the rendering cannot follow it.
 *
 * The full-colour banners are the reason this is a PDF rather than a print
 * stylesheet: a browser drops background colours unless the reader ticks a
 * box in a dialog they will not read, and a grey subheading is exactly
 * what gets lost in a list of thirty names.
 */
class SectionRosterHtmlBuilder
{
    private const INK = '#212529';
    private const MUTED = '#6c757d';
    private const RULE = '#dee2e6';

    /**
     * Bootstrap's own success / info / warning / primary, so a badge is
     * the same colour on paper as on `/chefs/membres`. Four distinct tones
     * on purpose: the screen's own comment says collapsing them into one
     * severity would erase information, and that is truer here, where the
     * legend is the only explanation a reader gets.
     *
     * The second value is whether the tone needs dark text — a badge on
     * `#ffc107` in white is unreadable.
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private const TONE_COLORS = [
        'new' => ['#198754', false],
        'section_change' => ['#0dcaf0', true],
        'branch_change' => ['#ffc107', true],
        'returning' => ['#0d6efd', false],
    ];

    /**
     * @param RosterSectionView[] $sections in the order the screen shows
     *        them; the document follows the section filter the screen is
     *        showing, so this is one section or all of them, never a set
     *        this class chose.
     */
    public function build(array $sections, string $unitName, string $yearLabel, string $siteUrl): string
    {
        $pages = '';
        foreach ($sections as $index => $section) {
            $pages .= $this->buildSheet($section, $unitName, $yearLabel, $index > 0);
        }

        if ($pages === '') {
            $pages = '<div class="muted" style="font-size:11pt;font-style:italic;">'
                . 'Aucune section à imprimer.</div>';
        }

        return '<!DOCTYPE html>'
            . '<html><head><meta charset="utf-8"><style>' . $this->css() . '</style></head><body>'
            . $this->runningFooter($siteUrl)
            . $pages
            . '</body></html>';
    }

    private function css(): string
    {
        // Same dompdf frame as Core\Pdf\PosterPdfService and the printable
        // trombinoscope: A4, 15mm margins, DejaVu Sans — the one bundled
        // face with full French coverage, so a totem with a circumflex is
        // not a box.
        return '@page { margin: 15mm; }'
            . 'body { font-family: DejaVu Sans, sans-serif; color: ' . self::INK . '; margin: 0; }'
            . 'table { border-collapse: collapse; }'
            . '.muted { color: ' . self::MUTED . '; }'
            . '.sheet { width: 100%; }'
            . '.page-break { page-break-before: always; }'
            . '.running { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: '
            . self::MUTED . '; }'
            . '.pageno:after { content: counter(page); }';
    }

    private function runningFooter(string $siteUrl): string
    {
        $left = trim($siteUrl . ($siteUrl !== '' ? ' · ' : '')
            . 'document généré le ' . $this->today());

        return '<div class="running"><table style="width:100%"><tr>'
            . '<td>' . $this->escape($left) . '</td>'
            . '<td style="text-align:right">page <span class="pageno"></span></td>'
            . '</tr></table></div>';
    }

    /**
     * One section, one sheet — always, and never two sections on the same
     * one: a sheet is torn off and handed to that section's animateur.
     *
     * The whole sheet is a single table whose `<thead>` carries the header
     * and the legend, so a section large enough to overflow repeats both
     * on its second page. The height is never set on this table, nor on
     * the group tables inside it: a table given the remaining height
     * distributes the surplus between its rows and produces an enormous
     * line spacing.
     */
    private function buildSheet(
        RosterSectionView $section,
        string $unitName,
        string $yearLabel,
        bool $pageBreak
    ): string
    {
        $density = RosterDensity::forLargestGroup($section->largestGroupSize());

        // Every printed line is a ROW of this one table — the banners and
        // the spacer included, through a colspan — and not a block inside
        // a single cell. That is the whole reason the overflow works:
        // dompdf cannot split a table CELL across pages, so a body wrapped
        // in one `<tr><td>` moved to the next page entire and left the
        // header alone on a blank sheet. Rows it splits between happily,
        // and repeats the `<thead>` above them.
        $rows = $this->groupRows('Animateurs', $section->leaders, $section->color, $density, false)
            . $this->groupRows('Animés', $section->youthMembers, $section->color, $density, true);

        return '<table class="sheet' . ($pageBreak ? ' page-break' : '') . '">'
            . '<thead>'
            . '<tr><td colspan="3" style="padding:0;">' . $this->header($section, $unitName, $yearLabel) . '</td></tr>'
            . '<tr><td colspan="3" style="padding:0;">' . $this->legend($density) . '</td></tr>'
            . '</thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>';
    }

    /**
     * The section's name, in exactly the colour it has on screen, and to
     * its right the count a staff checks before calling the first name.
     */
    private function header(RosterSectionView $section, string $unitName, string $yearLabel): string
    {
        $color = $this->color($section->color);

        return '<table style="width:100%;border-bottom:0.6mm solid ' . $color
            . ';padding-bottom:2mm;margin-bottom:2mm;"><tr>'
            . '<td style="width:3mm;padding:0;"><div style="width:2mm;height:9mm;background-color:'
            . $color . ';"></div></td>'
            . '<td style="padding-left:3mm;vertical-align:top;">'
            . '<div style="font-size:19pt;font-weight:bold;color:' . $color . ';">'
            . $this->escape($section->name) . '</div>'
            // 8.5pt so a unit name of ordinary length still fits beside
            // the count on one line. A very long one wraps, which costs a
            // few millimetres of header and nothing else.
            . '<div class="muted" style="font-size:8.5pt;margin-top:1mm;">'
            . $this->escape($this->subtitle($section, $unitName, $yearLabel)) . '</div>'
            . '</td>'
            // An explicit width, and it is not decoration: without one the
            // subtitle beside it takes what it likes and the count wraps
            // onto two lines under its own first half. The count is the
            // figure a staff checks before calling the first name, so it
            // is the subtitle that gives way, never this.
            . '<td style="width:64mm;text-align:right;vertical-align:top;font-size:8.5pt;" class="muted">'
            . $this->escape($this->counts($section)) . '</td>'
            . '</tr></table>';
    }

    private function subtitle(RosterSectionView $section, string $unitName, string $yearLabel): string
    {
        $parts = array_filter([$unitName, $section->branchName]);
        $parts[] = 'Année scoute ' . $yearLabel;

        return implode(' · ', $parts);
    }

    private function counts(RosterSectionView $section): string
    {
        return $this->plural(count($section->leaders), 'animateur') . ' · '
            . $this->plural(count($section->youthMembers), 'animé') . ' · '
            . $this->plural($section->notableCount(), 'mouvement');
    }

    private function plural(int $count, string $noun): string
    {
        return $count . ' ' . $noun . ($count > 1 ? 's' : '');
    }

    /**
     * Repeated on every page, because it sits in the sheet's `<thead>`. A
     * sheet detached and handed to one animateur must stay readable by
     * somebody who never saw the first one — and the badges are the whole
     * point of printing this rather than a plain list.
     */
    private function legend(RosterDensity $density): string
    {
        $html = '<table style="width:100%;border-bottom:0.25mm solid ' . self::RULE
            . ';padding-bottom:1.5mm;margin-bottom:2mm;"><tr><td style="padding:0;">';

        foreach (MemberMovementStatus::cases() as $status) {
            if (!$status->isNotable()) {
                continue;
            }
            $html .= $this->badge($status, $density) . ' ';
        }

        return $html
            . '<span class="muted" style="font-size:' . $density->badgeSize
            . 'pt;border:0.25mm solid ' . self::RULE . ';padding:0.4mm 1.2mm;">'
            . 'sans badge : continuité</span>'
            . '</td></tr></table>';
    }

    private function badge(MemberMovementStatus $status, RosterDensity $density): string
    {
        [$background, $darkText] = self::TONE_COLORS[$status->tone()] ?? [self::MUTED, false];

        return '<span style="background-color:' . $background
            . ';color:' . ($darkText ? self::INK : '#ffffff')
            . ';font-size:' . $density->badgeSize
            . 'pt;font-weight:bold;padding:0.4mm 1.2mm;">'
            . $this->escape($status->label()) . '</span>';
    }

    /**
     * A group under a full-colour banner carrying its own headcount,
     * emitted as ROWS of the sheet's own table — see buildSheet().
     *
     * A grey subheading gets lost in a list; nobody should have to hunt
     * for where the animés begin. And each name gets a tick box: this is
     * what a paper list is for, and leaving it out would produce a
     * document people annotate in the margin.
     *
     * @param RosterMemberView[] $members
     */
    private function groupRows(
        string $label,
        array $members,
        string $sectionColor,
        RosterDensity $density,
        bool $spaced
    ): string
    {
        $color = $this->color($sectionColor);

        $html = $spaced
            ? '<tr><td colspan="3" style="padding:0;height:' . $density->groupGap . 'mm;"></td></tr>'
            : '';

        $html .= '<tr><td colspan="3" style="background-color:' . $color . ';color:#ffffff;font-size:'
            . $density->bannerSize . 'pt;font-weight:bold;text-transform:uppercase;padding:1mm 2mm;">'
            . $this->escape($label) . ' · ' . count($members) . '</td></tr>';

        if ($members === []) {
            return $html . '<tr><td colspan="3" class="muted" style="font-size:' . $density->nameSize
                . 'pt;font-style:italic;padding:1.5mm 2mm;">Personne dans ce groupe cette année.</td></tr>';
        }

        $cell = 'padding:' . $density->rowPadding . 'mm 0;border-bottom:0.2mm solid ' . self::RULE
            . ';vertical-align:middle;';
        foreach ($members as $member) {
            $html .= '<tr>'
                . '<td style="width:' . ($density->checkbox + 2.5) . 'mm;' . $cell . '">'
                . '<div style="width:' . $density->checkbox . 'mm;height:' . $density->checkbox
                . 'mm;border:0.3mm solid ' . self::MUTED . ';"></div></td>'
                . '<td style="' . $cell . 'font-size:' . $density->nameSize . 'pt;">'
                . $this->name($member) . '</td>'
                . '<td style="' . $cell . 'text-align:right;">'
                . ($member->movement->isNotable() ? $this->badge($member->movement, $density) : '')
                . '</td>'
                . '</tr>';
        }

        return $html;
    }

    /**
     * The surname carries the weight — it is what a list is read from —
     * and the totem trails after it in a lighter tone, exactly as the
     * screen orders them.
     */
    private function name(RosterMemberView $member): string
    {
        $html = '<span style="font-weight:bold;">' . $this->escape($member->lastName) . '</span>, '
            . $this->escape($member->firstName);

        if ($member->totem !== null && $member->totem !== '') {
            $html .= ' <span class="muted">· ' . $this->escape($member->totem) . '</span>';
        }

        return $html;
    }

    /**
     * Core\Member\SectionService guarantees `#RRGGBB`, validated at write
     * time so every consumer can rely on it. The guard is for the case it
     * cannot cover — a fixture, an older row — and falls back to the muted
     * grey rather than emitting an attribute the engine would choke on.
     */
    private function color(string $color): string
    {
        return preg_match('/^#[0-9A-Fa-f]{3,8}$/', $color) === 1 ? $color : self::MUTED;
    }

    private function today(): string
    {
        return (new \DateTimeImmutable())->format('d/m/Y');
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
