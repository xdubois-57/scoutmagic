<?php

declare(strict_types=1);

namespace Core\Cookie;

class CookieRegistry
{
    /**
     * Returns all cookies declared by the core.
     *
     * @return array<int, array{name: string, category: string, purpose: string, duration: string}>
     */
    public static function getCoreCookies(): array
    {
        return [
            [
                'name' => 'PHPSESSID',
                'category' => 'necessary',
                'purpose' => 'Maintien de la connexion et de l\'état de navigation.',
                'duration' => 'Durée de la session navigateur',
            ],
            [
                'name' => '_csrf_token',
                'category' => 'necessary',
                'purpose' => 'Protection contre les attaques par falsification de requête.',
                'duration' => 'Durée de la session',
            ],
            [
                'name' => 'cookie_consent',
                'category' => 'necessary',
                'purpose' => 'Mémorisation de vos choix concernant les cookies.',
                'duration' => '13 mois',
            ],
            [
                'name' => 'last_login_method',
                'category' => 'functional',
                'purpose' => 'Mémorisation de la méthode de connexion utilisée la dernière fois, pour la présélectionner sur la page de connexion.',
                'duration' => '13 mois',
            ],
            [
                // Not an HTTP cookie (no Set-Cookie header involved) but a
                // Cache Storage API entry — declared here anyway per
                // AGENTS.md's cookie-declaration rule, so the preferences
                // page and consent banner stay a complete picture of the
                // site's local storage footprint. Strictly necessary: this
                // is the app shell only (Bootstrap, the site's own CSS/JS,
                // the icons, the offline page) required for the site to
                // install and open at all — no content, no personal data,
                // no consent gate (that arrives with actual content
                // caching, in a later lot).
                'name' => 'app-shell-{version}',
                'category' => 'necessary',
                'purpose' => 'Stocke localement les fichiers nécessaires à l\'installation et l\'ouverture de l\'application (mise en page, styles, icônes) — aucune donnée personnelle, aucun contenu du site.',
                'duration' => 'Jusqu\'à la prochaine mise à jour du site (remplacé automatiquement)',
            ],
        ];
    }
}
