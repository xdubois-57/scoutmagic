<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

use Core\Config\SettingService;

/**
 * Stores/serves every unit-logo-derived asset — favicons, PWA manifest
 * icons, the apple-touch-icon, and the small footer logo — deliberately
 * outside the generic Core\File\FileRepository/'files' table/`/files/{id}`
 * machinery (SECURITY.md §6's single-download-path rule doesn't apply
 * here: a favicon/manifest/home-screen icon is fetched with no session at
 * all, so these must be plain public files under storage/, read back by
 * Core\Http\Controller\PwaController::icon()/favicon() rather than routed
 * through the session/role-aware FileAccessGuard). No database row either
 * — the fixed filenames under $storagePath ARE the state; "has a custom
 * logo" is is_file() on the retained source (see hasCustomLogo()).
 *
 * Originally PwaIconService, scoped to the four PWA manifest icons only —
 * renamed once favicon/footer-logo derivation widened its scope beyond
 * "PWA" (see ARCHITECTURE.md §8.23). Same storage root family
 * (storage/core/), moved from storage/core/pwa/ to storage/core/logo/ to
 * match; both are covered by the blanket storage/core/ .gitignore entry,
 * so no separate ignore rule was needed either before or after the move.
 *
 * Unlike the original PWA-only version, the ORIGINAL uploaded file is
 * also retained (source.<ext>) — every derivative is regenerable from it
 * without asking the superadmin to re-upload, which matters the moment
 * pwa_background_color changes (the maskable icon and icon-180 are baked
 * onto that color — see rederiveFromSource(), called by
 * Core\Http\Controller\SettingsController::update() whenever that setting
 * changes) or a future lot adds yet another derived size.
 */
class UnitLogoService
{
    // Keys here are a real PHP array literal, not markup: PHP coerces any
    // key that's a canonical decimal-integer string ('16', '32', ... '512')
    // to an int key at parse time — only '512-maskable' stays a string key.
    // The @var below reflects that mix (int|string), not array<string,
    // string> — every lookup already passes a string $size (resolveIconContent(),
    // isValidSize()), which PHP/PHPStan both resolve against an int|string
    // key set without issue; only the annotation itself needed to match
    // the actual literal's shape.
    /** @var array<int|string, string> */
    private const FILENAMES = [
        '16' => 'favicon-16.png',
        '32' => 'favicon-32.png',
        '48' => 'favicon-48.png',
        '64' => 'logo-64.png',
        '180' => 'icon-180.png',
        '192' => 'icon-192.png',
        '512' => 'icon-512.png',
        '512-maskable' => 'icon-512-maskable.png',
    ];

    private const ICO_FILENAME = 'favicon.ico';

    public function __construct(
        private UnitLogoProcessor $processor,
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

        $this->storeSource($contents, $mimeType);
        $this->writeDerivatives($icons);
        $this->bumpVersion();
    }

    /**
     * Re-runs every derivative from the retained source against the
     * *current* pwa_background_color — without this, a superadmin who
     * changes that color after uploading a logo would see every OTHER use
     * of the color update immediately (theme, manifest background) except
     * the maskable icon and icon-180, which would keep showing whatever
     * color was in effect at upload time. A no-op (returns false) when no
     * custom logo was ever uploaded — the shipped defaults under
     * $defaultIconPath aren't derived from any live source, so there is
     * nothing here to re-derive.
     */
    public function rederiveFromSource(): bool
    {
        $sourcePath = $this->findSourcePath();
        if ($sourcePath === null) {
            return false;
        }

        $contents = file_get_contents($sourcePath);
        if ($contents === false) {
            return false;
        }
        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath);

        $backgroundColor = (string) ($this->settingService->get('pwa_background_color') ?: '#ffffff');
        $icons = $this->processor->process($contents, $mimeType, $backgroundColor);

        $this->writeDerivatives($icons);
        $this->bumpVersion();

        return true;
    }

    /**
     * Removes every uploaded override (source + all derivatives) so
     * resolveIconContent()/resolveFaviconIcoContent() fall back to the
     * shipped defaults again — "delete" is simply "nothing custom left",
     * same is_file()-is-the-state precedent as the rest of this service.
     */
    public function deleteUploadedLogo(): void
    {
        foreach (glob($this->storagePath . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->bumpVersion();
    }

    public function hasCustomLogo(): bool
    {
        return $this->findSourcePath() !== null;
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

        return $this->resolveOverrideOrDefault($filename);
    }

    /**
     * Same override-then-default resolution as resolveIconContent(), for
     * the hand-packed /favicon.ico container — kept as its own method
     * since it isn't one of the sized PNG entries in FILENAMES (it's the
     * one asset that packs several of them together).
     */
    public function resolveFaviconIcoContent(): ?string
    {
        return $this->resolveOverrideOrDefault(self::ICO_FILENAME);
    }

    public function isValidSize(string $size): bool
    {
        return isset(self::FILENAMES[$size]);
    }

    /**
     * Cache-busting query string for manifest/head icon URLs — bumped
     * every time a new logo is uploaded, deleted, or re-derived, so
     * browsers/OSes that cached the old icon (long max-age, see
     * PwaController::icon()) pick up the new one immediately instead of
     * waiting out the cache lifetime.
     */
    public function currentVersion(): int
    {
        return (int) ($this->settingService->get('pwa_icon_version') ?: 1);
    }

    private function resolveOverrideOrDefault(string $filename): ?string
    {
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

    private function findSourcePath(): ?string
    {
        $matches = glob($this->storagePath . '/source.*') ?: [];
        return $matches[0] ?? null;
    }

    private function storeSource(string $contents, string $mimeType): void
    {
        foreach (glob($this->storagePath . '/source.*') ?: [] as $old) {
            unlink($old);
        }

        $extension = match ($mimeType) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'bin',
        };
        file_put_contents($this->storagePath . '/source.' . $extension, $contents);
    }

    /**
     * @param array<string, string> $icons size key (incl. 'ico') => bytes
     */
    private function writeDerivatives(array $icons): void
    {
        foreach (self::FILENAMES as $size => $filename) {
            file_put_contents($this->storagePath . '/' . $filename, $icons[$size]);
        }
        file_put_contents($this->storagePath . '/' . self::ICO_FILENAME, $icons['ico']);
    }

    private function bumpVersion(): void
    {
        $version = (int) ($this->settingService->get('pwa_icon_version') ?: 1);
        $this->settingService->setInternal('pwa_icon_version', (string) ($version + 1));
    }
}
