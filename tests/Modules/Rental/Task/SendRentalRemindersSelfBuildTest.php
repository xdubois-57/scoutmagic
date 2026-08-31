<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Module\ModuleManager;
use Core\Scheduler\TaskCapabilities;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\Finance\Api\FinanceAccountInterface;
use Modules\Finance\Api\SepaQrCodeInterface;
use Modules\Finance\Api\StructuredCommunicationInterface;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\Rental\Service\RentalPaymentService;
use Modules\Rental\Service\RentalReminderService;
use Modules\Rental\Service\RentalRetentionService;
use Modules\Rental\Task\PurgeRentalBookingsHandler;
use Modules\Rental\Task\SendRentalRemindersHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The §5ter fix, proven at the object graph: the reminder pass and the
 * retention purge build ONE service graph from the TaskContext, and that
 * graph carries the Finance and inbound-mail halves exactly when the
 * capabilities resolve — no matter which entry point fired the task.
 * Before this, `public/cron.php` assembled the reminder service without
 * Finance while the web path assembled it with: the same relance said
 * nothing about money under a real crontab and everything about it on the
 * web path.
 */
class SendRentalRemindersSelfBuildTest extends TestCase
{
    private function contextWith(bool $financeAndInboundMail): TaskContext
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('getEnabledModuleIds')
            ->willReturn($financeAndInboundMail ? ['rental', 'finance', 'inbound_mail'] : ['rental']);
        $capabilities = new TaskCapabilities($moduleManager);
        $capabilities->register(ExpectedReceivableInterface::class, 'finance', fn (): object => $this->createMock(ExpectedReceivableInterface::class));
        $capabilities->register(StructuredCommunicationInterface::class, 'finance', fn (): object => $this->createMock(StructuredCommunicationInterface::class));
        $capabilities->register(SepaQrCodeInterface::class, 'finance', fn (): object => $this->createMock(SepaQrCodeInterface::class));
        $capabilities->register(FinanceAccountInterface::class, 'finance', fn (): object => $this->createMock(FinanceAccountInterface::class));
        $capabilities->register(InboundMailInterface::class, 'inbound_mail', fn (): object => $this->createMock(InboundMailInterface::class));

        return new TaskContext(
            Connection::withPdo($pdo),
            $encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($pdo)),
            new SettingService(new SettingRepository($pdo)),
            new UserAccountRepository($pdo, $encryption),
            sys_get_temp_dir(),
            null,
            $capabilities
        );
    }

    /**
     * @return mixed the private property, read through reflection
     */
    private static function read(object $object, string $property): mixed
    {
        return (new \ReflectionProperty($object::class, $property))->getValue($object);
    }

    private function builtReminderService(TaskContext $context): RentalReminderService
    {
        $method = new \ReflectionMethod(SendRentalRemindersHandler::class, 'selfBuiltService');

        return $method->invoke(new SendRentalRemindersHandler(), $context);
    }

    public function testTheReminderServiceCarriesTheMoneyHalfWhenFinanceResolves(): void
    {
        $service = $this->builtReminderService($this->contextWith(true));

        /** @var RentalPaymentService $payments */
        $payments = self::read($service, 'paymentService');
        $this->assertInstanceOf(RentalPaymentService::class, $payments);
        $this->assertNotNull(self::read($payments, 'receivables'));
        $this->assertNotNull(self::read($payments, 'communications'));
        $this->assertNotNull(self::read($payments, 'sepaQr'));
        $this->assertNotNull(self::read($payments, 'accounts'));
    }

    public function testTheReminderServiceStaysSilentAboutMoneyWhenFinanceDoesNot(): void
    {
        $service = $this->builtReminderService($this->contextWith(false));

        /** @var RentalPaymentService $payments */
        $payments = self::read($service, 'paymentService');
        $this->assertInstanceOf(RentalPaymentService::class, $payments);
        $this->assertNull(self::read($payments, 'receivables'), 'Without Finance the service says nothing about money rather than guessing.');
    }

    public function testTheReminderServiceCarriesEveryRentalInternalHalfEitherWay(): void
    {
        // The old cron construction was thinner than the web one beyond
        // Finance too (no document/stay service): the one construction
        // must carry everything the richer wiring carried.
        foreach ([true, false] as $financeAndInboundMail) {
            $service = $this->builtReminderService($this->contextWith($financeAndInboundMail));

            $this->assertNotNull(self::read($service, 'documentService'));
            $this->assertNotNull(self::read($service, 'stayService'));
            $this->assertNotNull(self::read($service, 'mailService'));
        }
    }

    public function testThePurgeServiceCarriesTheMailAndMoneyHalvesWhenTheyResolve(): void
    {
        $method = new \ReflectionMethod(PurgeRentalBookingsHandler::class, 'selfBuiltService');
        /** @var RentalRetentionService $with */
        $with = $method->invoke(new PurgeRentalBookingsHandler(), $this->contextWith(true));
        /** @var RentalRetentionService $without */
        $without = $method->invoke(new PurgeRentalBookingsHandler(), $this->contextWith(false));

        $this->assertNotNull(self::read($with, 'inboundMail'), 'A purged booking must lose its correspondence.');
        $this->assertNotNull(self::read($with, 'payments'));
        $this->assertNotNull(self::read($with, 'bookingAudit'));

        $this->assertNull(self::read($without, 'inboundMail'));
        /** @var RentalPaymentService $payments */
        $payments = self::read($without, 'payments');
        $this->assertNull(self::read($payments, 'receivables'));
    }
}
