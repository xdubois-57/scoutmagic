<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

use Core\Security\Role;

/**
 * Who is looking at « Contrôle des créances » — what a source module needs
 * to decide whether its screen would open for them before offering a link
 * to it (issue #836).
 *
 * A treasurer sees a receivable through the finance account it is booked
 * on; that says nothing about the module's own screen. A rental is managed
 * by the asset's managers, a form's answers by the role the form names. A
 * link to a page that answers « accès refusé » is worse than no link.
 */
final class ReceivableViewer
{
    public function __construct(
        public readonly ?string $email,
        public readonly Role $role
    ) {
    }
}
