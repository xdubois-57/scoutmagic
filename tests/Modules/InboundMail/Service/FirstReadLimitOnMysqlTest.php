<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Tests\UsesProductionEngine;

/**
 * The first-read limit against the production schema — where the pending
 * list of 5 000 UIDs has to fit the column that holds it.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FirstReadLimitOnMysqlTest extends FirstReadLimitTest
{
    use UsesProductionEngine;

    protected function database(): \PDO
    {
        return $this->productionEngine();
    }
}
