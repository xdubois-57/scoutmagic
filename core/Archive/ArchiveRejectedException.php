<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Archive;

/**
 * An archive refused as a whole, with the reason written for the person
 * who sent it.
 *
 * Marked {@see \Core\Exception\UserFacingException}: every message is a
 * French sentence built in {@see BoundedZipReader} or {@see OpenedArchive},
 * and the only variable part any of them carries is the name of a member
 * of the visitor's OWN archive — something they can see, never a path of
 * this server. A library's own words never reach one: a failure inside
 * `ZipArchive` is re-stated here and nothing of it is copied.
 */
final class ArchiveRejectedException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
