<?php

declare(strict_types=1);

namespace Core\Security\HumanCheck;

/**
 * What a form needs to render the three hidden barriers (partials/
 * human_check_fields.html.twig): a per-render trap field name, and a
 * signed token carrying that name plus the render timestamp. Both travel
 * in the form itself — HumanCheckService keeps no server-side state for
 * this (see the service's own docblock).
 */
final class HumanCheckChallenge
{
    public function __construct(
        public readonly string $trapField,
        public readonly string $tokenValue
    ) {
    }
}
