<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The opaque WebAuthn user handle (issue #1131) is 32 raw bytes in a
 * BINARY(32) column of a utf8mb4 table: the round trip, the "only if
 * still NULL" claim and the UNIQUE index over a column most rows leave
 * NULL are each something SQLite answers on its own terms.
 */
#[Group('database')]
final class WebAuthnUserHandleOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    public function testAHandleRoundTripsByteForByteAndIsClaimedOnlyOnce(): void
    {
        $repo = new UserAccountRepository($this->productionEngine(), new EncryptionService(
            str_repeat('a', 32),
            str_repeat('b', 32)
        ));
        $account = $repo->create('passkey@example.com');
        // Bytes that are not valid UTF-8, a NUL and trailing zeros —
        // everything a charset conversion or a padding rule would touch.
        $handle = "\xff\xfe\x00\xc3\x28" . random_bytes(25) . "\x00\x00";

        $this->assertNull($repo->findWebAuthnUserHandle($account->id));
        $this->assertSame($handle, $repo->claimWebAuthnUserHandle($account->id, $handle));
        $this->assertSame($handle, $repo->claimWebAuthnUserHandle($account->id, random_bytes(32)));
        $this->assertSame($handle, $repo->findWebAuthnUserHandle($account->id));
    }

    public function testAccountsWithoutAHandleDoNotCollideOnTheUniqueIndex(): void
    {
        $repo = new UserAccountRepository($this->productionEngine(), new EncryptionService(
            str_repeat('a', 32),
            str_repeat('b', 32)
        ));
        $first = $repo->create('first@example.com');
        $second = $repo->create('second@example.com');

        $this->assertNull($repo->findWebAuthnUserHandle($first->id));
        $this->assertNull($repo->findWebAuthnUserHandle($second->id));

        $shared = random_bytes(32);
        $repo->claimWebAuthnUserHandle($first->id, $shared);
        $this->expectException(\PDOException::class);
        $repo->claimWebAuthnUserHandle($second->id, $shared);
    }
}
