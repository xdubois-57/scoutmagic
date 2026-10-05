<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

use Modules\InboundMail\Api\HandlesOutboundMail;

/**
 * A `FakeMessageConsumer` that declares it wants the box's sent mail too
 * (#720) — the Locations' position, without the Locations.
 */
class FakeOutboundConsumer extends FakeMessageConsumer implements HandlesOutboundMail
{
}
