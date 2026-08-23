<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

/**
 * A backup or restore that could not be carried out, stated in French for
 * the admin who triggered it.
 *
 * Marked {@see \Core\Exception\UserFacingException}, which is a claim
 * about EVERY message this class is constructed with — so a wrap site must
 * never fold `$e->getMessage()` of the underlying ZipArchive/PDO/filesystem
 * failure into the sentence. Pass the cause as `$previous` instead: the
 * detail still reaches the journal and the stack trace, and the visitor
 * still gets a sentence someone wrote for them.
 */
class BackupException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
