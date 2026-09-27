<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Http\FlashMessage;
use Core\Security\CsrfGuard;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The three template functions that read the visitor's session:
 * `csrf_field()`, `csrf_token()` and `get_flash()`.
 *
 * An extension rather than closures in `TwigFactory::create()`, for one
 * reason: Twig lets a function added with `addFunction()` take precedence
 * over one of the same name coming from an extension, but refuses a SECOND
 * `addFunction()` of a name already added that way. Every function is
 * registered by the factory, so a test could never supply its own session
 * behaviour on top of the real environment — and so 140 tests built their
 * own environment instead, and stubbed everything else as well (issue
 * #465). Living here, these three are the ones `Tests\TestTwig` may
 * replace, and it may replace nothing else.
 */
final class SessionFunctionExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('csrf_field', [self::class, 'csrfField'], ['is_safe' => ['html']]),
            // The raw token, for the <meta> tag scripts read it from.
            new TwigFunction('csrf_token', [CsrfGuard::class, 'generateToken']),
            new TwigFunction('get_flash', [FlashMessage::class, 'get']),
        ];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(
            CsrfGuard::generateToken(),
            ENT_QUOTES,
            'UTF-8'
        ) . '">';
    }
}
