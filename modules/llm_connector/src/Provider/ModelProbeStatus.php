<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
declare(strict_types=1);

namespace Modules\LlmConnector\Provider;

/** A successful HTTP response alone does not establish that the image was read. */
enum ModelProbeStatus: string
{
    case VISION_VERIFIED = 'vision_verified';
    case CHAT_UNSUPPORTED = 'chat_unsupported';
    case UNVERIFIED = 'unverified';
}
