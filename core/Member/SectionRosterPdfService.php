<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Member\Pdf\RosterMemberView;
use Core\Member\Pdf\RosterSectionView;
use Core\Member\Pdf\SectionRosterHtmlBuilder;
use Core\Service\TextNormalizerService;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The roll-call sheet: one page per section, animateurs then animés, as a
 * PDF somebody holds while calling names at a passage ceremony or on a
 * rentrée day.
 *
 * Why a PDF rather than a print stylesheet, the same decision the
 * printable trombinoscope made and for the same reason: the full-colour
 * banners and the movement badges ARE the document. A browser drops
 * background colours when printing unless the reader ticks a box in a
 * dialog they will not read, and a sheet where the badges have gone grey
 * no longer answers the one question it was printed for.
 *
 * Core\Pdf\DocumentPdfService is not the pattern here (the trombinoscope
 * does not use it either, and its own comment says why PosterPdfService
 * did not fit): what is reused is the dompdf frame — `isRemoteEnabled =
 * false`, `defaultFont = 'DejaVu Sans'`, `@page { margin: 15mm }`, A4
 * portrait — and the split between a service that drives dompdf and a
 * Pdf\ namespace that owns the markup.
 *
 * **No contact of any kind reaches this document.** It circulates in a
 * local, in hands that are not all the staff's. The trombinoscope has a
 * setting for that; this one does not need one, because it never carries
 * the data.
 */
class SectionRosterPdfService
{
    public function __construct(
        private SectionRosterHtmlBuilder $htmlBuilder,
        /**
         * Where a rendered document is kept until its inputs change (a
         * directory, typically storage/temp). Null — the tests — means
         * every call renders. See generate().
         */
        private ?string $cacheDirectory = null
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $sections the sections to
     *        print, already filtered by the screen's own picker and
     *        already carrying the `color` Core\Member\SectionService::
     *        colorForSection() resolved. The colour is taken from here and
     *        never recomputed: a colour set by hand in Configuration >
     *        Config Desk wins over the branch's, so re-deriving it would
     *        print something different from the screen for any unit that
     *        has customised a section.
     * @param array<int, array{animateurs: MemberRosterRow[], intendants: MemberRosterRow[],
     *        animes: MemberRosterRow[]}> $roster keyed by section id, exactly as
     *        SectionRosterService::buildRoster() returns it
     * @param int|null $selectedSectionId the picker's current selection,
     *        or null for "Toutes" — part of the cache signature, since two
     *        filters are two different documents and confusing them would
     *        serve the wrong list
     * @return string raw PDF bytes
     */
    public function generate(
        int $scoutYearId,
        string $yearLabel,
        string $unitName,
        string $siteUrl,
        array $sections,
        array $roster,
        ?int $selectedSectionId = null
    ): string
    {
        $views = $this->buildViews($sections, $roster);

        // dompdf lays out one page per section and a large unit has
        // fifteen, while the inputs only change on a Desk import. So the
        // document is kept on disk under a signature of everything it is
        // made of — the year, the labels, the filter, and the whole
        // composition of every section down to each member's movement —
        // and served as-is while that signature holds. A stale copy is
        // impossible by construction: any change to an input is a
        // different file name. Older copies for the same year go when a
        // new one is written.
        $cacheFile = $this->cacheFile($scoutYearId, $yearLabel, $unitName, $siteUrl, $selectedSectionId, $views);
        if ($cacheFile !== null && is_file($cacheFile)) {
            $cached = @file_get_contents($cacheFile);
            if ($cached !== false && $cached !== '') {
                return $cached;
            }
        }

        $html = $this->htmlBuilder->build($views, $unitName, $yearLabel, $siteUrl);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf = $dompdf->output();

        if ($cacheFile !== null) {
            $this->store($cacheFile, $pdf, $scoutYearId);
        }

        return $pdf;
    }

    /**
     * Suggested file name, e.g. `appel-2025-2026.pdf`, or
     * `appel-2025-2026-louveteaux-1.pdf` when the picker names a section.
     *
     * Built from the scout year and, when there is one, the section —
     * organizational both, never a unit name and never a member name.
     * Nothing here is something a mail client would show before the file
     * is opened, which is the rule the printable trombinoscope already
     * documents for its own name.
     */
    public function fileName(string $yearLabel, ?string $sectionName = null): string
    {
        $parts = ['appel', $this->slug($yearLabel) ?: 'annee'];
        if ($sectionName !== null && $this->slug($sectionName) !== '') {
            $parts[] = $this->slug($sectionName);
        }

        return implode('-', $parts) . '.pdf';
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     * @param array<int, array{animateurs: MemberRosterRow[], intendants: MemberRosterRow[],
     *        animes: MemberRosterRow[]}> $roster
     * @return RosterSectionView[]
     */
    private function buildViews(array $sections, array $roster): array
    {
        $views = [];
        foreach ($sections as $section) {
            $sectionId = (int) $section['id'];
            $buckets = $roster[$sectionId] ?? ['animateurs' => [], 'intendants' => [], 'animes' => []];

            $views[] = new RosterSectionView(
                // A section with no name of its own falls back to its Desk
                // code, exactly as the screen does — never an empty header.
                name: ($section['name'] ?? '') !== '' ? (string) $section['name'] : (string) $section['desk_code'],
                branchName: (string) ($section['branch_name'] ?? ''),
                color: (string) ($section['color'] ?? ''),
                // The intendants bucket is deliberately dropped here and
                // nowhere else: see Pdf\RosterSectionView.
                animateurs: array_map($this->toMemberView(...), $buckets['animateurs']),
                animes: array_map($this->toMemberView(...), $buckets['animes'])
            );
        }

        return $views;
    }

    /**
     * The same normalisation the screen applies through its
     * `normalize_name`/`normalize_totem` filters, so a name is spelled
     * identically on the sheet and on the page it was printed from.
     */
    private function toMemberView(MemberRosterRow $row): RosterMemberView
    {
        $totem = $row->totem !== null ? TextNormalizerService::normalizeTotem($row->totem) : null;

        return new RosterMemberView(
            lastName: TextNormalizerService::normalizeName($row->lastName),
            firstName: TextNormalizerService::normalizeName($row->firstName),
            totem: $totem !== '' ? $totem : null,
            movement: $row->movement->status
        );
    }

    /**
     * @param RosterSectionView[] $views
     */
    private function cacheFile(
        int $scoutYearId,
        string $yearLabel,
        string $unitName,
        string $siteUrl,
        ?int $selectedSectionId,
        array $views
    ): ?string {
        if ($this->cacheDirectory === null) {
            return null;
        }

        $composition = [];
        foreach ($views as $view) {
            $people = [];
            foreach ([$view->animateurs, $view->animes] as $group) {
                $names = [];
                foreach ($group as $member) {
                    $names[] = [$member->lastName, $member->firstName, $member->totem, $member->movement->value];
                }
                $people[] = $names;
            }
            $composition[] = [$view->name, $view->branchName, $view->color, $people];
        }

        // The layout code itself is part of the signature: editing the
        // builder must invalidate every cached copy, or a deployment would
        // keep serving documents drawn by the previous version.
        $layout = (string) realpath(__DIR__ . '/Pdf/SectionRosterHtmlBuilder.php');
        $layoutStat = $layout !== '' ? @stat($layout) : false;

        $signature = hash('sha256', (string) json_encode([
            $scoutYearId,
            $yearLabel,
            $unitName,
            $siteUrl,
            $selectedSectionId,
            $composition,
            $layoutStat !== false ? [$layoutStat['mtime'], $layoutStat['size']] : null,
        ]));

        return $this->cacheDirectory . '/section-roster/' . $scoutYearId . '-' . $signature . '.pdf';
    }

    /**
     * Write, then swap. The temporary file and the `rename()` are what
     * make the replacement atomic: there is no instant at which a
     * concurrent reader could pick up a truncated document.
     */
    private function store(string $cacheFile, string $pdf, int $scoutYearId): void
    {
        $directory = dirname($cacheFile);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return;
        }
        foreach (glob($directory . '/' . $scoutYearId . '-*.pdf') ?: [] as $stale) {
            @unlink($stale);
        }
        $tmp = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $pdf) === false || !@rename($tmp, $cacheFile)) {
            @unlink($tmp);
        }
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($value)) ?? '';

        return trim($slug, '-');
    }
}
