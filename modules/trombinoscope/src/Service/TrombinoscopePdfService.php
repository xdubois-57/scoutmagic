<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Trombinoscope\Service;

use Core\Member\MemberProfile;
use Core\Member\SectionService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Modules\Trombinoscope\Pdf\SectionView;
use Modules\Trombinoscope\Pdf\StaffPhotoEmbedder;
use Modules\Trombinoscope\Pdf\StaffView;
use Modules\Trombinoscope\Pdf\TrombinoscopeHtmlBuilder;

/**
 * The printable trombinoscope: a directory page, then one page per
 * section, as a PDF a chef d'unité can attach to an e-mail to the parents.
 *
 * Why a PDF rather than a print stylesheet — a decision, not an
 * implementation detail. A stylesheet cannot be shared: the trombinoscope
 * is `role_min: identified`, so a parent would have to be sent a link, log
 * in, and print it themselves. And it guarantees nothing — browsers drop
 * background colours by default, so the section colour bands would vanish
 * unless the reader ticked a box in a dialog they will not read, on top of
 * the browser's own header and footer and whatever paper size it picked.
 *
 * Core\Pdf\PosterPdfService is not reusable here (its `generate()` takes a
 * title, an excerpt and a QR URL, and produces one page), but its dompdf
 * frame is: `isRemoteEnabled = false`, `defaultFont = 'DejaVu Sans'`,
 * `@page { margin: 15mm }`, A4 portrait.
 *
 * The document always covers the WHOLE unit and the effective scout year.
 * Nobody prints all of it — a parent of Louveteaux prints page one and
 * their own section's — which is why every page carries its section's name
 * at the top, where the print dialog shows it.
 */
class TrombinoscopePdfService
{
    public function __construct(
        private TrombinoscopeService $trombinoscopeService,
        private SectionService $sectionService,
        private StaffPhotoEmbedder $photoEmbedder,
        private TrombinoscopeHtmlBuilder $htmlBuilder,
        /**
         * Where a rendered document is kept until its inputs change (a
         * directory, typically storage/temp). Null — the tests — means
         * every call renders. See generate().
         */
        private ?string $cacheDirectory = null
    ) {
    }

    /**
     * @param bool $showContacts the module's single setting, applied HERE —
     *        a personal phone number or address that is false never reaches
     *        the HTML, so it is in neither the document nor its text layer.
     * @return string raw PDF bytes
     */
    public function generate(
        int $scoutYearId,
        string $yearLabel,
        string $unitName,
        string $siteUrl,
        bool $showContacts
    ): string
    {
        // Rendering costs one to two seconds for a large unit — every
        // portrait is decoded, cropped and re-encoded, then dompdf lays out
        // fifteen pages — and its inputs only change on a Desk import, a
        // photo upload or a setting. So the document is kept on disk under
        // a signature of everything it is made of (the staff of every
        // section, their photos' file ids, the labels, the flags, and the
        // layout code itself) and served as-is while that signature holds:
        // a stale copy is impossible by construction, since any change to
        // an input is a different file name. Older copies for the same
        // year are removed when a new one is written.
        $sections = $this->sectionService->getAllWithBranches();
        $staffBySection = $this->trombinoscopeService->getSectionStaffForSections(
            array_map(static fn(array $s): int => (int) $s['id'], $sections),
            $scoutYearId
        );
        $memberIds = [];
        foreach ($staffBySection as $data) {
            foreach (array_merge($data['lead'] !== null ? [$data['lead']] : [], $data['staff']) as $profile) {
                $memberIds[] = $profile->memberId;
            }
        }
        $this->photoEmbedder->prime($memberIds, $scoutYearId);

        $cacheFile = $this->cacheFile($scoutYearId, $yearLabel, $unitName, $siteUrl, $showContacts, $sections, $staffBySection);
        if ($cacheFile !== null && is_file($cacheFile)) {
            $cached = @file_get_contents($cacheFile);
            if ($cached !== false && $cached !== '') {
                return $cached;
            }
        }

        $views = $this->buildSectionViews($scoutYearId, $showContacts, $sections, $staffBySection);
        $html = $this->htmlBuilder->build($views, $unitName, $yearLabel, $siteUrl, $showContacts);

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
     * @param array<int, array<string, mixed>> $sections
     * @param array<int, array{lead: ?MemberProfile, staff: MemberProfile[]}> $staffBySection
     */
    private function cacheFile(
        int $scoutYearId,
        string $yearLabel,
        string $unitName,
        string $siteUrl,
        bool $showContacts,
        array $sections,
        array $staffBySection
    ): ?string {
        if ($this->cacheDirectory === null) {
            return null;
        }

        $people = [];
        foreach ($staffBySection as $sectionId => $data) {
            foreach (array_merge($data['lead'] !== null ? [$data['lead']] : [], $data['staff']) as $profile) {
                $people[] = [
                    $sectionId,
                    $data['lead'] === $profile,
                    $profile->memberYearId,
                    $profile->getDisplayName(),
                    $profile->firstName,
                    $profile->lastName,
                    $showContacts ? ($profile->mobile ?: $profile->phone) : null,
                    $showContacts ? $profile->email : null,
                    $this->photoEmbedder->fileIdFor($profile->memberId, $scoutYearId),
                ];
            }
        }
        $layout = (string) realpath(__DIR__ . '/../Pdf/TrombinoscopeHtmlBuilder.php');
        $layoutStat = $layout !== '' ? @stat($layout) : false;

        $signature = hash('sha256', json_encode([
            $scoutYearId,
            $yearLabel,
            $unitName,
            $siteUrl,
            $showContacts,
            array_map(static fn(array $s): array => [
                (int) $s['id'], $s['name'] ?? null, $s['desk_code'] ?? null, $s['branch_name'] ?? null,
                $s['color'] ?? null, $s['email'] ?? null, $s['sort_order'] ?? null,
            ], $sections),
            $people,
            $layoutStat !== false ? [$layoutStat['mtime'], $layoutStat['size']] : null,
        ]));

        return $this->cacheDirectory . '/trombinoscope/' . $scoutYearId . '-' . $signature . '.pdf';
    }

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

    /**
     * Suggested file name, e.g. `trombinoscope-2025-2026.pdf`. Built from
     * the scout year alone — no unit name, no member name, nothing a mail
     * client would show before the file is opened.
     */
    public function fileName(string $yearLabel): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($yearLabel)) ?? '';

        return 'trombinoscope-' . (trim($slug, '-') ?: 'annuaire') . '.pdf';
    }

    /**
     * @return SectionView[] every section of the unit, in the order the
     *         site shows them. The Staff d'Unité is a section like any
     *         other here — it has its page and it sits on the directory,
     *         with no special case and, when nobody is designated, the
     *         same "Responsable non désigné" as anybody else.
     */
    /**
     * @param array<int, array<string, mixed>> $sections
     * @param array<int, array{lead: ?MemberProfile, staff: MemberProfile[]}> $staffBySection
     * @return SectionView[]
     */
    private function buildSectionViews(int $scoutYearId, bool $showContacts, array $sections, array $staffBySection): array
    {
        $views = [];
        foreach ($sections as $section) {
            $data = $staffBySection[(int) $section['id']] ?? ['lead' => null, 'staff' => []];

            $lead = $data['lead'] !== null
                ? $this->toStaffView($data['lead'], $scoutYearId, true, $showContacts)
                : null;

            $staff = $lead !== null ? [$lead] : [];
            foreach ($data['staff'] as $member) {
                $staff[] = $this->toStaffView($member, $scoutYearId, false, $showContacts);
            }

            $views[] = new SectionView(
                // A section with no name of its own falls back to its Desk
                // code, exactly as the screen does — never to an empty header.
                name: ($section['name'] ?? '') !== '' ? $section['name'] : $section['desk_code'],
                branchName: $section['branch_name'],
                color: SectionService::colorForSection($section),
                email: $section['email'],
                lead: $lead,
                staff: $staff
            );
        }

        return $views;
    }

    private function toStaffView(MemberProfile $member, int $scoutYearId, bool $isLead, bool $showContacts): StaffView
    {
        $civilName = trim($member->firstName . ' ' . $member->lastName);

        return new StaffView(
            displayName: $member->getDisplayName(),
            civilName: $civilName,
            initials: $this->initials($member),
            photoDataUri: $this->photoEmbedder->dataUriFor($member->memberId, $scoutYearId),
            phone: $showContacts ? ($member->mobile ?: $member->phone) : null,
            email: $showContacts ? $member->email : null,
            isLead: $isLead
        );
    }

    private function initials(MemberProfile $member): string
    {
        $first = mb_substr(trim($member->firstName), 0, 1);
        $last = mb_substr(trim($member->lastName), 0, 1);
        $initials = mb_strtoupper($first . $last);

        return $initials !== '' ? $initials : mb_strtoupper(mb_substr($member->getDisplayName(), 0, 2));
    }
}
