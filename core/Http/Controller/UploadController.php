<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\File\UploadException;
use Core\File\UploadHandler;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SafeRedirect;
use Core\Import\AgeBranchRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Photo\AccountPhotoService;
use Core\Photo\ImageVariantService;
use Core\Photo\LandscapeImageProcessor;
use Core\Photo\MemberPhotoService;
use Core\Photo\SectionPhotoProcessor;
use Core\Photo\SectionPhotoService;
use Core\Photo\UnitLogoService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Core\View\ConfigurationMode;
use Core\View\EditableContentService;
use Twig\Environment;

class UploadController extends AbstractController
{
    private ?JournalService $journalService = null;

    public function __construct(
        protected Environment $twig,
        private UploadHandler $uploadHandler,
        private EditableContentService $editableContentService,
        private MemberPhotoService $memberPhotoService,
        private SectionPhotoService $sectionPhotoService,
        private SectionPhotoProcessor $sectionPhotoProcessor,
        private LandscapeImageProcessor $landscapeImageProcessor,
        private MemberService $memberService,
        private AgeBranchRepository $ageBranchRepository,
        private UnitLogoService $unitLogoService,
        private ImageVariantService $imageVariantService,
        // Trailing and optional so every existing construction of this
        // controller keeps working: without it the account_photo context
        // stores the file and simply never points an account at it,
        // which is the honest degradation for a collaborator that is not
        // wired.
        private ?AccountPhotoService $accountPhotoService = null
    ) {
    }

    public function setJournalService(JournalService $journalService): void
    {
        $this->journalService = $journalService;
    }

    /**
     * GET /upload — render the upload page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $context = (string) $request->getQuery('context', '');
        $key = (string) $request->getQuery('key', '');
        // Reflected into an href and a hidden field, and later into a redirect
        // — constrain it to a same-site path so it can't become an open
        // redirect (audit M17).
        $returnUrl = SafeRedirect::internalPath((string) $request->getQuery('return', '/'));

        return $this->render('upload/index.html.twig', [
            'context' => $context,
            'key' => $key,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * POST /upload — handle the upload.
     *
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, SafeRedirect::internalPath((string) $request->getBody('return_url', '/')))) !== null) {
            return $guard;
        }

        $context = (string) $request->getBody('context', '');
        $key = (string) $request->getBody('key', '');
        // Same-site path only — this drives the post-upload redirect (audit M17).
        $returnUrl = SafeRedirect::internalPath((string) $request->getBody('return_url', '/'));

        if (!$this->isUploadAuthorized($context, $key)) {
            return (new Response('', 403))->setBody('Forbidden.');
        }

        $uploadedFile = $request->getFile('file');
        if ($uploadedFile === null) {
            // Try camera input
            $uploadedFile = $request->getFile('file_camera');
        }

        if ($uploadedFile === null) {
            FlashMessage::set('error', 'Aucun fichier sélectionné.');
            return $this->redirect('/upload?context=' . urlencode($context) . '&key=' . urlencode($key) . '&return=' . urlencode($returnUrl));
        }

        try {
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            // 10 MB — raised from 5 MB now that the five core photo
            // contexts downscale to WebP/2400px client-side before POSTing
            // (public/assets/js/upload.js); this remains the server-side
            // floor for anything the client-side step skips (already-small
            // files) or can't run (JS disabled, non-browser client).
            $maxSize = 10 * 1024 * 1024;

            // unit_logo (Installation & serveur's "Paramètres généraux"
            // logo upload — feeds the favicon, the installed-app icons,
            // and the footer logo) is deliberately never handed to
            // UploadHandler::handle() at all: it never becomes a `files`
            // row / /files/{id} download, since every one of those is
            // fetched with no session — see Core\Photo\UnitLogoService's
            // own docblock. Returns here, short-circuiting the generic
            // files-table flow below entirely. Named 'pwa_icon' before the
            // logo feature widened this beyond the PWA manifest icons.
            if ($context === 'unit_logo') {
                $tmpName = (string) ($uploadedFile['tmp_name'] ?? '');
                if ($tmpName === '' || !is_file($tmpName)) {
                    throw new UploadException('Fichier invalide.');
                }
                $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmpName);
                if (!in_array($mimeType, $allowedMimes, true)) {
                    throw new UploadException('Type de fichier non accepté — formats attendus : JPEG, PNG, GIF, WebP.');
                }

                $this->unitLogoService->storeUploadedLogo((string) file_get_contents($tmpName), $mimeType);
                $this->journalService?->log('core', 'unit_logo_updated', 'info', 'Logo de l\'unité modifié', [], AuthSession::getUserAccountId());

                // The iOS caveat rides the same one-time success flash
                // rather than a permanent on-page fixture — it only
                // matters in the moment right after a change. iOS never
                // re-reads the manifest/apple-touch-icon for an
                // already-installed app (no web API can refresh that icon
                // — see ARCHITECTURE §8.23); Android/Chrome re-reads the
                // manifest on its own and needs no admin action.
                FlashMessage::set(
                    'success',
                    'Logo mis à jour. Sur iOS, les personnes ayant déjà installé l\'application devront la '
                    . 'supprimer puis la réinstaller pour voir le nouveau logo — la mise à jour est automatique '
                    . 'sur Android (peut prendre un moment).'
                );
                return $this->redirect($returnUrl);
            }

            // section_photo (the Staffs page's group photo of a section's
            // chiefs) is always cropped to a fixed 4:3 landscape rendition
            // server-side before it's ever stored — see
            // Core\Photo\SectionPhotoProcessor. Done here, before
            // UploadHandler::handle(), so the file it stores (and the size
            // recorded in the files table) is already the final, processed
            // one.
            if ($context === 'section_photo') {
                $uploadedFile = $this->processSectionPhoto($uploadedFile, $allowedMimes);
            }

            // editable_image is the generic, site-wide "editable picture"
            // mechanism (home page hero, and anywhere else it's reused) —
            // every upload through it is cropped to a fixed landscape
            // ratio matching the public news article page's featured-image
            // treatment (Core\Photo\LandscapeImageProcessor), same
            // before-UploadHandler::handle() pattern as section_photo above.
            if ($context === 'editable_image') {
                $uploadedFile = $this->processLandscapeImage($uploadedFile, $allowedMimes);
            }

            // member_photo is scoped to a member (not public site content)
            // — see Core\Photo\MemberPhotoService. section_photo is
            // 'public': it's rendered on the public Contact and Sections
            // pages (ARCHITECTURE §8.21) — a stricter floor here would
            // make FileAccessGuard deny it to any non-identified visitor,
            // which is exactly what those two pages already assume never
            // happens.
            $subDirectory = match ($context) {
                'member_photo' => 'core/member_photos',
                'account_photo' => 'core/account_photos',
                'section_photo' => 'core/section_photos',
                'age_branch_logo' => 'core/branch_logos',
                default => 'core/editable_contents',
            };
            // account_photo is somebody's own face, shown to identified
            // visitors and to nobody else — same floor as member_photo,
            // and for the same reason.
            $roleMin = match ($context) {
                'member_photo', 'account_photo' => 'identified',
                default => 'public',
            };

            $fileId = $this->uploadHandler->handle(
                $uploadedFile,
                $subDirectory,
                $allowedMimes,
                $maxSize,
                $roleMin,
                null,
                AuthSession::getUserAccountId()
            );

            // Derivative pipeline (Core\Photo\ImageVariantService) — exactly
            // one variant per core photo context, generated once here and
            // never regenerated on demand. Contexts outside this map
            // (unit_logo never reaches this point at all — see the
            // short-circuit above) get no derivative.
            $variant = match ($context) {
                'member_photo', 'account_photo' => 'thumb',
                'section_photo', 'editable_image', 'age_branch_logo' => 'md',
                default => null,
            };
            if ($variant !== null) {
                $this->imageVariantService->generate($fileId, $variant);
            }

            // For editable_image context, update the editable content record
            if ($context === 'editable_image' && $key !== '') {
                $userId = AuthSession::getUserAccountId();
                if ($userId !== null) {
                    $this->editableContentService->set($key, (string) $fileId, 'image', $userId);
                }
            }

            // For member_photo context, key is "{memberId}:{scoutYearId}"
            if ($context === 'member_photo' && $key !== '') {
                [$memberIdStr, $yearIdStr] = array_pad(explode(':', $key, 2), 2, '');
                $memberId = (int) $memberIdStr;
                $scoutYearId = (int) $yearIdStr;
                $userId = AuthSession::getUserAccountId();
                if ($memberId > 0 && $scoutYearId > 0 && $userId !== null) {
                    $this->memberPhotoService->setPhoto($memberId, $scoutYearId, $fileId, $userId);
                    $this->journalService?->log(
                        'core',
                        'member_photo_updated',
                        'info',
                        'Photo d\'un membre modifiée',
                        ['member_id' => $memberId, 'scout_year_id' => $scoutYearId],
                        $userId
                    );
                }
            }

            // For account_photo context, key is the account id — and it
            // has already been required to be the caller's OWN
            // (isUploadAuthorized() below): nobody sets somebody else's
            // face, configuration mode included.
            if ($context === 'account_photo' && $key !== '') {
                $userId = AuthSession::getUserAccountId();
                if ($userId !== null && (int) $key === $userId) {
                    $this->accountPhotoService?->setPhoto($userId, $fileId);
                    $this->journalService?->log(
                        'core',
                        'account_photo_updated',
                        'info',
                        'Photo de compte modifiée',
                        [],
                        $userId
                    );
                }
            }

            // For section_photo context, key is "{sectionId}:{scoutYearId}"
            if ($context === 'section_photo' && $key !== '') {
                [$sectionIdStr, $yearIdStr] = array_pad(explode(':', $key, 2), 2, '');
                $sectionId = (int) $sectionIdStr;
                $scoutYearId = (int) $yearIdStr;
                $userId = AuthSession::getUserAccountId();
                if ($sectionId > 0 && $scoutYearId > 0 && $userId !== null) {
                    $this->sectionPhotoService->setPhoto($sectionId, $scoutYearId, $fileId, $userId);
                    $this->journalService?->log(
                        'core',
                        'section_photo_updated',
                        'info',
                        'Photo de staff d\'une section modifiée',
                        ['section_id' => $sectionId, 'scout_year_id' => $scoutYearId],
                        $userId
                    );
                }
            }

            // For age_branch_logo context, key is the age_branch id — the
            // member page branch card's federation logo, configured from
            // Configuration > Config Desk (superadmin). See
            // Core\Import\AgeBranchRepository::setLogo().
            if ($context === 'age_branch_logo' && $key !== '') {
                $ageBranchId = (int) $key;
                $userId = AuthSession::getUserAccountId();
                if ($ageBranchId > 0) {
                    $this->ageBranchRepository->setLogo($ageBranchId, $fileId);
                    $this->journalService?->log(
                        'core',
                        'age_branch_logo_updated',
                        'info',
                        'Logo de branche d\'âge modifié',
                        ['age_branch_id' => $ageBranchId],
                        $userId
                    );
                }
            }

            FlashMessage::set('success', 'Fichier téléchargé avec succès.');

            return $this->redirect($returnUrl);
        } catch (UploadException $e) {
            FlashMessage::set('error', $e->getMessage());
            return $this->redirect('/upload?context=' . urlencode($context) . '&key=' . urlencode($key) . '&return=' . urlencode($returnUrl));
        }
    }

    /**
     * The real authorization boundary for /upload — the route's own
     * role_min (`identified`) only gets a logged-in user in the door; a UI
     * flag (member_photo()'s $editable param) is never sufficient on its
     * own. member_photo uploads are allowed either through configuration
     * mode OR when the requesting account is linked to the member the
     * upload key names — this is what lets a member replace their own
     * photo from the member page outside configuration mode.
     * age_branch_logo/unit_logo are direct role checks instead — both
     * pages are their own superadmin-only admin area, not the front-end
     * configuration-mode overlay, so there's no session flag to require.
     * Every other context (editable_image, section_photo) keeps the
     * pre-existing configuration-mode-only behavior.
     *
     * Configuration mode itself widened from superadmin-only to admin
     * (chief d'unité) — see Core\View\ConfigurationMode's own docblock —
     * and every context gated purely on ConfigurationMode::isActive()
     * (member_photo's config-mode branch included) widens the same way as
     * a deliberate, analyzed consequence: this method's whole point is
     * "can this session use the configuration-mode content-editing
     * overlay", and that capability itself is what moved to admin — there
     * is no reason for member_photo/editable_image/section_photo
     * specifically to stay carved out at superadmin while the mode that
     * gates them moves. age_branch_logo/unit_logo above are unaffected —
     * they never read ConfigurationMode at all.
     */
    private function isUploadAuthorized(string $context, string $key): bool
    {
        // Your own face and nobody else's. Deliberately NOT widened by
        // configuration mode, unlike every other context here: an
        // administrator editing the site's content has no business
        // setting another person's account photo, and there is no page
        // that offers it either.
        if ($context === 'account_photo') {
            $userId = AuthSession::getUserAccountId();

            return $userId !== null && (int) $key === $userId;
        }

        if ($context === 'member_photo') {
            if (ConfigurationMode::isActive()) {
                return true;
            }

            [$memberIdStr, $yearIdStr] = array_pad(explode(':', $key, 2), 2, '');
            $memberId = (int) $memberIdStr;
            $scoutYearId = (int) $yearIdStr;
            $email = AuthSession::getEmail();
            if ($memberId <= 0 || $scoutYearId <= 0 || $email === null) {
                return false;
            }

            return $this->memberService->isLinkedToMember($email, $memberId, $scoutYearId);
        }

        if ($context === 'age_branch_logo') {
            return Role::fromString(AuthSession::getRole())->hasAccess(Role::SUPERADMIN);
        }

        // unit_logo (Installation & serveur > Paramètres généraux's logo upload) —
        // same direct role check as age_branch_logo above: its own admin
        // page is already superadmin-gated, not the front-end
        // configuration-mode overlay, so there's no session flag to
        // require here either.
        if ($context === 'unit_logo') {
            return Role::fromString(AuthSession::getRole())->hasAccess(Role::SUPERADMIN);
        }

        return ConfigurationMode::isActive();
    }

    /**
     * Crops/resizes the raw upload via Core\Photo\SectionPhotoProcessor
     * and points the returned array at a fresh temp file holding the
     * processed bytes — UploadHandler::handle() then stores exactly that
     * (already-final) file, so its own EXIF-stripping re-encode is the
     * only processing that happens after this point. Mime-type validation
     * itself is left entirely to UploadHandler::handle()'s own check right
     * after this call — a non-image (or disallowed) file is simply passed
     * through unprocessed and rejected there with its normal error
     * message, rather than duplicating that validation here.
     *
     * @param array<string, mixed> $uploadedFile $_FILES entry
     * @param array<string> $allowedMimes
     * @return array<string, mixed>
     * @throws UploadException when the source can't be decoded/processed
     */
    private function processSectionPhoto(array $uploadedFile, array $allowedMimes): array
    {
        $tmpName = (string) ($uploadedFile['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName)) {
            return $uploadedFile;
        }

        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmpName);
        if (!in_array($mimeType, $allowedMimes, true)) {
            return $uploadedFile;
        }

        $processed = $this->sectionPhotoProcessor->process((string) file_get_contents($tmpName), $mimeType);

        $processedPath = tempnam(sys_get_temp_dir(), 'section_photo_');
        if ($processedPath === false) {
            throw new UploadException('Impossible de traiter cette image.');
        }
        file_put_contents($processedPath, $processed);

        $uploadedFile['tmp_name'] = $processedPath;
        $uploadedFile['size'] = strlen($processed);
        $uploadedFile['type'] = 'image/jpeg';

        return $uploadedFile;
    }

    /**
     * Same before-UploadHandler::handle() pattern as processSectionPhoto()
     * above, via Core\Photo\LandscapeImageProcessor.
     *
     * @param array<string, mixed> $uploadedFile $_FILES entry
     * @param array<string> $allowedMimes
     * @return array<string, mixed>
     * @throws UploadException when the source can't be decoded/processed
     */
    private function processLandscapeImage(array $uploadedFile, array $allowedMimes): array
    {
        $tmpName = (string) ($uploadedFile['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName)) {
            return $uploadedFile;
        }

        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmpName);
        if (!in_array($mimeType, $allowedMimes, true)) {
            return $uploadedFile;
        }

        $processed = $this->landscapeImageProcessor->process((string) file_get_contents($tmpName), $mimeType);

        $processedPath = tempnam(sys_get_temp_dir(), 'editable_image_');
        if ($processedPath === false) {
            throw new UploadException('Impossible de traiter cette image.');
        }
        file_put_contents($processedPath, $processed);

        $uploadedFile['tmp_name'] = $processedPath;
        $uploadedFile['size'] = strlen($processed);
        $uploadedFile['type'] = 'image/jpeg';

        return $uploadedFile;
    }
}
