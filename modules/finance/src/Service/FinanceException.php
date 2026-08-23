<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Exception\UserFacingException;

/**
 * A refused finance operation — a validation rule, a missing account, a bank
 * statement that does not match. Marked {@see UserFacingException}: every
 * message is a French sentence written for the intendant, naming only things
 * visible on the page (an amount, an IBAN's last four digits, a category).
 */
class FinanceException extends \RuntimeException implements UserFacingException
{
}
