<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Mail;

use Core\Config\SettingService;
use Core\Mail\MailIdentity;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageDirection;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Mail\OtherRenterEmailRule;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * Which addresses a manager outside Staff d'U may pick for « Autres
 * adresses du locataire » (issues #1106 and #1253).
 *
 * The site sends from `locations@unite.example`, and the module reads the
 * box `locations@unite.example`.
 *
 * @group database
 */
final class OtherRenterEmailRuleTest extends TestCase
{
    private \PDO $pdo;
    private RentalBookingRepository $bookingRepository;
    private SettingService $settings;
    private RentalBooking $booking;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->bookingRepository = new RentalBookingRepository($this->pdo, $encryption);
        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturnCallback(
            static fn(string $key): ?string => $key === MailIdentity::SETTING_FROM_ADDRESS ? 'locations@unite.example' : null
        );
        $this->settings = $settings;

        $assetId = (new RentalAssetRepository($this->pdo, $encryption))
            ->create('Local Saint-Georges', 'Local Saint-Georges', 'local-saint-georges', 60, 1, '18:00', '11:00', null, true);
        $created = $this->bookingRepository->create(
            $assetId,
            'LOC-K7Q2M4',
            '2027-07-01',
            '2027-07-04',
            1,
            20,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => 'jeanne@example.be',
                'phone' => null,
                'organisation' => 'Les Scouts de Nulle Part',
                'purpose' => null,
                'comment' => null,
            ],
            null,
            null,
            null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-01 10:00:00')
        );
        $booking = $this->bookingRepository->findById($created['id']);
        $this->assertNotNull($booking);
        $this->booking = $booking;
    }

    /**
     * The booking's signed reply address files whatever reaches it: a
     * message with a forged `From:` must not put the correspondent it
     * pretends to be on the list.
     */
    public function testAReceivedMessageWhoseSenderNobodyVouchedForShowsNobody(): void
    {
        $rule = $this->ruleOver([$this->received('federation@example.org', verified: false)]);

        $this->assertSame([], $rule->seenAddresses($this->booking));
        $this->assertNotNull($rule->refusal($this->booking, 'federation@example.org', false));
    }

    public function testAReceivedMessageWhoseSenderWasVouchedForShowsIt(): void
    {
        $rule = $this->ruleOver([$this->received('Tresorier@Groupe.example', verified: true)]);

        $this->assertSame(['tresorier@groupe.example'], $rule->seenAddresses($this->booking));
        $this->assertNull($rule->refusal($this->booking, 'tresorier@groupe.example', false));
    }

    /** What the unit sent is the unit's own doing: its recipients stay. */
    public function testTheRecipientsOfAMessageTheUnitSentStay(): void
    {
        $rule = $this->ruleOver([$this->sent(['president@groupe.example'])]);

        $this->assertSame(['president@groupe.example'], $rule->seenAddresses($this->booking));
    }

    /**
     * A colleague the unit copied is on the unit's domain: not the renter,
     * whether the mail showed them or a manager typed them.
     */
    public function testAnAddressOnTheUnitsDomainIsRefusedEvenWhenTheMailShowedIt(): void
    {
        $rule = $this->ruleOver([$this->sent(['tresorier@unite.example', 'president@groupe.example'])]);

        $this->assertSame(['president@groupe.example'], $rule->seenAddresses($this->booking));
        $this->assertNotNull($rule->refusal($this->booking, 'tresorier@unite.example', true));
    }

    /** The domain of a box the module reads is the unit's too. */
    public function testTheDomainOfABoxTheModuleReadsIsTheUnits(): void
    {
        $rule = $this->ruleOver([], boxes: ['courrier@scouts-box.example']);

        $this->assertNotNull($rule->refusal($this->booking, 'animateur@scouts-box.example', true));
    }

    /**
     * A box on a webmail does not make the webmail the unit's: every renter
     * writing from it would be refused. Only the box's own address is.
     */
    public function testAWebmailDomainIsNeverTheUnitsAsAWhole(): void
    {
        $rule = $this->ruleOver(
            [$this->received('groupe.nulle.part@gmail.com', verified: true)],
            boxes: ['locations.unite@gmail.com']
        );

        $this->assertSame(['groupe.nulle.part@gmail.com'], $rule->seenAddresses($this->booking));
        $this->assertNull($rule->refusal($this->booking, 'groupe.nulle.part@gmail.com', false));
        $this->assertNotNull($rule->refusal($this->booking, 'locations.unite+loc-k7q2m4@gmail.com', true));
    }

    /** Staff d'U keeps the free field, a forged sender's address included. */
    public function testTheUnitStaffStillTypesAnyAddressOffTheUnitsDomain(): void
    {
        $rule = $this->ruleOver([$this->received('federation@example.org', verified: false)]);

        $this->assertNull($rule->refusal($this->booking, 'federation@example.org', true));
    }

    /**
     * @param list<InboundMessage> $messages
     * @param list<string> $boxes
     */
    private function ruleOver(array $messages, array $boxes = ['locations@unite.example']): OtherRenterEmailRule
    {
        $inbound = $this->createStub(InboundMailInterface::class);
        $inbound->method('findForReference')->willReturn($messages);
        $inbound->method('probeAddressesFor')->willReturn($boxes);

        return new OtherRenterEmailRule($this->bookingRepository, $inbound, $this->settings);
    }

    private function received(string $from, bool $verified): InboundMessage
    {
        return new InboundMessage(
            id: 9,
            mailboxId: 3,
            consumerId: 'rental',
            businessReference: 'LOC-K7Q2M4',
            linkOrigin: LinkOrigin::THREAD,
            subject: 'Re: La caution',
            fromEmail: $from,
            fromName: '',
            messageId: '<m9@groupe.example>',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2027-06-01 10:00:00'),
            bodyText: 'Bonjour,',
            bodyHtml: '',
            toEmails: ['locations+loc-k7q2m4@unite.example'],
            senderVerified: $verified
        );
    }

    /** @param list<string> $to */
    private function sent(array $to): InboundMessage
    {
        return new InboundMessage(
            id: 10,
            mailboxId: 3,
            consumerId: 'rental',
            businessReference: 'LOC-K7Q2M4',
            linkOrigin: LinkOrigin::THREAD,
            subject: 'Re: La caution',
            fromEmail: 'locations@unite.example',
            fromName: 'Les Scouts',
            messageId: '<m10@unite.example>',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2027-06-02 10:00:00'),
            bodyText: 'Bonjour,',
            bodyHtml: '',
            toEmails: $to,
            direction: MessageDirection::SENT
        );
    }
}
