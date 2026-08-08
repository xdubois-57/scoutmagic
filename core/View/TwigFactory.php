<?php

declare(strict_types=1);

namespace Core\View;

use Core\Http\FlashMessage;
use Core\Maintenance\VersionFile;
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

        $cacheDir = self::cacheDirectory(dirname(__DIR__, 2));

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

        // Register editable_image() function — renders editable image.
        // The "no image yet" case must still render the same overlay/
        // editable-edit-btn as the "already has an image" case (same
        // pattern already followed by member_photo()/section_photo()
        // below) — a bare placeholder box with no button was a real bug:
        // clicking it did nothing, since editable.js only wires up
        // .editable-edit-btn elements.
        $environment->addFunction(new TwigFunction('editable_image', function (string $key, string $alt = '', string $cssClass = 'img-fluid rounded') use ($environment): string {
            /** @var EditableContentService|null $service */
            $service = $environment->getGlobals()['_editable_content_service'] ?? null;
            $configMode = $environment->getGlobals()['config_mode'] ?? false;

            $fileId = $service !== null ? $service->get($key) : null;
            $hasImage = $fileId !== null && $fileId !== '';

            if ($configMode) {
                $img = $hasImage
                    ? '<img src="/files/' . (int) $fileId . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" class="' . htmlspecialchars($cssClass, ENT_QUOTES) . '">'
                    : '<div class="d-flex align-items-center justify-content-center bg-light rounded" style="min-height:200px;">'
                        . '<span class="text-muted"><i class="bi bi-image"></i> Cliquer pour ajouter une image</span>'
                        . '</div>';
                $buttonLabel = $hasImage ? 'Changer' : 'Ajouter';

                return '<div class="editable-image" data-key="' . htmlspecialchars($key, ENT_QUOTES) . '" data-type="image">'
                    . '<div class="editable-overlay"><button class="btn btn-sm btn-outline-primary editable-edit-btn"><i class="bi bi-camera"></i> ' . $buttonLabel . '</button></div>'
                    . $img
                    . '</div>';
            }

            if ($hasImage) {
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
        // The $editable parameter is an explicit opt-in for the one other
        // place a non-config-mode user may replace their own photo (the
        // member page, outside configuration mode) — config_mode remains
        // the trigger everywhere else. Server-side authorization for the
        // resulting upload is enforced by UploadController, never by this
        // flag alone (see Core\Http\Controller\UploadController::store()).
        $environment->addFunction(new TwigFunction('member_photo', function (int $memberId, string $alt = '', string $cssClass = 'rounded-circle', bool $editable = false) use ($environment): string {
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

            if (($configMode || $editable) && $scoutYearId > 0) {
                $key = $memberId . ':' . $scoutYearId;
                return '<div class="editable-image" data-key="' . htmlspecialchars($key, ENT_QUOTES) . '" data-context="member_photo">'
                    . '<div class="editable-overlay"><button class="btn btn-sm btn-outline-primary editable-edit-btn"><i class="bi bi-pencil"></i></button></div>'
                    . $img
                    . '</div>';
            }

            return $img;
        }, ['is_safe' => ['html']]));

        // Register section_photo() function — "photo of the staff" (all
        // chiefs of a section, together) shown on the Staffs page. Same
        // per-year-with-fallback resolution and config-mode click-to-
        // replace overlay as member_photo() above, keyed by section
        // instead of member — see Core\Photo\SectionPhotoService. Unlike
        // member_photo(), there's no per-person initials fallback: with no
        // photo and outside config mode this renders nothing at all
        // (matches editable_image()'s own "nothing to show" behavior), so
        // an ordinary member sees no empty box for a section that's never
        // had one uploaded. The image is always already cropped to a 4:3
        // landscape rendition by Core\Photo\SectionPhotoProcessor before
        // it's ever stored — the inline aspect-ratio/object-fit here is
        // just a display-time safety net, not the actual crop.
        $environment->addFunction(new TwigFunction('section_photo', function (int $sectionId, string $alt = '', string $cssClass = 'w-100 rounded') use ($environment): string {
            /** @var \Core\Photo\SectionPhotoService|null $service */
            $service = $environment->getGlobals()['_section_photo_service'] ?? null;
            $scoutYearId = (int) ($environment->getGlobals()['effective_scout_year_id'] ?? 0);
            $configMode = $environment->getGlobals()['config_mode'] ?? false;

            $fileId = ($service !== null && $scoutYearId > 0) ? $service->resolveFileId($sectionId, $scoutYearId) : null;
            $imgStyle = 'aspect-ratio:4/3;object-fit:cover;';

            if ($configMode && $scoutYearId > 0) {
                $key = $sectionId . ':' . $scoutYearId;
                if ($fileId !== null) {
                    $img = '<img src="/files/' . $fileId . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" class="' . htmlspecialchars($cssClass, ENT_QUOTES) . '" style="' . $imgStyle . '">';
                } else {
                    $img = '<div class="d-flex align-items-center justify-content-center bg-light rounded ' . htmlspecialchars($cssClass, ENT_QUOTES) . '" style="' . $imgStyle . '">'
                        . '<span class="text-muted"><i class="bi bi-image"></i> Cliquer pour ajouter la photo du staff</span></div>';
                }
                return '<div class="editable-image" data-key="' . htmlspecialchars($key, ENT_QUOTES) . '" data-context="section_photo">'
                    . '<div class="editable-overlay"><button class="btn btn-sm btn-outline-primary editable-edit-btn"><i class="bi bi-camera"></i> Changer</button></div>'
                    . $img
                    . '</div>';
            }

            if ($fileId !== null) {
                return '<img src="/files/' . $fileId . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" class="' . htmlspecialchars($cssClass, ENT_QUOTES) . '" style="' . $imgStyle . '">';
            }

            return '';
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

        // Shared by full_name and display_name_full below — first name +
        // surname, normalized, never the totem.
        $buildFullName = function ($member): string {
            if ($member instanceof \Core\Member\MemberProfile) {
                return trim(
                    \Core\Service\TextNormalizerService::normalizeName($member->firstName)
                    . ' ' . \Core\Service\TextNormalizerService::normalizeName($member->lastName)
                );
            }
            if (is_array($member)) {
                $first = (string) ($member['first_name'] ?? '');
                $last = (string) ($member['last_name'] ?? '');
                return trim(
                    \Core\Service\TextNormalizerService::normalizeName($first)
                    . ' ' . \Core\Service\TextNormalizerService::normalizeName($last)
                );
            }
            return (string) $member;
        };

        // Register full_name filter — first name + surname, NEVER totem.
        // Used where the site must show a person's legal identity alone
        // (e.g. a postal address needs the name it's actually addressed
        // to) — do not use this for ordinary member display.
        $environment->addFilter(new TwigFilter('full_name', $buildFullName));

        // Register display_name_full filter — "Totem (Prénom Nom)" when a
        // totem is set, else just "Prénom Nom". Used wherever a totem
        // would otherwise be shown on its own (member page: badge/
        // référent holder lists, section responsable) so a reader who
        // doesn't know the totem can still identify the person.
        $environment->addFilter(new TwigFilter('display_name_full', function ($member) use ($buildFullName) {
            $full = $buildFullName($member);
            $totem = $member instanceof \Core\Member\MemberProfile
                ? $member->totem
                : (is_array($member) ? ($member['totem'] ?? null) : null);

            if ($totem) {
                return \Core\Service\TextNormalizerService::normalizeTotem($totem) . ' (' . $full . ')';
            }

            return $full;
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

    /**
     * Compiled-template cache directory, namespaced by the installed
     * version (`storage/temp/twig_cache/{version}`).
     *
     * Outside debug mode `auto_reload` is off, so Twig never re-checks a
     * compiled template against its `.twig` source — and Twig's cache key
     * derives from the template *name*, not its contents, so an updated
     * template maps to the exact same cache entry as the old one. With a
     * single flat directory that meant a deployment could change a
     * template on disk and the site would keep serving the previous
     * version's compiled class indefinitely, silently — a real production
     * incident (a nav/layout change that installed correctly and never
     * appeared).
     *
     * Scoping the directory by `Core\Maintenance\VersionFile` makes that
     * self-healing: every deployment path that matters writes VERSION
     * (`scripts/release.sh` commits it; `Task\InstallUpdateHandler` writes
     * it as its last step, for stable releases and `dev-{sha}` builds
     * alike), so the first request after an update simply looks in a
     * directory that doesn't exist yet and compiles fresh. Crucially this
     * needs no cooperation from whatever *installer* performed the
     * update — unlike clearing the cache from `InstallUpdateHandler`,
     * which only helps once the new installer is itself the one running.
     *
     * The one case this deliberately does not cover: editing a `.twig`
     * file in place without changing VERSION (a hand-edit over FTP). That
     * is what `debug` mode is for.
     */
    private static function cacheDirectory(string $basePath): string
    {
        // VERSION is developer-controlled, never user input, but it ends up
        // in a filesystem path — keep it to characters that cannot escape
        // the cache root regardless of what a future release writes there.
        $namespace = preg_replace('/[^A-Za-z0-9._-]/', '_', VersionFile::read($basePath)) ?? '';
        $namespace = trim($namespace, '.');

        return $basePath . '/storage/temp/twig_cache/' . ($namespace !== '' ? $namespace : 'unknown');
    }
}
