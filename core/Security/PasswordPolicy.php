<?php

declare(strict_types=1);

namespace Core\Security;

/**
 * The single password complexity ruleset — enforced server-side here on
 * both the password-reset page and the account page's change-password
 * box, and mirrored client-side by the same rule set in
 * partials/password_complexity_checklist.html.twig +
 * assets/js/password-complexity.js, so the two never drift apart.
 */
class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    /** @var array<string, string> rule key => French label, in the order the checklist displays them */
    public const RULES = [
        'length' => 'Au moins 12 caractères',
        'uppercase' => 'Au moins une majuscule',
        'lowercase' => 'Au moins une minuscule',
        'digit' => 'Au moins un chiffre',
        'symbol' => 'Au moins un caractère spécial (!@#$%...)',
    ];

    /**
     * Which rules $password fails to satisfy — empty array means fully compliant.
     *
     * @return string[] rule keys (see self::RULES) that are NOT satisfied
     */
    public static function violations(string $password): array
    {
        $violations = [];

        if (mb_strlen($password) < self::MIN_LENGTH) {
            $violations[] = 'length';
        }
        if (!preg_match('/\p{Lu}/u', $password)) {
            $violations[] = 'uppercase';
        }
        if (!preg_match('/\p{Ll}/u', $password)) {
            $violations[] = 'lowercase';
        }
        if (!preg_match('/\d/', $password)) {
            $violations[] = 'digit';
        }
        if (!preg_match('/[^\p{L}\p{N}]/u', $password)) {
            $violations[] = 'symbol';
        }

        return $violations;
    }

    public static function isValid(string $password): bool
    {
        return self::violations($password) === [];
    }
}
