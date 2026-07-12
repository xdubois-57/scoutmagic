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

        // Register csrf_token() function (returns raw token for meta tags)
        $environment->addFunction(new TwigFunction('csrf_token', function (): string {
            return CsrfGuard::generateToken();
        }));

        // Register file_url() function
        $environment->addFunction(new TwigFunction('file_url', function (int|string|null $id): string {
            if ($id === null || $id === '' || $id === 0) {
                return '';
            }
            return '/files/' . (int) $id;
        }));

        // Register editable() function — renders editable content
        $environment->addFunction(new TwigFunction('editable', function (string $key, string $default = '', string $type = 'rich_text') use ($environment): string {
            /** @var EditableContentService|null $service */
            $service = $environment->getGlobals()['_editable_content_service'] ?? null;
            $configMode = $environment->getGlobals()['config_mode'] ?? false;

            $value = $service !== null ? $service->get($key, $default) : $default;
            $value = $value ?? $default;

            if ($configMode) {
                return '<div class="editable-content" data-key="' . htmlspecialchars($key, ENT_QUOTES) . '" data-type="' . htmlspecialchars($type, ENT_QUOTES) . '">'
                    . '<div class="editable-overlay"><button class="btn btn-sm btn-outline-primary editable-edit-btn"><i class="bi bi-pencil"></i> Modifier</button></div>'
                    . $value
                    . '</div>';
            }

            return (string) $value;
        }, ['is_safe' => ['html']]));

        // Register editable_image() function — renders editable image
        $environment->addFunction(new TwigFunction('editable_image', function (string $key, string $alt = '', string $cssClass = 'img-fluid rounded') use ($environment): string {
            /** @var EditableContentService|null $service */
            $service = $environment->getGlobals()['_editable_content_service'] ?? null;
            $configMode = $environment->getGlobals()['config_mode'] ?? false;

            $fileId = $service !== null ? $service->get($key) : null;

            if ($configMode) {
                if ($fileId !== null && $fileId !== '') {
                    return '<div class="editable-image" data-key="' . htmlspecialchars($key, ENT_QUOTES) . '" data-type="image">'
                        . '<div class="editable-overlay"><button class="btn btn-sm btn-outline-primary editable-edit-btn"><i class="bi bi-camera"></i> Changer</button></div>'
                        . '<img src="/files/' . (int) $fileId . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" class="' . htmlspecialchars($cssClass, ENT_QUOTES) . '">'
                        . '</div>';
                }

                return '<div class="editable-image editable-placeholder" data-key="' . htmlspecialchars($key, ENT_QUOTES) . '" data-type="image">'
                    . '<div class="d-flex align-items-center justify-content-center bg-light rounded" style="min-height:200px;">'
                    . '<span class="text-muted"><i class="bi bi-image"></i> Cliquer pour ajouter une image</span>'
                    . '</div>'
                    . '</div>';
            }

            if ($fileId !== null && $fileId !== '') {
                return '<img src="/files/' . (int) $fileId . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" class="' . htmlspecialchars($cssClass, ENT_QUOTES) . '">';
            }

            return '';
        }, ['is_safe' => ['html']]));

        return $environment;
    }
}
