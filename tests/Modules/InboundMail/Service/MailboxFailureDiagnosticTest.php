<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Modules\InboundMail\Client\MailboxConnectionException;
use Modules\InboundMail\Mailbox\MailboxCredentials;
use Modules\InboundMail\Service\MailboxFailureDiagnostic;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;

final class MailboxFailureDiagnosticTest extends TestCase
{
    public function testEachOperationKeepsItsOwnStep(): void
    {
        foreach (['connect', 'list', 'open_folder', 'fetch', 'processing'] as $step) {
            $error = MailboxConnectionException::at($step, new ImapServerErrorException('BAD [UNAVAILABLE] Try later'));
            $diagnostic = MailboxFailureDiagnostic::from($error, new MailboxCredentials('box@example.test', 'secret'));
            self::assertSame($step, $diagnostic->step);
            self::assertSame('BAD', $diagnostic->details['imap_status']);
        }
    }

    public function testItKeepsTheStepCodesAndExceptionChain(): void
    {
        $error = MailboxConnectionException::at('open_folder', new ImapServerErrorException('NO [NONEXISTENT] Missing archive'));
        $diagnostic = MailboxFailureDiagnostic::from($error, new MailboxCredentials('box@example.test', 'secret'));

        self::assertSame('open_folder', $diagnostic->step);
        self::assertSame('NO', $diagnostic->details['imap_status']);
        self::assertSame('NONEXISTENT', $diagnostic->details['imap_code']);
        self::assertSame(['MailboxConnectionException', 'ImapServerErrorException'], $diagnostic->details['exception_classes']);
        self::assertSame('NO [NONEXISTENT] Missing archive', $diagnostic->details['server_response']);
    }

    public function testItRedactsSecretsEmailsAndAuthenticationPayloadsBeforeTruncating(): void
    {
        $credentials = new MailboxCredentials('box@example.test', 'quote"and\\slash');
        $variants = [$credentials->username, $credentials->password,
            addcslashes($credentials->password, '\\"'), base64_encode($credentials->username),
            base64_encode($credentials->password), base64_encode("\0" . $credentials->username . "\0" . $credentials->password)];
        foreach ($variants as $secret) {
            $error = new ImapServerErrorException('NO [AUTHENTICATIONFAILED] ' . $secret . ' other@example.test');
            $diagnostic = MailboxFailureDiagnostic::from($error, $credentials);
            $text = (string) $diagnostic->details['server_response'];
            self::assertStringNotContainsString($secret, $text);
            self::assertStringNotContainsString('other@example.test', $text);
            self::assertStringContainsString('[masqué]', $text);
        }
        foreach (["LOGIN unknown token", "AUTHENTICATE PLAIN token", "LO\0GIN unknown token"] as $command) {
            $diagnostic = MailboxFailureDiagnostic::from(new ImapServerErrorException('BAD ' . $command), $credentials);
            self::assertStringNotContainsString('token', (string) $diagnostic->details['server_response']);
        }
        $error = new ImapServerErrorException('NO ' . str_repeat('é', 290) . $credentials->password . str_repeat('é', 100));
        $diagnostic = MailboxFailureDiagnostic::from($error, $credentials);
        self::assertLessThanOrEqual(300, mb_strlen((string) $diagnostic->details['server_response']));
        self::assertStringNotContainsString('quote', (string) $diagnostic->details['server_response']);
    }

    public function testOnlyServerResponsesAreKeptAndControlCharactersAreRemoved(): void
    {
        $credentials = new MailboxCredentials('box@example.test', 'secret');
        $error = new ImapServerErrorException("NO [UNAVAILABLE] Retry\x1b later\nPrivate message body");
        $diagnostic = MailboxFailureDiagnostic::from($error, $credentials);
        self::assertSame('NO [UNAVAILABLE] Retry later', $diagnostic->details['server_response']);
        $processing = MailboxFailureDiagnostic::from(new \RuntimeException('Private message body'), $credentials);
        self::assertSame('processing', $processing->step);
        self::assertArrayNotHasKey('server_response', $processing->details);
    }

    public function testQuotedEmailLocalPartsAndAddressLiteralsAreFullyMasked(): void
    {
        $credentials = new MailboxCredentials('box@example.test', 'credential');
        foreach (['"first last"@example.org', '"first \\"last"@example.org',
            'person@[IPv6:2001:db8::1]', '"quoted local"@[127.0.0.1]'] as $address) {
            $diagnostic = MailboxFailureDiagnostic::from(
                new ImapServerErrorException('NO [UNAVAILABLE] ' . $address),
                $credentials
            );
            self::assertSame('NO [UNAVAILABLE] [masqué]', $diagnostic->details['server_response']);
        }
    }

    public function testControlCharactersInsideKnownCredentialsCannotBypassRedaction(): void
    {
        $credentials = new MailboxCredentials("box\tlogin", "control\0secret");
        $diagnostic = MailboxFailureDiagnostic::from(new ImapServerErrorException(
            'NO ' . $credentials->username . ' ' . $credentials->password
        ), $credentials);

        self::assertSame('NO [masqué] [masqué]', $diagnostic->details['server_response']);
    }

    public function testKnownCredentialsAreMaskedBeforeResponseLinesAreSplit(): void
    {
        foreach ([['box@example.test', "prefix\nsuffix"], ['box@example.test', "prefix\r\nsuffix"],
            ["box\r\nlogin", 'password']] as [$username, $password]) {
            $credentials = new MailboxCredentials($username, $password);
            $diagnostic = MailboxFailureDiagnostic::from(new ImapServerErrorException(
                'NO [AUTHENTICATIONFAILED] ' . $username . ' ' . $password . "\nPrivate body"
            ), $credentials);

            self::assertSame('NO [AUTHENTICATIONFAILED] [masqué] [masqué]', $diagnostic->details['server_response']);
        }
    }

    public function testNetworkAndOpenSslDiagnosticsAreSafeMetadata(): void
    {
        $credentials = new MailboxCredentials('box@example.test', 'secret');
        $network = MailboxFailureDiagnostic::from(new ConnectionFailedException('Connection refused', 111), $credentials);
        self::assertSame(111, $network->details['errno']);
        self::assertContains('connection refused', $network->details['library_reasons']);
        $tls = MailboxFailureDiagnostic::from(new ConnectionFailedException(
            'error:0A000086:SSL routines::certificate verify failed'
        ), $credentials);
        self::assertSame('0A000086', $tls->details['openssl_code']);
        self::assertSame('certificate verify failed', $tls->details['openssl_reason']);
        $alert = MailboxFailureDiagnostic::from(new ConnectionFailedException(
            'error:0A000102:SSL routines::tlsv1 alert protocol version'
        ), $credentials);
        self::assertSame('tlsv1 alert protocol version', $alert->details['openssl_reason']);
    }

    public function testFailureNatureIgnoresChangingServerProseButIncludesCodesAndStep(): void
    {
        $credentials = new MailboxCredentials('box@example.test', 'secret');
        $first = MailboxFailureDiagnostic::from(MailboxConnectionException::at('fetch',
            new ImapServerErrorException('NO [UNAVAILABLE] Retry at 10:00')), $credentials);
        $same = MailboxFailureDiagnostic::from(MailboxConnectionException::at('fetch',
            new ImapServerErrorException('NO [UNAVAILABLE] Retry at 11:00')), $credentials);
        $different = MailboxFailureDiagnostic::from(MailboxConnectionException::at('fetch',
            new ImapServerErrorException('NO [AUTHENTICATIONFAILED] Refused')), $credentials);
        self::assertSame($first->signature(), $same->signature());
        self::assertNotSame($first->signature(), $different->signature());
    }
}
