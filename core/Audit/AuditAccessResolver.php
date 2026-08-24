<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Audit;

/**
 * Who may read the history of a given entity type.
 *
 * Core deliberately knows none of the answers: an entity type belongs to
 * the module that records it, and only that module can say whether this
 * visitor may see this camp, this booking, this album. So the composition
 * root (public/index.php) registers one checker per entity type, exactly
 * where it already knows which modules are enabled.
 *
 * An UNREGISTERED entity type is denied, never allowed. Same fail-safe
 * as a route with no role_min: the failure mode of forgetting to register
 * a checker must be "the timeline does not show", not "the timeline shows
 * to everyone". This matters more here than usual, because the id in
 * /api/audit/{type}/{id} is guessable by construction.
 */
class AuditAccessResolver
{
    /** @var array<string, callable(int): bool> */
    private array $checkers = [];

    /**
     * @param callable(int): bool $checker receives the entity id and
     *        answers for the CURRENT visitor — the route's role_min has
     *        already run by the time it is called, so it only has to add
     *        what role alone cannot decide.
     */
    public function register(string $entityType, callable $checker): void
    {
        $this->checkers[$entityType] = $checker;
    }

    public function isRegistered(string $entityType): bool
    {
        return isset($this->checkers[$entityType]);
    }

    public function canRead(string $entityType, int $entityId): bool
    {
        if (!isset($this->checkers[$entityType])) {
            return false;
        }

        return ($this->checkers[$entityType])($entityId);
    }
}
