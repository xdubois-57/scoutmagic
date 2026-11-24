<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * What a request hands to `Core\Http\AfterResponse` runs only if somebody
 * flushes it (issue #1267). The sign-in e-mails ride on it, so a flush that is
 * never reached is an e-mail that is never sent, with nothing journaled.
 *
 * `public/index.php` flushes in two places, and both are pinned here: right
 * after the response is sent, and from a shutdown function, which is what still
 * runs when the client drops the connection, when the script calls `exit` or
 * when a fatal error ends it before the first.
 */
class AfterResponseIsFlushedTest extends TestCase
{
    private const FRONT_CONTROLLER = __DIR__ . '/../../public/index.php';

    private string $source;

    protected function setUp(): void
    {
        $this->source = (string) file_get_contents(self::FRONT_CONTROLLER);
    }

    public function testTheQueueIsFlushedRightAfterTheResponseIsSent(): void
    {
        $send = strpos($this->source, "\$response->send();\n\\Core\\Debug\\RequestTimeline::mark('response_send_done');");
        $flush = strpos($this->source, "\$afterResponse->flush();\n", (int) $send);

        $this->assertNotFalse($send, 'public/index.php no longer sends the response where this test expects.');
        $this->assertNotFalse($flush, 'public/index.php never flushes the AfterResponse queue after sending the response.');
        $this->assertLessThan(3000, $flush - $send, 'The flush must follow the send directly, not wait behind other work.');
    }

    public function testAShutdownFunctionFlushesWhatTheNormalPathNeverReached(): void
    {
        $this->assertMatchesRegularExpression(
            '/register_shutdown_function\(static function \(\) use \(\$afterResponse\): void \{\s*\$afterResponse->flush\(\);/',
            $this->source,
            'A dropped connection, an exit or a fatal error before the normal flush would lose the queued e-mail.'
        );
    }

    public function testTheQueueIsBuiltBeforeTheServicesThatReceiveIt(): void
    {
        $built = strpos($this->source, 'new \\Core\\Http\\AfterResponse()');
        $given = strpos($this->source, '$authService->setAfterResponse($afterResponse);');

        $this->assertNotFalse($built);
        $this->assertNotFalse($given);
        $this->assertLessThan($given, $built);
    }
}
