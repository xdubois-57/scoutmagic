<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert\Check;

use Core\Alert\AlertReading;
use Core\Alert\AlertThresholds;
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
 * expired last night does not edit a setting.
 *
 * So it is evaluated on a real web request, beside the cron check, from a
 * `$_SERVER` this class is handed rather than reads — which is also what
 * makes it testable without a browser.
 */
final class HttpsCheck implements OperationalCheck
{
    public const KEY = 'https_absent';

    /**
     * Where the observation is kept between requests: the Unix timestamp
     * of the last request this installation is known to have answered
     * without encryption. The same shape as `cron_last_run`, and
     * registered next to the check itself in `public/index.php`.
     */
    public const LAST_CLEAR_SETTING = 'insecure_request_last_seen';

    /**
     * @param array<string, mixed> $server normally the Request's own captured `$_SERVER`
     * @param SettingService|null $settings holds {@see LAST_CLEAR_SETTING}
     *        across requests — see {@see read()} for why one request's
     *        scheme is not enough on its own. Null only where no settings
     *        service exists to read, which makes the check inconclusive
     *        rather than guessing.
     */
    public function __construct(
        private readonly array $server,
        private readonly ?SettingService $settings = null,
        private readonly ?\DateTimeImmutable $now = null
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
     * The gap is time, not a second reading of the same boolean.
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
     * A second version made both directions require the observed scheme
     * and the declared `base_url` to agree, and it was worse than the
     * first: requiring `base_url` NOT to say `https://` in order to
     * trigger meant the check could not fire in the one case this class
     * was written for — a certificate that expired overnight on a site
     * that still, correctly, declares `https://`. And since every
     * triggered instance then had `base_url` saying `http://` by
     * construction, while re-arming required it to say `https://`,
     * repairing the certificate could never clear the alert: only editing
     * a setting could, which the alert's own advice never mentions.
     *
     * So the pair is now the same shape as every other check here — an
     * event, and how long ago it happened:
     *
     * - **Triggering**: this request arrived in clear. That is the whole
     *   condition. A site handing a password to the network does not
     *   become acceptable because a setting disagrees.
     * - **Re-arming**: this request arrived over HTTPS *and* nothing has
     *   been seen in clear for {@see AlertThresholds::HTTPS_REARM_QUIET_HOURS}.
     *
     * A site answering on both schemes keeps re-stamping and never goes
     * quiet, which is the correct answer rather than a tolerated one: it
     * is still handing passwords to the network. An administrator who
     * fixes the certificate clears the alert by fixing it, with nothing
     * to edit afterwards.
     *
     * **`base_url` is deliberately not read**, and dropping it lost
     * nothing: what a declared address does is send people to it, and
     * people sent to an `http://` address arrive here as clear requests —
     * which is exactly what this measures. The consequence is observable,
     * so the cause need not be.
     *
     * Worth one line for the next reader: `DevelopmentModeCheck` has the
     * complementary-boolean shape this check was rid of, and does not have
     * the defect, because it reads a **setting** — which changes when an
     * administrator changes it and cannot oscillate between two requests.
     * It is the nature of the source, not the shape of the code, that
     * decides whether complementary booleans flap.
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
        $now = ($this->now ?? new \DateTimeImmutable())->getTimestamp();

        if (!$secure) {
            $this->stampClearTraffic($this->settings, $now);

            return $this->reading(true, false, 'en clair à l\'instant');
        }

        $lastClear = (int) ($this->settings->get(self::LAST_CLEAR_SETTING) ?? 0);
        if ($lastClear <= 0) {
            // Never seen serving in clear, and secure right now. Nothing to
            // report and nothing to print: an empty value is also what
            // OperationalAlertService refuses to write over a figure a
            // still-triggered alert is showing.
            return $this->reading(false, true, '');
        }

        $hours = intdiv(max(0, $now - $lastClear), 3600);

        return $this->reading(
            false,
            $hours >= AlertThresholds::HTTPS_REARM_QUIET_HOURS,
            $hours < 1 ? 'en clair il y a moins d\'une heure' : sprintf('en clair il y a %d h', $hours)
        );
    }

    /**
     * Records that this installation was seen answering without encryption.
     *
     * **A check that writes, which no other one here does** — because no
     * other one has to. `cron_last_run` is there for `CronSilenceCheck` to
     * read because `public/cron.php` stamps it as it runs; the event
     * records itself. A request served in clear records nothing at all —
     * no row, no file, nothing but the `$_SERVER` of a request already
     * being answered — so the trace has to be made at the only moment it
     * exists, which is here.
     *
     * The cost is one `settings` write, at most once a quarter of an hour
     * ({@see \Core\Alert\RequestBoundChecks} decides whether this class
     * runs at all) and only on a site actually being served in clear: an
     * installation with a working certificate never writes anything. It
     * also lands past `send()` and `session_write_close()`, where clearing
     * the settings cache costs the visitor nothing.
     *
     * A `settings` row rather than a marker file under `storage/temp/`,
     * which is what `RequestBoundChecks` and `Core\Storage\DiskBudget` use
     * for their own request-path bookkeeping — and what decides it is what
     * losing the thing costs. Losing the throttle marker buys one extra
     * evaluation. Losing this stamp would let a site answering on both
     * schemes re-arm early and trigger again on the next clear request,
     * which is the exact flapping the design exists to stop.
     *
     * Silent on the one failure that is not a bug — no such row to write,
     * on an installation whose registration has not run — because a stamp
     * that cannot be written degrades the alert to its previous reading and
     * must not also cost the reading being taken right now. Anything else,
     * a database that has gone away included, goes up to
     * {@see \Core\Alert\OperationalAlertService}, which journals a check
     * that throws and carries on with the others (§8.99); silencing that
     * here would hide a real defect behind an alert that merely looks calm.
     */
    private function stampClearTraffic(SettingService $settings, int $now): void
    {
        try {
            $settings->setInternal(self::LAST_CLEAR_SETTING, (string) $now);
        } catch (\Core\Config\SettingException) {
            // Nothing to do and nobody to tell — see above.
        }
    }

    private function reading(bool $overTrigger, bool $underRearm, string $value): AlertReading
    {
        return new AlertReading(
            overTrigger: $overTrigger,
            underRearm: $underRearm,
            value: $value,
            title: 'Le site est servi en HTTP, sans chiffrement.',
            why: 'Les mots de passe et les données des membres circulent en clair entre le navigateur et '
                . 'le serveur, et n\'importe quel réseau traversé peut les lire. Activez le certificat '
                . 'HTTPS chez votre hébergeur — c\'est gratuit chez la plupart d\'entre eux.',
            actionUrl: '/config/maintenance',
            actionLabel: 'Voir l\'état du site'
        );
    }
}
