<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

use Core\Exception\UserFacingException;

/**
 * A refused finance operation — a validation rule, a missing account, a bank
 * statement that does not match. Part of the module's PUBLIC contract
 * (ARCHITECTURE.md §7.5): the Api interfaces declare it in their @throws
 * and a consuming module that must catch it — rental's payment service
 * does — needs it in the `Api\` namespace, the same precedent as
 * LlmConnector\Api\LlmException and Gallery\Api\GalleryException.
 * Marked {@see UserFacingException}: every
 * message is a French sentence written for the intendant, naming only things
 * visible on the page (an amount, an IBAN's last four digits, a category).
 */
class FinanceException extends \RuntimeException implements UserFacingException
{
}
