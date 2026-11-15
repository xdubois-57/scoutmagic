<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

/**
 * The passkey ceremony was answered after its challenge lapsed
 * (WebAuthnService::CHALLENGE_TTL_SECONDS, issue #1131), or with a
 * challenge stored before challenges carried their issue time. The
 * challenge has been spent all the same; the visitor only has to start
 * again, which is what USER_MESSAGE tells them — distinct from a refused
 * passkey, whose message stays the generic failure.
 */
final class WebAuthnChallengeExpired extends \RuntimeException
{
    /** What the visitor is told. */
    public const USER_MESSAGE = 'Le délai est dépassé. Veuillez réessayer.';

    public function __construct()
    {
        parent::__construct('The WebAuthn challenge has expired.');
    }
}
