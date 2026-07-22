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
        ];
    }
}
