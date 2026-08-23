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
        $isDevBuild = VersionFile::isDevBuild($raw);

        // A dev build's exact commit sha is NOT disclosed publicly: it
        // fingerprints precisely which patches an install is missing, which
        // matters more here than elsewhere because the app ships an
        // auto-updater (audit hardening). A dev build reports a bare "dev".
        $payload = ['version' => $isDevBuild ? 'dev' : $raw];

        // scripts/release.sh's deployment gate still needs to confirm a dev
        // build has actually caught up to the exact commit about to be
        // released — this answers "does it match?" without ever reading
        // the real value back, which is the one piece of information the
        // hardening above withholds. A caller who does not already know
        // the short sha (anyone but the releaser, who read it from their
        // own git checkout) learns nothing from a query that always comes
        // back false.
        $expectedShortSha = $request->getQuery('commit');
        if ($isDevBuild && is_string($expectedShortSha) && preg_match('/^[0-9a-f]{7,40}$/', $expectedShortSha) === 1) {
            $payload['matches'] = hash_equals('dev-' . $expectedShortSha, $raw);
        }

        return $this->json($payload);
    }
}
