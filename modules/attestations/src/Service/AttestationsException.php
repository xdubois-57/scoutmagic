<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Service;

use Core\Exception\UserFacingException;

/**
 * A refusal this module means, worded for the chef d'unité who is holding
 * the file. Same posture as Modules\Camps\Service\CampsException.
 *
 * Marked UserFacingException, which is a claim about EVERY message this
 * class is ever constructed with: French, a whole sentence, naming what the
 * reader deposited and what to do next — never a path, a class name, an SQL
 * fragment or a library's own English. A failure that cannot say that much
 * belongs in a plain RuntimeException wrapped through
 * Core\Exception\UserFacingMessage::from(), not in a shortened message here.
 */
class AttestationsException extends \RuntimeException implements UserFacingException
{
}
