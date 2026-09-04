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
        private TrombinoscopeHtmlBuilder $htmlBuilder
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
        $sections = $this->buildSectionViews($scoutYearId, $showContacts);
        $html = $this->htmlBuilder->build($sections, $unitName, $yearLabel, $siteUrl, $showContacts);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
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
    private function buildSectionViews(int $scoutYearId, bool $showContacts): array
    {
        $views = [];
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            $data = $this->trombinoscopeService->getSectionStaff((int) $section['id'], $scoutYearId);

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
