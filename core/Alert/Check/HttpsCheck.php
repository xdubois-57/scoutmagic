<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert\Check;

use Core\Alert\AlertReading;
use Core\Alert\OperationalCheck;
use Core\Config\SettingService;

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
     * @param SettingService|null $settings read for `base_url`, the site's
     *        declared address — see {@see read()} for why one request's
     *        scheme is not enough on its own. Null only where no settings
     *        service exists to read, which makes the check inconclusive
     *        rather than guessing.
     */
    public function __construct(
        private readonly array $server,
        private readonly ?SettingService $settings = null
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Connexion sécurisée';
    }

    /**
     * Two facts, and the gap between them is deliberate.
     *
     * **One request's scheme cannot be both the trigger and the re-arm.**
     * Every other check here has genuinely separate thresholds — disk
     * 85/75, backup age 10/3 days — so a value sitting near a boundary
     * does not flap; `AlertReading` says that gap is the whole design. A
     * first version of this check set `overTrigger: !$secure` and
     * `underRearm: $secure`, two complementary readings of the same
     * per-request boolean, which is a gap of zero. Nothing in this
     * codebase forces HTTP to HTTPS — no redirect in `public/.htaccess`,
     * HSTS emitted only once already secure — so a site answering on both
     * schemes alternates as visitors arrive, and this check runs every
     * quarter of an hour: triggered, armed, triggered, e-mailing every
     * super-admin each way.
     *
     * So the two directions read two different facts:
     *
     * - **Triggering needs both**: this request arrived over HTTP *and*
     *   `base_url` — the address the operator declared at setup, used to
     *   build every link in every e-mail — is not an `https://` one. One
     *   stray HTTP request against a site declared HTTPS proves a
     *   misconfiguration worth nobody's night.
     * - **Re-arming needs both too**: this request arrived over HTTPS
     *   *and* `base_url` says HTTPS.
     *
     * Between them — observed one way, declared the other — neither is
     * true and the state does not move. That is a real gap rather than an
     * arithmetic one, and it is built from a live observation and a stored
     * declaration, which is why it cannot close.
     */
    public function read(): AlertReading
    {
        if ($this->settings === null) {
            return AlertReading::inconclusive();
        }

        // The shared reading, so this agrees with the session cookie flags
        // and the HSTS header rather than forming a second opinion about
        // the same request (Core\Http\RequestScheme).
        $secure = \Core\Http\RequestScheme::isHttps($this->server);
        $declaredSecure = str_starts_with(
            strtolower(trim((string) ($this->settings->get('base_url') ?? ''))),
            'https://'
        );

        return new AlertReading(
            overTrigger: !$secure && !$declaredSecure,
            underRearm: $secure && $declaredSecure,
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
