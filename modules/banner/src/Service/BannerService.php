<?php

declare(strict_types=1);

namespace Modules\Banner\Service;

use Core\Module\HomeBannerProvider;
use Core\View\EditableContentService;
use Modules\Banner\Repository\Banner;
use Modules\Banner\Repository\BannerRepository;

/**
 * Homepage banner (module spec: Configuration > Bannière) — a random
 * active banner is shown on every homepage load. Each banner's formatted
 * text is stored via the core EditableContentService (same rich-text
 * engine and sanitization as the rest of the site), keyed
 * "banner_content_{id}"; this service only owns the banners table itself
 * (order + active flag).
 */
class BannerService implements HomeBannerProvider
{
    public function __construct(
        private BannerRepository $bannerRepository,
        private EditableContentService $editableContentService
    ) {
    }

    /**
     * @return array<int, array{id: int, is_active: bool, content: string}>
     */
    public function getAllForConfig(): array
    {
        return array_map(
            fn(Banner $banner) => [
                'id' => $banner->id,
                'is_active' => $banner->isActive,
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

    public function getRandomBannerHtml(): ?string
    {
        $active = $this->bannerRepository->findActiveOrdered();
        if ($active === []) {
            return null;
        }

        $chosen = $active[array_rand($active)];
        $html = $this->editableContentService->get($this->contentKeyFor($chosen->id));
        return $html !== null && $html !== '' ? $html : null;
    }

    private function contentKeyFor(int $id): string
    {
        return 'banner_content_' . $id;
    }
}
