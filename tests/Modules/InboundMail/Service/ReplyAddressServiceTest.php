<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Service\MailboxScopeService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use Modules\InboundMail\Service\ReplyAddressService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * Signed reply addresses (§8.58): minted for what the site sends,
 * recognised on what comes back, and nothing in between.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReplyAddressServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private InboundMailboxRepository $mailboxes;
    private MailboxScopeService $scopes;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->mailboxes = new InboundMailboxRepository($this->pdo, $this->encryption);

        $registry = new MessageConsumerRegistry();
        $registry->register(new FakeMessageConsumer('rental'));
        $registry->register(new FakeMessageConsumer('camps'));
        $this->scopes = new MailboxScopeService($this->mailboxes, $registry);
    }

    /** A shared box the operator opened to rental. */
    private function mailbox(string $username, string $name = 'Locations', bool $enabled = true): int
    {
        $id = $this->mailboxes->create($name, ProviderType::FAKE, 'imap.test', 993, 'ssl', $username, 'mdp', ['INBOX'], $enabled);
        $this->scopes->saveSharedScopes($id, ['rental' => ['analyze' => true, 'read' => 'none']]);

        return $id;
    }

    private function service(?SettingService $settings = null, bool $withScopes = true): ReplyAddressService
    {
        return new ReplyAddressService($this->mailboxes, $this->encryption, $withScopes ? $this->scopes : null, $settings);
    }

    public function testTheAddressIsTheBoxsOwnWithTheObjectAndASignature(): void
    {
        $this->mailbox('locations@unite.be');

        $address = $this->service()->addressFor('rental', 'LOC-2027-0042');

        $this->assertNotNull($address);
        $this->assertMatchesRegularExpression('/^locations\+rental\.LOC-2027-0042\.[a-f0-9]{12}@unite\.be$/', $address);
    }

    public function testTheSignatureIsStableAndSpecificToTheObject(): void
    {
        $this->mailbox('locations@unite.be');
        $service = $this->service();

        $this->assertSame($service->addressFor('rental', 'LOC-2027-0042'), $service->addressFor('rental', 'LOC-2027-0042'));
        $this->assertNotSame($service->addressFor('rental', 'LOC-2027-0042'), $service->addressFor('rental', 'LOC-2027-0043'));
        $this->assertNotSame($service->addressFor('rental', 'LOC-2027-0042'), $service->addressFor('camps', 'LOC-2027-0042'));
    }

    public function testWhatWasMintedIsRecognisedComingBack(): void
    {
        $this->mailbox('locations@unite.be');
        $service = $this->service();
        $address = $service->addressFor('rental', 'LOC-2027-0042');
        $this->assertNotNull($address);

        $resolved = $service->resolve(['tresorier@groupe.example', $address]);

        $this->assertNotNull($resolved);
        $this->assertSame('rental', $resolved->consumerId);
        $this->assertSame('LOC-2027-0042', $resolved->businessReference);
    }

    public function testAnAddressLowercasedByTheMailLayerStillVerifies(): void
    {
        // The IMAP client lowercases every recipient it reads; a signature
        // over the original case would never verify on a real box.
        $this->mailbox('locations@unite.be');
        $service = $this->service();
        $address = strtolower((string) $service->addressFor('rental', 'LOC-2027-0042'));

        $resolved = $service->resolve([$address]);

        $this->assertNotNull($resolved);
        $this->assertSame('loc-2027-0042', $resolved->businessReference, 'as the address carried it — the consumer canonicalises');
    }

    public function testATamperedSignatureIsAnOrdinaryAddress(): void
    {
        $this->mailbox('locations@unite.be');
        $service = $this->service();

        $this->assertNull($service->resolve(['locations+rental.LOC-2027-0042.000000000000@unite.be']));
        $this->assertNull($service->resolve(['locations+rental.LOC-2027-0043.'
            . substr((string) $service->addressFor('rental', 'LOC-2027-0042'), 29, 12) . '@unite.be']));
    }

    public function testOrdinaryRecipientsResolveToNothing(): void
    {
        $this->mailbox('locations@unite.be');

        $this->assertNull($this->service()->resolve(['locations@unite.be', 'tresorerie+factures@unite.be', '']));
    }

    public function testTheSettingTurnsMintingOffButNotRecognition(): void
    {
        // Mail sent while it was on keeps being answered for months.
        $this->mailbox('locations@unite.be');
        $minted = $this->service()->addressFor('rental', 'LOC-2027-0042');
        $this->assertNotNull($minted);

        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturn('0');
        $off = $this->service($settings);

        $this->assertNull($off->addressFor('rental', 'LOC-2027-0042'));
        $this->assertNotNull($off->resolve([$minted]));
    }

    public function testTheBoxDedicatedToTheConsumerIsPreferred(): void
    {
        $this->mailbox('unite@unite.be', 'Unité');
        $dedicated = $this->mailbox('locations@unite.be', 'Locations');
        $this->scopes->saveDedicated($dedicated, 'rental');

        $this->assertStringStartsWith('locations+rental.', (string) $this->service()->addressFor('rental', 'LOC-2027-0042'));
    }

    public function testABoxTheConsumerDoesNotAnalyseIsNeverUsed(): void
    {
        $camps = $this->mailbox('camps@unite.be', 'Camps');
        $this->scopes->saveDedicated($camps, 'camps');
        $shared = $this->mailbox('unite@unite.be', 'Unité');
        $this->scopes->saveSharedScopes($shared, ['camps' => ['analyze' => true, 'read' => 'none']]);

        $this->assertNull($this->service()->addressFor('rental', 'LOC-2027-0042'));
    }

    public function testAnAccountThatIsNotAnAddressHasNoDomainToReplyTo(): void
    {
        $this->mailbox('locations');

        $this->assertNull($this->service()->addressFor('rental', 'LOC-2027-0042'));
    }

    public function testADisabledBoxIsNotOffered(): void
    {
        $this->mailbox('locations@unite.be', enabled: false);

        $this->assertNull($this->service()->addressFor('rental', 'LOC-2027-0042'));
    }

    public function testAReferenceThatCannotTravelInAnAddressMintsNothing(): void
    {
        $this->mailbox('locations@unite.be');

        $this->assertNull($this->service()->addressFor('rental', 'LOC 2027/0042'));
        $this->assertNull($this->service()->addressFor('Rental!', 'LOC-2027-0042'));
    }

    public function testWithoutAScopeServiceAnyEnabledAddressBoxServes(): void
    {
        $this->mailbox('locations');
        $this->mailbox('unite@unite.be', 'Unité');

        $this->assertStringEndsWith('@unite.be', (string) $this->service(null, false)->addressFor('rental', 'LOC-2027-0042'));
    }
}
