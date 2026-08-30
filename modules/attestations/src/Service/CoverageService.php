<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Service;

use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\MemberNameRepository;
use Modules\Attestations\Value\AttestationCategory;
use Modules\Attestations\Value\Coverage;

/**
 * The question partial files make urgent: for one category and one scout
 * year, who received their certificate and who did not.
 *
 * The federation sends several PDFs a season — a first batch in February, a
 * complement in March for the late registrations, sometimes a correction —
 * and each one is a batch of its own (§8.86). After three of them, only the
 * site can say who is still missing, and this is the only screen from which
 * a chef d'unité can ask the federation for a complement while knowing
 * whose.
 *
 * **The population is the roster of THAT scout year, not today's.** A
 * member who has since left was there when the certificate was earned, and
 * measuring against the current roster would silently drop exactly the
 * families with no page on the site to notice for themselves.
 *
 * **The comparison is on `members.id`**, so a member's annual rows never
 * enter into it: one person, one answer.
 */
class CoverageService
{
    public function __construct(
        private MemberNameRepository $members,
        private BatchLineRepository $lines
    ) {
    }

    public function forYear(AttestationCategory $category, int $scoutYearId): Coverage
    {
        $roster = $this->members->findRoster($scoutYearId);
        $covered = array_flip($this->lines->findCoveredMemberIds($category->value, $scoutYearId));

        $withCertificate = [];
        $without = [];
        foreach ($roster as $memberId => $summary) {
            if (isset($covered[$memberId])) {
                $withCertificate[] = $summary;
            } else {
                $without[] = $summary;
            }
        }

        return new Coverage($withCertificate, $without);
    }
}
