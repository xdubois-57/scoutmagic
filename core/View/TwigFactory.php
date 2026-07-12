<?php

declare(strict_types=1);

namespace Core\View;

use Core\Http\FlashMessage;
use Core\Security\CsrfGuard;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class TwigFactory
{
    public static function create(string $templateDir, bool $debug = false): Environment
    {
        $loader = new FilesystemLoader($templateDir);

        $cacheDir = dirname(__DIR__, 2) . '/storage/temp/twig_cache';

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $environment = new Environment($loader, [
            'cache' => $debug ? false : $cacheDir,
            'debug' => $debug,
            'auto_reload' => $debug,
            'autoescape' => 'html',
        ]);

        // Register csrf_field() function
        $environment->addFunction(new TwigFunction('csrf_field', function (): string {
            $token = CsrfGuard::generateToken();
            return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
        }, ['is_safe' => ['html']]));

        // Register get_flash() function
        $environment->addFunction(new TwigFunction('get_flash', function (): ?array {
            return FlashMessage::get();
        }));

        return $environment;
    }
}
