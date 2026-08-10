<?php

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Module\EspaceAnimesEntryProvider;

/**
 * Core\Module\EspaceAnimesEntryProvider implementation — wired into the
 * composition root only when this module is enabled (ARCHITECTURE.md
 * §7.4). One entry per pending request linked to the visitor's email.
 */
class RegistrationMenuHookService implements EspaceAnimesEntryProvider
{
    public function __construct(private TrackingService $trackingService)
    {
    }

    public function getEntriesForEmail(string $email): array
    {
        $entries = [];

        foreach ($this->trackingService->findAllLinkedByEmail($email) as $request) {
            $entries[] = [
                'label' => trim($request->childFirstName . ' ' . $request->childLastName),
                'url' => '/inscriptions/suivi/demande/' . $request->id,
                'subtitle' => 'Demande d\'inscription',
            ];
        }

        return $entries;
    }
}
