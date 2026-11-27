<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Archive;

/**
 * A member the decompressor gives up on, or one read to its end that does
 * not match the CRC-32 its archive declares (#1238): the archive is
 * damaged, not merely over a limit.
 *
 * Still an {@see ArchiveRejectedException}, so every caller that refuses an
 * archive refuses this one. A caller that leaves a member out for a reason
 * of its own — an image over its size, say — catches this first and lets
 * it through: a damaged archive is never quietly a smaller one.
 */
final class ArchiveDamagedException extends ArchiveRejectedException
{
}
