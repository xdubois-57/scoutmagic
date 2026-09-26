<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Meta;

/**
 * Every Meta endpoint this module calls, and nowhere else.
 *
 * **Two separate logins, because Meta has two.** A Facebook Page is
 * reached through Facebook Login: the user token it yields is exchanged
 * for a long-lived one, which lists the Pages the person manages, each
 * with a Page token that — obtained that way — does not expire. An
 * Instagram professional account is reached through Instagram Login,
 * which needs no Facebook Page at all: its token lives sixty days and is
 * renewed by {@see refreshInstagramToken()} before then.
 *
 * The Graph API version is pinned: Meta retires a version two years after
 * its release, and a version bump is a change to review, not something
 * to discover in production.
 */
class MetaClient
{
    public const GRAPH_VERSION = 'v26.0';

    /** Read the Pages, read their engagement (needed to publish), publish. */
    private const FACEBOOK_SCOPES = 'pages_show_list,pages_read_engagement,pages_manage_posts';

    /** Read the account, publish to it. */
    private const INSTAGRAM_SCOPES = 'instagram_business_basic,instagram_business_content_publish';

    /** The account types Instagram's publishing API serves; a personal account is not one of them. */
    private const PROFESSIONAL_ACCOUNT_TYPES = ['BUSINESS', 'MEDIA_CREATOR'];

    /** How many times an Instagram container is asked whether it is ready, a second apart. */
    private const CONTAINER_POLLS = 10;

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /**
     * @param (\Closure(int): void)|null $sleep how to wait between two
     *        polls of an Instagram container — tests pass a no-op
     */
    public function __construct(
        private readonly MetaTransport $transport = new StreamMetaTransport(),
        ?\Closure $sleep = null
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function facebookAuthorizationUrl(string $appId, string $redirectUri, string $state): string
    {
        return 'https://www.facebook.com/' . self::GRAPH_VERSION . '/dialog/oauth?' . http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => self::FACEBOOK_SCOPES,
            'response_type' => 'code',
        ]);
    }

    /**
     * The consent's code, turned into a LONG-LIVED user token — the one
     * whose Page tokens never expire. A short-lived one would hand out
     * Page tokens that die within the hour.
     */
    public function facebookUserToken(string $appId, string $appSecret, string $redirectUri, string $code): string
    {
        $short = $this->getJson($this->graph('oauth/access_token', [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]));
        $shortToken = self::string($short, 'access_token');

        $long = $this->getJson($this->graph('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $shortToken,
        ]));

        return self::string($long, 'access_token');
    }

    /**
     * The Pages the person who consented manages, each with its token.
     *
     * @return list<array{id: string, name: string, access_token: string}>
     */
    public function facebookPages(string $userToken): array
    {
        $answer = $this->getJson($this->graph('me/accounts', [
            'fields' => 'id,name,access_token',
            'limit' => '100',
            'access_token' => $userToken,
        ]));

        $pages = [];
        foreach (is_array($answer['data'] ?? null) ? $answer['data'] : [] as $page) {
            if (!is_array($page)) {
                continue;
            }
            $id = $page['id'] ?? null;
            $token = $page['access_token'] ?? null;
            if (!is_string($id) || $id === '' || !is_string($token) || $token === '') {
                continue;
            }
            $name = is_string($page['name'] ?? null) ? $page['name'] : $id;
            $pages[] = ['id' => $id, 'name' => $name, 'access_token' => $token];
        }

        return $pages;
    }

    /**
     * Reads the Page with its own token: the whole of « Tester la
     * connexion » for Facebook. Returns the Page's current name.
     */
    public function facebookPageName(string $pageId, string $pageToken): string
    {
        $answer = $this->getJson($this->graph(rawurlencode($pageId), [
            'fields' => 'id,name',
            'access_token' => $pageToken,
        ]));

        return self::string($answer, 'name');
    }

    public function instagramAuthorizationUrl(string $appId, string $redirectUri, string $state): string
    {
        return 'https://www.instagram.com/oauth/authorize?' . http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => self::INSTAGRAM_SCOPES,
            'response_type' => 'code',
        ]);
    }

    /**
     * The consent's code, turned into a long-lived (sixty-day) token.
     *
     * @return array{token: string, expires_in: int|null}
     */
    public function instagramToken(string $appId, string $appSecret, string $redirectUri, string $code): array
    {
        $short = $this->decode($this->transport->postForm('https://api.instagram.com/oauth/access_token', [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]));
        // The answer has been seen both bare and wrapped in `data`.
        if (is_array($short['data'] ?? null) && is_array($short['data'][0] ?? null)) {
            $short = $short['data'][0];
        }
        $shortToken = self::string($short, 'access_token');

        $long = $this->getJson('https://graph.instagram.com/access_token?' . http_build_query([
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $appSecret,
            'access_token' => $shortToken,
        ]));

        return ['token' => self::string($long, 'access_token'), 'expires_in' => self::seconds($long)];
    }

    /**
     * A fresh sixty days for a token that is at least a day old and not
     * yet expired — Meta refuses the renewal otherwise.
     *
     * @return array{token: string, expires_in: int|null}
     */
    public function refreshInstagramToken(string $token): array
    {
        $answer = $this->getJson('https://graph.instagram.com/refresh_access_token?' . http_build_query([
            'grant_type' => 'ig_refresh_token',
            'access_token' => $token,
        ]));

        return ['token' => self::string($answer, 'access_token'), 'expires_in' => self::seconds($answer)];
    }

    /**
     * The account the token acts for. Refuses a personal account here,
     * with its own sentence, rather than letting the first publication
     * fail on it.
     *
     * @return array{id: string, username: string}
     */
    public function instagramAccount(string $token): array
    {
        $answer = $this->getJson('https://graph.instagram.com/' . self::GRAPH_VERSION . '/me?' . http_build_query([
            'fields' => 'user_id,username,account_type',
            'access_token' => $token,
        ]));

        $type = is_string($answer['account_type'] ?? null) ? $answer['account_type'] : '';
        if ($type !== '' && !in_array($type, self::PROFESSIONAL_ACCOUNT_TYPES, true)) {
            throw new MetaException(
                'Ce compte Instagram est un compte personnel. Passez-le en compte professionnel dans l\'application '
                    . 'Instagram, puis reconnectez-le.',
                'account_type ' . $type
            );
        }

        $id = $answer['user_id'] ?? $answer['id'] ?? null;
        if (is_int($id)) {
            $id = (string) $id;
        }
        if (!is_string($id) || $id === '') {
            throw MetaException::unexpected('no user_id');
        }

        return ['id' => $id, 'username' => self::string($answer, 'username')];
    }

    /**
     * An image post on the Page: Meta fetches `$imageUrl` itself.
     *
     * @return string the post's id
     */
    public function publishFacebookPhoto(string $pageId, string $pageToken, string $imageUrl, string $caption): string
    {
        $answer = $this->decode($this->transport->postForm(
            'https://graph.facebook.com/' . self::GRAPH_VERSION . '/' . rawurlencode($pageId) . '/photos',
            ['url' => $imageUrl, 'caption' => $caption, 'access_token' => $pageToken]
        ));

        $id = $answer['post_id'] ?? $answer['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : throw MetaException::unexpected('no post id');
    }

    /**
     * A link post on the Page: Facebook builds the preview from the page's
     * own Open Graph tags.
     *
     * @return string the post's id
     */
    public function publishFacebookLink(string $pageId, string $pageToken, string $link, string $message): string
    {
        $answer = $this->decode($this->transport->postForm(
            'https://graph.facebook.com/' . self::GRAPH_VERSION . '/' . rawurlencode($pageId) . '/feed',
            ['link' => $link, 'message' => $message, 'access_token' => $pageToken]
        ));

        return self::string($answer, 'id');
    }

    /**
     * An Instagram image post, in Meta's two steps: a container that Meta
     * fills by fetching `$imageUrl`, then its publication once it is
     * ready.
     *
     * @return string the media's id
     */
    public function publishInstagramImage(string $accountId, string $token, string $imageUrl, string $caption): string
    {
        $base = 'https://graph.instagram.com/' . self::GRAPH_VERSION . '/' . rawurlencode($accountId);
        $container = self::string($this->decode($this->transport->postForm(
            $base . '/media',
            ['image_url' => $imageUrl, 'caption' => $caption, 'access_token' => $token]
        )), 'id');

        $this->awaitContainer($container, $token);

        return self::string($this->decode($this->transport->postForm(
            $base . '/media_publish',
            ['creation_id' => $container, 'access_token' => $token]
        )), 'id');
    }

    /**
     * An image container is usually ready at once; when Meta says it is
     * still working, it is asked again a few times, and a container that
     * failed — an image Meta could not fetch, a format it refuses — says
     * so rather than being published.
     */
    private function awaitContainer(string $container, string $token): void
    {
        for ($poll = 0; $poll < self::CONTAINER_POLLS; $poll++) {
            $answer = $this->getJson('https://graph.instagram.com/' . self::GRAPH_VERSION . '/'
                . rawurlencode($container) . '?'
                . http_build_query(['fields' => 'status_code', 'access_token' => $token]));
            $status = is_string($answer['status_code'] ?? null) ? $answer['status_code'] : 'FINISHED';

            if ($status === 'FINISHED' || $status === 'PUBLISHED') {
                return;
            }
            if ($status === 'ERROR' || $status === 'EXPIRED') {
                throw new MetaException(
                    'Instagram n\'a pas pu récupérer l\'image. Réessayez dans quelques minutes.',
                    'container ' . $status
                );
            }

            ($this->sleep)(1);
        }

        throw new MetaException(
            'Instagram met trop de temps à préparer l\'image. Réessayez dans quelques minutes.',
            'container still in progress',
            false,
            true
        );
    }

    /**
     * @param array<string, string> $query
     */
    private function graph(string $path, array $query): string
    {
        return 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/' . $path . '?' . http_build_query($query);
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url): array
    {
        return $this->decode($this->transport->get($url));
    }

    /**
     * @param array{status: int, body: string}|null $response
     * @return array<string, mixed>
     */
    private function decode(?array $response): array
    {
        if ($response === null) {
            throw MetaException::unreachable();
        }

        $decoded = json_decode($response['body'], true);
        $status = $response['status'];

        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        if (is_array($error) || ($status !== 0 && ($status < 200 || $status >= 300))) {
            $code = is_array($error) && is_int($error['code'] ?? null) ? $error['code'] : 0;
            $message = is_array($error) && is_string($error['message'] ?? null)
                ? $error['message']
                : (is_array($decoded) && is_string($decoded['error_message'] ?? null) ? $decoded['error_message'] : '');

            throw MetaException::fromError($status, $code, $message);
        }

        if (!is_array($decoded)) {
            throw MetaException::unexpected('not JSON (HTTP ' . $status . ')');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<mixed> $answer
     */
    private static function string(array $answer, string $key): string
    {
        $value = $answer[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw MetaException::unexpected('no ' . $key);
        }

        return $value;
    }

    /**
     * @param array<mixed> $answer
     */
    private static function seconds(array $answer): ?int
    {
        $value = $answer['expires_in'] ?? null;

        return is_int($value) && $value > 0 ? $value : null;
    }
}
