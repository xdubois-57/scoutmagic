<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\File\EncryptedFileStorageService;
use Core\Mail\MailService;
use Core\Member\MemberDocumentMailer;
use PHPUnit\Framework\TestCase;

/**
 * The message a private member document travels in (issue #1096): it names
 * the member the document belongs to, and says only the title when there is
 * no name to give.
 */
class MemberDocumentMailerTest extends TestCase
{
    private string $storagePath;

    /** @var array{to: string, subject: string, html: string, text: string, attachments: array<int, mixed>}|null */
    private ?array $sent = null;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/member_document_mailer_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @rmdir($this->storagePath . '/temp');
        @rmdir($this->storagePath);
    }

    private function mailer(): MemberDocumentMailer
    {
        $mail = $this->createMock(MailService::class);
        $mail->expects($this->once())->method('send')->willReturnCallback(
            function (string $to, string $subject, string $html, string $text, ?string $replyTo, array $attachments): void {
                $this->sent = [
                    'to' => $to,
                    'subject' => $subject,
                    'html' => $html,
                    'text' => $text,
                    'attachments' => $attachments,
                ];
            }
        );
        $files = $this->createStub(EncryptedFileStorageService::class);
        $files->method('retrieve')->willReturn('%PDF-1.4 fake');

        return new MemberDocumentMailer($mail, $files, $this->storagePath);
    }

    public function testTheSubjectAndBothBodiesNameTheMember(): void
    {
        $this->mailer()->send('Attestation fiscale 2025', 7, 'famille@example.org', 'Unité Saint-Michel', 'emma DUPONT');

        $this->assertNotNull($this->sent);
        $this->assertSame('famille@example.org', $this->sent['to']);
        $this->assertSame('Attestation fiscale 2025 — Emma Dupont', $this->sent['subject']);
        $this->assertStringContainsString(
            'le document <strong>Attestation fiscale 2025</strong> concernant <strong>Emma Dupont</strong>.',
            $this->sent['html']
        );
        $this->assertStringContainsString('dans ses documents privés', $this->sent['html']);
        $this->assertStringContainsString(
            'le document « Attestation fiscale 2025 » concernant Emma Dupont.',
            $this->sent['text']
        );
        $this->assertStringContainsString('Unité Saint-Michel', $this->sent['text']);
    }

    /**
     * The attachment keeps the title alone: a file name travels through a
     * downloads folder and a backup.
     */
    public function testTheAttachmentNameDoesNotCarryTheMember(): void
    {
        $this->mailer()->send('Attestation fiscale 2025', 7, 'famille@example.org', 'Unité', 'Emma Dupont');

        $this->assertNotNull($this->sent);
        $this->assertSame('Attestation-fiscale-2025.pdf', $this->sent['attachments'][0]['name']);
    }

    public function testANameIsEscapedInTheHtmlAndKeptAsIsInTheText(): void
    {
        $this->mailer()->send('Attestation', 7, 'famille@example.org', 'Unité', "Zoé <b>d'Arc</b>");

        $this->assertNotNull($this->sent);
        $this->assertStringNotContainsString('<b>', $this->sent['html']);
        $this->assertStringContainsString('&lt;b&gt;', $this->sent['html']);
        $this->assertStringContainsString('&#039;', $this->sent['html']);
        $this->assertStringContainsString("d'Arc", $this->sent['text']);
    }

    /**
     * No name, or none worth showing: the message says what it always said,
     * with the title alone — never a name it is not sure of.
     */
    public function testWithoutANameTheMessageCarriesTheTitleAlone(): void
    {
        foreach ([null, '', "   \n "] as $missing) {
            $this->sent = null;
            $this->mailer()->send('Attestation fiscale 2025', 7, 'famille@example.org', 'Unité', $missing);

            $this->assertNotNull($this->sent);
            $this->assertSame('Attestation fiscale 2025', $this->sent['subject'], json_encode($missing));
            $this->assertStringContainsString('votre document : <strong>Attestation fiscale 2025</strong>', $this->sent['html']);
            $this->assertStringNotContainsString('concernant', $this->sent['text']);
        }
    }

    /** The name goes into a Subject header: it is one line, whatever was stored. */
    public function testALineBreakInTheNameNeverReachesTheSubject(): void
    {
        $this->mailer()->send('Attestation', 7, 'famille@example.org', 'Unité', "Emma\r\nBcc: x@example.org");

        $this->assertNotNull($this->sent);
        $this->assertStringNotContainsString("\n", $this->sent['subject']);
        $this->assertStringNotContainsString("\r", $this->sent['subject']);
    }
}
