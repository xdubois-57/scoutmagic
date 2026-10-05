<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * A consumer that wants the mail SENT from a box, not only the mail it
 * received (#720, step 3).
 *
 * Opt-in, and declared — the same optional-companion shape as
 * `PruningConsumerInterface`. A consumer that does not implement it is
 * never handed a message whose `direction` is `MessageDirection::SENT`:
 * its rules read the sender as the person writing to the unit, and on a
 * sent message the sender IS the unit. Declaring it is also what makes a
 * box's « Envoyés » folder read at all: a box opened to no such consumer
 * reads its configured folders and nothing else.
 *
 * **A sent message is stored only when one of these consumers files it.**
 * The reverse of received mail, which is kept whatever the analysis said:
 * the unit's sent mail is the unit's business, and only what concerns one
 * of a module's objects has any reason to be on this site.
 */
interface HandlesOutboundMail
{
}
