<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\FlashMessage;
use PHPUnit\Framework\TestCase;

class FlashMessageTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        @session_start();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function testSetThenGetReturnsMessage(): void
    {
        FlashMessage::set('success', 'Test message');

        $flash = FlashMessage::get();

        $this->assertNotNull($flash);
        $this->assertSame('success', $flash['type']);
        $this->assertSame('Test message', $flash['message']);
    }

    public function testGetClearsMessage(): void
    {
        FlashMessage::set('error', 'Error message');

        FlashMessage::get();
        $secondCall = FlashMessage::get();

        $this->assertNull($secondCall);
    }

    public function testGetReturnsNullWhenNoMessageSet(): void
    {
        $result = FlashMessage::get();

        $this->assertNull($result);
    }

    public function testDifferentTypes(): void
    {
        FlashMessage::set('warning', 'A warning');

        $flash = FlashMessage::get();

        $this->assertSame('warning', $flash['type']);
        $this->assertSame('A warning', $flash['message']);
    }

    /**
     * Regression: public/index.php calls session_write_close() early
     * (before the DB connection/migration) to avoid holding the session
     * file lock for the whole request — after that, session_status()
     * reports PHP_SESSION_NONE for the rest of the request even though
     * $_SESSION itself stays populated. get() must not gate its read on
     * session_status() === PHP_SESSION_ACTIVE, or a flash message set on
     * a previous request would never display on the request that follows
     * it (dispatch always runs after the early close on a real request).
     */
    public function testGetStillWorksAfterSessionWriteCloseLikeARealRequest(): void
    {
        FlashMessage::set('success', 'Compte créé avec succès');
        session_write_close();

        $this->assertSame(PHP_SESSION_NONE, session_status());

        $flash = FlashMessage::get();

        $this->assertNotNull($flash);
        $this->assertSame('success', $flash['type']);

        // get() already reopened the session itself to persist the
        // unset() — only reopen here if it somehow didn't, so
        // tearDown()'s session_destroy() has an active session to act on.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
