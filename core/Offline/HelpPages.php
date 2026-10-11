<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Offline;

use Core\Help\HelpService;
use Core\Security\Role;

/**
 * `/aide` and every help topic this role may read (#883). Its entry is
 * marked `last`: the help is downloaded after the other pages and their
 * images, being the least likely reason the app was opened offline.
 */
final class HelpPages implements OfflinePageProvider
{
    public function __construct(private HelpService $helpService)
    {
    }

    public function pagesFor(Role $role, ?string $email): array
    {
        $pages = ['/aide'];
        foreach ($this->helpService->listForRole($role) as $topics) {
            foreach ($topics as $topic) {
                $pages[] = '/aide/' . rawurlencode($topic->id);
            }
        }

        return $pages;
    }
}
