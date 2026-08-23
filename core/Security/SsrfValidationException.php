<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

/**
 * Thrown when a caller-supplied URL fails the SSRF safety check
 * (Core\Security\SsrfUrlValidator) — a non-https scheme, embedded
 * credentials, a disallowed port, or a host that resolves to a
 * non-public address.
 *
 * Marked {@see \Core\Exception\UserFacingException}: every message is a
 * French sentence written for the visitor.
 */
class SsrfValidationException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
