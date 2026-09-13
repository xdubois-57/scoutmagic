<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\MailPurpose;
use Core\Mail\MailTransportInterface;
use Core\Mail\PhpMailerTransport;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\MailTransportChain;
use Core\Mail\Transport\MailTransportFactory;
use Core\Mail\Transport\ProviderConnections;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * One construction of the chain, for both entry points
 * (ARCHITECTURE.md §8.106).
 *
 * This class exists because two composition roots wiring the same graph
 * by hand is how this project has already broken production twice
 * (§8.17), and a test is what keeps the reason from eroding: a chain
 * built differently on the web path and the scheduled path would mean a
 * mailing obeying quotas under one trigger and ignoring them under the
 * other.
 *
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailTransportFactoryTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
    }

    public function testItBuildsAChainThatRoutesOnTheLane(): void
    {
        $providers = new MailProviderRepository($this->pdo);
        $chains = new LaneChainRepository($this->pdo);
        $relay = $providers->create('Relais', null, 50, 10);
        $chains->append(MailLane::Authentication, $relay, true);
        $chains->append(MailLane::Bulk, MailProvider::LOCAL_ID, true);

        $delivery = $this->recordingTransport();
        $built = MailTransportFactory::build(
            $this->pdo,
            [ProviderConnections::prefixFor($relay) . '_host' => 'smtp.relais.test'],
            $this->settings,
            $delivery
        );

        $this->assertInstanceOf(MailTransportChain::class, $built['chain']);

        $built['chain']->deliver($this->message(), MailPurpose::MagicLink);
        $built['chain']->deliver($this->message(), MailPurpose::Bulk);

        $this->assertSame(['smtp.relais.test', ''], $delivery->attemptedHosts);
    }

    /**
     * The directory comes back with the chain rather than being rebuilt
     * by the caller: resolving a provider reads `secrets.enc`, and a
     * second directory would read it again and memoise nothing.
     */
    public function testItHandsBackTheDirectoryItBuiltTheChainOn(): void
    {
        $relay = (new MailProviderRepository($this->pdo))->create('Brevo', 300, 50, 10);

        $built = MailTransportFactory::build(
            $this->pdo,
            [ProviderConnections::prefixFor($relay) . '_host' => 'smtp-relay.brevo.test'],
            $this->settings
        );

        $provider = $built['directory']->find($relay);
        $this->assertNotNull($provider);
        $this->assertSame('smtp-relay.brevo.test', $provider->host);
        $this->assertSame(300, $provider->dailyQuota);
        $this->assertNotNull($built['directory']->find(MailProvider::LOCAL_ID), 'The local send is always there.');
    }

    /**
     * The one thing the two entry points legitimately differ on.
     */
    public function testWithNoDeliveryTransportItFallsBackToTheOrdinaryOne(): void
    {
        $built = MailTransportFactory::build($this->pdo, [], $this->settings);

        $this->assertInstanceOf(MailTransportChain::class, $built['chain']);

        // The DELIVERY dependency is what this test is about, and
        // asserting the chain's own class said nothing about it: the
        // factory could have swapped `PhpMailerTransport` for anything
        // and this still passed. Read through reflection because the
        // alternative is exercising it, and the default transport would
        // really try to send.
        $this->assertInstanceOf(PhpMailerTransport::class, $this->deliveryOf($built['chain']));

        $supplied = new PhpMailerTransport();
        $explicit = MailTransportFactory::build($this->pdo, [], $this->settings, $supplied);
        $this->assertSame(
            $supplied,
            $this->deliveryOf($explicit['chain']),
            'A transport handed in is the one used — this is the seam the sandbox hangs on.'
        );
    }

    /**
     * The transport the chain delegates to once it has pointed PHPMailer
     * at a provider.
     */
    private function deliveryOf(MailTransportChain $chain): MailTransportInterface
    {
        $property = new \ReflectionProperty(MailTransportChain::class, 'delivery');

        $delivery = $property->getValue($chain);
        $this->assertInstanceOf(MailTransportInterface::class, $delivery);

        return $delivery;
    }

    private function message(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';

        return $mail;
    }

    private function recordingTransport(): MailTransportInterface
    {
        return new class implements MailTransportInterface {
            /** @var array<int, string> */
            public array $attemptedHosts = [];

            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                $this->attemptedHosts[] = $mail->Host;
            }
        };
    }
}
