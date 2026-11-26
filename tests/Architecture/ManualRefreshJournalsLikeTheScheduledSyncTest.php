<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * « Relever le courrier maintenant » writes the same journal as the
 * scheduled relève (issue #1406).
 *
 * The two paths build their own `MailboxSyncService`, in two composition
 * roots, and the manual one was built with `analysisJournal: null` — so the
 * button a superadmin presses to find out why a message produced nothing
 * stored its messages and said nothing about them. Nothing in the services
 * can tell the difference: the omission is in the wiring, so the wiring is
 * what is pinned.
 */
class ManualRefreshJournalsLikeTheScheduledSyncTest extends TestCase
{
    public function testTheManualRefreshBuildsItsSyncServiceWithAnAnalysisJournal(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../public/index.php');

        $start = strpos($source, '$inboundManualRefresh = new \\Modules\\InboundMail\\Service\\ManualRefreshService(');
        $this->assertNotFalse($start, 'public/index.php no longer builds the manual refresh where this test expects.');

        $end = strpos($source, '$settingService,', (int) $start);
        $this->assertNotFalse($end);
        $construction = substr($source, (int) $start, (int) $end - (int) $start);

        $this->assertStringContainsString(
            'new \\Modules\\InboundMail\\Service\\AnalysisJournal($journalService)',
            $construction,
            'The manual refresh must journal what it stores, as the scheduled relève does.'
        );
    }

    public function testTheScheduledSyncJournalsToo(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../public/scheduler-bootstrap.php');

        $this->assertStringContainsString(
            'new \\Modules\\InboundMail\\Service\\AnalysisJournal($journalService),',
            $source,
            'The scheduled relève is the reference the manual one is held to.'
        );
    }
}
