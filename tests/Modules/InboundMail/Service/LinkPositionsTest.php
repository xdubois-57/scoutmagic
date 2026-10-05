<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\InboundMailService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * The positions a consumer stores to say « read up to here » (#720), and
 * what came after them.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class LinkPositionsTest extends TestCase
{
    private InboundMessageRepository $messages;
    private InboundMailService $service;
    private int $mailboxId;
    private int $uid = 600;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->messages = new InboundMessageRepository($pdo, $encryption);
        $mailboxes = new InboundMailboxRepository($pdo, $encryption);
        $this->service = new InboundMailService(
            $this->messages,
            $mailboxes,
            new FileRepository($pdo),
            new MessageConsumerRegistry()
        );
        $this->mailboxId = $mailboxes->create(
            'Locations', ProviderType::IMAP, 'imap.test', 993, 'ssl', 'locations@unite.be', 'secret', ['INBOX'], true
        );
    }

    public function testNothingFiledIsPositionZeroAndNothingAfterIt(): void
    {
        $this->assertSame(0, $this->service->latestLinkPosition('rental', 'LOC-2027-K7Q2MX'));
        $this->assertSame([], $this->service->countLinksAfter('rental', ['LOC-2027-K7Q2MX' => 0]));
        $this->assertSame([], $this->service->countLinksAfter('rental', []));
    }

    public function testWhatWasFiledAfterAPositionIsCountedPerObject(): void
    {
        $this->file('LOC-2027-K7Q2MX');
        $read = $this->service->latestLinkPosition('rental', 'LOC-2027-K7Q2MX');
        $this->file('LOC-2027-K7Q2MX');
        $this->file('LOC-2027-K7Q2MX');
        $this->file('LOC-2027-P4W8ZA');

        $this->assertSame(
            ['LOC-2027-K7Q2MX' => 2, 'LOC-2027-P4W8ZA' => 1],
            $this->service->countLinksAfter('rental', ['LOC-2027-K7Q2MX' => $read, 'LOC-2027-P4W8ZA' => 0])
        );
    }

    public function testReadingUpToTheLatestLeavesNothing(): void
    {
        $this->file('LOC-2027-K7Q2MX');
        $this->file('LOC-2027-K7Q2MX');

        $latest = $this->service->latestLinkPosition('rental', 'LOC-2027-K7Q2MX');

        $this->assertSame([], $this->service->countLinksAfter('rental', ['LOC-2027-K7Q2MX' => $latest]));
    }

    public function testAnotherModulesAssociationsAndAttachmentLevelOnesDoNotCount(): void
    {
        $messageId = $this->file('LOC-2027-K7Q2MX');
        $this->messages->addLink($messageId, 'rental', 'LOC-2027-K7Q2MX', LinkOrigin::SENDER, 42);
        $other = $this->store();
        $this->messages->addLink($other, 'camps', 'LOC-2027-K7Q2MX', LinkOrigin::REFERENCE);

        $this->assertSame(['LOC-2027-K7Q2MX' => 1], $this->service->countLinksAfter('rental', ['LOC-2027-K7Q2MX' => 0]));
    }

    private function file(string $reference): int
    {
        $messageId = $this->store();
        $this->messages->addLink($messageId, 'rental', $reference, LinkOrigin::SENDER);

        return $messageId;
    }

    private function store(): int
    {
        $this->uid++;

        return $this->messages->create(
            mailboxId: $this->mailboxId,
            folder: 'INBOX',
            uidValidity: 1,
            imapUid: $this->uid,
            messageId: 'm' . $this->uid . '@mail',
            inReplyTo: null,
            subject: 'Réservation',
            fromEmail: 'jeanne@example.be',
            fromName: 'Jeanne Martin',
            bodyText: 'Bonjour',
            bodyHtml: '',
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00')
        );
    }
}
