<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Service;

use Core\Security\EncryptionService;
use Modules\SosStaff\Provider\PhoneProviderInterface;
use Modules\SosStaff\Provider\ProviderException;
use Modules\SosStaff\Repository\ProviderCredentialRepository;
use Modules\SosStaff\Service\ProviderConfigService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;

/**
 * @group database
 */
class ProviderConfigServiceTest extends TestCase
{
    private \PDO $pdo;
    private ProviderCredentialRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SosStaffTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new ProviderCredentialRepository($this->pdo, $encryption);
    }

    private function serviceWithTransport(\Closure $transport): ProviderConfigService
    {
        return new ProviderConfigService($this->repository, $transport);
    }

    public function testGetProviderOptionsMarksOvhAvailableAndOthersPlanned(): void
    {
        $service = new ProviderConfigService($this->repository);

        $options = $service->getProviderOptions();

        $ovh = array_values(array_filter($options, fn($o) => $o['id'] === 'ovh'))[0];
        $twilio = array_values(array_filter($options, fn($o) => $o['id'] === 'twilio'))[0];
        $this->assertTrue($ovh['is_available']);
        $this->assertFalse($twilio['is_available']);
    }

    public function testSaveOvhCredentialsRejectsEmptyValues(): void
    {
        $service = new ProviderConfigService($this->repository);

        $this->expectException(ProviderException::class);
        $service->saveOvhCredentials('', 'secret');
    }

    public function testSaveOvhCredentialsPersistsAndPreservesLaterMerges(): void
    {
        $service = new ProviderConfigService($this->repository);

        $service->saveOvhCredentials('AK123', 'AS456');

        $credential = $service->getOvhCredential();
        $this->assertSame('AK123', $credential->config['application_key']);
        $this->assertSame('AS456', $credential->config['application_secret']);
    }

    public function testGenerateConsumerKeyRequiresCredentialsFirst(): void
    {
        $service = new ProviderConfigService($this->repository);

        $this->expectException(ProviderException::class);
        $service->generateConsumerKey();
    }

    public function testGenerateConsumerKeyStoresPendingUnvalidatedKey(): void
    {
        $service = $this->serviceWithTransport(function (string $method, string $url) {
            if (str_ends_with($url, '/auth/credential')) {
                return ['status' => 200, 'body' => '{"consumerKey":"CK789","validationUrl":"https://ovh.example/auth/CK789"}'];
            }
            return ['status' => 404, 'body' => '{}'];
        });
        $service->saveOvhCredentials('AK123', 'AS456');

        $validationUrl = $service->generateConsumerKey();

        $this->assertSame('https://ovh.example/auth/CK789', $validationUrl);
        $credential = $service->getOvhCredential();
        $this->assertSame('CK789', $credential->config['consumer_key']);
        $this->assertFalse($credential->config['consumer_key_validated']);
    }

    public function testGenerateConsumerKeyWrapsOvhFailureAsProviderException(): void
    {
        $service = $this->serviceWithTransport(fn() => ['status' => 400, 'body' => '{"message":"Invalid key"}']);
        $service->saveOvhCredentials('AK123', 'AS456');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Invalid key');
        $service->generateConsumerKey();
    }

    public function testValidateConsumerKeyMarksConfigValidatedOnSuccess(): void
    {
        $service = $this->serviceWithTransport(function (string $method, string $url) {
            if (str_ends_with($url, '/auth/time')) {
                return ['status' => 200, 'body' => (string) time()];
            }
            return ['status' => 200, 'body' => '["ba-1"]'];
        });
        $service->saveOvhCredentials('AK123', 'AS456');
        $this->repository->save('ovh', array_merge($service->getOvhCredential()->config, ['consumer_key' => 'CK789']));

        $result = $service->validateConsumerKey();

        $this->assertTrue($result);
        $this->assertTrue($service->getOvhCredential()->config['consumer_key_validated']);
    }

    public function testValidateConsumerKeyFailsWhenNotYetApprovedOnOvhSide(): void
    {
        $service = $this->serviceWithTransport(fn() => ['status' => 403, 'body' => '{"message":"Not validated yet"}']);
        $service->saveOvhCredentials('AK123', 'AS456');
        $this->repository->save('ovh', array_merge($service->getOvhCredential()->config, ['consumer_key' => 'CK789']));

        $this->expectException(ProviderException::class);
        $service->validateConsumerKey();
    }

    public function testListOvhLinesRequiresValidatedConsumerKey(): void
    {
        $service = new ProviderConfigService($this->repository);
        $this->repository->save('ovh', [
            'application_key' => 'AK123',
            'application_secret' => 'AS456',
            'consumer_key' => 'CK789',
            'consumer_key_validated' => false,
        ]);

        $this->expectException(ProviderException::class);
        $service->listOvhLines();
    }

    public function testListOvhLinesReturnsLinesWhenValidated(): void
    {
        $service = $this->serviceWithTransport(function (string $method, string $url) {
            if (str_ends_with($url, '/auth/time')) {
                return ['status' => 200, 'body' => (string) time()];
            }
            if (str_ends_with($url, '/telephony')) {
                return ['status' => 200, 'body' => '["ba-1"]'];
            }
            if (str_ends_with($url, '/telephony/ba-1/line')) {
                return ['status' => 200, 'body' => '["0033100000001"]'];
            }
            return ['status' => 404, 'body' => '{}'];
        });
        $this->repository->save('ovh', [
            'application_key' => 'AK123',
            'application_secret' => 'AS456',
            'consumer_key' => 'CK789',
            'consumer_key_validated' => true,
        ]);

        $lines = $service->listOvhLines();

        $this->assertCount(1, $lines);
        $this->assertSame('ba-1', $lines[0]->billingAccount);
    }

    public function testSelectOvhLinePersistsSosNumberAndActivatesProvider(): void
    {
        $service = new ProviderConfigService($this->repository);
        $service->saveOvhCredentials('AK123', 'AS456');

        $service->selectOvhLine('ba-1', '0033100000001');

        $this->assertSame('0033100000001', $service->getSosNumber());
        $active = $this->repository->findActive();
        $this->assertSame('ovh', $active->provider);
    }

    public function testGetActiveProviderReturnsNullWhenNothingConfigured(): void
    {
        $service = new ProviderConfigService($this->repository);

        $this->assertNull($service->getActiveProvider());
    }

    public function testGetActiveProviderReturnsNullWhenConfigIsIncomplete(): void
    {
        $service = new ProviderConfigService($this->repository);
        $service->saveOvhCredentials('AK123', 'AS456');
        // No line selected yet, so never activated.

        $this->assertNull($service->getActiveProvider());
    }

    public function testGetActiveProviderReturnsAWorkingProviderAfterFullSetup(): void
    {
        $service = new ProviderConfigService($this->repository);
        $service->saveOvhCredentials('AK123', 'AS456');
        $this->repository->save('ovh', array_merge($service->getOvhCredential()->config, ['consumer_key' => 'CK789']));
        $service->selectOvhLine('ba-1', '0033100000001');

        $provider = $service->getActiveProvider();

        $this->assertInstanceOf(PhoneProviderInterface::class, $provider);
    }

    public function testTestConnectionThrowsWhenNoProviderActive(): void
    {
        $service = new ProviderConfigService($this->repository);

        $this->expectException(ProviderException::class);
        $service->testConnection();
    }
}
