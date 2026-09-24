<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Service;

use Core\Member\SectionStaffAuthorizationService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\Role;

/**
 * Builds the CarpoolViewer for a request, once: the account, its role, the
 * authorization year, and the sections it staffs.
 *
 * The session is read by the controller and handed in — a service never
 * touches it (AGENTS.md § Architecture).
 */
class CarpoolViewerResolver
{
    public function __construct(
        private ScoutYearResolver $scoutYears,
        private ?SectionStaffAuthorizationService $staffAuthorization = null
    ) {
    }

    public function resolve(int $accountId, string $email, string $role): CarpoolViewer
    {
        $yearId = $this->scoutYears->getAuthorizationYear()->id;
        $roleEnum = Role::fromString($role);

        $staffed = [];
        if ($this->staffAuthorization !== null && $roleEnum->hasAccess(Role::CHIEF)) {
            foreach ($this->staffAuthorization->getStaffedSections($email, $role, $yearId) as $section) {
                $staffed[] = (int) $section['id'];
            }
        }

        return new CarpoolViewer($accountId, $email, $roleEnum, $yearId, $staffed);
    }
}
