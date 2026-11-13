<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Finance;

use PHPUnit\Framework\TestCase;

/**
 * Where public/index.php stamps the campaign workbooks stored before they
 * had an owner (issue #1111) — read from the raw file, like
 * Tests\Core\File\FileOwnershipCheckerWiringTest, because nothing else can
 * exercise the composition root's statement order.
 *
 * What it protects (CodeRabbit on #1178): with the call inside
 * `if ($isEnabled('finance'))`, an installation whose finance module is
 * disabled at upgrade would keep its old workbooks without an owner, and
 * /files/{id}, which does not ask which module a file belongs to, would go
 * on serving them to every intendant. Stamped, they are refused to all
 * while the module — and its ownership checker — is away.
 */
final class CampaignWorkbookBackfillWiringTest extends TestCase
{
    private string $indexPhp;

    protected function setUp(): void
    {
        $this->indexPhp = (string) file_get_contents(dirname(__DIR__, 3) . '/public/index.php');
    }

    public function testTheBackfillRunsWhetherOrNotFinanceIsEnabled(): void
    {
        $call = strpos($this->indexPhp, 'new \\Modules\\Finance\\Service\\CampaignWorkbookOwnership(');
        $financeBlock = strpos($this->indexPhp, "if (\$isEnabled('finance')) {");
        $this->assertNotFalse($call, 'public/index.php no longer stamps the campaign workbooks');
        $this->assertNotFalse($financeBlock);

        $this->assertLessThan($financeBlock, $call, 'the backfill must run before, and outside, the finance block');
        $this->assertSame(1, substr_count($this->indexPhp, 'CampaignWorkbookOwnership('));
    }

    /**
     * Not under any module test either: the closest `if` before it must not
     * be an `$isEnabled(...)` that is still open.
     */
    public function testNoModuleTestEnclosesIt(): void
    {
        $call = (int) strpos($this->indexPhp, 'new \\Modules\\Finance\\Service\\CampaignWorkbookOwnership(');
        $lineStart = strrpos(substr($this->indexPhp, 0, $call), "\n");
        $this->assertNotFalse($lineStart);

        // At the composition root's top level: the statement starts at
        // column zero, which no line inside an `if` block does.
        $this->assertSame('(', substr($this->indexPhp, $lineStart + 1, 1));
    }
}
