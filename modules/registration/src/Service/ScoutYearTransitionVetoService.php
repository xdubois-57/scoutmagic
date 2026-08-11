<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Modules\Registration\Api\ScoutYearTransitionVetoProvider;
use Modules\Registration\Repository\RegistrationRequestRepository;

/**
 * The module's implementation of its own Api\ScoutYearTransitionVetoProvider
 * (ARCHITECTURE.md §7.5/§8.38) — wired nullable into Core\ScoutYear\
 * ScoutYearAdminService by the composition root, only when this module is
 * enabled. A thin pass-through: the actual "non-final, all years" query
 * lives in the repository, since it's a plain read no other service-layer
 * logic needs to sit in front of.
 */
class ScoutYearTransitionVetoService implements ScoutYearTransitionVetoProvider
{
    public function __construct(private RegistrationRequestRepository $repository)
    {
    }

    public function countBlockingRequests(): int
    {
        return $this->repository->countNonFinalAllYears();
    }
}
