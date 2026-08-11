<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Notification;

use Minishlink\WebPush\VAPID;

/**
 * VAPID::createVapidKeys() itself does not always produce a key pair its
 * own VAPID::validate() (and therefore WebPush's constructor) will accept
 * — observed in the wild on at least one shared host, where a freshly
 * generated key intermittently decoded to fewer than the required 65/32
 * bytes, taking down every single page load with an uncaught
 * ErrorException from deep inside WebPush's constructor. EC key
 * generation is randomized per call, so a bad draw is not necessarily
 * reproducible — retrying with a fresh key pair is enough to recover from
 * it, and every generated key pair is validated before being trusted.
 */
final class VapidKeyPairFactory
{
    private const MAX_ATTEMPTS = 5;

    /**
     * @return array{publicKey: string, privateKey: string}
     */
    public static function createValid(): array
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $keys = VAPID::createVapidKeys();
            if (self::isValid($keys['publicKey'], $keys['privateKey'])) {
                return $keys;
            }
        }

        throw new \RuntimeException(
            "Impossible de générer une paire de clés VAPID valide après plusieurs tentatives — "
            . "vérifiez que l'extension OpenSSL de ce serveur supporte la courbe EC prime256v1."
        );
    }

    public static function isValid(string $publicKey, string $privateKey): bool
    {
        if ($publicKey === '' || $privateKey === '') {
            return false;
        }

        try {
            VAPID::validate([
                'subject' => 'mailto:placeholder@example.com',
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
