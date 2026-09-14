<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Volume;

/**
 * Which filesystem a path is on — the one question {@see VolumeInventory}
 * asks the kernel, behind an interface so the grouping rule can be tested
 * without one.
 *
 * **Why this is a seam rather than a private method.** The rule worth
 * testing is « two directories on one device are one volume, and their
 * occupations add up on it » — and a test of that rule needs two paths on
 * two devices, which means mounting a filesystem. CI runners do not let a
 * test do that, so the alternative was to write the test against whatever
 * second device the machine happened to have (`/dev/shm`, usually) and
 * watch it turn into a test that proves nothing on the machine that does
 * not. Handing the answer to the inventory instead makes the rule
 * deterministic everywhere, and leaves the kernel's part —
 * {@see StatDeviceResolver} — a much smaller thing to check against real
 * paths.
 */
interface DeviceResolver
{
    /**
     * A stable identifier for the filesystem $path is on, or **null** when
     * the system will not say.
     *
     * Null means « cannot be proved to be any volume », never « the same
     * unknown volume as the last one »: see {@see VolumeInventory}, which
     * keeps two nulls apart precisely so that an unprovable pair is never
     * merged into one free-space figure.
     */
    public function deviceIdOf(string $path): ?string;
}
