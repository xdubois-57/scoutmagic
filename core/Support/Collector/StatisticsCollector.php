<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Statistics\StatisticsPayloadBuilder;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;

/**
 * `statistics.json` — exactly the same document the daily report would
 * transmit (Core\Statistics\StatisticsPayloadBuilder), with the values it
 * has at the moment of generation.
 *
 * Produced **even when reporting is disabled**, and that is a rule rather
 * than an accident of how this class happens to be written: it calls the
 * builder without ever consulting `statistics_enabled`, on purpose.
 * `statistics_enabled` withdraws consent to the AUTOMATIC daily send, and
 * to nothing else. Somebody generating a support package is asking for
 * help and triggering the transmission themselves — emptying the archive
 * because a scheduled task is off would answer a question nobody asked,
 * and would leave a maintainer diagnosing an installation they cannot see.
 * `Tests\Core\Support\Collector\StatisticsCollectorTest` pins both
 * halves.
 *
 * It carries no secret, by construction — the secret is never a payload
 * field.
 */
class StatisticsCollector implements SupportCollectorInterface
{
    public function __construct(private StatisticsPayloadBuilder $payloadBuilder)
    {
    }

    public function name(): string
    {
        return 'statistics';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $context->addFileFromContent('statistics.json', $this->payloadBuilder->buildJson());
    }
}
