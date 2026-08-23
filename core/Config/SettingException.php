<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Config;

/**
 * A refused settings write — an unknown key, a read-only setting, a value
 * that does not match the setting's declared type.
 *
 * Marked {@see \Core\Exception\UserFacingException}: these messages are
 * rendered straight into the settings dialog
 * (Core\Http\Controller\SettingsController::update()), which is why they
 * are French sentences rather than the English developer notes they used
 * to be.
 */
class SettingException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
