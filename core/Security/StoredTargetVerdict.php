<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

/**
 * What re-checking a stored address on use found (#1269, SECURITY.md §17).
 *
 * **Three answers, because a caller acts on two different facts.** Every
 * request is refused unless the address is Safe. But a caller that would
 * destroy what it stored — a Web Push subscription — does so only on
 * Unsafe: an address this installation could not verify (nothing answered
 * for it, and without ext-sockets that silence proves nothing) is refused
 * this time and kept for the next.
 */
enum StoredTargetVerdict
{
    /** Structurally sound, and nothing it resolves to is off-limits. */
    case Safe;

    /** Refused on its structure, or resolving somewhere it must not be reached. */
    case Unsafe;

    /** Nothing resolved, and this installation cannot resolve as the client will. */
    case Unverifiable;
}
