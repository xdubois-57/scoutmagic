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
 *
 * What it does carry is names, so the disk cache has a stated retention
 * rather than an open-ended one — see CACHE_TTL_DAYS, and the "Durée de
 * conservation" rule Core\View\RgpdContentService states for generated
 * documents.
 */
class SectionRosterPdfService
{
    /**
     * How long a rendered sheet may sit in the cache directory.
     *
     * The cache holds member names in the clear — that is what a PDF is —
     * so its retention has to be a stated number rather than "until
     * something replaces it". Purging only the same year's superseded
     * copies, which is what the printable trombinoscope does, leaves last
     * season's sheet on disk for ever the day nobody prints that year
     * again.
     *
     * A week: long enough that a rentrée weekend printing the same sheet
     * repeatedly still pays for one render, short enough that a document
     * nobody has asked for in seven days is simply gone. Re-rendering
     * after that costs about a second.
     */
    private const CACHE_TTL_DAYS = 7;

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
            // The deadline is enforced HERE and not only in store(): a
            // document whose inputs never change is never rewritten, so
            // purgeExpired() would never run over it and a sheet full of
            // names would be served for ever. A stated retention that only
            // holds when somebody happens to print something else is not a
            // retention.
            if ($this->hasExpired($cacheFile)) {
                @unlink($cacheFile);
            } else {
                $cached = @file_get_contents($cacheFile);
                if ($cached !== false && $cached !== '') {
                    return $cached;
                }
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
            $this->store($cacheFile, $pdf, $scoutYearId, $selectedSectionId);
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
                leaders: array_map($this->toMemberView(...), $buckets['animateurs']),
                youthMembers: array_map($this->toMemberView(...), $buckets['animes'])
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
            foreach ([$view->leaders, $view->youthMembers] as $group) {
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

        // Encoded first rather than inline in hash(): two arguments opening
        // on the same line as a multi-line array is the one shape this
        // file's linter asks not to be written.
        $payload = json_encode([
            $scoutYearId,
            $yearLabel,
            $unitName,
            $siteUrl,
            $selectedSectionId,
            $composition,
            $layoutStat !== false ? [$layoutStat['mtime'], $layoutStat['size']] : null,
        ]);
        if ($payload === false) {
            // No signature, no cache. `(string) false` is '', and
            // hash('sha256', '') is the same digest for every document of
            // the year — two filters would land on one file and the
            // second reader would be served the first one's list, which
            // is the single failure this cache may not have. A member
            // name is decrypted database text, so malformed UTF-8 is not
            // hypothetical.
            return null;
        }
        $signature = hash('sha256', $payload);

        // The filter is in the NAME, not only in the signature, so the
        // same-year purge below can be scoped to it: without that, writing
        // section B's sheet deleted "Toutes" and section A's, and a chief
        // alternating filters paid a full render every time — exactly what
        // this cache exists to avoid.
        return $this->cacheDirectory . '/section-roster/'
            . $this->cachePrefix($scoutYearId, $selectedSectionId) . '-' . $signature . '.pdf';
    }

    /**
     * Write, then swap. The temporary file and the `rename()` are what
     * make the replacement atomic: there is no instant at which a
     * concurrent reader could pick up a truncated document.
     */
    private function store(string $cacheFile, string $pdf, int $scoutYearId, ?int $selectedSectionId): void
    {
        $directory = dirname($cacheFile);

        // 0700, not 0755: this directory holds PDFs of member names, which
        // puts it with Core\Security\SecretManager and Core\Support\
        // SupportPackageService rather than with the image variants. The
        // reference deployment is shared hosting, where a traversable
        // parent means another local account can read them. Fail closed —
        // no enforceable permission, no cache, and the document is simply
        // rendered every time.
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }
        if (!@chmod($directory, 0700)) {
            return;
        }

        // Two purges, and the second is the one that bounds retention.
        // The superseded copies of THIS year AND THIS FILTER go because
        // they are superseded — scoped to the filter, since the sheets of
        // two different filters are two different documents and neither
        // supersedes the other. Every year's and every filter's expired
        // ones go because a document holding names may not outlive
        // CACHE_TTL_DAYS, and a season nobody prints again would
        // otherwise keep its sheet on disk indefinitely.
        foreach (glob($directory . '/' . $this->cachePrefix($scoutYearId, $selectedSectionId) . '-*.pdf') ?: [] as $stale) {
            @unlink($stale);
        }
        $this->purgeExpired($directory);

        $tmp = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $pdf) === false || !@rename($tmp, $cacheFile)) {
            @unlink($tmp);
        }
    }

    /**
     * What a cached sheet's name starts with: the year and the filter it
     * was printed for. Two filters are two documents, so this is what the
     * same-year purge must match on rather than the year alone.
     */
    private function cachePrefix(int $scoutYearId, ?int $selectedSectionId): string
    {
        return $scoutYearId . '-' . ($selectedSectionId ?? 'all');
    }

    /**
     * Every cached sheet past CACHE_TTL_DAYS, whatever year or filter it
     * belongs to. Runs on each write rather than as a scheduled task: this
     * directory is only ever written by this service, so the moment it
     * grows is exactly the moment to sweep it.
     */
    private function purgeExpired(string $directory): void
    {
        foreach (glob($directory . '/*.pdf') ?: [] as $file) {
            if ($this->hasExpired($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Whether a cached sheet has outlived CACHE_TTL_DAYS. One definition
     * for the sweep on write and the check on read, so the two can never
     * come to disagree about what "expired" means.
     *
     * A file whose mtime cannot be read counts as expired: unknown age is
     * not an argument for keeping personal data.
     */
    private function hasExpired(string $file): bool
    {
        $modified = @filemtime($file);

        return $modified === false || $modified < time() - (self::CACHE_TTL_DAYS * 24 * 60 * 60);
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($value)) ?? '';

        return trim($slug, '-');
    }
}
