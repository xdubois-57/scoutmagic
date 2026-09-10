<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Portable;

use Core\Maintenance\Portable\PortableManifest;
use PHPUnit\Framework\TestCase;

final class PortableManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private function decode(PortableManifest $manifest): array
    {
        $decoded = json_decode($manifest->toJson(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function manifest(?string $installationId = 'abc123'): PortableManifest
    {
        return new PortableManifest(
            '2.4.1',
            $installationId,
            false,
            new \DateTimeImmutable('2026-09-10 08:30:00', new \DateTimeZone('UTC'))
        );
    }

    public function testItSaysWhatItIsBeforeAnythingElse(): void
    {
        $decoded = $this->decode($this->manifest());

        $this->assertSame(PortableManifest::FORMAT, $decoded['format']);
        $this->assertSame(PortableManifest::FORMAT_VERSION, $decoded['format_version']);
    }

    public function testItRecordsTheOrigin(): void
    {
        $decoded = $this->decode($this->manifest());

        $this->assertSame('2.4.1', $decoded['scoutmagic_version']);
        $this->assertSame('abc123', $decoded['installation_id']);
        $this->assertSame('2026-09-10T08:30:00+00:00', $decoded['created_at']);
        $this->assertFalse($decoded['includes_gallery']);
    }

    /**
     * An installation with no identifier says so, rather than inventing one.
     *
     * A site that never enabled usage statistics has no identifier at all,
     * and minting one to fill this field would give it a permanent identity
     * as a side effect of taking a backup. A restore assigns a new one
     * anyway (D6), so null is the honest value and has to survive
     * serialisation as null rather than as `""`.
     */
    public function testAnUnknownOriginIsNullRatherThanInvented(): void
    {
        $decoded = $this->decode($this->manifest(null));

        $this->assertNull($decoded['installation_id']);
        $this->assertArrayHasKey('installation_id', $decoded);
    }

    public function testMembersCarryTheirDigestAndSize(): void
    {
        $manifest = $this->manifest();
        $manifest->addMember('database.sql', str_repeat('a', 64), 4096);

        $decoded = $this->decode($manifest);

        $this->assertSame(str_repeat('a', 64), $decoded['members']['database.sql']['sha256']);
        $this->assertSame(4096, $decoded['members']['database.sql']['bytes']);
        $this->assertArrayNotHasKey('restore_target', $decoded['members']['database.sql']);
    }

    /**
     * A sealed secret says where it belongs, because its name does not.
     *
     * `secrets/master.key.enc` is deliberately not `storage/keys/master.key`
     * — an entry with the live name would be extracted straight over the
     * real key by an ordinary restore, writing the SEALED bytes over it and
     * locking the installation out of its own database. The destination has
     * to travel somewhere, and that somewhere is here.
     */
    public function testASealedSecretCarriesItsRestoreTarget(): void
    {
        $manifest = $this->manifest();
        $manifest->addMember('secrets/master.key.enc', str_repeat('b', 64), 60, 'storage/keys/master.key');

        $decoded = $this->decode($manifest);

        $this->assertSame(
            'storage/keys/master.key',
            $decoded['members']['secrets/master.key.enc']['restore_target']
        );
    }

    public function testTheDerivationIsRecordedOnce(): void
    {
        $manifest = $this->manifest();
        $manifest->describeDerivation(['kdf' => 'argon2id', 'salt' => 'c2VsCg==', 'opslimit' => 2]);

        $decoded = $this->decode($manifest);

        $this->assertSame('argon2id', $decoded['secret_derivation']['kdf']);
        $this->assertSame('c2VsCg==', $decoded['secret_derivation']['salt']);
    }

    /**
     * The two secret members are named in one place.
     *
     * Three things have to agree on these names — what seals them, what
     * describes them, and eventually what puts them back — and the map is
     * how they agree. It is also where the rule "never under `storage/`"
     * is enforced, so this test states it: an entry whose name is its live
     * path is an entry an ordinary extraction would write over the real
     * file.
     */
    public function testTheSecretMembersNeverCarryTheirLivePathAsAName(): void
    {
        $this->assertNotSame([], PortableManifest::SECRET_MEMBERS);

        foreach (PortableManifest::SECRET_MEMBERS as $livePath => $member) {
            $this->assertStringStartsWith('secrets/', $member);
            $this->assertStringNotContainsString('storage/', $member);
            $this->assertNotSame('storage/' . $livePath, $member);
        }
    }
}
