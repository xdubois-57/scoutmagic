<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

/**
 * One rule a registration request breaks (Service\
 * RegistrationRequestValidator): which field, how — absent, not a value
 * the module accepts, or too long — and the sentence the public form shows
 * for it.
 */
final class RegistrationRequestViolation
{
    public const MISSING = 'missing';
    public const INVALID = 'invalid';
    public const TOO_LONG = 'too_long';

    public function __construct(
        public readonly string $field,
        public readonly string $kind,
        public readonly string $formMessage
    ) {
    }
}
