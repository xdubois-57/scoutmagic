<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Security\SessionStore;

/**
 * The last failed S3 connection test, held for the « Expliquer avec l'IA »
 * button that comes right after it.
 *
 * The two are one gesture split across two requests, and what the second
 * needs is what the first found out. The AWS SDK's own words — « The
 * request signature we calculated does not match the signature you
 * provided », « The specified bucket does not exist » — are the entire
 * diagnostic material: they say which of half a dozen indistinguishable
 * mistakes was made. They are also English, technical, and quote a third
 * party, so they stay off the page (Controller\GalleryConfigController
 * ::testConnection() journals them and shows the admin a French sentence).
 *
 * Until now the explainer was handed the French sentence instead, over
 * the wire, from the browser: « Connexion impossible : vérifiez vos
 * identifiants » is true, useless to diagnose, and — coming from the
 * browser — a string an admin could have edited into the prompt. So the
 * model was asked to explain an error nobody had told it about.
 *
 * Per-session and overwritten by the next test, because it describes the
 * test this admin just ran and nothing else. Never journaled from here:
 * testConnection() already does that, with the actor.
 */
class S3TestFailure
{
    private const SESSION_KEY = '_gallery_s3_test_failure';

    public static function remember(string $summary, ?string $technicalError): void
    {
        SessionStore::set(self::SESSION_KEY, [
            'summary' => $summary,
            'technical' => $technicalError,
        ]);
    }

    /**
     * @return array{summary: string, technical: ?string}|null
     */
    public static function read(): ?array
    {
        $stored = SessionStore::get(self::SESSION_KEY);
        if (!is_array($stored) || !isset($stored['summary'])) {
            return null;
        }

        return [
            'summary' => (string) $stored['summary'],
            'technical' => isset($stored['technical']) ? (string) $stored['technical'] : null,
        ];
    }

    public static function forget(): void
    {
        SessionStore::remove(self::SESSION_KEY);
    }
}
