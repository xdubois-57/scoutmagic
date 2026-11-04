<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
declare(strict_types=1);

namespace Modules\LlmConnector\Provider;

/** Distinguish established capabilities from transient failures and explicit refusals. */
enum ModelProbeStatus: string
{
    case VISION_VERIFIED = 'vision_verified';
    case VISION_NOT_VERIFIED = 'vision_not_verified';
    case CHAT_UNSUPPORTED = 'chat_unsupported';
    case UNVERIFIED = 'unverified';
}
