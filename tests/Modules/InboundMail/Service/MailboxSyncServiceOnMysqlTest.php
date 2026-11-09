<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Tests\UsesProductionEngine;

/** The full sync and attachment pipeline against the production schema. */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailboxSyncServiceOnMysqlTest extends MailboxSyncServiceTest
{
    use UsesProductionEngine;

    protected function database(): \PDO
    {
        return $this->productionEngine();
    }
}
