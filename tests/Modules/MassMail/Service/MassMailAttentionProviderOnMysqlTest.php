<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Tests\UsesProductionEngine;

/**
 * The stalled-mailing point on the complete production schema: its query
 * groups an email by its subject, which MySQL's ONLY_FULL_GROUP_BY judges
 * and SQLite does not.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class MassMailAttentionProviderOnMysqlTest extends MassMailAttentionProviderTest
{
    use UsesProductionEngine;

    protected function database(): \PDO
    {
        return $this->productionEngine();
    }
}
