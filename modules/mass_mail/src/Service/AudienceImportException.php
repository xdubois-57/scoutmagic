<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Exception\UserFacingException;

/**
 * A refused mail-merge import (module spec: the whole file is rejected,
 * never partially imported). $errors carries EVERY problem found — one
 * entry per offending line/structure issue — so the chief fixes the file
 * once, not one error at a time.
 *
 * Marked {@see UserFacingException}: both $message and every entry of
 * $errors are French sentences naming a line number and a column of the
 * chief's own spreadsheet.
 */
class AudienceImportException extends \Exception implements UserFacingException
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
