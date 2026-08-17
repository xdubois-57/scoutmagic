<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Maintenance\VersionFile;
use Twig\Environment;

/**
 * GET /api/version — public, unauthenticated. Exposes the same
 * version/commit already shown to a logged-in admin on Configuration >
 * Maintenance (Core\Http\Controller\MaintenanceController::index()),
 * purely so scripts/release.sh's deployment gate can verify a previous
 * release actually reached production before starting a new one, via a
 * plain `curl`. role_min: public — the repository is public (AGPL) and
 * every tag/commit is already visible on GitHub, so this discloses
 * nothing that isn't already public knowledge.
 */
class VersionController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private string $storagePath
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $raw = VersionFile::read(dirname($this->storagePath));

        $version = $raw;
        $commit = null;
        if (preg_match('/^dev-([0-9a-f]{7,40})$/', $raw, $matches)) {
            $version = 'dev';
            $commit = $matches[1];
        }

        return $this->json([
            'version' => $version,
            'commit' => $commit,
        ]);
    }
}
