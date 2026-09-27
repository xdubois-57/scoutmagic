<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Api\FinanceException;

/**
 * No format recognized the uploaded file. The import screen answers it —
 * and only it — by offering the list of formats to choose from by hand: a
 * choice always on screen would be taken out of habit, wrongly included.
 */
final class StatementFormatNotRecognized extends FinanceException
{
    public function __construct()
    {
        parent::__construct("Nous n'avons pas reconnu ce fichier. Choisissez le format manuellement, puis déposez-le à nouveau.");
    }
}
