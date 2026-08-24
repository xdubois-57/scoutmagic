<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Audit\AuditAccessResolver;
use Core\Audit\AuditEntry;
use Core\Audit\AuditService;
use Core\Http\Request;
use Core\Http\Response;
use Twig\Environment;

/**
 * GET /api/audit/{entity_type}/{entity_id} — the pages after the first,
 * for partials/audit_timeline.html.twig's "Afficher plus" (public/assets/
 * js/audit-timeline.js appends them).
 *
 * The route's role_min is 'chief', which is the FLOOR, not the answer:
 * whether this particular chief may read this particular entity's history
 * is the owning module's business, and Core\Audit\AuditAccessResolver
 * asks it. An entity type nobody registered is refused — the ids here are
 * sequential and guessable, so "unknown type" must never mean "allowed".
 */
class AuditController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private AuditService $auditService,
        private AuditAccessResolver $accessResolver
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function page(Request $request, array $params): Response
    {
        $entityType = (string) ($params['entity_type'] ?? '');
        $entityId = (int) ($params['entity_id'] ?? 0);
        if ($entityType === '' || $entityId <= 0) {
            return $this->json(['error' => 'Requête invalide.'], 400);
        }

        // One answer for "no such entity type", "not registered" and "not
        // yours": a distinct 404 vs 403 would let a caller map which camps
        // exist by watching which ids answer differently.
        if (!$this->accessResolver->canRead($entityType, $entityId)) {
            return $this->json(['error' => 'Historique indisponible.'], 403);
        }

        $page = max(1, (int) $request->getQuery('page', 1));
        $result = $this->auditService->page($entityType, $entityId, $page, AuditService::DEFAULT_PER_PAGE);

        return $this->json([
            'page' => $result->page,
            'has_more' => $result->hasMore(),
            'total' => $result->total,
            'entries' => array_map(
                fn(AuditEntry $e): array => [
                    'id' => $e->id,
                    'field_key' => $e->fieldKey,
                    'from_value' => $e->fromValue,
                    'to_value' => $e->toValue,
                    'summary' => $e->summary,
                    'source' => $e->source->value,
                    'actor_name' => $e->actorName,
                    'is_automatic' => $e->isAutomatic(),
                    'created_at' => $e->createdAt,
                ],
                $result->entries
            ),
        ]);
    }
}
