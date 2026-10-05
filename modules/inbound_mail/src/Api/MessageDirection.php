<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * Whether a message came INTO a box or was sent FROM it (#720, step 3).
 *
 * Everything the module ever read was received, and every rule a consumer
 * applies reads the sender as the person writing to the unit. A message
 * read in the box's « Envoyés » folder turns that around: the unit wrote
 * it, and the person it concerns is among its recipients. Hence a field
 * rather than a guess, and only consumers that declare they understand it
 * (`HandlesOutboundMail`) ever see a sent one.
 */
enum MessageDirection: string
{
    case RECEIVED = 'received';
    case SENT = 'sent';
}
