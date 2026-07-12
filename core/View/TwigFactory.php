<?php

declare(strict_types=1);

namespace Core\View;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

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

        return $environment;
    }
}
