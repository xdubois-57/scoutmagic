<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Volume;

use Core\Storage\Volume\StatDeviceResolver;
use PHPUnit\Framework\TestCase;

/**
 * The part {@see \Tests\Core\Storage\Volume\VolumeInventoryTest} stubs out:
 * what the kernel actually contributes.
 *
 * Deliberately small. It cannot check « two devices are two answers »
 * without mounting a filesystem, which a CI runner will not allow — so it
 * checks the three things that can be checked anywhere and that the
 * inventory's correctness rests on: one filesystem gives one answer
 * whatever the spelling, a directory that does not exist yet is placed on
 * the volume it is about to be created on, and an unplaceable path answers
 * null rather than something that would be merged with another.
 */
class StatDeviceResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/scoutmagic-device-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/nested/deeper', 0777, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->root . '/nested/deeper');
        @rmdir($this->root . '/nested');
        @rmdir($this->root);
    }

    /**
     * **The reference answer is pinned non-null first, in every test
     * below.** `assertSame(null, null)` passes, so comparing two answers
     * and nothing else leaves this whole file green on a resolver that has
     * stopped identifying anything at all — `open_basedir`, a `stat()`
     * that fails, a regression in the climb loop — while the inventory
     * silently splits one filesystem into as many volumes as it has
     * directories. That is exactly the failure these tests exist to catch,
     * so the non-null is asserted rather than assumed.
     */
    private function pinnedDeviceIdOf(StatDeviceResolver $resolver, string $path): string
    {
        $deviceId = $resolver->deviceIdOf($path);
        $this->assertNotNull(
            $deviceId,
            'The test machine must be able to identify ' . $path . ', or this file proves nothing.'
        );

        return $deviceId;
    }

    public function testTwoDirectoriesOnOneFilesystemGetTheSameAnswer(): void
    {
        $resolver = new StatDeviceResolver();

        $this->assertSame(
            $this->pinnedDeviceIdOf($resolver, $this->root),
            $resolver->deviceIdOf($this->root . '/nested/deeper'),
            'One filesystem must answer once, or the inventory would split it into several volumes.'
        );
    }

    /** A trailing slash is a spelling, not another disk. */
    public function testTheAnswerDoesNotDependOnHowThePathIsSpelled(): void
    {
        $resolver = new StatDeviceResolver();

        $this->assertSame(
            $this->pinnedDeviceIdOf($resolver, $this->root),
            $resolver->deviceIdOf($this->root . '/')
        );
    }

    /**
     * A local location an administrator has just declared has no folder
     * yet. It is still on a volume — the one its parent is on — and
     * answering null for it would put a brand-new location on a volume of
     * its own, beside the very directory it is about to be created inside.
     */
    public function testADirectoryThatDoesNotExistYetIsPlacedOnItsParentsVolume(): void
    {
        $resolver = new StatDeviceResolver();

        $this->assertSame(
            $this->pinnedDeviceIdOf($resolver, $this->root),
            $resolver->deviceIdOf($this->root . '/not-created-yet/photos'),
            'A declared folder not yet created sits on the volume it will be created on.'
        );
    }

    /**
     * A path that names a FILE rather than a directory is still placed —
     * an administrator who typed the path of a file instead of a folder
     * gets a location that fails its own health test, not a phantom volume
     * of its own next to the real one.
     *
     * The null answer this resolver can also give is not exercised here on
     * purpose: it needs `stat()` to fail on a path that exists, which
     * means `open_basedir` or an unreadable mount, and neither can be
     * arranged inside a test. What DEPENDS on that answer is covered where
     * it matters — {@see VolumeInventoryTest} feeds two nulls through a
     * stub and pins that they never merge into one volume.
     */
    public function testAPathNamingAFileIsPlacedOnTheFilesystemThatHoldsIt(): void
    {
        $file = $this->root . '/nested/typed-a-file-by-mistake.txt';
        file_put_contents($file, 'x');
        $resolver = new StatDeviceResolver();

        $this->assertSame($this->pinnedDeviceIdOf($resolver, $this->root), $resolver->deviceIdOf($file));

        unlink($file);
    }
}
