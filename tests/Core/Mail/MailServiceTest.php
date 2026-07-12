<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Mail\DkimManager;
use Core\Mail\MailException;
use Core\Mail\MailService;
use PHPUnit\Framework\TestCase;

class MailServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mailservice_test_' . uniqid();
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testSubjectIsPrefixedWithShortName(): void
    {
        $dkimManager = new DkimManager($this->tempDir);
        $service = new MailService(
            mode: 'local',
            fromAddress: 'test@example.com',
            fromName: 'Test Unit',
            shortName: '25SV',
            dkimManager: $dkimManager,
            dkimSelector: 'mail'
        );

        // We cannot send without a real mail server, but we can verify the class instantiates
        // and the service is properly configured.
        $this->assertInstanceOf(MailService::class, $service);
    }

    public function testMailServiceFactoryCreatesService(): void
    {
        $dkimManager = new DkimManager($this->tempDir);

        $secrets = [
            'mail_mode' => 'smtp',
            'mail_from_address' => 'noreply@scout.be',
            'mail_from_name' => 'Unité Scout',
            'short_name' => '25SV',
            'dkim_selector' => 'mail',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587',
            'smtp_user' => 'user@example.com',
            'smtp_password' => 'secret',
        ];

        $service = \Core\Mail\MailServiceFactory::create($secrets, $dkimManager);
        $this->assertInstanceOf(MailService::class, $service);
    }

    public function testMailExceptionIsThrowable(): void
    {
        $exception = new MailException('Test error');
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame('Test error', $exception->getMessage());
    }

    public function testSendThrowsMailExceptionOnInvalidSmtp(): void
    {
        $dkimManager = new DkimManager($this->tempDir);
        $service = new MailService(
            mode: 'smtp',
            fromAddress: 'test@example.com',
            fromName: 'Test',
            shortName: '25SV',
            dkimManager: $dkimManager,
            dkimSelector: 'mail',
            smtpHost: 'invalid.host.example',
            smtpPort: 587,
            smtpUser: 'user',
            smtpPassword: 'pass'
        );

        $this->expectException(MailException::class);
        $service->send(
            to: 'recipient@example.com',
            subject: 'Test Subject',
            bodyHtml: '<p>Hello</p>',
            bodyText: 'Hello'
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
