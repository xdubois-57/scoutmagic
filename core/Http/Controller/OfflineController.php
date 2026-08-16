<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Module\StaffDirectoryProvider;
use Core\Photo\MemberPhotoService;
use Twig\Environment;

/**
 * Offline mode (Lot 3) support surface. Used to also serve a bespoke
 * on-demand staff-photo derivative through a route deliberately outside
 * `/files/{id}` — that exception is retired: `GET /files/{id}/thumb`
 * (Core\Photo\ImageVariantService, Core\Http\Controller\FileController
 * ::variant()) now serves exactly the same square avatar through the
 * single, guarded download path, so photoManifest() below simply points
 * the offline pre-download at that URL instead of a route of its own.
 */
class OfflineController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private MemberPhotoService $memberPhotoService,
        private ScoutYearService $scoutYearService,
        private ?StaffDirectoryProvider $staffDirectoryProvider = null
    ) {
    }

    /**
     * GET /api/offline/photo-manifest — every thumbnail URL the caller is
     * entitled to pre-download, across every section (module spec: "not
     * only theirs"). Members with no current photo are simply omitted —
     * nothing to fetch for them. Each URL still goes through the exact
     * same FileAccessGuard check as any other `/files/{id}/thumb` request
     * once the pre-download script fetches it — this manifest only lists
     * candidates, it grants no access of its own.
     *
     * @param array<string, string> $params
     */
    public function photoManifest(Request $request, array $params): Response
    {
        if ($this->staffDirectoryProvider === null) {
            return $this->json(['photos' => []]);
        }

        $scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];

        $photos = [];
        foreach ($this->staffDirectoryProvider->getAllEligibleStaffMemberIds($scoutYearId) as $memberId) {
            $fileId = $this->memberPhotoService->resolveFileId($memberId, $scoutYearId);
            if ($fileId !== null) {
                $photos[] = ['member_id' => $memberId, 'url' => '/files/' . $fileId . '/thumb'];
            }
        }

        return $this->json(['photos' => $photos]);
    }
}
