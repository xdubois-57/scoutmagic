<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Trombinoscope\Pdf;

/**
 * The printable trombinoscope's markup — everything dompdf is handed, and
 * nothing else. Separate from Service\TrombinoscopePdfService so the
 * layout can be asserted on as a string, without a PDF engine in the way.
 *
 * Three things about dompdf decide the shape of what follows, and none of
 * them is a preference:
 *
 * - **No flexbox and no grid.** Every arrangement here is a table. The
 *   mockup this was built from shows the target, not the technique — its
 *   classes are not transposed.
 * - **No image is ever fetched.** `isRemoteEnabled` is false, so a
 *   portrait arrives as a data URI already reduced by
 *   {@see StaffPhotoEmbedder}, or not at all.
 * - **`counter(pages)` is not resolvable**, since dompdf does not know the
 *   total while it lays out. The running footer therefore numbers pages
 *   without claiming a total, and what actually lets a reader find their
 *   section in the print dialog is the section's name at the top of its
 *   own page — which is why every page carries one.
 *
 * The colour bands are the reason this is a PDF at all: a browser drops
 * background colours when printing unless the reader ticks a box in a
 * dialog they will not read. They are never replaced by a thin border.
 */
class TrombinoscopeHtmlBuilder
{
    /** Longest civil name / totem kept on a directory card. */
    private const DIRECTORY_NAME_LIMIT = 30;

    private const INK = '#212529';
    private const MUTED = '#6c757d';
    private const RULE = '#ced4da';
    private const PLACEHOLDER = '#adb5bd';

    /**
     * @param SectionView[] $sections every section of the unit, in the
     *        order the site shows them — the document always covers the
     *        whole unit, Staff d'Unité included, and never a selection.
     */
    public function build(array $sections, string $unitName, string $yearLabel, string $siteUrl, bool $showContacts): string
    {
        $pages = $this->buildDirectoryPage($sections, $unitName, $yearLabel, $showContacts);
        foreach ($sections as $section) {
            $pages .= $this->buildSectionPage($section, $unitName, $yearLabel, $showContacts);
        }

        return '<!DOCTYPE html>'
            . '<html><head><meta charset="utf-8"><style>' . $this->css() . '</style></head><body>'
            . $this->runningFooter($unitName, $siteUrl)
            . $pages
            . '</body></html>';
    }

    private function css(): string
    {
        // Same dompdf frame as Core\Pdf\PosterPdfService: A4, 15mm
        // margins, DejaVu Sans (the one bundled face with full French
        // coverage — a totem with a circumflex must not become a box).
        return '@page { margin: 15mm; }'
            . 'body { font-family: DejaVu Sans, sans-serif; color: ' . self::INK . '; margin: 0; }'
            . 'table { border-collapse: collapse; }'
            . '.grid { width: 100%; table-layout: fixed; }'
            . '.card { width: 100%; border: 0.25mm solid ' . self::RULE . '; }'
            . '.muted { color: ' . self::MUTED . '; }'
            // An address wraps rather than being clipped, everywhere it
            // appears. A shortened address is an address nobody can write
            // to, which is the one failure this document cannot afford.
            . '.addr { word-wrap: break-word; }'
            . '.page-break { page-break-before: always; }'
            . '.running { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: ' . self::MUTED . '; }'
            . '.pageno:after { content: counter(page); }';
    }

    /**
     * Repeated by dompdf on every page. Sits 10mm below the content box,
     * inside the page margin, so it never competes with the flow for the
     * height the directory page needs.
     */
    private function runningFooter(string $unitName, string $siteUrl): string
    {
        $left = $this->escape(trim($unitName . ($siteUrl !== '' ? ' · ' . $siteUrl : '')));

        return '<div class="running"><table style="width:100%"><tr>'
            . '<td>' . $left . '</td>'
            . '<td style="text-align:right">page <span class="pageno"></span></td>'
            . '</tr></table></div>';
    }

    /* ================= page 1 — the directory ========================= */

    /**
     * The page a parent puts on their fridge: one responsable per section,
     * with a way to reach them, and every section's own address underneath.
     *
     * @param SectionView[] $sections
     */
    private function buildDirectoryPage(array $sections, string $unitName, string $yearLabel, bool $showContacts): string
    {
        $density = DirectoryDensity::forSectionCount(count($sections));

        $html = '<div style="border-bottom:0.6mm solid ' . self::INK . ';padding-bottom:2mm;margin-bottom:4mm;">'
            . '<div style="font-size:20pt;font-weight:bold;">Qui contacter</div>'
            . '<div class="muted" style="font-size:10pt;margin-top:1mm;">'
            . $this->escape($unitName) . ' · Année scoute ' . $this->escape($yearLabel)
            . '</div></div>';

        $html .= $this->buildCardGrid($sections, $density, $showContacts);
        $html .= $this->buildAddressFooter($sections, $density, $showContacts);

        return $html;
    }

    /**
     * @param SectionView[] $sections
     */
    private function buildCardGrid(array $sections, DirectoryDensity $density, bool $showContacts): string
    {
        $rows = array_chunk($sections, $density->columns);
        $cellWidth = round(100 / $density->columns, 4);

        $html = '<table class="grid">';
        foreach ($rows as $row) {
            $html .= '<tr>';
            for ($column = 0; $column < $density->columns; $column++) {
                $isLast = $column === $density->columns - 1;
                $padding = sprintf(
                    'padding:0 %smm %smm 0;',
                    $isLast ? '0' : $this->mm($density->gap),
                    $this->mm($density->gap)
                );
                $html .= '<td style="width:' . $cellWidth . '%;vertical-align:top;' . $padding . '">';
                if (isset($row[$column])) {
                    $html .= $this->buildDirectoryCard($row[$column], $density, $showContacts);
                }
                $html .= '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</table>';
    }

    private function buildDirectoryCard(SectionView $section, DirectoryDensity $density, bool $showContacts): string
    {
        $lead = $section->lead;
        $pad = $this->mm($density->padding);

        // The branch colour is a full-height band down the card's edge,
        // drawn as a thick left border rather than a cell so it spans the
        // card whatever its content — an empty cell would not.
        $html = '<table class="card" style="border-left:' . $this->mm($density->band) . 'mm solid ' . $this->color($section->color) . ';">'
            . '<tr><td style="width:' . $this->mm($density->portrait) . 'mm;padding:' . $pad . 'mm;">';

        $html .= $lead !== null
            ? $this->portrait($lead, $section->color, $density->portrait, $density->portrait / 2.6)
            : $this->vacantPortrait($density->portrait, $density->portrait / 2.6);

        $html .= '</td><td style="padding:' . $pad . 'mm ' . $pad . 'mm ' . $pad . 'mm 0;">'
            . '<div style="font-size:' . $density->sectionNameSize . 'pt;font-weight:bold;color:' . $this->color($section->color) . ';">'
            . $this->escape(mb_strtoupper($section->name)) . '</div>';

        if ($lead === null) {
            // A section with nobody designated keeps its card. A visible
            // hole pushes the Staff d'Unité to fix it; leaving the section
            // out would suggest it does not exist.
            $html .= '<div class="muted" style="font-size:' . $density->civilNameSize . 'pt;font-style:italic;margin-top:1mm;">'
                . 'Responsable non désigné</div>';
        } else {
            $html .= '<div style="font-size:' . $density->totemSize . 'pt;font-weight:bold;">'
                . $this->escape($this->shorten($lead->displayName, self::DIRECTORY_NAME_LIMIT)) . '</div>'
                . '<div class="muted" style="font-size:' . $density->civilNameSize . 'pt;">'
                . $this->escape($this->shorten($lead->civilName, self::DIRECTORY_NAME_LIMIT)) . '</div>';
            if ($showContacts) {
                $html .= $this->contactLines($lead, $density->contactSize);
            }
        }

        return $html . '</td></tr></table>';
    }

    /**
     * Every section's own address, in full. Organizational data (design.md
     * §2.6): it belongs to the section rather than to whoever runs it, so
     * it is printed whether or not personal contacts are.
     *
     * @param SectionView[] $sections
     */
    private function buildAddressFooter(array $sections, DirectoryDensity $density, bool $showContacts): string
    {
        $count = count($sections);
        $columns = $density->footerColumns($count);
        $size = $density->footerSize($count);
        $withEmail = array_values(array_filter($sections, fn(SectionView $s) => $s->email !== null && $s->email !== ''));

        $html = '<div style="border-top:0.6mm solid ' . self::INK . ';padding-top:2mm;margin-top:3mm;">';

        if ($withEmail !== []) {
            $html .= '<div style="font-size:8pt;font-weight:bold;text-transform:uppercase;">Écrire à une section</div>'
                . '<table class="grid" style="margin-top:1mm;">';
            foreach (array_chunk($withEmail, $columns) as $row) {
                $html .= '<tr>';
                for ($column = 0; $column < $columns; $column++) {
                    $html .= '<td class="addr" style="vertical-align:top;padding:0 2mm 0.6mm 0;font-size:' . $size . 'pt;">';
                    if (isset($row[$column])) {
                        $html .= '<span style="font-weight:bold;color:' . $this->color($row[$column]->color) . ';">'
                            . $this->escape($row[$column]->name) . '</span> '
                            . $this->escape((string) $row[$column]->email);
                    }
                    $html .= '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table>';
        }

        $note = 'Document généré le ' . (new \DateTimeImmutable())->format('d/m/Y');
        if (!$showContacts) {
            $note .= ' · coordonnées personnelles masquées par le réglage';
        }

        return $html . '<div class="muted" style="font-size:7pt;margin-top:2mm;">' . $this->escape($note) . '</div></div>';
    }

    /* ================= one page per section =========================== */

    /**
     * A section always starts its own sheet. It may run onto a second when
     * its staff is large, but it never begins halfway down the previous
     * one — a parent detaching "the Louveteaux page" must get the whole of
     * it and nothing else.
     */
    private function buildSectionPage(SectionView $section, string $unitName, string $yearLabel, bool $showContacts): string
    {
        $staff = $section->staff;
        $density = SectionDensity::forStaffCount(count($staff));
        $color = $this->color($section->color);

        $html = '<div class="page-break">';

        // The name at the top is what lets a reader pick this page out of
        // the print dialog. Nothing else on the sheet does that job.
        $html .= '<table style="width:100%;border-bottom:0.6mm solid ' . $color . ';padding-bottom:2mm;margin-bottom:4mm;"><tr>'
            . '<td style="width:3mm;padding:0;"><div style="width:2mm;height:11mm;background-color:' . $color . ';"></div></td>'
            . '<td style="padding-left:3mm;">'
            . '<div style="font-size:20pt;font-weight:bold;color:' . $color . ';">' . $this->escape($section->name) . '</div>'
            . '<div class="muted" style="font-size:10pt;margin-top:1mm;">'
            . $this->escape($this->sectionSubtitle($section, count($staff), $yearLabel))
            . '</div></td></tr></table>';

        $html .= $staff === []
            ? '<div class="muted" style="font-size:11pt;font-style:italic;">Aucun animateur pour cette section cette année.</div>'
            : $this->buildPortraitGrid($staff, $section->color, $density, $showContacts);

        return $html . $this->buildSectionFooter($section, $unitName) . '</div>';
    }

    private function sectionSubtitle(SectionView $section, int $staffCount, string $yearLabel): string
    {
        $parts = [];
        if ($section->branchName !== '') {
            $parts[] = $section->branchName;
        }
        $parts[] = $staffCount === 1 ? '1 animateur' : $staffCount . ' animateurs';
        $parts[] = $yearLabel;

        return implode(' · ', $parts);
    }

    /**
     * @param StaffView[] $staff
     */
    private function buildPortraitGrid(array $staff, string $sectionColor, SectionDensity $density, bool $showContacts): string
    {
        $cellWidth = round(100 / $density->columns, 4);
        $html = '<table class="grid">';

        foreach (array_chunk($staff, $density->columns) as $row) {
            $html .= '<tr>';
            for ($column = 0; $column < $density->columns; $column++) {
                $isLast = $column === $density->columns - 1;
                $html .= '<td style="width:' . $cellWidth . '%;vertical-align:top;padding:0 '
                    . ($isLast ? '0' : $this->mm($density->gap)) . 'mm ' . $this->mm($density->gap) . 'mm 0;">';
                if (isset($row[$column])) {
                    $html .= $this->buildPortraitCard($row[$column], $sectionColor, $density, $showContacts);
                }
                $html .= '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</table>';
    }

    private function buildPortraitCard(StaffView $member, string $sectionColor, SectionDensity $density, bool $showContacts): string
    {
        $pad = $this->mm($density->padding);
        $color = $this->color($sectionColor);

        $html = '<table class="card"><tr><td style="padding:' . $pad . 'mm;text-align:center;">'
            . $this->portrait($member, $sectionColor, $density->portrait, $density->portrait / 2.8)
            . '</td></tr><tr><td style="padding:0 ' . $pad . 'mm ' . $pad . 'mm ' . $pad . 'mm;">'
            . '<div style="font-size:' . $density->totemSize . 'pt;font-weight:bold;">'
            . $this->escape($this->shorten($member->displayName, $density->nameLimit)) . '</div>'
            . '<div class="muted" style="font-size:' . $density->civilNameSize . 'pt;">'
            . $this->escape($this->shorten($member->civilName, $density->nameLimit)) . '</div>';

        if ($member->isLead) {
            // A one-cell table rather than a div: dompdf gives a block the
            // full width of its container, which would turn a chip into a
            // band across the card, while a width-less table shrinks to
            // its content.
            $html .= '<table style="margin-top:1mm;"><tr><td style="font-size:' . max(6.0, $density->contactSize - 0.5) . 'pt;'
                . 'font-weight:bold;text-transform:uppercase;color:#ffffff;background-color:' . $color . ';padding:0.4mm 1.2mm;">'
                . 'Responsable</td></tr></table>';
        }

        if ($showContacts) {
            $html .= $this->contactLines($member, $density->contactSize);
        }

        return $html . '</td></tr></table>';
    }

    /**
     * The section's own address, large and in its colour: a parent who
     * detached only this sheet must be able to write without going back to
     * page one.
     */
    private function buildSectionFooter(SectionView $section, string $unitName): string
    {
        $color = $this->color($section->color);
        $html = '<div style="border-top:0.6mm solid ' . $color . ';padding-top:2mm;margin-top:3mm;">';

        if ($section->email !== null && $section->email !== '') {
            $html .= '<div class="addr" style="font-size:12pt;">'
                . '<span style="font-weight:bold;text-transform:uppercase;color:' . $color . ';">Écrire aux '
                . $this->escape($section->name) . '</span> '
                . '<span style="font-weight:bold;">' . $this->escape($section->email) . '</span></div>';
        }

        return $html . '<div class="muted" style="font-size:7pt;margin-top:1mm;">' . $this->escape($unitName) . '</div></div>';
    }

    /* ================= shared pieces ================================== */

    /**
     * A member's photo, clipped to a disc — or, with no photo, the same
     * initials-in-a-filled-disc avatar the site draws on screen.
     */
    private function portrait(StaffView $member, string $sectionColor, float $side, float $fontSize): string
    {
        $sideMm = $this->mm($side);

        if ($member->photoDataUri !== null) {
            return '<img src="' . $member->photoDataUri . '" style="width:' . $sideMm . 'mm;height:' . $sideMm . 'mm;border-radius:50%;" alt="">';
        }

        return $this->disc(
            $side,
            'background-color:' . $this->color($sectionColor) . ';',
            'color:#ffffff;font-weight:bold;font-size:' . round($fontSize, 1) . 'pt;',
            $this->escape($member->initials)
        );
    }

    /** The dashed disc standing in for a section with no designated lead. */
    private function vacantPortrait(float $side, float $fontSize): string
    {
        return $this->disc(
            max(1.0, $side - 1.0),
            'border:0.5mm dashed ' . self::PLACEHOLDER . ';',
            'color:' . self::PLACEHOLDER . ';font-size:' . round($fontSize, 1) . 'pt;',
            '?'
        );
    }

    /**
     * A disc with one short string centred in it, both ways.
     *
     * dompdf has no flexbox and ignores `line-height` as a centring
     * device on a fixed-height block — the initials came out pinned to the
     * top of the circle. A one-row table with the height declared on the
     * row AND the cell is what actually centres under it; `border-radius:
     * 50%` on the wrapper is what makes it a circle rather than a square.
     *
     * @param string $content already escaped
     */
    private function disc(float $side, string $boxStyle, string $textStyle, string $content): string
    {
        $sideMm = $this->mm($side);

        return '<div style="width:' . $sideMm . 'mm;height:' . $sideMm . 'mm;border-radius:50%;' . $boxStyle . '">'
            . '<table style="width:' . $sideMm . 'mm;"><tr style="height:' . $sideMm . 'mm;">'
            . '<td style="height:' . $sideMm . 'mm;text-align:center;vertical-align:middle;' . $textStyle . '">'
            . $content . '</td></tr></table></div>';
    }

    private function contactLines(StaffView $member, float $size): string
    {
        $html = '';
        if ($member->phone !== null && $member->phone !== '') {
            $html .= '<div style="font-size:' . $size . 'pt;font-weight:bold;">' . $this->escape($member->phone) . '</div>';
        }
        if ($member->email !== null && $member->email !== '') {
            $html .= '<div class="muted addr" style="font-size:' . $size . 'pt;">' . $this->escape($member->email) . '</div>';
        }

        return $html !== '' ? '<div style="margin-top:0.8mm;">' . $html . '</div>' : '';
    }

    /**
     * Totems and civil names may lose their tail — three letters off a
     * compound name stops nobody from acting. Never called on an address.
     */
    private function shorten(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit ? $text : rtrim(mb_substr($text, 0, $limit - 1)) . '…';
    }

    /**
     * Colours reach this class from Core\Member\SectionService::
     * colorForSection(), the site's single source, and are still checked
     * here: a value that is not a hex colour would otherwise close the
     * style attribute it is written into.
     */
    private function color(string $color): string
    {
        return preg_match('/^#[0-9A-Fa-f]{3,8}$/', $color) === 1 ? $color : self::MUTED;
    }

    private function mm(float $value): string
    {
        return (string) round($value, 2);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
