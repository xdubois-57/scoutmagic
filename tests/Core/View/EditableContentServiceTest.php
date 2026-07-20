<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use PHPUnit\Framework\TestCase;

class EditableContentServiceTest extends TestCase
{
    private \PDO $pdo;
    private EditableContentService $service;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec("CREATE TABLE editable_contents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            content_key TEXT NOT NULL UNIQUE,
            content_type TEXT NOT NULL,
            content_value TEXT,
            module_id TEXT,
            modified_at TEXT,
            modified_by INTEGER
        )");

        $repo = new EditableContentRepository($this->pdo);
        $this->service = new EditableContentService($repo);
    }

    public function testGetReturnsDefaultWhenKeyDoesNotExist(): void
    {
        $result = $this->service->get('nonexistent', 'default value');
        $this->assertSame('default value', $result);
    }

    public function testSetThenGetRoundTripsForRichText(): void
    {
        $this->service->set('test.key', '<p>Hello world</p>', 'rich_text', 1);
        $result = $this->service->get('test.key');
        $this->assertStringContainsString('Hello world', $result);
    }

    public function testSetSanitizesHtmlBeforeStoring(): void
    {
        $this->service->set('test.xss', '<p>Safe</p><script>alert(1)</script>', 'rich_text', 1);
        $result = $this->service->get('test.xss');
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('Safe', $result);
    }

    public function testSetThenGetForImageType(): void
    {
        $this->service->set('test.image', '42', 'image', 1);
        $result = $this->service->get('test.image');
        $this->assertSame('42', $result);
    }

    public function testUpdateOverwritesPreviousValue(): void
    {
        $this->service->set('test.update', '<p>First</p>', 'rich_text', 1);
        $this->service->set('test.update', '<p>Second</p>', 'rich_text', 1);
        $result = $this->service->get('test.update');
        $this->assertStringContainsString('Second', $result);
        $this->assertStringNotContainsString('First', $result);
    }

    public function testGetReturnsNullDefaultWhenKeyDoesNotExist(): void
    {
        $result = $this->service->get('missing');
        $this->assertNull($result);
    }

    public function testDeleteRemovesTheKey(): void
    {
        $this->service->set('test.delete', '<p>Bye</p>', 'rich_text', 1);

        $this->service->delete('test.delete');

        $this->assertNull($this->service->get('test.delete'));
    }

    public function testDeleteOnUnknownKeyIsANoOp(): void
    {
        $this->service->delete('never.existed');

        $this->assertNull($this->service->get('never.existed'));
    }

    public function testGetLastUpdatedReturnsNullForUnknownKey(): void
    {
        $this->assertNull($this->service->getLastUpdated('never.existed'));
    }

    public function testGetLastUpdatedReturnsTimestampAfterSet(): void
    {
        $this->service->set('test.timestamp', '<p>Hello</p>', 'rich_text', 1);

        $this->assertNotNull($this->service->getLastUpdated('test.timestamp'));
    }

    public function testReSavingIdenticalContentDoesNotChangeLastUpdated(): void
    {
        $this->service->set('test.stable', '<p>Stable</p>', 'rich_text', 1);
        $first = $this->service->getLastUpdated('test.stable');

        sleep(1);
        $this->service->set('test.stable', '<p>Stable</p>', 'rich_text', 1);
        $second = $this->service->getLastUpdated('test.stable');

        $this->assertSame($first, $second);
    }

    public function testSavingDifferentContentUpdatesLastUpdated(): void
    {
        $this->service->set('test.changing', '<p>First</p>', 'rich_text', 1);
        $first = $this->service->getLastUpdated('test.changing');

        sleep(1);
        $this->service->set('test.changing', '<p>Second</p>', 'rich_text', 1);
        $second = $this->service->getLastUpdated('test.changing');

        $this->assertNotSame($first, $second);
    }
}
