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
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\SessionStore;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Meta\MetaClient;
use Modules\Social\Meta\MetaException;
use Modules\Social\Repository\ConnectionRepository;
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
 * **The journal names no account.** Connected, reconnected, refused,
 * disconnected — with the platform and nothing else. The Page's name or the
 * handle are on this page, for the administrator who needs them.
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
        private readonly SettingService $settings,
        private readonly JournalService $journalService,
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
            FlashMessage::set('error', 'L\'identifiant de l\'application est une suite de chiffres. Copiez-le depuis '
                . 'le tableau de bord de votre application Meta.');

            return $this->redirect(self::PAGE_URL);
        }
        if ($appSecret === '' && ($current === null || !$current->hasAppSecret || $current->appId !== $appId)) {
            FlashMessage::set('error', 'Renseignez la clé secrète de l\'application.');

            return $this->redirect(self::PAGE_URL);
        }

        $this->connections->saveCredentials($platform, $appId, $appSecret === '' ? null : $appSecret);
        $this->journal($platform, 'credentials_saved', 'security', 'Identifiants de l\'application Meta enregistrés');
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
            FlashMessage::set('error', 'Le retour de Meta n\'a pas pu être vérifié. Recommencez la connexion.');

            return $this->redirect(self::PAGE_URL);
        }

        // « Annuler » on Meta's screen: not an incident.
        if ((string) $request->getQuery('error', '') !== '') {
            FlashMessage::set('error', 'La connexion a été annulée : Meta n\'a pas accordé l\'autorisation.');

            return $this->redirect(self::PAGE_URL);
        }

        $code = (string) $request->getQuery('code', '');
        $connection = $this->connections->find($platform);
        if ($code === '' || $connection === null || !$connection->hasAppSecret) {
            FlashMessage::set('error', 'Meta n\'a renvoyé aucun code d\'autorisation. Recommencez la connexion.');

            return $this->redirect(self::PAGE_URL);
        }

        $wasConnected = $connection->isConnected();
        $appSecret = $this->connections->secretsOf($platform)->appSecret;
        $redirectUri = self::redirectUriFor($this->baseUrl(), $platform);

        try {
            if ($platform === SocialPlatform::Facebook) {
                return $this->receiveFacebook($connection->appId, $appSecret, $redirectUri, $code, $wasConnected);
            }

            $token = $this->meta->instagramToken($connection->appId, $appSecret, $redirectUri, $code);
            $account = $this->meta->instagramAccount($token['token']);
            $now = new \DateTimeImmutable();
            $this->connections->connect(
                $platform,
                $account['id'],
                $account['username'],
                $token['token'],
                self::expiry($now, $token['expires_in']),
                $now
            );
        } catch (MetaException $e) {
            $this->journalRefusal($platform, $e);
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::PAGE_URL);
        }

        return $this->connected($platform, $wasConnected);
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
        $pageId = (string) $request->getBody('page_id', '');
        $offeredIds = is_array($offered) ? array_column($offered, 'id') : [];
        $userToken = $this->connections->secretsOf(SocialPlatform::Facebook)->pendingUserToken;
        // Only a Page this very consent offered: the list is the session's,
        // not the form's.
        if ($userToken === '' || !in_array($pageId, $offeredIds, true)) {
            FlashMessage::set('error', 'Cette Page n\'a pas été proposée par Meta. Recommencez la connexion.');

            return $this->redirect(self::PAGE_URL);
        }

        $wasConnected = $this->connections->find(SocialPlatform::Facebook)?->isConnected() === true;

        try {
            foreach ($this->meta->facebookPages($userToken) as $page) {
                if ($page['id'] === $pageId) {
                    return $this->attachPage($page, $wasConnected);
                }
            }
        } catch (MetaException $e) {
            $this->journalRefusal(SocialPlatform::Facebook, $e);
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::PAGE_URL);
        }

        FlashMessage::set('error', 'Meta ne donne plus accès à cette Page. Recommencez la connexion.');

        return $this->redirect(self::PAGE_URL);
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

        $connection = $this->connections->find($platform);
        if ($connection === null || !$connection->isConnected()) {
            FlashMessage::set('error', 'Aucun compte n\'est connecté.');

            return $this->redirect(self::PAGE_URL);
        }

        $token = $this->connections->secretsOf($platform)->accessToken;
        $now = new \DateTimeImmutable();

        try {
            $name = $platform === SocialPlatform::Facebook
                ? $this->meta->facebookPageName((string) $connection->accountId, $token)
                : $this->meta->instagramAccount($token)['username'];
        } catch (MetaException $e) {
            $this->connections->recordCheck($platform, false, $now);
            $this->journalRefusal($platform, $e);
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::PAGE_URL);
        }

        $this->connections->recordCheck($platform, true, $now, $name);
        FlashMessage::set('success', 'Connexion vérifiée : Meta répond et accepte l\'autorisation de ce site.');

        return $this->redirect(self::PAGE_URL);
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

        $this->connections->delete($platform);
        if ($platform === SocialPlatform::Facebook) {
            SessionStore::remove(self::PAGES_SESSION_KEY);
        }
        $this->journal($platform, 'disconnected', 'security', 'Compte déconnecté du site');
        FlashMessage::set('success', 'Compte déconnecté. Ce site ne peut plus y publier.');

        return $this->redirect(self::PAGE_URL);
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

    private function receiveFacebook(
        string $appId,
        string $appSecret,
        string $redirectUri,
        string $code,
        bool $wasConnected
    ): Response {
        $userToken = $this->meta->facebookUserToken($appId, $appSecret, $redirectUri, $code);
        $pages = $this->meta->facebookPages($userToken);

        if ($pages === []) {
            FlashMessage::set('error', 'Ce compte Facebook ne gère aucune Page, ou n\'en a partagé aucune avec '
                . 'l\'application. Reconnectez-vous et cochez la Page de l\'unité.');

            return $this->redirect(self::PAGE_URL);
        }

        if (count($pages) === 1) {
            return $this->attachPage($pages[0], $wasConnected);
        }

        $this->connections->holdPendingUserToken(SocialPlatform::Facebook, $userToken);
        SessionStore::set(
            self::PAGES_SESSION_KEY,
            array_map(static fn (array $page): array => ['id' => $page['id'], 'name' => $page['name']], $pages)
        );
        FlashMessage::set('warning', 'Ce compte gère plusieurs Pages : choisissez celle de l\'unité.');

        return $this->redirect(self::PAGE_URL);
    }

    /**
     * @param array{id: string, name: string, access_token: string} $page
     */
    private function attachPage(array $page, bool $wasConnected): Response
    {
        // A Page token obtained from a long-lived user token has no end.
        $this->connections->connect(
            SocialPlatform::Facebook,
            $page['id'],
            $page['name'],
            $page['access_token'],
            null,
            new \DateTimeImmutable()
        );
        SessionStore::remove(self::PAGES_SESSION_KEY);

        return $this->connected(SocialPlatform::Facebook, $wasConnected);
    }

    private function connected(SocialPlatform $platform, bool $wasConnected): Response
    {
        $wasConnected
            ? $this->journal($platform, 'reconnected', 'security', 'Compte reconnecté au site')
            : $this->journal($platform, 'connected', 'security', 'Compte connecté au site');
        FlashMessage::set('success', match ($platform) {
            SocialPlatform::Facebook => 'Page Facebook connectée.',
            SocialPlatform::Instagram => 'Compte Instagram connecté.',
        });

        return $this->redirect(self::PAGE_URL);
    }

    private function journalRefusal(SocialPlatform $platform, MetaException $e): void
    {
        $this->journalService->log(
            'social',
            'auth_failed',
            'warning',
            $platform->label() . ' : ' . $e->getMessage(),
            // Meta's own words, tokens redacted — the only way to tell a
            // wrong secret from a withdrawn authorisation afterwards.
            ['platform' => $platform->value, 'detail' => $e->detail],
            (int) AuthSession::getUserAccountId()
        );
    }

    private function journal(SocialPlatform $platform, string $event, string $level, string $message): void
    {
        $this->journalService->log(
            'social',
            $event,
            $level,
            $platform->label() . ' : ' . $message,
            ['platform' => $platform->value],
            (int) AuthSession::getUserAccountId()
        );
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

    private static function expiry(\DateTimeImmutable $now, ?int $seconds): ?\DateTimeImmutable
    {
        return $seconds === null ? null : $now->modify('+' . $seconds . ' seconds');
    }
}
