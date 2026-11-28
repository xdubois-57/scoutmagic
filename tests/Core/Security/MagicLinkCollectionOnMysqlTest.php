<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Tests\UsesProductionEngine;

/**
 * Issue #1530 on the production engine: the second collection comes from a
 * second connection, as a second request would.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MagicLinkCollectionOnMysqlTest extends MagicLinkCollectionTest
{
    use UsesProductionEngine;

    protected function database(): \PDO
    {
        return $this->productionEngine();
    }

    protected function otherRequestsConnection(): \PDO
    {
        return self::productionEngineConnection(self::$productionEngineDatabase)->getPdo();
    }
}
