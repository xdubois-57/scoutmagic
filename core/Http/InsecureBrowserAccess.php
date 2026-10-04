<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

use Core\Config\SettingException;
use Core\Config\SettingService;

/**
 * « ScoutMagic a récemment été consulté depuis une connexion non
 * sécurisée » — the one state every surface reads (#751).
 *
 * **The browser is the witness, not PHP.** On a shared host that
 * terminates TLS in front of PHP, every request reaches the application
 * in clear while every visitor is on HTTPS; the previous check read
 * `$_SERVER` and raised a false alarm on exactly those installations
 * (#352). The page itself knows how it was loaded: `public/assets/js/
 * api.js` checks `location.protocol === 'https:' && window.isSecureContext`
 * once; on a page that fails it, every same-origin request carries
 * {@see HEADER} with `0`, and one beacon goes to {@see BEACON_PATH} when the
 * page made no request at all. A secure page sends nothing: the signal is
 * one-way, and an absent header is no statement.
 *
 * **One fact is kept: when it last happened.** The problem is active for
 * {@see ACTIVE_HOURS} after the last insecure observation; a later HTTPS
 * visit does not erase it, and a day without a new one clears it on its
 * own. Points d'attention and the notification (through
 * {@see \Core\Alert\Check\HttpsCheck}), Santé de l'hébergement and the
 * diagnostic package all read this class — none of them looks at a
 * scheme of its own, so they cannot contradict each other.
 *
 * **From any visitor, because a session cannot exist there.** While HTTPS
 * is required the session cookie is always `Secure`, and a browser on
 * plain `http://` neither sends it nor accepts a new one — so a page
 * genuinely loaded in clear has no session and no CSRF token, and a rule
 * demanding either would never fire on the one case it exists for. What
 * stands in their place: the header is a custom one, which a page on
 * another site cannot add without a CORS preflight this site never
 * grants, and the beacon must come from this site's own origin
 * ({@see isSameOrigin()}). Anybody outside a browser can still make the
 * statement up — it is a statement, not a proof — which is why it costs
 * nothing but a date written at most once per
 * {@see WRITE_THROTTLE_SECONDS}, expires on its own after
 * {@see ACTIVE_HOURS}, and can be cleared by a superadmin
 * ({@see dismiss()}). Kept only while HTTPS is required: an installation
 * that explicitly tolerates HTTP for development has nothing to report.
 */
final class InsecureBrowserAccess
{
    /** Unix timestamp of the last insecure observation; '0' = never. */
    public const SETTING = 'last_insecure_browser_access_at';

    /** Sent as '0' by api.js, only from an insecure page; absent means no statement. */
    public const HEADER = 'X-ScoutMagic-Secure-Context';

    /** The same header, as PHP exposes it in `$_SERVER`. */
    public const SERVER_KEY = 'HTTP_X_SCOUTMAGIC_SECURE_CONTEXT';

    /** The fallback for an insecure page that sent no request of its own. */
    public const BEACON_PATH = '/api/connexion-non-securisee';

    /** How long one insecure observation keeps the problem active. */
    public const ACTIVE_HOURS = 24;

    /**
     * An insecure browser sends the header on every request; writing the
     * same fact each time would cost a settings write per click. A
     * quarter of an hour is far below the 24-hour window it feeds.
     */
    public const WRITE_THROTTLE_SECONDS = 900;

    public function __construct(private readonly SettingService $settings)
    {
    }

    /**
     * Whether this request carries the browser's own statement that it
     * loaded the page outside a secure context. Absent header = no
     * statement: a request that did not come from api.js says nothing.
     *
     * @param array<string, mixed> $server
     */
    public static function reportsInsecure(array $server): bool
    {
        return ($server[self::SERVER_KEY] ?? null) === '0';
    }

    /**
     * What public/index.php does with every request once the response is
     * sent: keep the browser's « not secure » while HTTPS is required — an
     * installation that explicitly tolerates HTTP has nothing to report.
     * Returns whether it wrote.
     *
     * @param array<string, mixed> $server
     */
    public function observe(array $server, int $now): bool
    {
        if (!RequestScheme::httpsRequired() || !self::reportsInsecure($server)) {
            return false;
        }

        return $this->record($now);
    }

    /**
     * Whether a beacon comes from a page of this site: its `Origin` names
     * the host the request was sent to. The scheme is deliberately not
     * compared — the page that sends it is the one on `http://`, and PHP
     * behind a TLS terminator may believe either. An absent or opaque
     * (`null`) Origin is refused: every browser sends one with a POST
     * beacon, and only a page of another site would hide it.
     */
    public static function isSameOrigin(?string $origin, ?string $host): bool
    {
        if ($origin === null || $origin === '' || $origin === 'null' || $host === null || $host === '') {
            return false;
        }

        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['host'])) {
            return false;
        }

        $originHost = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return self::withoutDefaultPort($originHost) === self::withoutDefaultPort($host);
    }

    private static function withoutDefaultPort(string $host): string
    {
        return (string) preg_replace('/:(80|443)$/', '', strtolower($host));
    }

    /**
     * Records an insecure observation, at most once per
     * {@see WRITE_THROTTLE_SECONDS}. Returns whether it wrote.
     */
    public function record(int $now): bool
    {
        $last = $this->lastObservedAt();
        if ($last !== null && $now - $last < self::WRITE_THROTTLE_SECONDS) {
            return false;
        }

        $this->register();
        try {
            $this->settings->setInternal(self::SETTING, (string) $now);
        } catch (SettingException) {
            // A row that cannot be written degrades the surfaces to their
            // previous reading; it must not fail the request carrying it.
            return false;
        }

        return true;
    }

    /**
     * « Ignorer » on Santé de l'hébergement: forget the last observation,
     * so every surface reads « no insecure access » again. A browser that
     * is still on `http://` raises it again at its next request — clearing
     * a statement does not make the connection secure.
     */
    public function dismiss(): void
    {
        $this->register();
        $this->settings->setInternal(self::SETTING, '0');
    }

    public function lastObservedAt(): ?int
    {
        $value = (int) ($this->settings->get(self::SETTING) ?? 0);

        return $value > 0 ? $value : null;
    }

    public function isActive(int $now): bool
    {
        return self::activeAt($this->lastObservedAt(), $now);
    }

    /**
     * The rule itself, for a surface that was handed the timestamp rather
     * than this service: active for {@see ACTIVE_HOURS} after the last
     * insecure observation, and not a second longer.
     */
    public static function activeAt(?int $last, int $now): bool
    {
        return $last !== null && $now - $last < self::ACTIVE_HOURS * 3600;
    }

    /** When the problem stops being active on its own, or null when it is not. */
    public function activeUntil(int $now): ?int
    {
        $last = $this->lastObservedAt();

        return $this->isActive($now) && $last !== null ? $last + self::ACTIVE_HOURS * 3600 : null;
    }

    /**
     * « il y a 3 h » — how long ago the last insecure observation was,
     * the same words on every surface.
     */
    public static function ago(int $last, int $now): string
    {
        $hours = intdiv(max(0, $now - $last), 3600);

        return $hours < 1 ? 'il y a moins d\'une heure' : sprintf('il y a %d h', $hours);
    }

    /**
     * Registered where it is written, like `cron_last_run` beside
     * public/cron.php: idempotent, and served from the settings cache
     * once the row exists.
     */
    public function register(): void
    {
        $this->settings->register(
            self::SETTING,
            '0',
            'number',
            'Dernier accès non sécurisé',
            'Horodatage du dernier chargement de ScoutMagic observé par un navigateur hors connexion '
                . 'sécurisée, sur lequel reposent le point d\'attention et le contrôle « Connexion '
                . 'sécurisée ». Géré automatiquement.',
            null,
            null,
            null,
            false,
            128
        );
    }
}
