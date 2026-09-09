<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage;

/**
 * Why a directory tree is being walked — which decides two behaviours that
 * always move together.
 *
 * They were two independent booleans once, and that was the bug: a walk
 * set up for a *measurement* was reused to build a backup archive, so the
 * archive quietly inherited the measurement's leniency. It could then
 * close successfully while missing a whole unreadable subtree, with
 * nothing logged anywhere — discovered only on the day a rollback needed
 * the missing files. One parameter naming the intent makes that mistake
 * impossible to make by omission.
 */
enum DirectoryWalk
{
    /**
     * Measuring how much room something takes.
     *
     * Symbolic links are **not** followed: a link to a file inside the
     * tree double-counts it, and a link to one outside counts bytes that
     * are not on this account's quota at all.
     *
     * An unreadable subdirectory is **skipped**, not fatal: this feeds a
     * warning and a refusal-to-write, and neither is worth turning a
     * configuration page into a 500 over one directory whose permissions
     * are wrong.
     */
    case Measurement;

    /**
     * Collecting the files that will go into an archive.
     *
     * Symbolic links **are** followed — links to directories included,
     * which is the case that matters and the one this promise silently
     * failed at first. A backup is not the place to start leaving out
     * what somebody's host symlinked elsewhere, and on shared hosting the
     * thing symlinked onto another volume is a whole directory
     * (`storage/gallery`), not a file. Making that true needs
     * `FilesystemIterator::FOLLOW_SYMLINKS` on the iterator itself, and
     * makes cycles reachable, so {@see DirectorySize} enters each
     * directory once by resolved path.
     *
     * An unreadable subdirectory is **fatal**, which is also what it has
     * always been. A backup that skips what it cannot read is worse than
     * one that fails, because only the failure is visible before the
     * restore.
     */
    case Archive;

    public function followsLinks(): bool
    {
        return $this === self::Archive;
    }

    public function failsOnUnreadable(): bool
    {
        return $this === self::Archive;
    }
}
