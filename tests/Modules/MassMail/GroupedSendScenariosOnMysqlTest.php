<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail;

use Tests\UsesProductionEngine;

/** Consent and delivery selection on the complete production schema. */
#[\PHPUnit\Framework\Attributes\Group('database')]
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class GroupedSendScenariosOnMysqlTest extends GroupedSendScenariosTest
{
    use UsesProductionEngine;

    protected function database(): \PDO
    {
        return $this->productionEngine();
    }
}
