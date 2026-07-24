<?php

declare(strict_types=1);

namespace Core\View;

use Core\Http\FlashMessage;
use Core\Security\CsrfGuard;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

class TwigFactory
{
    /**
     * @param array<string, string> $moduleTemplateDirs Namespace => path mapping for modules
     */
    public static function create(string $templateDir, bool $debug = false, array $moduleTemplateDirs = []): Environment
    {
        $loader = new FilesystemLoader($templateDir);

        // Register module template namespaces
        foreach ($moduleTemplateDirs as $namespace => $path) {
            if (is_dir($path)) {
                $loader->addPath($path, $namespace);
            }
        }

        $cacheDir = dirname(__DIR__, 2) . '/storage/temp/twig_cache';

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $environment = new Environment($loader, [
            'cache' => $debug ? false : $cacheDir,
            'debug' => $debug,
            'auto_reload' => $debug,
            'autoescape' => 'html',
        ]);

        // Register csrf_field() function
        $environment->addFunction(new TwigFunction('csrf_field', function (): string {
            $token = CsrfGuard::generateToken();
            return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
        }, ['is_safe' => ['html']]));

        // Register get_flash() function
        $environment->addFunction(new TwigFunction('get_flash', function (): ?array {
            return FlashMessage::get();
        }));

        // Register csrf_token() function (returns raw token for meta tags)
        $environment->addFunction(new TwigFunction('csrf_token', function (): string {
            return CsrfGuard::generateToken();
        }));

        // Register file_url() function
        $environment->addFunction(new TwigFunction('file_url', function (int|string|null $id): string {
            if ($id === null || $id === '' || $id === 0) {
                return '';
            }
            return '/files/' . (int) $id;
        }));

        // Register editable() function — renders editable content
        $environment->addFunction(new TwigFunction('editable', function (string $key, string $default = '', string $type = 'rich_text') use ($environment): string {
            /** @var EditableContentService|null $service */
            $service = $environment->getGlobals()['_editable_content_service'] ?? null;
            $configMode = $environment->getGlobals()['config_mode'] ?? false;

            $value = $service !== null ? $service->get($key, $default) : $default;
            $value = $value ?? $default;

            if ($configMode) {
                return '<div class="editable-content" data-key="' . htmlspecialchars($key, ENT_QUOTES) . '" data-type="' . htmlspecialchars($type, ENT_QUOTES) . '">'
                    . '<div class="editable-overlay"><button class="btn btn-sm btn-outline-primary editable-edit-btn"><i class="bi bi-pencil"></i> Modifier</button></div>'
                    . $value
                    . '</div>';
            }

            return (string) $value;
        }, ['is_safe' => ['html']]));

        // Register editable_image() function — renders editable image
        $environment->addFunction(new TwigFunction('editable_image', function (string $key, string $alt = '', string $cssClass = 'img-fluid rounded') use ($environment): string {
            /** @var EditableContentService|null $service */
            $service = $environment->getGlobals()['_editable_content_service'] ?? null;
            $configMode = $environment->getGlobals()['config_mode'] ?? false;

            $fileId = $service !== null ? $service->get($key) : null;

            if ($configMode) {
                if ($fileId !== null && $fileId !== '') {
                    return '<div class="editable-image" data-key="' . htmlspecialchars($key, ENT_QUOTES) . '" data-type="image">'
                        . '<div class="editable-overlay"><button class="btn btn-sm btn-outline-primary editable-edit-btn"><i class="bi bi-camera"></i> Changer</button></div>'
                        . '<img src="/files/' . (int) $fileId . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" class="' . htmlspecialchars($cssClass, ENT_QUOTES) . '">'
                        . '</div>';
                }

                return '<div class="editable-image editable-placeholder" data-key="' . htmlspecialchars($key, ENT_QUOTES) . '" data-type="image">'
                    . '<div class="d-flex align-items-center justify-content-center bg-light rounded" style="min-height:200px;">'
                    . '<span class="text-muted"><i class="bi bi-image"></i> Cliquer pour ajouter une image</span>'
                    . '</div>'
                    . '</div>';
            }

            if ($fileId !== null && $fileId !== '') {
                return '<img src="/files/' . (int) $fileId . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" class="' . htmlspecialchars($cssClass, ENT_QUOTES) . '">';
            }

            return '';
        }, ['is_safe' => ['html']]));

        // Register member_photo() function — core "photo per person per year"
        // component (ARCHITECTURE.md §8): resolves the member's photo for the
        // site's current scout year (with fallback to the most recent earlier
        // photo — see Core\Photo\MemberPhotoService). When none exists, falls
        // back to the same "initials in a filled circle" avatar used for the
        // logged-in account menu (partials/nav.html.twig), sized to the same
        // box as a real photo so grids stay aligned. In configuration mode,
        // renders the same click-to-replace overlay as editable_image().
        $environment->addFunction(new TwigFunction('member_photo', function (int $memberId, string $alt = '', string $cssClass = 'rounded-circle') use ($environment): string {
            /** @var \Core\Photo\MemberPhotoService|null $service */
            $service = $environment->getGlobals()['_member_photo_service'] ?? null;
            $scoutYearId = (int) ($environment->getGlobals()['effective_scout_year_id'] ?? 0);
            $configMode = $environment->getGlobals()['config_mode'] ?? false;

            $fileId = ($service !== null && $scoutYearId > 0) ? $service->resolveFileId($memberId, $scoutYearId) : null;

            if ($fileId !== null) {
                $img = '<img src="/files/' . $fileId . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" class="' . htmlspecialchars($cssClass, ENT_QUOTES) . '">';
            } else {
                $initials = mb_strtoupper(mb_substr(trim($alt), 0, 2));
                $img = '<div class="' . htmlspecialchars($cssClass, ENT_QUOTES) . ' member-photo-placeholder" title="' . htmlspecialchars($alt, ENT_QUOTES) . '">'
                    . '<span class="member-photo-initials d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-bold">'
                    . htmlspecialchars($initials, ENT_QUOTES)
                    . '</span></div>';
            }

            if ($configMode && $scoutYearId > 0) {
                $key = $memberId . ':' . $scoutYearId;
                return '<div class="editable-image" data-key="' . htmlspecialchars($key, ENT_QUOTES) . '" data-context="member_photo">'
                    . '<div class="editable-overlay"><button class="btn btn-sm btn-outline-primary editable-edit-btn"><i class="bi bi-pencil"></i></button></div>'
                    . $img
                    . '</div>';
            }

            return $img;
        }, ['is_safe' => ['html']]));

        // Register text normalization filters (normalize_name/totem/phone/address)
        $environment->addExtension(new TextNormalizerExtension());

        // Register display_name filter
        $environment->addFilter(new TwigFilter('display_name', function ($member) {
            if ($member instanceof \Core\Member\MemberProfile) {
                return $member->getDisplayName();
            }
            // Also handle arrays (from menu builder)
            if (is_array($member)) {
                return $member['totem'] ?? $member['first_name'] ?? '?';
            }
            return (string) $member;
        }));

        // Register french_date filter — formats a Y-m-d(-His) string or
        // DateTimeInterface as "12 juillet 2026", no intl extension
        // required (ARCHITECTURE.md: no dependency not explicitly
        // justified — a 12-entry month name lookup isn't worth one).
        $environment->addFilter(new TwigFilter('french_date', function ($date) {
            static $months = [
                1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
                7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
            ];

            if ($date === null || $date === '') {
                return '';
            }

            $dateTime = $date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable((string) $date);

            return (int) $dateTime->format('j') . ' ' . $months[(int) $dateTime->format('n')] . ' ' . $dateTime->format('Y');
        }));

        return $environment;
    }
}
