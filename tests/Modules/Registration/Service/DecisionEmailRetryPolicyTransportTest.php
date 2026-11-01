<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\DkimManager;
use Core\Mail\MailException;
use Core\Mail\MailPurpose;
use Core\Mail\MailService;
use Core\Mail\MailTransportInterface;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\MailTransportChain;
use Core\Mail\Transport\ProviderConnections;
use Core\Mail\Transport\SendCounterRepository;
use Core\Mail\Transport\TransportConfigurator;
use Modules\Registration\Service\DecisionEmailRetryPolicy;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/** The real chain must not hide an earlier uncertain delivery behind its last refusal. */
final class DecisionEmailRetryPolicyTransportTest extends TestCase
{
    #[DataProvider('providerFailures')]
    public function testEveryAttemptMustBeAConfirmedTemporaryRefusal(
        string $firstReason,
        string $lastReason,
        bool $expected,
        bool $writeLastErrorInfo = true
    ): void {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $providers = new MailProviderRepository($pdo);
        $chains = new LaneChainRepository($pdo);
        $settings = new SettingService(new SettingRepository($pdo));
        $secrets = [];
        foreach (['first.test', 'second.test'] as $host) {
            $id = $providers->create($host, null, 50, 10);
            $prefix = ProviderConnections::prefixFor($id);
            $secrets[$prefix . '_host'] = $host;
            $secrets[$prefix . '_port'] = '587';
            $secrets[$prefix . '_user'] = 'fixture';
            $secrets[$prefix . '_password'] = 'fixture';
            $chains->append(MailLane::Transactional, $id, true);
        }
        $connections = new ProviderConnections($secrets);
        $delivery = new class ($firstReason, $lastReason, $writeLastErrorInfo) implements MailTransportInterface {
            /** @var list<string> */
            public array $attemptedHosts = [];

            public function __construct(
                private string $firstReason,
                private string $lastReason,
                private bool $writeLastErrorInfo
            ) {
            }

            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                $this->attemptedHosts[] = $mail->Host;
                $first = count($this->attemptedHosts) === 1;
                $reason = $first ? $this->firstReason : $this->lastReason;
                if ($first || $this->writeLastErrorInfo) {
                    $mail->ErrorInfo = $reason;
                }
                throw new \RuntimeException($reason);
            }
        };
        $chain = new MailTransportChain(
            new MailProviderDirectory($providers, $connections, $settings),
            $chains,
            new SendCounterRepository($pdo),
            new TransportConfigurator($connections),
            $delivery
        );
        $service = new MailService(
            mode: 'local',
            fromAddress: 'fixture@example.test',
            fromName: 'Fixture',
            shortName: 'Fixture',
            dkimManager: new DkimManager(sys_get_temp_dir()),
            dkimSelector: 'fixture',
            transport: $chain
        );

        try {
            $service->withoutDeferral()->send('recipient@example.test', 'Fixture', '<p>Fixture</p>', 'Fixture');
            $this->fail('Both simulated relays must refuse.');
        } catch (MailException $failure) {
            $this->assertSame(['first.test', 'second.test'], $delivery->attemptedHosts);
            $this->assertSame($expected, DecisionEmailRetryPolicy::isTemporary($failure));
            $this->assertCount(2, $failure->getAttemptFailures());
            $this->assertStringNotContainsString(
                'sensitive@example.test',
                implode(' ', $failure->getAttemptFailures())
            );
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2: bool, 3?: bool}> */
    public static function providerFailures(): iterable
    {
        yield 'uncertain first outcome' => ['Connection reset while writing DATA', 'Connection refused', false];
        yield 'partial first delivery' => [
            'SMTP Error: The following recipients failed: SMTP code: 450 mailbox busy',
            'SMTP code: 421 retry later',
            false,
        ];
        yield 'permanent first refusal' => ['SMTP code: 550 unknown recipient', 'SMTP code: 421 retry later', false];
        yield 'first certificate failure' => ['certificate verify failed', 'SMTP code: 421 retry later', false];
        yield 'unknown first failure' => ['Unknown transport failure', 'SMTP code: 421 retry later', false];
        yield 'uncertain last outcome' => ['SMTP code: 421 retry later', 'Connection reset while writing DATA', false];
        yield 'all temporary refusals' => ['SMTP code: 451 retry later', 'Connection refused', true];
        yield 'all connection refusals' => ['Connection refused', 'SMTP code: 421 retry later', true];
        yield 'redacted earlier failure' => [
            'SMTP code: 451 mailbox busy for sensitive@example.test',
            'Connection refused',
            true,
        ];
        yield 'recipient refusal retains earlier reasons' => [
            'SMTP code: 451 retry later',
            'SMTP Error: The following recipients failed: SMTP code: 450 mailbox busy',
            false,
        ];
        yield 'stale SMTP error cannot hide a later exception' => [
            'SMTP code: 451 retry later',
            'Connection reset while writing DATA',
            false,
            false,
        ];
    }
}
