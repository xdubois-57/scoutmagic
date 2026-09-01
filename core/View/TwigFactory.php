<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Http\FlashMessage;
use Core\Maintenance\VersionFile;
use Core\Security\CsrfGuard;
use Core\Service\DateInput;
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
            // ALWAYS on, deliberately — not `$debug`, which is what it was.
            //
            // Without it Twig never re-reads a template it has already
            // compiled: it loads the cached class and does not so much as
            // stat the source. The compiled files are namespaced by
            // VERSION (see cacheDirectory()), so a numbered release does
            // get a fresh directory — but VersionFile::read() falls back
            // to the constant 'dev' when there is no VERSION file, which
            // is exactly what a site deployed from a checkout has. On
            // such an install the namespace NEVER changes, so every
            // deploy after the first served the previous deploy's
            // templates: new controllers passing new variables into old
            // compiled markup, for as long as storage/temp survived.
            // That is a deployment defect, not a caching trade-off.
            //
            // The cost is one filemtime() per template actually used by
            // the request — a handful — against a page that already opens
            // a database connection and runs the schema-hash check. The
            // version namespace stays: it still isolates releases and
            // still lets the updater wipe one cleanly
            // (Task\InstallUpdateHandler::clearCompiledTemplateCache()).
            'auto_reload' => true,
            // HTML everywhere, EXCEPT the plain-text half of an email.
            //
            // Escaping is right for every page and for the HTML body of a
            // message; in a text/plain part it is simply wrong, and
            // silently so — nothing renders it back. A renter called
            // O'Brien read « Bonjour O&#039;Brien », and « c&#039;est
            // votre seul accès » sat in the acknowledgement of every
            // rental request. The templates cannot fix it one variable at
            // a time either: |raw on each of them is the same decision
            // made repeatedly, and forgotten once.
            'autoescape' => static fn (string $name): string|false
                => str_ends_with($name, '.text.twig') ? false : 'html',
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

        // Register file_url() function. $variant, when given, must be one
        // of Core\Photo\ImageVariantService's fixed vocabulary ('thumb',
        // 'md') — it's a plain string here (Twig call sites, not user
        // input) rather than re-validated against the service, since the
        // actual security boundary is FileController::variant()'s own
        // check on the URL segment, not this URL-building helper.
        // asset() — a static asset's URL with the release version as a
        // cache-busting query (?v=…), the same mechanism base.html.twig
        // already used for /sw.js. This is what lets the web server give
        // /assets/** a far-future lifetime: the reference changes when a
        // release changes the file, so a year-long cache can never serve
        // a stale script against a new page. Every template reference to
        // /assets/ must go through it — pinned by
        // Tests\Core\View\AssetVersioningTest.
        // `asset_version` rather than `app_version`: the release version
        // does not change between two deploys of the same release, and
        // `main` sits at one release across dozens of merges — so this
        // query string stopped busting anything at all. See
        // Core\Maintenance\AssetVersion. The fallback chain keeps a
        // test-built environment (which sets neither global) working.
        $environment->addFunction(new TwigFunction('asset', function (string $path) use ($environment): string {
            $globals = $environment->getGlobals();
            $version = (string) ($globals['asset_version'] ?? $globals['app_version'] ?? 'dev');

            return $path . '?v=' . rawurlencode($version);
        }));

        $environment->addFunction(new TwigFunction('file_url', function (int|string|null $id, ?string $variant = null): string {
            if ($id === null || $id === '' || $id === 0) {
                return '';
            }
            $suffix = $variant !== null ? '/' . $variant : '';
            return '/files/' . (int) $id . $suffix;
        }));

        // Register editable() function — renders editable content.
        //
        // A rich-text block always comes out inside a `.rich-text`
        // element, in configuration mode and outside it alike. That
        // class is what bounds an image nobody sized (app.css § Rich
        // text): the stored HTML is printed with `|raw`, so a 4000px
        // photo pasted into an editable block would otherwise push the
        // page sideways exactly as it did on a news article. Wrapping
        // here rather than at the ten call sites is deliberate — a
        // future template calling editable() gets the rule without
        // having to know it exists. An empty block still renders
        // nothing at all: several call sites (a section's text, the
        // federation blurb) are empty on most installs, and an empty
        // <div> would be a box in a layout for no reason.
        $environment->addFunction(new TwigFunction('editable', function (string $key, string $default = '', string $type = 'rich_text') use ($environment): string {
            /** @var EditableContentService|null $service */
            $service = $environment->getGlobals()['_editable_content_service'] ?? null;
            $configMode = $environment->getGlobals()['config_mode'] ?? false;

            $value = $service !== null ? $service->get($key, $default) : $default;
            $value = $value ?? $default;

            $richTextClass = $type === 'rich_text' ? ' rich-text' : '';

            if ($configMode) {
                return '<div class="editable-content' . $richTextClass . '"'
                    . ' data-key="' . htmlspecialchars($key, ENT_QUOTES) . '"'
                    . ' data-type="' . htmlspecialchars($type, ENT_QUOTES) . '">'
                    . '<div class="editable-overlay"><button class="btn btn-sm btn-outline-primary editable-edit-btn"><i class="bi bi-pencil"></i> Modifier</button></div>'
                    . $value
                    . '</div>';
            }

            $value = (string) $value;
            if ($richTextClass === '' || trim($value) === '') {
                return $value;
            }

            return '<div class="rich-text">' . $value . '</div>';
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
                    ? '<img src="/files/' . (int) $fileId . '/md" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" class="' . htmlspecialchars($cssClass, ENT_QUOTES) . '">'
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
                // Deliberately NOT loading="lazy": this renders the home
                // page's hero — usually the largest paint on the page —
                // and lazy-loading the LCP image delays it for no saving
                // (there is one editable image per page, not dozens).
                return '<img src="/files/' . (int) $fileId . '/md" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" class="' . htmlspecialchars($cssClass, ENT_QUOTES) . '" decoding="async">';
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
                // loading="lazy": the trombinoscope and rosters render one
                // of these per member, each /files/… request a full
                // application boot — only the visible ones should fire.
                $img = '<img src="/files/' . $fileId . '/thumb" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" class="' . htmlspecialchars($cssClass, ENT_QUOTES) . '" loading="lazy" decoding="async">';
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

        // Register person_avatar() function — THE way this site draws a
        // person: a circle holding their photo if one is known, their
        // initials if not (Core\View\PersonAvatar, which owns the
        // markup). One component behind the member entries in the mobile
        // menu, the connected person in the header and in that menu, and
        // the author of a message in a discussion group.
        //
        // Which photo depends on WHICH IDENTITY the avatar stands for,
        // and the two are never mixed: `member_id` resolves the member's
        // photo for the effective scout year (Core\Photo\
        // MemberPhotoService, editable on the member page), `account_id`
        // resolves the login's own (Core\Photo\AccountPhotoService,
        // editable on "Mon compte"). Passing a member id and falling
        // back to the account's photo would put a parent's face on their
        // child's menu entry, so it does not.
        //
        // `editable: true` only OFFERS the click-to-replace overlay —
        // Controller\UploadController re-authorises every upload on its
        // own, and a flag in a template is never sufficient.
        $environment->addFunction(new TwigFunction('person_avatar', function (string $name, array $options = []) use ($environment): string {
            $memberId = (int) ($options['member_id'] ?? 0);
            $accountId = (int) ($options['account_id'] ?? 0);
            $size = (int) ($options['size'] ?? 40);
            $editable = (bool) ($options['editable'] ?? false);
            $extraClass = (string) ($options['class'] ?? '');

            $globals = $environment->getGlobals();
            $scoutYearId = (int) ($globals['effective_scout_year_id'] ?? 0);

            $fileId = null;
            $editableTarget = null;

            if ($memberId > 0) {
                /** @var \Core\Photo\MemberPhotoService|null $memberPhotos */
                $memberPhotos = $globals['_member_photo_service'] ?? null;
                $fileId = ($memberPhotos !== null && $scoutYearId > 0)
                    ? $memberPhotos->resolveFileId($memberId, $scoutYearId)
                    : null;
                if ($editable && $scoutYearId > 0) {
                    $editableTarget = ['context' => 'member_photo', 'key' => $memberId . ':' . $scoutYearId];
                }
            } elseif ($accountId > 0) {
                /** @var \Core\Photo\AccountPhotoService|null $accountPhotos */
                $accountPhotos = $globals['_account_photo_service'] ?? null;
                $fileId = $accountPhotos?->resolveFileId($accountId);
                if ($editable) {
                    $editableTarget = ['context' => 'account_photo', 'key' => (string) $accountId];
                }
            }

            return \Core\View\PersonAvatar::render($name, $fileId, $size, $editableTarget, $extraClass);
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
                    $img = '<img src="/files/' . $fileId . '/md" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" class="' . htmlspecialchars($cssClass, ENT_QUOTES) . '" style="' . $imgStyle . '">';
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
                // loading="lazy" is safe against layout shift here: the
                // aspect-ratio style reserves the box before the bytes land.
                return '<img src="/files/' . $fileId . '/md" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" class="' . htmlspecialchars($cssClass, ENT_QUOTES) . '" style="' . $imgStyle . '" loading="lazy" decoding="async">';
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

        // Every date filter below reads its argument through this, and
        // that is a deliberate choice about what a DISPLAY filter should
        // do with a value it cannot read.
        //
        // `new DateTimeImmutable($v)` throws on a malformed string — so
        // one unreadable timestamp anywhere on a page used to take the
        // WHOLE page down with a 500, not just blank out the field. It
        // also answers *now* for an empty string, which is how a missing
        // value renders as today's date and is believed. Both are
        // Core\Service\DateInput::fromStorage()'s business (SECURITY.md
        // § 35); here the answer to "not a date" is the same as the one
        // these filters already give for null: nothing at all.
        $readDate = static function ($date): ?\DateTimeInterface {
            if ($date instanceof \DateTimeInterface) {
                return $date;
            }

            return DateInput::fromStorage(is_scalar($date) ? (string) $date : null);
        };

        // Register french_date filter — formats a Y-m-d(-His) string or
        // DateTimeInterface as "12 juillet 2026", no intl extension
        // required (ARCHITECTURE.md: no dependency not explicitly
        // justified — a 12-entry month name lookup isn't worth one).
        $environment->addFilter(new TwigFilter('french_date', function ($date) use ($readDate) {
            static $months = [
                1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
                7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
            ];

            if ($date === null || $date === '') {
                return '';
            }

            $dateTime = $readDate($date);
            if ($dateTime === null) {
                return '';
            }

            return (int) $dateTime->format('j') . ' ' . $months[(int) $dateTime->format('n')] . ' ' . $dateTime->format('Y');
        }));

        // "il y a 2 heures" — a coarse, French relative age for a stored
        // timestamp. Deliberately coarse: a feed only needs to answer
        // "recently or a while ago", and a to-the-second rendering would
        // be a value that is wrong the moment the page is cached. Falls
        // back to the absolute date past a week, where "il y a 23 jours"
        // stops being easier to read than the date itself.
        $environment->addFilter(new TwigFilter('relative_date', function ($date) use ($environment, $readDate) {
            if ($date === null || $date === '') {
                return '';
            }

            // A naive stored timestamp is on the application clock
            // (Core\Config\AppClock) — both the PHP writers and the
            // database's own CURRENT_TIMESTAMP produce it there — so it is
            // parsed under PHP's default timezone and compared against a
            // "now" read the same way. Forcing UTC on either end (which
            // this used to do, back when the whole app ran on UTC) now
            // shifts every age by the offset, and would have every
            // just-posted message read "il y a 2 heures".
            $then = $readDate($date);
            if ($then === null) {
                return '';
            }
            $seconds = (new \DateTimeImmutable('now'))->getTimestamp() - $then->getTimestamp();

            // A clock skew (or a timestamp a second into the future) reads
            // as "just now" rather than a negative age.
            if ($seconds < 60) {
                return "à l'instant";
            }
            if ($seconds < 3600) {
                $minutes = intdiv($seconds, 60);
                return 'il y a ' . $minutes . ' minute' . ($minutes > 1 ? 's' : '');
            }
            if ($seconds < 86400) {
                $hours = intdiv($seconds, 3600);
                return 'il y a ' . $hours . ' heure' . ($hours > 1 ? 's' : '');
            }
            if ($seconds < 604800) {
                $days = intdiv($seconds, 86400);
                return 'il y a ' . $days . ' jour' . ($days > 1 ? 's' : '');
            }

            return 'le ' . $environment->getFilter('french_date')->getCallable()($then);
        }));

        // Short French date/time formats. Two filters instead of 30-odd
        // hand-written |date('d/m/Y…') calls that had drifted into five
        // variants ('d/m/Y', 'd/m/Y H:i', 'd/m/Y à H:i', 'd/m/Y à H\hi'):
        // one canonical rendering each, so two adjacent pages stop
        // disagreeing about what a timestamp looks like. french_date
        // above stays the long form ("12 juillet 2026") for prose.
        $environment->addFilter(new TwigFilter('date_fr', function ($date) use ($readDate) {
            $dateTime = $readDate($date);

            return $dateTime !== null ? $dateTime->format('d/m/Y') : '';
        }));
        $environment->addFilter(new TwigFilter('datetime_fr', function ($date) use ($readDate) {
            $dateTime = $readDate($date);

            return $dateTime !== null ? $dateTime->format('d/m/Y à H:i') : '';
        }));

        // Belgian-French money rendering — "1 234,56 €". One filter
        // instead of ~75 hand-written number_format(2, ',', ' ') ~ ' €'
        // chains; |money_cents is the same thing for integer cents (the
        // rental module stores cents and used to divide inline at every
        // call site). Null renders empty — an absent amount is not 0,00 €.
        $environment->addFilter(new TwigFilter('money', function ($amount) {
            if ($amount === null || $amount === '') {
                return '';
            }

            return number_format((float) $amount, 2, ',', ' ') . ' €';
        }));
        $environment->addFilter(new TwigFilter('money_cents', function ($cents) {
            if ($cents === null || $cents === '') {
                return '';
            }

            return number_format(((int) $cents) / 100, 2, ',', ' ') . ' €';
        }));

        // Register markdown filter — renders release/commit notes (see
        // Core\View\MarkdownRenderer) as safe HTML instead of raw Markdown
        // syntax.
        $environment->addFilter(new TwigFilter('markdown', function (?string $text): string {
            return MarkdownRenderer::toHtml((string) $text);
        }, ['is_safe' => ['html']]));

        // Free text a member typed, with its URLs as links and its
        // newlines as <br> (Core\View\TextLinker). Escapes first and
        // builds the anchors around the escaped text, so it replaces
        // `|nl2br` on user content rather than being combined with it —
        // `{{ body|autolink }}`, never `{{ body|autolink|nl2br }}`.
        $environment->addFilter(new TwigFilter('autolink', function (?string $text): string {
            return TextLinker::toHtml($text);
        }, ['is_safe' => ['html']]));

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
