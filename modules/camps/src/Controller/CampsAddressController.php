<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Controller;

use Core\Geo\AddressLocator;
use Core\Geo\AddressLookup;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Twig\Environment;

/**
 * The place forms' address lookup (#1353): the camps end of the core's point
 * picker, which asks it once when the address field is left — never per
 * keystroke — so the map can centre on a place before it is saved.
 *
 * A route of its own, small on purpose: the lookup is the core's
 * (`Core\Geo\AddressLocator`, cached, throttled site-wide, a newer request of
 * the same person superseding an older one — ARCHITECTURE.md §8.119), the
 * right to ask is the camps place form's (`chief`, in module.json), and the
 * switch is the module's own `camps_geocoding_enabled`: off, the composition
 * root passes no locator and the route answers « unavailable » without
 * asking anybody.
 */
class CampsAddressController extends AbstractController
{
    public function __construct(
        Environment $twig,
        private ?AddressLocator $locator = null
    ) {
        parent::__construct($twig);
    }

    /**
     * GET /chefs/camps/adresse?q= — the point of the address being typed.
     * When nothing is found the answer says why, so the form can say it
     * under the field: `not_found`, `unavailable` (off, or the lookup could
     * not run in time) or `superseded` (this person already asked for
     * another address, which is the one that answers).
     *
     * @param array<string, string> $params
     */
    public function locateAddress(Request $request, array $params): Response
    {
        $accountId = AuthSession::getUserAccountId();
        if ($this->locator === null || $accountId === null) {
            return $this->json(['success' => true, 'found' => false, 'reason' => AddressLookup::UNAVAILABLE]);
        }

        $lookup = $this->locator->locate((string) $request->getQuery('q', ''), $accountId);
        if ($lookup->point === null) {
            return $this->json(['success' => true, 'found' => false, 'reason' => $lookup->status]);
        }

        return $this->json([
            'success' => true,
            'found' => true,
            'latitude' => $lookup->point->latitude,
            'longitude' => $lookup->point->longitude,
        ]);
    }
}
