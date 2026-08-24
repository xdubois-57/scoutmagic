<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Mail;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Modules\Camps\Mail\CampsMessageConsumer;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\ContactRepository;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\LinkOrigin;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

class CampsMessageConsumerTest extends TestCase
{
    private const SHARED_MAILBOX = 1;
    private const DEDICATED_MAILBOX = 2;

    private \PDO $pdo;
    private EncryptionService $encryption;
    private CampRepository $camps;
    private ContactRepository $contacts;
    private SettingService $settings;
    private int $campId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->camps = new CampRepository($this->pdo, $this->encryption);
        $this->contacts = new ContactRepository($this->pdo, $this->encryption);
        $this->settings = new SettingService(new SettingRepository($this->pdo));

        $this->pdo->exec("INSERT INTO camp_places (name) VALUES ('Domaine de Mozet')");
        $this->campId = $this->camps->create(
            1, Camp::STAY_GRAND_CAMP, '2026-07-12', '2026-07-19', null,
            Camp::STATUS_CONFIRMED, null, null, null, null, []
        );
    }

    // ── The shared mailbox: narrow on purpose ───────────────────────

    public function testAnUnknownSenderInASharedMailboxIsNotClaimed(): void
    {
        // Everything this consumer takes is a message another module will
        // never see. A camps module claiming loosely would quietly
        // swallow the unit's mail.
        $this->assertNull($this->consumer()->claim($this->message('inconnu@example.org')));
    }

    public function testASubjectMentioningAPlaceIsNotEnough(): void
    {
        // Never a place name in a subject: it is not a claim, it is a
        // coincidence waiting to happen.
        $this->assertNull($this->consumer()->claim(
            $this->message('inconnu@example.org', 'Domaine de Mozet — disponibilités')
        ));
    }

    public function testAKnownContactWritingInTheWindowIsClaimed(): void
    {
        $this->contacts->create($this->campId, 'Mme Lambert', null, 'lambert@example.org', null, null);

        $claim = $this->consumer()->claim($this->message('lambert@example.org'));

        $this->assertNotNull($claim);
        $this->assertSame('camp-' . $this->campId, $claim->businessReference);
        $this->assertSame(LinkOrigin::SENDER, $claim->origin);
    }

    public function testTheSenderMatchIsCaseInsensitive(): void
    {
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);

        $this->assertNotNull($this->consumer()->claim($this->message('Lambert@Example.ORG')));
    }

    public function testAKnownContactWritingYearsLaterIsNotClaimed(): void
    {
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);

        // Next year's enquiry from the same farmer must not land on last
        // year's camp.
        $this->assertNull($this->consumer()->claim(
            $this->message('lambert@example.org', 'Bonjour', '2029-03-01')
        ));
    }

    public function testTwoStaysMatchingOneSenderClaimNothing(): void
    {
        $second = $this->camps->create(
            1, Camp::STAY_GRAND_CAMP, '2026-08-01', '2026-08-08', null,
            Camp::STATUS_CONFIRMED, null, null, null, null, []
        );
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);
        $this->contacts->create($second, null, null, 'lambert@example.org', null, null);

        // Putting a farmer's message on whichever of two stays sorted
        // first is worse than leaving it where it was: the chief reading
        // the wrong stay has no way to know it is the wrong one.
        $this->assertNull($this->consumer()->claim($this->message('lambert@example.org')));
    }

    public function testAReplyInAKnownThreadIsClaimed(): void
    {
        $inbound = $this->createStub(InboundMailInterface::class);
        $inbound->method('findReferenceByThread')->willReturn('camp-' . $this->campId);

        $claim = $this->consumer($inbound)->claim(
            $this->message('quelquun@example.org', 'RE: terrain', null, ['<abc@mail>'])
        );

        $this->assertNotNull($claim);
        $this->assertSame(LinkOrigin::THREAD, $claim->origin);
    }

    // ── The dedicated mailbox: everything, then sorted by hand ──────

    public function testADedicatedMailboxClaimsEvenAnUnknownSender(): void
    {
        $this->setDedicatedMailboxes((string) self::DEDICATED_MAILBOX);

        $claim = $this->consumer()->claim(
            $this->message('newsletter@campingbelgique.be', 'Nos offres', null, [], self::DEDICATED_MAILBOX)
        );

        $this->assertNotNull($claim);
        $this->assertSame(CampsMessageConsumer::UNSORTED_REFERENCE, $claim->businessReference);
    }

    public function testADedicatedMailboxStillPrefersARealStayOverUnsorted(): void
    {
        $this->setDedicatedMailboxes((string) self::DEDICATED_MAILBOX);
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);

        $claim = $this->consumer()->claim(
            $this->message('lambert@example.org', 'Bonjour', null, [], self::DEDICATED_MAILBOX)
        );

        $this->assertSame('camp-' . $this->campId, $claim?->businessReference);
    }

    public function testTheSharedMailboxIsUnaffectedByTheDedicatedSetting(): void
    {
        $this->setDedicatedMailboxes((string) self::DEDICATED_MAILBOX);

        // The same unknown sender, in the ordinary mailbox: still not
        // ours, or the module would swallow mail meant for others.
        $this->assertNull($this->consumer()->claim(
            $this->message('newsletter@campingbelgique.be', 'Nos offres', null, [], self::SHARED_MAILBOX)
        ));
    }

    public function testSeveralDedicatedMailboxesAreSupported(): void
    {
        $this->setDedicatedMailboxes('2, 7 ,9');

        $this->assertSame([2, 7, 9], $this->consumer()->dedicatedMailboxIds());
        $this->assertNotNull($this->consumer()->claim(
            $this->message('x@example.org', 'X', null, [], 7)
        ));
    }

    public function testNoDedicatedMailboxIsTheDefault(): void
    {
        $this->assertSame([], $this->consumer()->dedicatedMailboxIds());
    }

    public function testAStayReferenceCanNeverCollideWithTheReservedOne(): void
    {
        $this->assertSame('camp-42', CampsMessageConsumer::referenceFor(42));
        $this->assertSame(42, CampsMessageConsumer::campIdFromReference('camp-42'));
        $this->assertNull(CampsMessageConsumer::campIdFromReference(CampsMessageConsumer::UNSORTED_REFERENCE));
    }

    /**
     * Written straight into `settings`: SettingService::set() refuses a
     * key the module registration has not declared yet, and this test
     * exercises the consumer rather than the settings machinery.
     */
    private function setDedicatedMailboxes(string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, default_value, setting_type, label, description)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['camps', 'camps_dedicated_mailbox_ids', $value, '', 'text', 'Boîtes dédiées', '']);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
    }

    private function consumer(?InboundMailInterface $inbound = null): CampsMessageConsumer
    {
        return new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption, $this->settings, $inbound, null
        );
    }

    /**
     * @param string[] $references
     */
    private function message(
        string $from,
        string $subject = 'Bonjour',
        ?string $sentAt = null,
        array $references = [],
        int $mailboxId = self::SHARED_MAILBOX
    ): CandidateMessage {
        return new CandidateMessage(
            $mailboxId,
            $subject,
            $from,
            null,
            '<msg@mail>',
            $references !== [] ? $references[0] : null,
            $references,
            ['camps@unite.be'],
            new \DateTimeImmutable($sentAt ?? '2026-06-01'),
            'Corps du message',
            ''
        );
    }
}
