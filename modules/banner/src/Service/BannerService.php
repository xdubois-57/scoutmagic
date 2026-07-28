<?php

declare(strict_types=1);

namespace Modules\Banner\Service;

use Core\Module\HomeBannerProvider;
use Core\Security\Role;
use Core\View\EditableContentService;
use Modules\Banner\Repository\Banner;
use Modules\Banner\Repository\BannerRepository;

/**
 * Homepage banner (module spec: Configuration > Bannière) — a random
 * active banner matching the current viewer's role is shown on every
 * homepage load. Each banner's formatted text is stored via the core
 * EditableContentService (same rich-text engine and sanitization as the
 * rest of the site), keyed "banner_content_{id}"; this service only owns
 * the banners table itself (order + active flag + minimum viewer role).
 */
class BannerService implements HomeBannerProvider
{
    public function __construct(
        private BannerRepository $bannerRepository,
        private EditableContentService $editableContentService
    ) {
    }

    /**
     * @return array<int, array{id: int, is_active: bool, role_min: string, content: string}>
     */
    public function getAllForConfig(): array
    {
        return array_map(
            fn(Banner $banner) => [
                'id' => $banner->id,
                'is_active' => $banner->isActive,
                'role_min' => $banner->roleMin,
                'content' => $this->editableContentService->get($this->contentKeyFor($banner->id), '') ?? '',
            ],
            $this->bannerRepository->findAllOrdered()
        );
    }

    public function create(): Banner
    {
        $id = $this->bannerRepository->create();
        $banner = $this->bannerRepository->findById($id);
        \assert($banner !== null);
        return $banner;
    }

    /**
     * @throws BannerException when the banner doesn't exist
     */
    public function setActive(int $id, bool $active): void
    {
        if ($this->bannerRepository->findById($id) === null) {
            throw new BannerException('Bannière introuvable.');
        }
        $this->bannerRepository->setActive($id, $active);
    }

    /**
     * @throws BannerException when the banner doesn't exist or $roleMin
     *                          isn't one of Banner::ROLE_MINS
     */
    public function setRoleMin(int $id, string $roleMin): void
    {
        if ($this->bannerRepository->findById($id) === null) {
            throw new BannerException('Bannière introuvable.');
        }
        if (!in_array($roleMin, Banner::ROLE_MINS, true)) {
            throw new BannerException('Visibilité invalide.');
        }
        $this->bannerRepository->setRoleMin($id, $roleMin);
    }

    /**
     * @throws BannerException when the banner doesn't exist
     */
    public function delete(int $id): void
    {
        if ($this->bannerRepository->findById($id) === null) {
            throw new BannerException('Bannière introuvable.');
        }
        $this->bannerRepository->delete($id);
        $this->editableContentService->delete($this->contentKeyFor($id));
    }

    /**
     * @param int[] $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $this->bannerRepository->reorder($orderedIds);
    }

    public function getRandomBannerHtml(string $viewerRole): ?string
    {
        $viewer = Role::fromString($viewerRole);
        $visible = array_values(array_filter(
            $this->bannerRepository->findActiveOrdered(),
            fn(Banner $banner) => $viewer->hasAccess(Role::fromString($banner->roleMin))
        ));
        if ($visible === []) {
            return null;
        }

        $chosen = $visible[array_rand($visible)];
        $html = $this->editableContentService->get($this->contentKeyFor($chosen->id));
        return $html !== null && $html !== '' ? $html : null;
    }

    private function contentKeyFor(int $id): string
    {
        return 'banner_content_' . $id;
    }
}
