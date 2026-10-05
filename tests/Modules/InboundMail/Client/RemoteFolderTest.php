<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Client;

use Modules\InboundMail\Client\RemoteFolder;
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Mailbox\SyncState;
use PHPUnit\Framework\TestCase;

/**
 * Which folder is a box's sent mail (#720): the one the server marks
 * `\Sent` (RFC 6154) on the LIST it already answers, or the one the
 * operator names — never a name guessed.
 */
class RemoteFolderTest extends TestCase
{
    public function testTheSentAttributeIsReadOffTheListing(): void
    {
        $folders = RemoteFolder::fromListing([
            'INBOX' => ['delimiter' => '/', 'flags' => ['\\HasNoChildren']],
            'Envoyés' => ['delimiter' => '/', 'flags' => ['\\HasNoChildren', '\\Sent']],
            'Brouillons' => ['delimiter' => '/', 'flags' => ['\\Drafts']],
        ]);

        $this->assertSame(['INBOX', 'Envoyés', 'Brouillons'], RemoteFolder::paths($folders));
        $this->assertSame('Envoyés', RemoteFolder::sentAmong($folders));
    }

    public function testTheAttributeIsMatchedWhateverItsCase(): void
    {
        $folders = RemoteFolder::fromListing(['Sent Items' => ['delimiter' => '.', 'flags' => ['\\sent']]]);

        $this->assertSame('Sent Items', RemoteFolder::sentAmong($folders));
    }

    public function testAFolderNamedSentButNotMarkedIsNotTakenForIt(): void
    {
        $folders = RemoteFolder::fromListing([
            'INBOX' => ['delimiter' => '/', 'flags' => []],
            'Sent' => ['delimiter' => '/', 'flags' => ['\\HasNoChildren']],
        ]);

        $this->assertNull(RemoteFolder::sentAmong($folders));
    }

    public function testTwoMarkedFoldersLeaveNoHonestChoice(): void
    {
        $folders = [new RemoteFolder('Sent', true), new RemoteFolder('Envoyés', true)];

        $this->assertNull(RemoteFolder::sentAmong($folders));
    }

    public function testAMalformedEntryIsAFolderWithoutAttributes(): void
    {
        $folders = RemoteFolder::fromListing(['INBOX' => 'garbage', 'Archive' => ['delimiter' => '/']]);

        $this->assertSame(['INBOX', 'Archive'], RemoteFolder::paths($folders));
        $this->assertNull(RemoteFolder::sentAmong($folders));
    }

    public function testTheOperatorsFolderWinsOverTheServersMark(): void
    {
        $this->assertSame('INBOX.Sent', $this->mailbox(sentFolder: 'INBOX.Sent')->sentFolderAmong('Envoyés'));
        $this->assertSame('Envoyés', $this->mailbox()->sentFolderAmong('Envoyés'));
        $this->assertNull($this->mailbox()->sentFolderAmong(null));
    }

    public function testAWatchedFolderIsNeverReadAgainAsSentMail(): void
    {
        $this->assertNull($this->mailbox(folders: ['INBOX', 'Envoyés'])->sentFolderAmong('Envoyés'));
        $this->assertNull($this->mailbox(sentFolder: 'INBOX')->sentFolderAmong(null));
    }

    /** @param string[] $folders */
    private function mailbox(array $folders = [], ?string $sentFolder = null): Mailbox
    {
        return new Mailbox(
            id: 1,
            name: 'Locations',
            providerType: ProviderType::FAKE,
            host: 'imap.test',
            port: 993,
            encryption: 'ssl',
            username: 'locations@unite.be',
            folders: $folders,
            isEnabled: true,
            syncState: SyncState::NEVER,
            sentFolder: $sentFolder
        );
    }
}
