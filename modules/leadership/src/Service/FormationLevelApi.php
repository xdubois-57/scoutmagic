<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Service;

use Modules\Leadership\Api\FormationLevel;
use Modules\Leadership\Api\FormationLevelInterface;
use Modules\Leadership\Repository\FormationLevelMappingRepository;

/**
 * The module's side of `Api\FormationLevelInterface` (roadmap IT-21).
 *
 * A thin adapter over Service\FormationLevelResolver, and thin on
 * purpose: the resolution rules — the admin mapping first, then the
 * built-in heuristic, "en cours" never resolving to a brevet — stay in
 * one place, and a consumer gets exactly the reading this unit's own
 * pages show.
 *
 * **The mapping is loaded once, on the first question asked.** A
 * consumer walks a whole roster (fees checks a federation invoice line by
 * line), so a repository round trip per member would be hundreds of
 * queries for a table of a dozen rows; loading it in the constructor
 * would instead cost one query on every request that merely *builds* the
 * composition root, which is every request there is.
 */
final class FormationLevelApi implements FormationLevelInterface
{
    private ?FormationLevelResolver $resolved = null;

    public function __construct(
        private FormationLevelMappingRepository $mappingRepository,
        private FormationLevelResolver $resolver
    ) {
    }

    public function resolve(?string $rawFormationLevel): FormationLevel
    {
        $this->resolved ??= $this->resolver->withMapping($this->mappingRepository->findAll());
        $step = $this->resolved->resolve($rawFormationLevel);

        return new FormationLevel(
            code: $step->value,
            label: $step->label(),
            recognised: $step !== \Modules\Leadership\FormationStep::UNKNOWN,
            countsForOneRatio: $step->countsForOneRatio(),
            countsForFederationDiscount: $step->countsForFederationDiscount()
        );
    }
}
