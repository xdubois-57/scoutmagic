<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Modules\InboundMail\Mailbox\MailProvider;
use Modules\InboundMail\Service\MailProviderRecognizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The provider an address belongs to (D8): its domain first, then — for an
 * address on the unit's own domain — the mail exchangers of that domain,
 * read through an injected resolver so no test touches the network.
 */
final class MailProviderRecognizerTest extends TestCase
{
    /** @var list<string> the domains the resolver was asked about */
    private array $asked = [];

    /**
     * @param array<string, list<string>> $mx
     */
    private function recognizer(array $mx = []): MailProviderRecognizer
    {
        return new MailProviderRecognizer(function (string $domain) use ($mx): array {
            $this->asked[] = $domain;

            return $mx[$domain] ?? [];
        });
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function domains(): array
    {
        return [
            'Gmail' => ['contact.unite@gmail.com', 'gmail', 'imap.gmail.com'],
            'Googlemail' => ['x@googlemail.com', 'gmail', 'imap.gmail.com'],
            'Outlook' => ['x@outlook.com', 'outlook', 'outlook.office365.com'],
            'Hotmail' => ['x@hotmail.be', 'outlook', 'outlook.office365.com'],
            'Live' => ['x@live.fr', 'outlook', 'outlook.office365.com'],
            'Infomaniak' => ['x@ik.me', 'infomaniak', 'mail.infomaniak.com'],
            'upper case' => ['Contact@GMAIL.com', 'gmail', 'imap.gmail.com'],
        ];
    }

    #[DataProvider('domains')]
    public function testAKnownDomainIsRecognisedWithoutAskingTheDns(string $address, string $key, string $host): void
    {
        $provider = $this->recognizer()->recognize($address);

        $this->assertNotNull($provider);
        $this->assertSame($key, $provider->key);
        $this->assertSame($host, $provider->host);
        $this->assertSame(993, $provider->port);
        $this->assertSame('ssl', $provider->encryption);
        $this->assertSame([], $this->asked);
    }

    /**
     * @return array<string, array{list<string>, string, string}>
     */
    public static function exchangers(): array
    {
        return [
            'Google Workspace' => [['aspmx.l.google.com.', 'alt1.aspmx.l.google.com.'], 'google_workspace', 'imap.gmail.com'],
            'Google Workspace, new MX' => [['smtp.google.com'], 'google_workspace', 'imap.gmail.com'],
            'Microsoft 365' => [['unite-be.mail.protection.outlook.com'], 'microsoft_365', 'outlook.office365.com'],
            'OVH' => [['mx1.mail.ovh.net', 'mx2.mail.ovh.net'], 'ovh', 'ssl0.ovh.net'],
            'Infomaniak' => [['mta-gw.infomaniak.ch'], 'infomaniak', 'mail.infomaniak.com'],
        ];
    }

    /**
     * @param list<string> $mx
     */
    #[DataProvider('exchangers')]
    public function testAnOwnDomainIsRecognisedByItsMx(array $mx, string $key, string $host): void
    {
        $provider = $this->recognizer(['unite.be' => $mx])->recognize('locations@unite.be');

        $this->assertNotNull($provider);
        $this->assertSame($key, $provider->key);
        $this->assertSame($host, $provider->host);
        $this->assertSame(['unite.be'], $this->asked);
    }

    /** A lookalike name is not the provider: the suffix is matched on a label boundary. */
    public function testALookalikeExchangerIsNotRecognised(): void
    {
        $this->assertNull($this->recognizer(['unite.be' => ['mx.notgoogle.com']])->recognize('a@unite.be'));
        $this->assertNull($this->recognizer(['unite.be' => ['google.com.evil.example']])->recognize('a@unite.be'));
    }

    public function testAnUnknownDomainIsNotRecognised(): void
    {
        $this->assertNull($this->recognizer(['exemple-hebergeur.be' => ['mx.exemple-hebergeur.be']])
            ->recognize('unite@exemple-hebergeur.be'));
    }

    /** A failed lookup is an empty answer: « not recognised », never an error. */
    public function testADomainWithoutMxIsNotRecognised(): void
    {
        $this->assertNull($this->recognizer()->recognize('unite@unite.be'));
    }

    public function testSomethingThatIsNoAddressIsNeverLookedUp(): void
    {
        foreach (['', 'pas une adresse', 'a@', 'a@localhost', 'a@-x.be', 'a@b c.be'] as $value) {
            $this->assertNull($this->recognizer()->recognize($value), $value);
        }
        $this->assertSame([], $this->asked);
    }

    /** The app-password help is for Gmail and Outlook alone (D12). */
    public function testOnlyGoogleAndMicrosoftAskForAnAppPassword(): void
    {
        $this->assertSame(MailProvider::HELP_GMAIL, MailProviderRecognizer::provider('gmail')?->passwordHelp);
        $this->assertSame(MailProvider::HELP_GMAIL, MailProviderRecognizer::provider('google_workspace')?->passwordHelp);
        $this->assertSame(MailProvider::HELP_OUTLOOK, MailProviderRecognizer::provider('outlook')?->passwordHelp);
        $this->assertSame(MailProvider::HELP_OUTLOOK, MailProviderRecognizer::provider('microsoft_365')?->passwordHelp);
        $this->assertNull(MailProviderRecognizer::provider('ovh')?->passwordHelp);
        $this->assertNull(MailProviderRecognizer::provider('infomaniak')?->passwordHelp);
        $this->assertNull(MailProviderRecognizer::provider('inconnu'));
    }
}
