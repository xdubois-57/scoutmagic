<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\File\UploadException;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SafeRedirect;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Photo\PhotoIngestionService;
use Core\Security\AuthSession;
use Core\Security\Role;
use Core\View\ConfigurationMode;
use Twig\Environment;

class UploadController extends AbstractController
{
    private ?JournalService $journalService = null;

    /**
     * Two collaborators, where there used to be eleven. Everything that
     * happens to an uploaded image now lives in PhotoIngestionService;
     * MemberService is still needed here because the authorization boundary
     * below has to know whether the requesting account is the member whose
     * photo the key names.
     */
    public function __construct(
        protected Environment $twig,
        private PhotoIngestionService $photoIngestionService,
        private MemberService $memberService,
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
        if (($guard = $this->guardCsrf($request,
            SafeRedirect::internalPath((string) $request->getBody('return_url', '/')))) !== null) {
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
            return $this->redirect(
                '/upload?context='
                    . urlencode($context)
                    . '&key='
                    . urlencode($key)
                    . '&return='
                    . urlencode($returnUrl)
            );
        }

        try {
            // Everything that happens to the bytes — the MIME allowlist, the
            // size cap, the per-context crop, the storage, the derivative and
            // the linking — lives in Core\Photo\PhotoIngestionService. What is
            // left here is what genuinely belongs to a request: the CSRF token
            // and the authorization boundary above, and the journal entry,
            // flash message and redirect below.
            $result = $this->photoIngestionService->ingest(
                $uploadedFile,
                $context,
                $key,
                AuthSession::getUserAccountId(),
            );

            if ($result->linked) {
                $this->journalUpload($context, $result->journalContext);
            }

            if ($context === 'unit_logo') {
                // The iOS caveat rides the same one-time success flash rather
                // than a permanent on-page fixture — it only matters in the
                // moment right after a change. iOS never re-reads the
                // manifest/apple-touch-icon for an already-installed app (no
                // web API can refresh that icon — see ARCHITECTURE §8.23);
                // Android/Chrome re-reads the manifest on its own and needs no
                // admin action.
                FlashMessage::set(
                    'success',
                    'Logo mis à jour. Sur iOS, les personnes ayant déjà installé l\'application devront la '
                    . 'supprimer puis la réinstaller pour voir le nouveau logo — la mise à jour est automatique '
                    . 'sur Android (peut prendre un moment).'
                );

                return $this->redirect($returnUrl);
            }

            FlashMessage::set('success', 'Fichier téléchargé avec succès.');

            return $this->redirect($returnUrl);
        } catch (UploadException $e) {
            FlashMessage::set('error', $e->getMessage());
            return $this->redirect(
                '/upload?context='
                    . urlencode($context)
                    . '&key='
                    . urlencode($key)
                    . '&return='
                    . urlencode($returnUrl)
            );
        }
    }

    /**
     * One journal entry per context, with the payload the ingestion service
     * already parsed out of the key. Kept in the controller rather than in the
     * service: these are French, user-facing descriptions of a web action, and
     * a command-line caller has no business writing them.
     *
     * @param array<string, int> $context
     */
    private function journalUpload(string $uploadContext, array $context): void
    {
        $userId = AuthSession::getUserAccountId();

        [$type, $description] = match ($uploadContext) {
            'unit_logo' => ['unit_logo_updated', 'Logo de l\'unité modifié'],
            'member_photo' => ['member_photo_updated', 'Photo d\'un membre modifiée'],
            'account_photo' => ['account_photo_updated', 'Photo de compte modifiée'],
            'section_photo' => ['section_photo_updated', 'Photo de staff d\'une section modifiée'],
            'age_branch_logo' => ['age_branch_logo_updated', 'Logo de branche d\'âge modifié'],
            default => ['', ''],
        };

        if ($type === '') {
            return;
        }

        $this->journalService?->log('core', $type, 'info', $description, $context, $userId);
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
}
