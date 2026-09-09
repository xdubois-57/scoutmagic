<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert\Check;

use Core\Alert\AlertReading;
use Core\Alert\OperationalCheck;

/**
 * Is the site being served over plain HTTP?
 *
 * **A second check that cannot live in the scheduled task**, and the
 * chantier document names only the cron one. The reason is the same in
 * kind: a scheme is a property of a request, and a task runs on the CLI
 * where there is no request and no `$_SERVER` scheme to read. Asking a
 * background pass what protocol visitors are getting can only ever be
 * answered with a guess.
 *
 * It could have been read off the `base_url` setting instead, which a task
 * *can* reach — and that was rejected: `base_url` is what an administrator
 * once typed, not what a visitor is actually served. A certificate that
 * expired last night does not edit a setting. The point of this check is
 * the gap between the two.
 *
 * So it is evaluated on a real web request, beside the cron check, from a
 * `$_SERVER` this class is handed rather than reads — which is also what
 * makes it testable without a browser.
 */
final class HttpsCheck implements OperationalCheck
{
    public const KEY = 'https_absent';

    /**
     * @param array<string, mixed> $server normally the Request's own captured `$_SERVER`
     */
    public function __construct(private readonly array $server)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Connexion sécurisée';
    }

    public function read(): AlertReading
    {
        // The shared reading, so this agrees with the session cookie flags
        // and the HSTS header rather than forming a second opinion about
        // the same request (Core\Http\RequestScheme).
        $secure = \Core\Http\RequestScheme::isHttps($this->server);

        return new AlertReading(
            overTrigger: !$secure,
            underRearm: $secure,
            value: $secure ? 'HTTPS' : 'HTTP',
            title: 'Le site est servi en HTTP, sans chiffrement.',
            why: 'Les mots de passe et les données des membres circulent en clair entre le navigateur et '
                . 'le serveur, et n\'importe quel réseau traversé peut les lire. Activez le certificat '
                . 'HTTPS chez votre hébergeur — c\'est gratuit chez la plupart d\'entre eux.',
            actionUrl: '/config/maintenance',
            actionLabel: 'Voir l\'état du site'
        );
    }
}
