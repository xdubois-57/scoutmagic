<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Exception\UserFacingException;

/**
 * A refused address import. $errors carries EVERY structural problem
 * found at once — a misspelled header, a missing column, a file that is
 * not a spreadsheet — so the chief fixes the file once rather than one
 * error at a time.
 *
 * Structural problems refuse the WHOLE file, and nothing is written.
 * A bad *address* on one line is a different thing and is not this: it is
 * reported alongside the preview, the other lines pass, and only the
 * confirmation writes anything.
 *
 * Marked {@see UserFacingException}: both $message and every entry of
 * $errors are French sentences naming a line or a column of the chief's
 * own spreadsheet, and nothing internal.
 */
class ListAddressImportException extends \Exception implements UserFacingException
{
    /**
     * @param string[] $errors
     */
    public function __construct(
        public readonly array $errors,
        string $message = 'Le fichier n\'a pas été accepté.'
    ) {
        parent::__construct($message);
    }
}
