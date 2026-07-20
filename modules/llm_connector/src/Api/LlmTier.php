<?php

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
