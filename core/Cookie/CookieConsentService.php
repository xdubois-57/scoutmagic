<?php

declare(strict_types=1);

namespace Core\Cookie;

class CookieConsentService
{
    private const CONSENT_COOKIE_NAME = 'cookie_consent';
    private const CONSENT_DURATION_DAYS = 395; // 13 months per ePrivacy directive

    /** @var array<string, array{label: string, description: string}> */
    private const CATEGORY_META = [
        'necessary' => [
            'label' => 'Cookies strictement nécessaires',
            'description' => 'Ces cookies sont indispensables au fonctionnement du site et ne peuvent pas être désactivés. Ils sont définis en réponse à des actions de votre part comme la connexion ou le remplissage de formulaires.',
        ],
        'functional' => [
            'label' => 'Cookies fonctionnels',
            'description' => 'Ces cookies permettent d\'améliorer le confort d\'utilisation du site en mémorisant vos préférences. Si vous les désactivez, certaines fonctionnalités optionnelles pourraient ne plus être disponibles.',
        ],
        'analytics' => [
            'label' => 'Cookies d\'analyse',
            'description' => 'Ces cookies permettent de mesurer la fréquentation du site afin d\'en améliorer le fonctionnement. Les données collectées sont anonymes.',
        ],
    ];

    /** @var array<int, array{name: string, category: string, purpose: string, duration: string}> */
    private array $moduleCookies = [];

    /** @var array<string, mixed>|null Overridable cookie jar for testing */
    private ?array $cookieJar;

    /** @var bool Whether setcookie was actually called (for testing) */
    private bool $cookieWasSet = false;

    /**
     * @param array<string, mixed>|null $cookieJar Injectable cookie source (null = use $_COOKIE)
     */
    public function __construct(?array $cookieJar = null)
    {
        $this->cookieJar = $cookieJar;
    }

    /**
     * Get all declared cookies from core + active modules, grouped by category.
     *
     * @return array<string, array{label: string, description: string, cookies: array<int, array{name: string, purpose: string, duration: string}>}>
     */
    public function getAllDeclaredCookies(): array
    {
        $coreCookies = CookieRegistry::getCoreCookies();
        $allCookies = array_merge($coreCookies, $this->moduleCookies);

        $grouped = [];
        foreach (self::CATEGORY_META as $categoryId => $meta) {
            $grouped[$categoryId] = [
                'label' => $meta['label'],
                'description' => $meta['description'],
                'cookies' => [],
            ];
        }

        foreach ($allCookies as $cookie) {
            $category = $cookie['category'];
            if (!isset($grouped[$category])) {
                continue;
            }
            $grouped[$category]['cookies'][] = [
                'name' => $cookie['name'],
                'purpose' => $cookie['purpose'],
                'duration' => $cookie['duration'],
            ];
        }

        return $grouped;
    }

    /**
     * Check if the user has made a consent choice.
     */
    public function hasConsented(): bool
    {
        $raw = $this->readCookie(self::CONSENT_COOKIE_NAME);

        return $raw !== null && $raw !== '';
    }

    /**
     * Check if a specific category is allowed.
     */
    public function isAllowed(string $category): bool
    {
        if ($category === 'necessary') {
            return true;
        }

        $consent = $this->getConsent();

        if ($consent === null) {
            return false;
        }

        return (bool) ($consent[$category] ?? false);
    }

    /**
     * Save consent choices.
     *
     * @param array<string, bool> $choices ['functional' => true/false, 'analytics' => true/false]
     */
    public function saveConsent(array $choices): void
    {
        $data = [
            'functional' => (bool) ($choices['functional'] ?? false),
            'analytics' => (bool) ($choices['analytics'] ?? false),
        ];

        $json = json_encode($data);
        if ($json === false) {
            return;
        }

        $this->writeCookie(self::CONSENT_COOKIE_NAME, $json);
    }

    /**
     * Accept all non-necessary categories.
     */
    public function acceptAll(): void
    {
        $this->saveConsent(['functional' => true, 'analytics' => true]);
    }

    /**
     * Reject all non-necessary categories.
     */
    public function rejectAll(): void
    {
        $this->saveConsent(['functional' => false, 'analytics' => false]);
    }

    /**
     * Get the current consent state.
     *
     * @return array{functional: bool, analytics: bool}|null
     */
    public function getConsent(): ?array
    {
        $raw = $this->readCookie(self::CONSENT_COOKIE_NAME);

        if ($raw === null || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return null;
        }

        return [
            'functional' => (bool) ($data['functional'] ?? false),
            'analytics' => (bool) ($data['analytics'] ?? false),
        ];
    }

    /**
     * Register module cookies (integration point for ModuleManager, iteration 12).
     *
     * @param array<int, array{name: string, category: string, purpose: string, duration: string}> $cookies
     */
    public function registerModuleCookies(string $moduleId, array $cookies): void
    {
        foreach ($cookies as $cookie) {
            $this->moduleCookies[] = $cookie;
        }
    }

    /**
     * Read a cookie value from the injectable jar or $_COOKIE.
     */
    private function readCookie(string $name): ?string
    {
        if ($this->cookieJar !== null) {
            $value = $this->cookieJar[$name] ?? null;
            return is_string($value) ? $value : null;
        }

        $value = $_COOKIE[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * Write a cookie. In test mode (with cookieJar), writes to the jar instead of calling setcookie().
     */
    private function writeCookie(string $name, string $value): void
    {
        if ($this->cookieJar !== null) {
            $this->cookieJar[$name] = $value;
            $this->cookieWasSet = true;
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

        setcookie($name, $value, [
            'expires' => time() + (self::CONSENT_DURATION_DAYS * 86400),
            'path' => '/',
            'httponly' => false,
            'secure' => $isHttps,
            'samesite' => 'Lax',
        ]);

        // Also set in $_COOKIE for immediate availability in this request
        $_COOKIE[$name] = $value;
    }

    /**
     * Whether a cookie was set during this request (for testing).
     */
    public function wasCookieSet(): bool
    {
        return $this->cookieWasSet;
    }
}
