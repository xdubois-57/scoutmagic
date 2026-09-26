<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Core\Journal\JournalService;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Meta\MetaClient;
use Modules\Social\Meta\MetaException;
use Modules\Social\Repository\ConnectionRepository;

/**
 * Everything that changes a connection, and its journal entries — shared
 * by « Configuration › Réseaux sociaux » and the nightly task, so that
 * « Tester la connexion » and the task can never judge a connection two
 * different ways.
 *
 * The controller keeps what belongs to a request: the CSRF guard, the
 * OAuth `state` and the Page list in the session, the flash message.
 *
 * **The journal names no account.** The platform, the event and, for a
 * failure, Meta's redacted detail; the Page's name and the handle are on
 * the configuration page, for the administrator who needs them. A failure
 * is journalled **once**, when a working connection stops working — not on
 * every check after.
 */
class ConnectionService
{
    /** Meta refuses a renewal younger than a day; a week keeps well clear of it. */
    public const REFRESH_AFTER_DAYS = 7;

    /** How long a held Facebook user token waits for a Page to be chosen. */
    public const PENDING_TOKEN_HOURS = 24;

    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly JournalService $journal,
        private readonly MetaClient $meta = new MetaClient()
    ) {
    }

    /**
     * Records the unit's Meta app; a null secret keeps the one on file.
     */
    public function saveCredentials(SocialPlatform $platform, string $appId, ?string $appSecret, ?int $userId): void
    {
        $this->connections->saveCredentials($platform, $appId, $appSecret);
        $this->log(
            $platform,
            'credentials_saved',
            'security',
            'Identifiants de l\'application Meta enregistrés',
            $userId
        );
    }

    /**
     * Turns the consent's code into a connection.
     *
     * @return list<array{id: string, name: string}> the Pages to choose
     *         from when a Facebook account manages several — ids and names,
     *         never their tokens; empty once connected
     * @throws MetaException with a sentence the page may show
     */
    public function receiveCode(
        SocialPlatform $platform,
        string $redirectUri,
        string $code,
        \DateTimeImmutable $now,
        ?int $userId
    ): array {
        $connection = $this->connections->find($platform);
        if ($connection === null || !$connection->hasAppSecret) {
            throw new MetaException('Enregistrez d\'abord l\'identifiant et la clé secrète de l\'application.');
        }
        $wasConnected = $connection->isConnected();
        $appSecret = $this->connections->secretsOf($platform)->appSecret;

        try {
            if ($platform === SocialPlatform::Instagram) {
                $token = $this->meta->instagramToken($connection->appId, $appSecret, $redirectUri, $code);
                $account = $this->meta->instagramAccount($token['token']);
                $this->connections->connect(
                    $platform,
                    $account['id'],
                    $account['username'],
                    $token['token'],
                    $token['expires_in'] === null ? null : $now->modify('+' . $token['expires_in'] . ' seconds'),
                    $now
                );
                $this->connected($platform, $wasConnected, $userId);

                return [];
            }

            $userToken = $this->meta->facebookUserToken($connection->appId, $appSecret, $redirectUri, $code);
            $pages = $this->meta->facebookPages($userToken);
        } catch (MetaException $e) {
            $this->logRefusal($platform, $e, $userId);

            throw $e;
        }

        if ($pages === []) {
            throw new MetaException(
                'Ce compte Facebook ne gère aucune Page, ou n\'en a partagé aucune avec l\'application. '
                    . 'Reconnectez-vous et cochez la Page de l\'unité.'
            );
        }

        if (count($pages) === 1) {
            $this->attachPage($pages[0], $wasConnected, $now, $userId);

            return [];
        }

        $this->connections->holdPendingUserToken(SocialPlatform::Facebook, $userToken, $now);

        return array_map(static fn (array $page): array => ['id' => $page['id'], 'name' => $page['name']], $pages);
    }

    /**
     * Attaches the Page chosen among those the consent offered.
     *
     * @param list<string> $offeredIds the ids this very consent offered
     * @throws MetaException when it cannot be attached
     */
    public function choosePage(string $pageId, array $offeredIds, \DateTimeImmutable $now, ?int $userId): void
    {
        $userToken = $this->connections->secretsOf(SocialPlatform::Facebook)->pendingUserToken;
        // Only a Page this very consent offered: the list is the session's,
        // not the form's.
        if ($userToken === '' || !in_array($pageId, $offeredIds, true)) {
            throw new MetaException('Cette Page n\'a pas été proposée par Meta. Recommencez la connexion.');
        }

        $wasConnected = $this->connections->find(SocialPlatform::Facebook)?->isConnected() === true;

        try {
            $pages = $this->meta->facebookPages($userToken);
        } catch (MetaException $e) {
            $this->logRefusal(SocialPlatform::Facebook, $e, $userId);

            throw $e;
        }

        foreach ($pages as $page) {
            if ($page['id'] === $pageId) {
                $this->attachPage($page, $wasConnected, $now, $userId);

                return;
            }
        }

        throw new MetaException('Meta ne donne plus accès à cette Page. Recommencez la connexion.');
    }

    /**
     * One real call with the stored token — « Tester la connexion » and the
     * nightly task alike. `$refresh` lets the task renew an Instagram token
     * that is due first.
     *
     * @return array{0: CheckOutcome, 1: ?MetaException} the exception carries
     *         the sentence to show on a failure
     */
    public function check(SocialPlatform $platform, \DateTimeImmutable $now, ?int $userId, bool $refresh = false): array
    {
        $connection = $this->connections->find($platform);
        if ($connection === null || !$connection->isConnected()) {
            return [CheckOutcome::NotConnected, null];
        }

        $wasWorking = $connection->checkOk !== false;

        // Meta refuses to renew an expired token, and a person has to go
        // back through its consent screen: nothing to send.
        if ($connection->isExpired($now)) {
            $this->connections->recordCheck($platform, false, $now);
            if ($wasWorking) {
                $this->log(
                    $platform,
                    'token_expired',
                    'warning',
                    'L\'autorisation a expiré. Reconnectez le compte dans Configuration > Réseaux sociaux.',
                    $userId
                );
            }

            return [CheckOutcome::Expired, new MetaException(
                'L\'autorisation de Meta a expiré. Reconnectez le compte.',
                'token expired',
                true
            )];
        }

        $token = $this->connections->secretsOf($platform)->accessToken;

        try {
            $due = $refresh && $platform === SocialPlatform::Instagram
                && $this->dueForRefresh($connection->tokenRefreshedAt, $now);
            if ($due) {
                $renewed = $this->meta->refreshInstagramToken($token);
                $token = $renewed['token'];
                $this->connections->recordRefresh(
                    $platform,
                    $token,
                    $renewed['expires_in'] === null ? null : $now->modify('+' . $renewed['expires_in'] . ' seconds'),
                    $now
                );
                $this->log($platform, 'token_refreshed', 'info', 'Autorisation renouvelée.', $userId);
            }

            $name = $platform === SocialPlatform::Facebook
                ? $this->meta->facebookPageName((string) $connection->accountId, $token)
                : $this->meta->instagramAccount($token)['username'];
        } catch (MetaException $e) {
            // The network or Meta itself, not the authorisation: the last
            // verdict stands, and nothing is journalled as refused.
            if ($e->transient) {
                return [CheckOutcome::Unreachable, $e];
            }

            $this->connections->recordCheck($platform, false, $now);
            if ($wasWorking) {
                $this->logRefusal($platform, $e, $userId);
            }

            return [CheckOutcome::Refused, $e];
        }

        $this->connections->recordCheck($platform, true, $now, $name);

        return [CheckOutcome::Ok, null];
    }

    /**
     * Drops a Facebook user token held for a Page choice nobody finished.
     */
    public function dropAbandonedPageChoice(\DateTimeImmutable $now): void
    {
        $this->connections->dropPendingUserTokenHeldBefore(
            SocialPlatform::Facebook,
            $now->modify('-' . self::PENDING_TOKEN_HOURS . ' hours')
        );
    }

    /**
     * Forgets the platform: account, token and app secret.
     */
    public function disconnect(SocialPlatform $platform, ?int $userId): void
    {
        $this->connections->delete($platform);
        $this->log($platform, 'disconnected', 'security', 'Compte déconnecté du site', $userId);
    }

    /**
     * @param array{id: string, name: string, access_token: string} $page
     */
    private function attachPage(array $page, bool $wasConnected, \DateTimeImmutable $now, ?int $userId): void
    {
        // A Page token obtained from a long-lived user token has no end.
        $this->connections->connect(
            SocialPlatform::Facebook,
            $page['id'],
            $page['name'],
            $page['access_token'],
            null,
            $now
        );
        $this->connected(SocialPlatform::Facebook, $wasConnected, $userId);
    }

    private function connected(SocialPlatform $platform, bool $wasConnected, ?int $userId): void
    {
        $wasConnected
            ? $this->log($platform, 'reconnected', 'security', 'Compte reconnecté au site', $userId)
            : $this->log($platform, 'connected', 'security', 'Compte connecté au site', $userId);
    }

    private function dueForRefresh(?\DateTimeImmutable $refreshedAt, \DateTimeImmutable $now): bool
    {
        return $refreshedAt === null || $refreshedAt <= $now->modify('-' . self::REFRESH_AFTER_DAYS . ' days');
    }

    private function logRefusal(SocialPlatform $platform, MetaException $e, ?int $userId): void
    {
        // Meta's own words, tokens redacted — the only way to tell a wrong
        // secret from a withdrawn authorisation afterwards.
        $this->log($platform, 'auth_failed', 'warning', $e->getMessage(), $userId, $e->detail);
    }

    private function log(
        SocialPlatform $platform,
        string $event,
        string $level,
        string $message,
        ?int $userId,
        ?string $detail = null
    ): void {
        $context = ['platform' => $platform->value];
        if ($detail !== null) {
            $context['detail'] = $detail;
        }

        $this->journal->log('social', $event, $level, $platform->label() . ' : ' . $message, $context, $userId);
    }
}
