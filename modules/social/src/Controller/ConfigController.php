<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\SessionStore;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Meta\MetaClient;
use Modules\Social\Meta\MetaException;
use Modules\Social\Repository\ConnectionRepository;
use Modules\Social\Service\CheckOutcome;
use Modules\Social\Service\ConnectionService;
use Twig\Environment;

/**
 * « Configuration › Réseaux sociaux »: the unit's own Meta app, and the
 * Page and account it reaches.
 *
 * Modelled on {@see \Core\Http\Controller\GoogleDriveConnectionController},
 * for the same reasons: a fixed redirect address per platform with no
 * identifier in it, a random single-use `state` held in the session, a
 * callback behind the same `superadmin` floor as the page that starts the
 * flow, and a site address taken from the settings, never from the
 * request's Host header.
 *
 * **Two cards, two flows.** Facebook and Instagram are two logins at Meta
 * (see {@see MetaClient}), so each card carries its own « Tester la
 * connexion » and « Reconnecter »: one platform can be broken while the
 * other works, and a single pair of buttons could not say which.
 *
 * **What changes a connection is {@see ConnectionService}'s**, shared with
 * the nightly task; this class keeps what belongs to a request — the CSRF
 * guard, the `state` and the offered Pages in the session, the flash.
 */
final class ConfigController extends AbstractController
{
    public const PAGE_URL = '/config/reseaux-sociaux';

    private const STATE_SESSION_KEY = 'social_oauth_state_';

    /** The Pages offered after a consent that granted several — ids and names, never their tokens. */
    private const PAGES_SESSION_KEY = 'social_facebook_pages';

    public function __construct(
        Environment $twig,
        private readonly ConnectionRepository $connections,
        private readonly ConnectionService $service,
        private readonly SettingService $settings,
        private readonly MetaClient $meta = new MetaClient()
    ) {
        parent::__construct($twig);
    }

    /**
     * GET — the two cards.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $pages = SessionStore::get(self::PAGES_SESSION_KEY);
        $facebook = $this->connections->find(SocialPlatform::Facebook);

        return $this->render('@social/config/index.html.twig', [
            'facebook' => $facebook,
            'instagram' => $this->connections->find(SocialPlatform::Instagram),
            'facebook_redirect_uri' => self::redirectUriFor($this->baseUrl(), SocialPlatform::Facebook),
            'instagram_redirect_uri' => self::redirectUriFor($this->baseUrl(), SocialPlatform::Instagram),
            // Offered only while the held user token that can honour the
            // choice is still there.
            'page_choices' => $facebook?->awaitsPageChoice === true && is_array($pages) ? $pages : [],
            'now' => new \DateTimeImmutable(),
        ]);
    }

    /**
     * POST — the Meta app's id and secret for one platform.
     *
     * @param array<string, string> $params
     */
    public function saveCredentials(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PAGE_URL)) !== null) {
            return $guard;
        }
        $platform = self::platform($params);
        if ($platform === null) {
            return new Response('Not Found', 404);
        }

        $appId = trim((string) $request->getBody('app_id', ''));
        $appSecret = trim((string) $request->getBody('app_secret', ''));
        $current = $this->connections->find($platform);

        // Meta's app ids are numbers; anything else is a paste of the
        // wrong field, and saying so now beats Meta's error page later.
        if (preg_match('/^\d{5,25}$/', $appId) !== 1) {
            return $this->back(
                'error',
                'L\'identifiant de l\'application est une suite de chiffres. Copiez-le depuis le tableau de bord '
                    . 'de votre application Meta.'
            );
        }
        if ($appSecret === '' && ($current === null || !$current->hasAppSecret || $current->appId !== $appId)) {
            FlashMessage::set('error', 'Renseignez la clé secrète de l\'application.');

            return $this->redirect(self::PAGE_URL);
        }

        $this->service->saveCredentials($platform, $appId, $appSecret === '' ? null : $appSecret, self::userId());
        FlashMessage::set('success', 'Identifiants enregistrés. Vous pouvez maintenant connecter le compte.');

        return $this->redirect(self::PAGE_URL);
    }

    /**
     * GET — leaves for Meta's consent screen. « Reconnecter » is this
     * same route.
     *
     * @param array<string, string> $params
     */
    public function connect(Request $request, array $params): Response
    {
        $platform = self::platform($params);
        if ($platform === null) {
            return new Response('Not Found', 404);
        }

        $connection = $this->connections->find($platform);
        if ($connection === null || $connection->appId === '' || !$connection->hasAppSecret) {
            FlashMessage::set('error', 'Enregistrez d\'abord l\'identifiant et la clé secrète de l\'application.');

            return $this->redirect(self::PAGE_URL);
        }

        $redirectUri = self::redirectUriFor($this->baseUrl(), $platform);
        if ($redirectUri === '') {
            FlashMessage::set(
                'error',
                'Ce site ne connaît pas encore sa propre adresse. Renseignez-la dans Configuration > Réglages '
                . '(« Adresse du site ») : c\'est elle qui compose l\'adresse de redirection à déclarer chez Meta.'
            );

            return $this->redirect(self::PAGE_URL);
        }

        // Through SessionStore, never $_SESSION: the session is closed
        // early (ARCHITECTURE.md §8.20), and a lost state would refuse
        // every return for a reason nobody could see.
        $state = bin2hex(random_bytes(16));
        SessionStore::set(self::STATE_SESSION_KEY . $platform->value, $state);
        $appId = $connection->appId;

        return $this->redirect(match ($platform) {
            SocialPlatform::Facebook => $this->meta->facebookAuthorizationUrl($appId, $redirectUri, $state),
            SocialPlatform::Instagram => $this->meta->instagramAuthorizationUrl($appId, $redirectUri, $state),
        });
    }

    /**
     * GET — where Meta sends the browser back.
     *
     * @param array<string, string> $params
     */
    public function callback(Request $request, array $params): Response
    {
        $platform = self::platform($params);
        if ($platform === null) {
            return new Response('Not Found', 404);
        }

        $stored = SessionStore::get(self::STATE_SESSION_KEY . $platform->value);
        // Single-use: gone before anything is decided.
        SessionStore::remove(self::STATE_SESSION_KEY . $platform->value);
        $expected = is_string($stored) ? $stored : '';
        if ($expected === '' || !hash_equals($expected, (string) $request->getQuery('state', ''))) {
            return $this->back('error', 'Le retour de Meta n\'a pas pu être vérifié. Recommencez la connexion.');
        }

        // « Annuler » on Meta's screen: not an incident.
        if ((string) $request->getQuery('error', '') !== '') {
            return $this->back('error', 'La connexion a été annulée : Meta n\'a pas accordé l\'autorisation.');
        }

        $code = (string) $request->getQuery('code', '');
        if ($code === '') {
            return $this->back('error', 'Meta n\'a renvoyé aucun code d\'autorisation. Recommencez la connexion.');
        }

        try {
            $offered = $this->service->receiveCode(
                $platform,
                self::redirectUriFor($this->baseUrl(), $platform),
                $code,
                new \DateTimeImmutable(),
                self::userId()
            );
        } catch (MetaException $e) {
            return $this->back('error', $e->getMessage());
        }

        if ($offered !== []) {
            SessionStore::set(self::PAGES_SESSION_KEY, $offered);

            return $this->back('warning', 'Ce compte gère plusieurs Pages : choisissez celle de l\'unité.');
        }

        SessionStore::remove(self::PAGES_SESSION_KEY);

        return $this->back('success', self::connectedMessage($platform));
    }

    /**
     * POST — the Page to publish to, when the consent granted several.
     *
     * @param array<string, string> $params
     */
    public function choosePage(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PAGE_URL)) !== null) {
            return $guard;
        }

        $offered = SessionStore::get(self::PAGES_SESSION_KEY);
        $offeredIds = is_array($offered) ? array_map('strval', array_column($offered, 'id')) : [];

        try {
            $this->service->choosePage(
                (string) $request->getBody('page_id', ''),
                $offeredIds,
                new \DateTimeImmutable(),
                self::userId()
            );
        } catch (MetaException $e) {
            return $this->back('error', $e->getMessage());
        }

        SessionStore::remove(self::PAGES_SESSION_KEY);

        return $this->back('success', self::connectedMessage(SocialPlatform::Facebook));
    }

    /**
     * POST — « Tester la connexion »: one real call with the stored token.
     *
     * @param array<string, string> $params
     */
    public function test(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PAGE_URL)) !== null) {
            return $guard;
        }
        $platform = self::platform($params);
        if ($platform === null) {
            return new Response('Not Found', 404);
        }

        [$outcome, $error] = $this->service->check($platform, new \DateTimeImmutable(), self::userId());

        return match ($outcome) {
            CheckOutcome::Ok => $this->back(
                'success',
                'Connexion vérifiée : Meta répond et accepte l\'autorisation de ce site.'
            ),
            CheckOutcome::NotConnected => $this->back('error', 'Aucun compte n\'est connecté.'),
            default => $this->back('error', (string) $error?->getMessage()),
        };
    }

    /**
     * POST — forgets the platform: account, token and app secret. What was
     * already published stays where it is.
     *
     * @param array<string, string> $params
     */
    public function disconnect(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PAGE_URL)) !== null) {
            return $guard;
        }
        $platform = self::platform($params);
        if ($platform === null) {
            return new Response('Not Found', 404);
        }

        $this->service->disconnect($platform, self::userId());
        if ($platform === SocialPlatform::Facebook) {
            SessionStore::remove(self::PAGES_SESSION_KEY);
        }

        return $this->back('success', 'Compte déconnecté. Ce site ne peut plus y publier.');
    }

    /**
     * The address Meta must send the browser back to, spelled once for the
     * screen and the exchange alike: Meta compares it character for
     * character with the one declared in the app. Empty without a site
     * address, rather than a bare path Meta would refuse.
     */
    public static function redirectUriFor(string $baseUrl, SocialPlatform $platform): string
    {
        $base = rtrim($baseUrl, '/');

        return $base === '' ? '' : $base . self::PAGE_URL . '/' . $platform->value . '/retour';
    }

    private function back(string $type, string $message): Response
    {
        FlashMessage::set($type, $message);

        return $this->redirect(self::PAGE_URL);
    }

    private static function connectedMessage(SocialPlatform $platform): string
    {
        return match ($platform) {
            SocialPlatform::Facebook => 'Page Facebook connectée.',
            SocialPlatform::Instagram => 'Compte Instagram connecté.',
        };
    }

    private static function userId(): ?int
    {
        return AuthSession::getUserAccountId();
    }

    private function baseUrl(): string
    {
        return (string) ($this->settings->get('base_url') ?: '');
    }

    /**
     * @param array<string, string> $params
     */
    private static function platform(array $params): ?SocialPlatform
    {
        return SocialPlatform::tryFrom((string) ($params['platform'] ?? ''));
    }
}
