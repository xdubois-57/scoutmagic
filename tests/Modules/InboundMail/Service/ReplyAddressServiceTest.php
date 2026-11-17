<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\MailboxPurpose;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Service\ReplyAddressService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
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

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->mailboxes = new InboundMailboxRepository($this->pdo, $this->encryption);
    }

    /** A general box, which every consumer analyses (§8.58). */
    private function mailbox(string $username, bool $enabled = true): int
    {
        return $this->mailboxes->create(
            ProviderType::FAKE,
            'imap.test',
            993,
            'ssl',
            $username,
            'mdp',
            ['INBOX'],
            $enabled
        );
    }

    private function reserve(int $mailboxId, string $consumerId): void
    {
        $this->mailboxes->setPurpose($mailboxId, MailboxPurpose::DEDICATED, $consumerId);
    }

    private function service(?SettingService $settings = null): ReplyAddressService
    {
        return new ReplyAddressService($this->mailboxes, $this->encryption, $settings);
    }

    public function testTheAddressIsTheBoxsOwnWithTheObjectAndASignature(): void
    {
        $this->mailbox('locations@unite.be');

        $address = $this->service()->addressFor('rental', 'LOC-K7Q2M4');

        $this->assertNotNull($address);
        $this->assertMatchesRegularExpression('/^locations\+rental\.LOC-K7Q2M4\.[a-f0-9]{12}@unite\.be$/', $address);
    }

    public function testTheSignatureIsStableAndSpecificToTheObject(): void
    {
        $this->mailbox('locations@unite.be');
        $service = $this->service();

        $this->assertSame($service->addressFor('rental', 'LOC-K7Q2M4'), $service->addressFor('rental', 'LOC-K7Q2M4'));
        $this->assertNotSame($service->addressFor('rental', 'LOC-K7Q2M4'), $service->addressFor('rental', 'LOC-K7Q2M5'));
        $this->assertNotSame($service->addressFor('rental', 'LOC-K7Q2M4'), $service->addressFor('camps', 'LOC-K7Q2M4'));
    }

    public function testWhatWasMintedIsRecognisedComingBack(): void
    {
        $this->mailbox('locations@unite.be');
        $service = $this->service();
        $address = $service->addressFor('rental', 'LOC-K7Q2M4');
        $this->assertNotNull($address);

        $resolved = $service->resolve(['tresorier@groupe.example', $address]);

        $this->assertNotNull($resolved);
        $this->assertSame('rental', $resolved->consumerId);
        $this->assertSame('LOC-K7Q2M4', $resolved->businessReference);
    }

    public function testAnAddressLowercasedByTheMailLayerStillVerifies(): void
    {
        // The IMAP client lowercases every recipient it reads; a signature
        // over the original case would never verify on a real box.
        $this->mailbox('locations@unite.be');
        $service = $this->service();
        $address = strtolower((string) $service->addressFor('rental', 'LOC-K7Q2M4'));

        $resolved = $service->resolve([$address]);

        $this->assertNotNull($resolved);
        $this->assertSame('loc-k7q2m4', $resolved->businessReference, 'as the address carried it — the consumer canonicalises');
    }

    public function testATamperedSignatureIsAnOrdinaryAddress(): void
    {
        $this->mailbox('locations@unite.be');
        $service = $this->service();

        $this->assertNull($service->resolve(['locations+rental.LOC-K7Q2M4.000000000000@unite.be']));
        $this->assertNull($service->resolve(['locations+rental.LOC-K7Q2M5.'
            . substr((string) $service->addressFor('rental', 'LOC-K7Q2M4'), 29, 12) . '@unite.be']));
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
        $minted = $this->service()->addressFor('rental', 'LOC-K7Q2M4');
        $this->assertNotNull($minted);

        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturn('0');
        $off = $this->service($settings);

        $this->assertNull($off->addressFor('rental', 'LOC-K7Q2M4'));
        $this->assertNotNull($off->resolve([$minted]));
    }

    public function testTheBoxReservedToTheConsumerIsPreferred(): void
    {
        $this->mailbox('unite@unite.be');
        $this->reserve($this->mailbox('locations@unite.be'), 'rental');

        $this->assertStringStartsWith('locations+rental.', (string) $this->service()->addressFor('rental', 'LOC-K7Q2M4'));
    }

    /** Without a box of its own, the consumer answers from the general box. */
    public function testWithoutABoxOfItsOwnTheGeneralBoxServes(): void
    {
        $this->reserve($this->mailbox('camps@unite.be'), 'camps');
        $this->mailbox('unite@unite.be');

        $this->assertStringStartsWith('unite+rental.', (string) $this->service()->addressFor('rental', 'LOC-K7Q2M4'));
    }

    /**
     * A box reserved to another module is offered to that module alone: a
     * reply sent there would reach nobody who reads for this one.
     */
    public function testABoxReservedToAnotherModuleIsNeverUsed(): void
    {
        $this->reserve($this->mailbox('camps@unite.be'), 'camps');

        $this->assertNull($this->service()->addressFor('rental', 'LOC-K7Q2M4'));
    }

    public function testAnAccountThatIsNotAnAddressHasNoDomainToReplyTo(): void
    {
        $this->mailbox('locations');

        $this->assertNull($this->service()->addressFor('rental', 'LOC-K7Q2M4'));
    }

    public function testADisabledBoxIsNotOffered(): void
    {
        $this->mailbox('locations@unite.be', enabled: false);

        $this->assertNull($this->service()->addressFor('rental', 'LOC-K7Q2M4'));
    }

    public function testAReferenceThatCannotTravelInAnAddressMintsNothing(): void
    {
        $this->mailbox('locations@unite.be');

        $this->assertNull($this->service()->addressFor('rental', 'LOC 2027/0042'));
        $this->assertNull($this->service()->addressFor('Rental!', 'LOC-K7Q2M4'));
    }
}
