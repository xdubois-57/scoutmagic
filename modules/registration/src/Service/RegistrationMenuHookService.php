<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\SettingService;
use Core\Module\MenuEntry;
use Core\Module\MenuEntryProvider;
use Core\View\MenuBuilder;
use Modules\Registration\Repository\RegistrationRequest;

/**
 * Core\Module\MenuEntryProvider implementation — wired into the
 * composition root only when this module is enabled (ARCHITECTURE.md
 * §7.4). One entry per request linked to the visitor's email, with two
 * exclusions the module spec calls for: an 'encoded' request never
 * appears here at all (the real member page takes over immediately), and
 * a 'refused'/'withdrawn' request stops appearing once
 * `registration_espace_animes_retention_months` has passed since it
 * reached that final state — never `receivedAt`. A still-open (pending/
 * accepted) request always shows, regardless of age.
 */
class RegistrationMenuHookService implements MenuEntryProvider
{
    public function __construct(
        private TrackingService $trackingService,
        private SettingService $settingService
    ) {
    }

    public function getMenuEntries(?string $email): array
    {
        if ($email === null) {
            return [];
        }

        $retentionMonths = (int) ($this->settingService->get('registration_espace_animes_retention_months',
            'registration') ?: 3);
        $now = new \DateTimeImmutable();

        $entries = [];
        foreach ($this->trackingService->findAllLinkedByEmail($email) as $request) {
            if (!$this->isVisible($request, $retentionMonths, $now)) {
                continue;
            }

            $entries[] = new MenuEntry(
                MenuBuilder::MENU_ESPACE_ANIMES,
                trim($request->childFirstName . ' ' . $request->childLastName),
                '/inscriptions/suivi/demande/' . $request->id,
                'identified',
                // Sorts after the core-built per-member entries, which use
                // small orders, while staying inside SORT_GROUP_DYNAMIC so it
                // still lands before the separator.
                1000 + count($entries),
                true,
                'Demande d\'inscription',
                MenuBuilder::SORT_GROUP_DYNAMIC,
                null,
                // Same column as the core-built per-member entries: a
                // request stands for a child the visitor is waiting to see
                // become a member, not for a page of the site.
                'mes_membres'
            );
        }

        return $entries;
    }

    private function isVisible(RegistrationRequest $request, int $retentionMonths, \DateTimeImmutable $now): bool
    {
        if ($request->status === RegistrationRequest::STATUS_ENCODED) {
            return false;
        }

        if (in_array($request->status, [RegistrationRequest::STATUS_REFUSED, RegistrationRequest::STATUS_WITHDRAWN],
            true)
            && $request->finalAt !== null
        ) {
            return $now < $request->finalAt->modify("+{$retentionMonths} months");
        }

        return true;
    }
}
