<?php

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\SettingService;
use Core\Module\EspaceAnimesEntryProvider;
use Modules\Registration\Repository\RegistrationRequest;

/**
 * Core\Module\EspaceAnimesEntryProvider implementation — wired into the
 * composition root only when this module is enabled (ARCHITECTURE.md
 * §7.4). One entry per request linked to the visitor's email, with two
 * exclusions the module spec calls for: an 'encoded' request never
 * appears here at all (the real member page takes over immediately), and
 * a 'refused'/'withdrawn' request stops appearing once
 * `registration_espace_animes_retention_months` has passed since it
 * reached that final state — never `receivedAt`. A still-open (pending/
 * accepted) request always shows, regardless of age.
 */
class RegistrationMenuHookService implements EspaceAnimesEntryProvider
{
    public function __construct(
        private TrackingService $trackingService,
        private SettingService $settingService
    ) {
    }

    public function getEntriesForEmail(string $email): array
    {
        $retentionMonths = (int) ($this->settingService->get('registration_espace_animes_retention_months', 'registration') ?: 3);
        $now = new \DateTimeImmutable();

        $entries = [];
        foreach ($this->trackingService->findAllLinkedByEmail($email) as $request) {
            if (!$this->isVisible($request, $retentionMonths, $now)) {
                continue;
            }

            $entries[] = [
                'label' => trim($request->childFirstName . ' ' . $request->childLastName),
                'url' => '/inscriptions/suivi/demande/' . $request->id,
                'subtitle' => 'Demande d\'inscription',
            ];
        }

        return $entries;
    }

    private function isVisible(RegistrationRequest $request, int $retentionMonths, \DateTimeImmutable $now): bool
    {
        if ($request->status === RegistrationRequest::STATUS_ENCODED) {
            return false;
        }

        if (in_array($request->status, [RegistrationRequest::STATUS_REFUSED, RegistrationRequest::STATUS_WITHDRAWN], true)
            && $request->finalAt !== null
        ) {
            return $now < $request->finalAt->modify("+{$retentionMonths} months");
        }

        return true;
    }
}
