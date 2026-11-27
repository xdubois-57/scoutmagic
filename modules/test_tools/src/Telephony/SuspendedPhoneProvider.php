<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\TestTools\Telephony;

use Modules\SosStaff\Api\ForwardingState;
use Modules\SosStaff\Api\PhoneProviderInterface;
use Modules\SosStaff\Api\ProviderException;

/**
 * The line `sos_staff` talks to while simulated telephony is armed and no
 * longer acting (Core\Security\SandboxLossState, ARCHITECTURE.md §8.63,
 * issue #1469): it refuses everything and reaches nothing.
 *
 * Handed over by the composition roots in place of the simulated line
 * — never built by this module's own wiring, which does not run when the
 * module is not loaded. Every refusal is a `ProviderException`, the one
 * failure `sos_staff` already treats as « the operator cannot be
 * reached »: the page shows the line as unreadable, a redirect is
 * journaled as failed, and the configured operator's credentials are never
 * used.
 */
final class SuspendedPhoneProvider implements PhoneProviderInterface
{
    public const MESSAGE = 'Le téléphone d\'urgence est suspendu : la téléphonie simulée est armée mais n\'agit '
        . 'plus. Un super-administrateur doit décider depuis Configuration › Capture suspendue.';

    public function readForwardingState(): ForwardingState
    {
        throw new ProviderException(self::MESSAGE);
    }

    public function setForwarding(string $number): void
    {
        throw new ProviderException(self::MESSAGE);
    }

    public function testConnection(): bool
    {
        throw new ProviderException(self::MESSAGE);
    }

    public function listLines(): array
    {
        throw new ProviderException(self::MESSAGE);
    }
}
