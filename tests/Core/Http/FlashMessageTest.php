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
}
