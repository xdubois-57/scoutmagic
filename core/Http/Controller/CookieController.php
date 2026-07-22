<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Cookie\CookieConsentService;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\CsrfGuard;
use Core\Security\LastLoginMethodCookie;
use Twig\Environment;

class CookieController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private CookieConsentService $cookieConsentService
    ) {
    }

    /**
     * GET /cookies — render cookie preferences page.
     *
     * @param array<string, string> $params
     */
    public function preferences(Request $request, array $params): Response
    {
        $categories = $this->cookieConsentService->getAllDeclaredCookies();
        $consent = $this->cookieConsentService->getConsent();

        $cookieCategories = [];
        foreach ($categories as $categoryId => $category) {
            $accepted = $categoryId === 'necessary'
                ? true
                : ($consent[$categoryId] ?? false);

            $cookieCategories[$categoryId] = [
                'label' => $category['label'],
                'description' => $category['description'],
                'cookies' => $category['cookies'],
                'accepted' => $accepted,
            ];
        }

        return $this->render('cookies/preferences.html.twig', [
            'cookie_categories' => $cookieCategories,
        ]);
    }

    /**
     * POST /cookies/save — save cookie preferences from form.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        $csrf = (string) $request->getBody('_csrf_token', '');
        if (!CsrfGuard::validateToken($csrf)) {
            return (new Response('', 403))->setBody('Forbidden: invalid CSRF token.');
        }

        $choices = [
            'functional' => $request->getBody('functional') !== null,
            'analytics' => $request->getBody('analytics') !== null,
        ];

        $this->cookieConsentService->saveConsent($choices);
        if (!$choices['functional']) {
            LastLoginMethodCookie::forget();
        }
        FlashMessage::set('success', 'Vos préférences cookies ont été enregistrées.');

        return $this->redirect('/cookies');
    }

    /**
     * POST /cookies/accept-all — accept all categories (AJAX from banner).
     *
     * @param array<string, string> $params
     */
    public function acceptAll(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return $this->json(['success' => false, 'error' => 'Session expirée.'], 403);
        }

        $this->cookieConsentService->acceptAll();

        return $this->json(['success' => true]);
    }

    /**
     * POST /cookies/reject-all — reject all categories (AJAX from banner).
     *
     * @param array<string, string> $params
     */
    public function rejectAll(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return $this->json(['success' => false, 'error' => 'Session expirée.'], 403);
        }

        $this->cookieConsentService->rejectAll();
        LastLoginMethodCookie::forget();

        return $this->json(['success' => true]);
    }
}
