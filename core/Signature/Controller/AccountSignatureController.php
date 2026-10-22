<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Signature\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SafeRedirect;
use Core\Security\AuthSession;
use Core\Signature\PersonalSignatureService;
use Core\Signature\SignatureException;
use Core\Signature\SignatureImage;
use Core\Signature\SignaturePng;
use Core\Signature\SignatureSubmission;
use Twig\Environment;

/**
 * « Ma signature », under Mon compte (issue #926, lot B): the personal
 * signature an account keeps for signing documents — drawn or imported,
 * shown to its owner, replaced, deleted.
 *
 * Every route is `identified`: any account may keep one, whatever its role
 * and whether or not a member record stands behind it. **The account is
 * the session's, and nothing else.** No route reads an account id from the
 * request — a `user_account_id` posted alongside is ignored, and a test
 * proves it — so there is no address to change to reach another person's
 * signature (SECURITY.md §5).
 *
 * A page of its own rather than a card on `/account`: that page is cached
 * for offline reading (`Core\Offline\OfflineWhitelist`), and a signature's
 * bytes must never rest in a cache. This page is answered `no-store`, and
 * the image comes from a route of its own, `no-store` too.
 */
class AccountSignatureController extends AbstractController
{
    private const PAGE = '/account/signature';

    public function __construct(
        protected Environment $twig,
        private PersonalSignatureService $signatures
    ) {
    }

    /**
     * GET /account/signature
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $account = AuthSession::getUserAccountId();
        if ($account === null) {
            return $this->redirect('/login');
        }

        return $this->render('account/signature.html.twig', [
            'has_signature' => $this->signatures->has($account),
            'saved_at' => $this->signatures->savedAt($account),
            'return_to' => self::returnTo((string) $request->getQuery('retour', '')),
            'breadcrumb_current' => 'Ma signature',
        ])->setHeader('Cache-Control', 'private, no-store');
    }

    /**
     * POST /account/signature — a drawn signature (the pad's PNG) or an
     * imported image, re-encoded by SignatureImage and kept encrypted,
     * replacing the one the account had.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        $account = AuthSession::getUserAccountId();
        if ($account === null) {
            return $this->redirect('/login');
        }

        $back = self::returnTo((string) $request->getBody('retour', ''));
        $page = self::PAGE . ($back !== null ? '?retour=' . rawurlencode($back) : '');

        if (($guard = $this->guardCsrf($request, $page)) !== null) {
            return $guard;
        }

        try {
            $this->signatures->save($account, self::submitted($request), new \DateTimeImmutable());
        } catch (SignatureException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect($page);
        }

        FlashMessage::set('success', 'Votre signature est enregistrée. Vous seul pouvez la voir et l\'utiliser.');

        return $this->redirect($back ?? self::PAGE);
    }

    /**
     * POST /account/signature/delete — gone, whenever its owner asks;
     * documents already signed keep the copy they carry.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $account = AuthSession::getUserAccountId();
        if ($account === null) {
            return $this->redirect('/login');
        }

        if (($guard = $this->guardCsrf($request, self::PAGE)) !== null) {
            return $guard;
        }

        $this->signatures->delete($account);
        FlashMessage::set('success', 'Votre signature est supprimée.');

        return $this->redirect(self::PAGE);
    }

    /**
     * GET /account/signature/image — the signature itself, to its owner and
     * to nobody else: the account asking is the account read, and there is
     * no id in the address to change. A 404 for an account that keeps none,
     * the same answer whatever the reason.
     *
     * `private, no-store` so neither the browser's cache, the service
     * worker nor a proxy keeps it; `nosniff` so the bytes are only ever
     * read as the PNG they are.
     *
     * @param array<string, string> $params
     */
    public function image(Request $request, array $params): Response
    {
        $account = AuthSession::getUserAccountId();
        $png = $account !== null ? $this->signatures->ownImage($account) : null;
        if ($png === null) {
            return $this->notFound();
        }

        return (new Response($png->bytes()))
            ->setHeader('Content-Type', 'image/png')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * The drawing when there is one, else the imported file.
     *
     * The pad fills `signature_data` only once something was traced, so an
     * untouched pad never hides an import. The file must really be an
     * upload of this request (`is_uploaded_file()`): a path is never taken
     * from anywhere else.
     *
     * @throws SignatureException when neither is usable
     */
    private static function submitted(Request $request): SignaturePng
    {
        $drawn = trim((string) $request->getBody('signature_data', ''));
        if ($drawn !== '') {
            return SignatureImage::fromDataUrl($drawn);
        }

        $path = SignatureSubmission::uploadedPath($request);
        if ($path !== null) {
            return SignatureImage::fromUploadedFile($path);
        }

        throw new SignatureException('Tracez votre signature ou choisissez une image.');
    }

    /**
     * Where to come back to after saving: a path of this site, never an
     * address elsewhere (SECURITY.md §26) — and never this page itself,
     * which is where the visitor already is.
     */
    private static function returnTo(string $candidate): ?string
    {
        $path = SafeRedirect::internalPath($candidate, '');
        if ($path === '' || str_starts_with($path, self::PAGE)) {
            return null;
        }

        return $path;
    }
}
