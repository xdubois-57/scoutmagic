<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Page;

use Core\Exception\UserFacingException;

/**
 * A page could not be written, and the message is the French sentence the
 * screen shows.
 *
 * Carrying the user-facing wording on the exception rather than a code
 * the controller translates keeps the reason and its explanation in one
 * place — the service is where the refusal is decided, so it is where
 * the sentence belongs.
 *
 * {@see UserFacingException} is a claim about every message this class is
 * ever constructed with, so here is the check it stands on: each of the
 * eight `throw new` sites in {@see TextPageService} passes a French
 * literal — « Choisissez une colonne pour cette section. », « Le titre de
 * la page est obligatoire. » — naming only what the person filling in the
 * form can see. None names a column, a table, a class or a path, and none
 * of them is built from another exception's `getMessage()`, which is the
 * laundering the marker's own docblock warns about.
 */
final class TextPageException extends \RuntimeException implements UserFacingException
{
}
