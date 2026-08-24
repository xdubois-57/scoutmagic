<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Audit;

/**
 * A reusable per-entity change history (ARCHITECTURE.md §8.66), rendered
 * on the entity's own page by partials/audit_timeline.html.twig.
 *
 * NOT Core\Journal\JournalService. The journal is a global administrative
 * log: it forbids personal data, has no entity anchor, and is read as one
 * long stream by a superadmin. This is a timeline a chief reads on one
 * camp, one booking, one place, and it therefore may hold the values
 * themselves — which is exactly why every one of them is encrypted. The
 * two coexist; a sensitive action usually writes to both, one line for
 * the administrator and one for the entity.
 *
 * This component knows nothing about any module. It never formats,
 * parses, compares or interprets a value: callers pass strings already
 * written for a reader ("2 450 €", "Confirmé", "12–19 juillet 2028"), and
 * the timeline shows exactly those.
 */
class AuditService
{
    public const DEFAULT_PER_PAGE = 10;

    public function __construct(private AuditRepository $repository)
    {
    }

    /**
     * Records one field's change.
     *
     * $from and $to are both nullable and both meaningful: a null $from is
     * a value that did not exist before (a contact added), a null $to one
     * that no longer does (a price cleared). Recording a change where the
     * two are equal is the caller's business to avoid — this never
     * compares them, because "2 450 €" and "2450 EUR" are the same price
     * to a human and different strings here.
     */
    public function record(
        string $entityType,
        int $entityId,
        string $fieldKey,
        ?string $from,
        ?string $to,
        AuditSource $source,
        ?string $summary = null,
        ?string $sourceReference = null,
        ?int $actorUserAccountId = null
    ): void {
        $this->repository->insert(
            $entityType,
            $entityId,
            $fieldKey,
            $from,
            $to,
            $source,
            $summary,
            $sourceReference,
            $actorUserAccountId
        );
    }

    /**
     * One page of history, newest first. $page is 1-based; anything below
     * 1 reads as 1 rather than producing a negative OFFSET.
     */
    public function page(string $entityType, int $entityId, int $page, int $perPage): AuditPage
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $total = $this->repository->countForEntity($entityType, $entityId);
        $entries = $total > 0
            ? $this->repository->findForEntity($entityType, $entityId, $perPage, ($page - 1) * $perPage)
            : [];

        return new AuditPage($entries, $page, $perPage, $total);
    }

    /**
     * Blanks the recorded values for a set of entities and fields, and
     * returns how many rows changed.
     *
     * This lives in core rather than in whichever module needs it first,
     * because a history that keeps a name after that person asked to be
     * erased is the same failure whatever module recorded it — and it is
     * the kind of thing each module would otherwise reimplement slightly
     * differently. The rows themselves stay: that a field changed, when,
     * and by whom is not the personal data; the old and new values were.
     *
     * @param int[]    $entityIds
     * @param string[] $fieldKeys
     */
    public function anonymiseValues(string $entityType, array $entityIds, array $fieldKeys): int
    {
        return $this->repository->anonymiseValues($entityType, $entityIds, $fieldKeys);
    }
}
