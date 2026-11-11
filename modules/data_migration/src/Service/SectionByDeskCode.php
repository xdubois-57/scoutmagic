<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Service;

use Core\Member\SectionService;

/**
 * A section referenced by an archive, by its Desk code (D10).
 *
 * Migration archives never carry a SQL id of the old site: they name a
 * section by `sections.desk_code`, the one identifier both sites share
 * with the federation. No match is an answer, not an error: the import
 * drops the link — or, when the link is what decides who may see the
 * object (an album limited to one section), ignores the object rather than
 * widening its visibility to the whole unit.
 */
class SectionByDeskCode
{
    /** @var array<string, ?int> */
    private array $memo = [];

    public function __construct(private SectionService $sections)
    {
    }

    /** The section id for this Desk code, or null for an absent, empty or unknown one. */
    public function find(mixed $deskCode): ?int
    {
        if (!is_string($deskCode) || trim($deskCode) === '') {
            return null;
        }

        $code = trim($deskCode);
        if (!array_key_exists($code, $this->memo)) {
            $this->memo[$code] = $this->sections->findByDeskCode($code)['id'] ?? null;
        }

        return $this->memo[$code];
    }
}
