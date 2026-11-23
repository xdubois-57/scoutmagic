<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Client;

use Modules\InboundMail\Client\MailboxResponseRedactor;
use Modules\InboundMail\Mailbox\MailboxCredentials;
use Modules\InboundMail\Service\MailboxFailureDiagnostic;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;

final class MailboxResponseRedactorTest extends TestCase
{
    /** @return array<string, array{string, string, string, string, string}> */
    public static function overlappingIdentifiers(): array
    {
        $cases = [
            'bare username' => ['locations', 'credential-value', 'locations@unite.be', '[masqué]'],
            'domain fragment' => ['account', 'unite', 'person@unite.be', '[masqué]'],
            'at sign in secret' => ['account', '@unite.be', 'person@unite.be', '[masqué]'],
            'secret begins before address' => ['account', 'prefix person', 'prefix person@unite.be suffix', '[masqué] suffix'],
            'secret ends after address' => ['account', 'person@unite.be suffix', 'prefix person@unite.be suffix', 'prefix [masqué]'],
            'quoted local part' => ['account', 'first', '"first last"@unite.be', '[masqué]'],
            'IPv6 literal' => ['account', '2001:db8', 'person@[IPv6:2001:db8::1]', '[masqué]'],
            'overlapping credentials' => ['abcd', 'cdef', 'abcdef', '[masqué]'],
            'repeated credentials' => ['account', 'credential-value', 'account account', '[masqué] [masqué]'],
        ];
        $result = [];
        foreach ($cases as $name => $case) {
            foreach (['raw', 'normalized', 'diagnostic'] as $stage) {
                $result[$name . ' ' . $stage] = [...$case, $stage];
            }
        }

        return $result;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('overlappingIdentifiers')]
    public function testEveryOverlappingIdentifierIsFullyMasked(
        string $username, string $password, string $input, string $expected, string $stage
    ): void {
        $credentials = new MailboxCredentials($username, $password);
        $actual = match ($stage) {
            'raw' => MailboxResponseRedactor::maskKnownSecrets($input, $credentials),
            'normalized' => MailboxResponseRedactor::redact($input, $credentials),
            default => MailboxFailureDiagnostic::from(new ImapServerErrorException('NO [UNAVAILABLE] ' . $input),
                $credentials)->details['server_response'],
        };

        self::assertSame(($stage === 'diagnostic' ? 'NO [UNAVAILABLE] ' : '') . $expected, $actual);
    }

    /** @return array<string, array{string, string}> */
    public static function authenticationResponses(): array
    {
        return [
            'app-password explanation' => ['NO [ALERT] Use your app password, not your login password',
                'NO [ALERT] Use your app password, not your login password'],
            'disabled-login explanation' => ['NO [AUTHENTICATIONFAILED] Your login is disabled; enable IMAP and retry',
                'NO [AUTHENTICATIONFAILED] Your login is disabled; enable IMAP and retry'],
            'title-case prose' => ['NO [ALERT] Your Login is disabled', 'NO [ALERT] Your Login is disabled'],
            'uppercase command' => ['BAD LOGIN user unknown-token', 'BAD LOGIN [masqué]'],
            'lowercase command' => ['BAD login user unknown-token', 'BAD login [masqué]'],
            'tagged mixed-case command' => ['NO [ALERT] A12 Authenticate PLAIN unknown-token', 'NO [ALERT] A12 Authenticate [masqué]'],
            'embedded uppercase command' => ['NO [ALERT] Rejected command LOGIN user unknown-token',
                'NO [ALERT] Rejected command LOGIN [masqué]'],
            'command with controls' => ["BAD lo\0gin user unknown-token", 'BAD login [masqué]'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('authenticationResponses')]
    public function testCommandsAreMaskedWithoutRemovingOrdinaryLoginExplanations(string $input, string $expected): void
    {
        $diagnostic = MailboxFailureDiagnostic::from(new ImapServerErrorException($input),
            new MailboxCredentials('box@example.test', 'credential-value'));

        self::assertSame($expected, $diagnostic->details['server_response']);
    }

    public function testInvalidServerEncodingCannotLeavePartOfAnAddressAfterMasking(): void
    {
        $credentials = new MailboxCredentials('locations', 'credential-value');
        $input = "NO [UNAVAILABLE] \xff locations@unite.be";

        $previousSubstitute = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            self::assertSame([
                "NO [UNAVAILABLE] \xff [masqué]",
                "NO [UNAVAILABLE] \u{FFFD} [masqué]",
            ], [
                MailboxResponseRedactor::maskKnownSecrets($input, $credentials),
                MailboxFailureDiagnostic::from(new ImapServerErrorException($input), $credentials)->details['server_response'],
            ]);
        } finally {
            mb_substitute_character($previousSubstitute);
        }
    }

    public function testASecretInsideTheCommandNameCannotExposeItsUnknownPayload(): void
    {
        $responses = [];
        foreach ([['LOG', 'BAD LOGIN user unknown-token'], ['LO', "BAD LO\0GIN user unknown-token"]] as [$username, $input]) {
            $responses[] = MailboxFailureDiagnostic::from(new ImapServerErrorException($input),
                new MailboxCredentials($username, 'credential-value'))->details['server_response'];
        }

        self::assertSame(['BAD [masqué]IN [masqué]', 'BAD [masqué]GIN [masqué]'], $responses);
    }

    public function testRawMaskingPreservesLineBoundariesInsideCredentials(): void
    {
        $responses = [];
        foreach (["\n", "\r\n", "\r"] as $lineBreak) {
            $credentials = new MailboxCredentials('box@example.test', 'prefix' . $lineBreak . 'suffix');
            $responses[] = MailboxResponseRedactor::maskKnownSecrets(
                'NO ' . $credentials->password . ' Later content', $credentials
            );
        }

        self::assertSame(["NO [masqué]\n Later content", "NO [masqué]\r\n Later content",
            "NO [masqué]\r Later content"], $responses);
    }
}
