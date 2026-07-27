<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Repository;

use Core\Security\EncryptionService;
use Modules\Gallery\Repository\S3SecretRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * @group database
 */
class S3SecretRepositoryTest extends TestCase
{
    private S3SecretRepository $repository;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new S3SecretRepository($pdo, $encryption);
    }

    public function testGetReturnsNullWhenNeverSet(): void
    {
        $this->assertNull($this->repository->get());
    }

    public function testSetThenGetRoundTripsDecrypted(): void
    {
        $this->repository->set('super-secret-key');

        $this->assertSame('super-secret-key', $this->repository->get());
    }

    public function testSetTwiceOverwritesThePreviousValue(): void
    {
        $this->repository->set('first-secret');
        $this->repository->set('second-secret');

        $this->assertSame('second-secret', $this->repository->get());
    }

    public function testClearRemovesTheSecret(): void
    {
        $this->repository->set('a-secret');

        $this->repository->clear();

        $this->assertNull($this->repository->get());
    }
}
