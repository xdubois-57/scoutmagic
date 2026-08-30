<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Banner\Repository\BannerRepository;
use Modules\Banner\Service\BannerService;

/**
 * Creates the homepage banners, in the two halves the module keeps them in.
 *
 * `BannerService` owns the row — order, active flag, minimum viewer role —
 * and nothing else: the formatted text belongs to the core editable-content
 * store under `banner_content_{id}`, exactly as the config page's rich-text
 * editor writes it. Writing the HTML anywhere else would produce a banner
 * that exists, is active, and renders nothing at all, which is the failure
 * this two-step is worth spelling out for.
 *
 * The key's shape is the module's documented contract (see
 * BannerService's own header and `modules/banner/views/config.html.twig`),
 * and `BannerService::contentKeyFor()` is private, so it is composed here the
 * same way the template composes it.
 */
final class BannerSeeder
{
    private readonly BannerService $bannerService;

    private readonly EditableContentService $editableContent;

    public function __construct(\PDO $pdo, private readonly int $actorId)
    {
        $this->editableContent = new EditableContentService(new EditableContentRepository($pdo));
        $this->bannerService = new BannerService(new BannerRepository($pdo), $this->editableContent);
    }

    /** @return int the number of banners created */
    public function seed(): int
    {
        $created = 0;

        foreach (BannerBlueprint::BANNERS as $declared) {
            $banner = $this->bannerService->create();
            $this->bannerService->setRoleMin($banner->id, $declared['roleMin']);
            $this->bannerService->setActive($banner->id, $declared['isActive']);
            $this->editableContent->set(
                'banner_content_' . $banner->id,
                $declared['html'],
                'rich_text',
                $this->actorId,
            );
            $created++;
        }

        return $created;
    }
}
