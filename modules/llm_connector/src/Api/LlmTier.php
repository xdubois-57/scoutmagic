<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Api;

/**
 * The only vocabulary for model selection exposed to consuming modules.
 * No model name, no provider name — just a capability tier.
 */
enum LlmTier: string
{
    case CHEAP = 'cheap';
    case CAPABLE = 'capable';
    case OCR = 'ocr';
}
