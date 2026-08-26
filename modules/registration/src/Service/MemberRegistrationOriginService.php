<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Module\MemberRegistrationOriginProvider;
use Core\Module\MemberRegistrationOriginView;
use Modules\Registration\Repository\RegistrationRequestRepository;

/**
 * This module's answer to core's "where did this member come from" hook
 * (Core\Module\MemberRegistrationOriginProvider, ARCHITECTURE.md §7.4).
 *
 * Lives under Service\, not Api\, and the distinction is the one §7.4
 * and §7.5 draw: `Api\` is where a module PUBLISHES a capability others
 * may want to consume, defining the interface itself. Here the interface
 * is core's and this class merely implements it — an ordinary module
 * service, wired nullable by the composition root.
 *
 * **A pointer, never a copy.** It hands back a label, a path and where
 * the request ended up, and nothing else — not the parent's name, not
 * the address, not the internal notes. The request has its own page,
 * built by the code that owns it; recopying three of its fields onto the
 * member page would create a second place to keep in step.
 *
 * `registration_requests.linked_member_id` is unique, so a member is the
 * target of at most one request and there is never a list to choose
 * from.
 */
class MemberRegistrationOriginService implements MemberRegistrationOriginProvider
{
    /** The module's own route — core never learns this string. */
    private const REQUEST_PATH_PREFIX = '/config/inscriptions/demandes/';

    public function __construct(private RegistrationRequestRepository $requests)
    {
    }

    public function getRegistrationOrigin(int $memberId): ?MemberRegistrationOriginView
    {
        $request = $this->requests->findByLinkedMemberId($memberId);
        if ($request === null) {
            return null;
        }

        return new MemberRegistrationOriginView(
            'Demande du ' . $request->receivedAt->format('d/m/Y'),
            self::REQUEST_PATH_PREFIX . $request->id,
            $request->statusLabel()
        );
    }
}
