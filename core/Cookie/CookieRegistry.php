<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
            [
                // Not an HTTP cookie but a localStorage entry — declared
                // here anyway, same reasoning as the Cache Storage entries
                // around it: the preferences page and consent banner must
                // stay a complete picture of the site's local storage
                // footprint. Functional, with a real consent gate enforced
                // client-side (public/assets/js/theme.js checks the
                // cookie_consent cookie before writing; without functional
                // consent the theme toggle works for the session only and
                // stores nothing). Only ever holds 'light' or 'dark' —
                // 'automatique' (the default, follows the device setting)
                // is stored as an absence of the entry.
                'name' => 'theme_preference',
                'category' => 'functional',
                'purpose' => 'Mémorisation du thème d\'affichage choisi (clair ou sombre) pour l\'appliquer à chaque visite.',
                'duration' => 'Jusqu\'au retrait (suppression manuelle ou retour au thème automatique)',
            ],
            [
                // Another localStorage entry written by a script under
                // public/assets/js/ (camps-map.js), which is why it is
                // declared here beside theme_preference rather than in a
                // module manifest: every client-side storage key this
                // repository ships is written by a file in that one
                // directory, and one list of them is what makes the
                // preferences page and the consent banner a complete
                // picture. Functional, with the same client-side consent
                // gate as theme_preference — camps-map.js reads the
                // cookie_consent cookie before writing AND before
                // trusting what it reads back, so withdrawing the
                // category drops the entry on the next visit. Only ever
                // holds '1' (the reader folded the map away); expanded,
                // the default, is stored as an absence of the entry.
                'name' => 'camps_map_collapsed',
                'category' => 'functional',
                'purpose' => 'Mémorisation du repli de la carte des lieux de camp, pour la retrouver telle que vous l\'avez laissée à chaque visite.',
                'duration' => 'Jusqu\'au retrait (suppression manuelle ou réouverture de la carte)',
            ],
            [
                // Also a Cache Storage API entry, not an HTTP cookie — the
                // Lot 3 content cache the app-shell entry above's comment
                // anticipated, widened in Lot 4 (ARCHITECTURE §8.25) to a
                // module-aggregated whitelist that now includes the
                // member's own page and Mon compte. Functional, with a
                // real consent gate (Core\Offline, public/sw.js): stores
                // full page copies of a server-declared, per-module-
                // extensible whitelist — never /files/{id} (the original),
                // never finance, member documents, or mass-mail content —
                // plus the exact photo variants those pages render.
                // Scoped per signed-in account so a different member on
                // the same device never inherits the previous one's
                // copies; only ever written to by the installed app (a
                // plain browser tab only ever reads it); emptied on
                // logout or on withdrawing this consent.
                'name' => 'content-{accountScope}-{version}',
                'category' => 'functional',
                'purpose' => 'Conserve localement, depuis l\'application installée, une copie des pages consultables hors ligne (accueil, contact, sections, protection des données, calendrier, notifications, trombinoscope, staffs, statistiques, prévisions, votre page personnelle et « Mon compte ») ainsi que les photos qu\'elles affichent, pour pouvoir les consulter hors connexion.',
                'duration' => 'Jusqu\'à péremption (durée configurable, 30 jours par défaut), déconnexion, ou retrait de ce consentement',
            ],
        ];
    }
}
