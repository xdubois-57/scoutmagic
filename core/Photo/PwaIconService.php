<?php

declare(strict_types=1);

namespace Core\Photo;

use Core\Config\SettingService;

/**
 * Stores/serves the four PWA icon derivatives — deliberately outside the
 * generic Core\File\FileRepository/'files' table/`/files/{id}` machinery
 * (SECURITY.md §6's single-download-path rule doesn't apply here: a
 * manifest is fetched with no session at all, so these must be plain
 * public files under storage/, read back by Core\Http\Controller\
 * PwaController::icon() rather than routed through the session/role-aware
 * FileAccessGuard). No database row either — the four fixed filenames
 * under $storagePath ARE the state; "has a custom icon" is simply
 * is_file().
 */
class PwaIconService
{
    /** @var array{192: string, 512: string, '512-maskable': string, 180: string} */
    private const FILENAMES = [
        '192' => 'icon-192.png',
        '512' => 'icon-512.png',
        '512-maskable' => 'icon-512-maskable.png',
        '180' => 'icon-180.png',
    ];

    public function __construct(
        private PwaIconProcessor $processor,
        private SettingService $settingService,
        private string $storagePath,
        private string $defaultIconPath
    ) {
    }

    /**
     * @throws \Core\File\UploadException on an undecodable source
     */
    public function storeUploadedLogo(string $contents, string $mimeType): void
    {
        $backgroundColor = (string) ($this->settingService->get('pwa_background_color') ?: '#ffffff');
        $icons = $this->processor->process($contents, $mimeType, $backgroundColor);

        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
        foreach (self::FILENAMES as $size => $filename) {
            file_put_contents($this->storagePath . '/' . $filename, $icons[$size]);
        }

        $version = (int) ($this->settingService->get('pwa_icon_version') ?: 1);
        $this->settingService->setInternal('pwa_icon_version', (string) ($version + 1));
    }

    /**
     * The actual bytes to serve for a given size — an uploaded override
     * if one exists, otherwise the shipped default under
     * public/assets/img/pwa/. Null only for an unrecognized $size.
     */
    public function resolveIconContent(string $size): ?string
    {
        $filename = self::FILENAMES[$size] ?? null;
        if ($filename === null) {
            return null;
        }

        $overridePath = $this->storagePath . '/' . $filename;
        if (is_file($overridePath)) {
            $content = file_get_contents($overridePath);
            if ($content !== false) {
                return $content;
            }
        }

        $defaultPath = $this->defaultIconPath . '/' . $filename;
        $content = @file_get_contents($defaultPath);

        return $content !== false ? $content : null;
    }

    public function isValidSize(string $size): bool
    {
        return isset(self::FILENAMES[$size]);
    }

    /**
     * Cache-busting query string for manifest/head icon URLs — bumped
     * every time a new logo is uploaded, so browsers/OSes that cached the
     * old icon (long max-age, see PwaController::icon()) pick up the new
     * one immediately instead of waiting out the cache lifetime.
     */
    public function currentVersion(): int
    {
        return (int) ($this->settingService->get('pwa_icon_version') ?: 1);
    }
}
