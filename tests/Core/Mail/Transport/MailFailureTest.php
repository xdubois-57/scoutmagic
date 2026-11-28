<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Mail\Transport\MailFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whose fault a `550` is, read from its enhanced status code (issue #1488).
 *
 * A relay answers « 550 » for anything it refuses, its own sending quota
 * included. Read as a bad address, a quota refusal failed the message
 * instead of queueing it, never counted against a relay that will refuse
 * everything until tomorrow, and abandoned a queued message at its first
 * retry. The enhanced code is what tells the two apart.
 *
 * **The responses below are quoted from the providers' public
 * documentation**, not observed from a relay this site uses:
 * Google Workspace's « Gmail SMTP errors and codes » page and Microsoft's
 * « Email nondelivery reports (NDRs) and SMTP errors in Exchange Online »,
 * plus one OVHcloud community report for a quota message with no code.
 * The PHPMailer-shaped cases are the same responses as `ErrorInfo`
 * carries them once PHPMailer's SMTP client has cut the codes off the
 * server's lines and appended them again (`PHPMailer::setError()`).
 */
class MailFailureTest extends TestCase
{
    #[DataProvider('responses')]
    public function testAResponseIsClassifiedByItsEnhancedCode(string $reason, MailFailure $expected): void
    {
        $this->assertSame($expected, MailFailure::classify($reason));
    }

    /**
     * @return array<string, array{string, MailFailure}>
     */
    public static function responses(): array
    {
        return [
            // ── the sender's quota: the provider's, so queued and counted ──
            'Gmail daily sending limit' => ['550 5.4.5 Daily user sending limit exceeded.', MailFailure::Provider],
            'Gmail relay limit, multiline' => [
                "550-5.4.5 Daily SMTP relay limit exceeded for user. For more information on\r\n"
                    . '550 5.4.5 SMTP relay sending limits - gsmtp',
                MailFailure::Provider,
            ],
            // The real shape: a refusal at RCPT TO reads « recipients
            // failed », which alone would say « bad address ».
            'Gmail daily limit, as PHPMailer reports it' => [
                'SMTP Error: The following recipients failed: [adresse]: Daily user sending limit exceeded.'
                    . ' SMTP server error: RCPT TO command failed Detail: Daily user sending limit exceeded.'
                    . ' SMTP code: 550 Additional SMTP info: 5.4.5',
                MailFailure::Provider,
            ],
            'Microsoft daily recipient limit, inside the 5.1 family' => [
                "550 5.1.90 Your message can't be sent because you've reached your daily limit for message recipients",
                MailFailure::Provider,
            ],
            'Microsoft submission quota, under the mailbox-full code' => [
                '550 5.2.2 Submission quota exceeded',
                MailFailure::Provider,
            ],
            'Microsoft blocked sender, the sender side of the 5.1 family' => [
                '550 5.1.8 Access denied, bad outbound sender',
                MailFailure::Provider,
            ],
            // A three-digit detail is not taken by PHPMailer's pattern, so
            // it stays in the text after the 550.
            'Microsoft per-hour receive limit, as PHPMailer reports it' => [
                "SMTP Error: data not accepted. SMTP server error: DATA END command failed Detail: 5.2.122"
                    . " Recipient's per hour message receive limit exceeded SMTP code: 550",
                MailFailure::Provider,
            ],
            'a full mailbox, which RFC 3463 calls transient' => [
                "552 5.2.2 The recipient's inbox is out of storage space and inactive.",
                MailFailure::Provider,
            ],
            'a class-4 code is transient, whatever the prose' => [
                '450 4.1.1 Recipient address rejected: unverified address',
                MailFailure::Provider,
            ],
            // ── the address itself: the recipient's, failed at once ──
            'Gmail unknown account' => [
                '550 5.1.1 The email account that you tried to reach does not exist.',
                MailFailure::Recipient,
            ],
            'an unknown account, as PHPMailer reports it' => [
                'SMTP Error: The following recipients failed: [adresse]: The email account that you tried to reach'
                    . ' does not exist. SMTP server error: RCPT TO command failed Detail: The email account that'
                    . ' you tried to reach does not exist. SMTP code: 550 Additional SMTP info: 5.1.1',
                MailFailure::Recipient,
            ],
            'Microsoft recipient not found' => ['550 5.1.10 Recipient not found', MailFailure::Recipient],
            'Gmail inactive account' => [
                '550 5.2.1 The email account that you tried to reach is inactive.',
                MailFailure::Recipient,
            ],
            // ── codes that say nothing: the 550 and the prose decide ──
            'Microsoft unknown outlook.com address' => [
                '550 5.5.0 Requested action not taken: mailbox unavailable',
                MailFailure::Recipient,
            ],
            // ── no enhanced code: unchanged ──
            'a bare 550' => ['550 Requested action not taken', MailFailure::Recipient],
            'OVH hourly limit, no code at all' => [
                'You have exceeded the limit of 200 messages per hour and per account.',
                MailFailure::Provider,
            ],
            // A dotted address is not a code: the old literal « 5.1.1 »
            // read this as a bad recipient.
            'an IP address that contains 5.1.1' => ['SMTP connect() failed to 10.5.1.1', MailFailure::Provider],
            'a recipient refusal next to a version number' => [
                '550 5.1.1 Recipient unknown; server=v4.97.1',
                MailFailure::Recipient,
            ],
            'a recipient refusal next to a version that looks like a policy code' => [
                '550 5.1.1 Recipient unknown; server=v4.7.1',
                MailFailure::Recipient,
            ],
            'a recipient refusal next to an address that looks like a policy code' => [
                '550 5.1.1 Recipient unknown; client [4.7.1.9] not found',
                MailFailure::Recipient,
            ],
            // ── the policy family, read first and unchanged ──
            'Gmail relay limit for the customer, under 5.7.1' => [
                '550 5.7.1 Daily SMTP relay sending limit exceeded for this customer.',
                MailFailure::Provider,
            ],
            'Microsoft tenant over its threshold' => [
                '550 5.7.705 Access denied, tenant has exceeded threshold',
                MailFailure::Provider,
            ],
        ];
    }

    /**
     * A long PHPMailer message keeps, once bounded, the code it is
     * classified by: the cap alone would leave a bare « 550 » that reads
     * as a refused address (#1571 review).
     */
    public function testABoundedReasonKeepsTheCodeItIsReadBy(): void
    {
        $long = 'SMTP Error: The following recipients failed: jean.dupont@exemple.be: '
            . str_repeat('Daily user sending limit exceeded. ', 20)
            . 'SMTP code: 550 Additional SMTP info: 5.4.5';

        $bounded = MailFailure::boundedReason($long);

        $this->assertLessThanOrEqual(400, mb_strlen($bounded));
        $this->assertStringNotContainsString('@', $bounded);
        $this->assertSame('5.4.5', MailFailure::enhancedCode($bounded));
        $this->assertSame(MailFailure::Provider, MailFailure::classify($bounded));
        $this->assertSame(MailFailure::classify($long), MailFailure::classify($bounded));
    }

    /** A reason that fits, or that has no code, is only redacted and bounded. */
    public function testABoundedReasonIsOtherwiseTheRedactedText(): void
    {
        $this->assertSame('550 5.1.1 [adresse] unknown', MailFailure::boundedReason('550 5.1.1 a@b.be unknown'));
        $plain = str_repeat('Connexion refusée. ', 40);
        $this->assertSame(mb_substr(trim($plain), 0, 400), MailFailure::boundedReason($plain));
    }

    #[DataProvider('codes')]
    public function testTheEnhancedCodeIsReadWhereverItIs(string $reason, ?string $expected): void
    {
        $this->assertSame($expected, MailFailure::enhancedCode($reason));
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function codes(): array
    {
        return [
            'after a space' => ['550 5.4.5 Daily user sending limit exceeded.', '5.4.5'],
            'after a hyphen' => ['550-5.4.5 Daily user sending limit exceeded.', '5.4.5'],
            'appended by PHPMailer' => ['SMTP code: 550 Additional SMTP info: 5.4.5', '5.4.5'],
            'at the end of a sentence' => ['refused with 5.1.1.', '5.1.1'],
            'three-digit detail' => ["550 5.2.122 Recipient's per hour message receive limit exceeded", '5.2.122'],
            'leading zeros' => ['550 5.01.001 x', '5.1.1'],
            'the one that decided, of several' => ['550 5.1.1 then 5.4.5', '5.4.5'],
            'a code that says nothing, when it is all there is' => ['550 5.5.0 mailbox unavailable', '5.5.0'],
            'an IP address' => ['connection to 10.5.1.2 refused', null],
            'four dotted numbers starting with a class' => ['host 5.1.1.4 refused', null],
            'a success code' => ['250 2.1.0 Ok', null],
            'a version number' => ['PHPMailer 6.9.1', null],
            'a version number with a letter before it' => ['server=v4.97.1', null],
            'a bare basic code' => ['550 Requested action not taken', null],
            'nothing' => ['', null],
        ];
    }
}
