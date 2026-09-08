<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Mail\MailErrorRedaction;
use PHPUnit\Framework\TestCase;

/**
 * The one implementation of a control with two persistence sites
 * (ARCHITECTURE.md §8.7): the journal's `reason`, and the mail sandbox's
 * `captured_emails.error_message`.
 */
class MailErrorRedactionTest extends TestCase
{
    public function testTheAddressGoesAndTheDiagnosisStays(): void
    {
        $clean = MailErrorRedaction::withoutAddresses(
            'SMTP Error: 550 5.1.1 <camille.dupont@example.be> User unknown'
        );

        $this->assertStringNotContainsString('camille.dupont@example.be', $clean);
        $this->assertStringContainsString('[adresse]', $clean);
        // The SMTP code and the server's words are the whole diagnosis.
        $this->assertStringContainsString('550 5.1.1', $clean);
        $this->assertStringContainsString('User unknown', $clean);
    }

    public function testEveryAddressGoes(): void
    {
        $clean = MailErrorRedaction::withoutAddresses(
            'RCPT TO a@b.be failed; RCPT TO c.d+tag@sub.example.co.uk failed'
        );

        $this->assertStringNotContainsString('@', $clean);
        $this->assertSame(2, substr_count($clean, '[adresse]'));
    }

    /**
     * A transport error is routinely not valid UTF-8 — a relay banner, a
     * driver message, a stray byte from a misconfigured server. The
     * pattern is byte-oriented precisely so `preg_replace()` cannot
     * return null here and turn the one clue into an empty string.
     */
    public function testAnErrorThatIsNotValidUtf8StillRedactsAndStillSaysSomething(): void
    {
        $clean = MailErrorRedaction::withoutAddresses(
            "550 \xC3\x28 refus\xE9 pour destinataire@example.be"
        );

        $this->assertNotSame('', $clean);
        $this->assertStringNotContainsString('destinataire@example.be', $clean);
        $this->assertStringContainsString('550', $clean);
    }

    public function testAWallOfTextIsBounded(): void
    {
        $clean = MailErrorRedaction::withoutAddresses(str_repeat('x', 5000));

        $this->assertSame(400, mb_strlen($clean));
    }
}
