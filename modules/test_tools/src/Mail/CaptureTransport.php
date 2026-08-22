<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\TestTools\Mail;

use Core\File\EncryptedFileStorageService;
use Core\Mail\MailTransportInterface;
use Modules\TestTools\Repository\CapturedEmailRepository;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * The mail sandbox's transport (ARCHITECTURE.md §8.63): assemble the
 * message exactly as it would have been sent, then store it instead of
 * putting it on the wire.
 *
 * Capture is **all-or-nothing** by design. There is no "capture and also
 * send", no whitelist of addresses that really go out and no per-recipient
 * exception: an operator who has to reason about which half of the mail
 * left the server has a tool that cannot be trusted to answer "what did
 * this feature actually send?".
 */
final class CaptureTransport implements MailTransportInterface
{
    /**
     * Attachments and the raw message are stored under this role, so
     * FileAccessGuard applies to them exactly as to any other file and only
     * a superadmin can fetch one through /files/{id}.
     */
    private const FILE_ROLE_MIN = 'superadmin';

    private const MODULE_ID = 'test_tools';

    private const STORAGE_SUBDIRECTORY = 'modules/test_tools/mail';

    public function __construct(
        private CapturedEmailRepository $repository,
        private EncryptedFileStorageService $fileStorage
    ) {
    }

    public function deliver(PHPMailer $mail): void
    {
        // Force SMTP semantics before assembling. In `mail` mode PHPMailer
        // moves To and Subject out of MIMEHeader into mailHeader (they
        // become arguments to mail()) and switches line endings to
        // PHP_EOL; the captured result stays complete either way, because
        // both halves are read below, but SMTP shape — CRLF endings, the
        // full header set in one block — is the realistic thing to inspect
        // and the shape a real mail client expects from a .eml file.
        //
        // Nothing is actually connected to: preSend() never opens a socket.
        $mail->isSMTP();

        $recipients = $mail->getToAddresses();
        $recipient = $recipients[0][0] ?? '';
        $replyToAddresses = array_values($mail->getReplyToAddresses());
        $replyTo = $replyToAddresses[0][0] ?? null;

        // Read off the instance BEFORE preSend(): the source paths are
        // frequently temporary files that will not exist by the time the
        // sandbox is opened, so each attachment is copied into encrypted
        // storage now. This also means the sandbox never has to parse MIME
        // to offer a download.
        $attachments = $this->storeAttachments($mail);

        try {
            // preSend() is the entire library minus the network hop:
            // recipient validation, MIME assembly, attachment encoding,
            // header construction and the DKIM signature. postSend() — the
            // half that talks SMTP or hands off to mail() — is never
            // called, here or anywhere else in this module.
            $mail->preSend();
        } catch (\Exception $e) {
            // A failure during assembly is captured too, then rethrown so
            // the caller sees the same MailException it would have seen. A
            // test tool that silently swallows a broken mail is worse than
            // useless.
            $this->repository->create(
                capturedAt: new \DateTimeImmutable(),
                subject: $mail->Subject,
                recipient: $recipient,
                fromAddress: $mail->From,
                replyTo: $replyTo,
                sizeBytes: 0,
                hasDkim: false,
                mimeFileId: null,
                bodyHtmlFileId: null,
                bodyTextFileId: null,
                errorMessage: $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage(),
                attachments: $attachments
            );

            throw $e;
        }

        $message = $mail->getSentMIMEMessage();

        // The two body parts, taken from the library rather than carved
        // back out of the message it just assembled. Same reasoning as the
        // attachments above: PHPMailer already knows what it built, so the
        // sandbox never has to walk MIME boundaries to show a preview.
        $bodyHtmlFileId = $this->storeBodyPart($mail->Body, 'text/html', 'corps.html');
        $bodyTextFileId = $this->storeBodyPart($mail->AltBody, 'text/plain', 'corps.txt');

        $mimeFileId = $this->fileStorage->store(
            content: $message,
            mimeType: 'message/rfc822',
            originalName: $this->emlFileName($mail->Subject),
            subDirectory: self::STORAGE_SUBDIRECTORY,
            roleMin: self::FILE_ROLE_MIN,
            moduleId: self::MODULE_ID
        );

        $this->repository->create(
            capturedAt: new \DateTimeImmutable(),
            subject: $mail->Subject,
            recipient: $recipient,
            fromAddress: $mail->From,
            replyTo: $replyTo,
            sizeBytes: strlen($message),
            // The header, not the configured key: a key can be present and
            // the signature still absent (an unreadable key file, an empty
            // DKIM_domain), and what the operator needs to know is whether
            // THIS message carries one.
            hasDkim: stripos($message, 'DKIM-Signature:') !== false,
            mimeFileId: $mimeFileId,
            bodyHtmlFileId: $bodyHtmlFileId,
            bodyTextFileId: $bodyTextFileId,
            errorMessage: null,
            attachments: $attachments
        );
    }

    /**
     * An empty part is stored as nothing rather than as an empty file: the
     * detail page distinguishes "this message had no plain-text half" from
     * "its plain-text half was empty", and a null id is how it does.
     */
    private function storeBodyPart(string $content, string $mimeType, string $name): ?int
    {
        if (trim($content) === '') {
            return null;
        }

        return $this->fileStorage->store(
            content: $content,
            mimeType: $mimeType,
            originalName: $name,
            subDirectory: self::STORAGE_SUBDIRECTORY,
            roleMin: self::FILE_ROLE_MIN,
            moduleId: self::MODULE_ID
        );
    }

    /**
     * Copies each attachment into encrypted storage and returns the
     * metadata rows to persist alongside the message.
     *
     * A file that cannot be read is recorded with a null file_id rather
     * than dropped: "this message carried an attachment the sandbox could
     * not keep" is information; a silently shorter list is not.
     *
     * @return array<int, array{file_name: string, mime_type: string, size_bytes: int, file_id: int|null}>
     */
    private function storeAttachments(PHPMailer $mail): array
    {
        $stored = [];

        foreach ($mail->getAttachments() as $attachment) {
            // PHPMailer's own tuple: 0 path-or-string, 2 display name,
            // 4 MIME type, 5 whether index 0 is the content itself.
            $isStringAttachment = (bool) ($attachment[5] ?? false);
            $name = (string) ($attachment[2] ?? '');
            $mimeType = (string) ($attachment[4] ?? 'application/octet-stream');

            $content = $isStringAttachment
                ? (string) ($attachment[0] ?? '')
                : self::readFile((string) ($attachment[0] ?? ''));

            if ($content === null) {
                $stored[] = [
                    'file_name' => $name,
                    'mime_type' => $mimeType,
                    'size_bytes' => 0,
                    'file_id' => null,
                ];
                continue;
            }

            $stored[] = [
                'file_name' => $name,
                'mime_type' => $mimeType,
                'size_bytes' => strlen($content),
                'file_id' => $this->fileStorage->store(
                    content: $content,
                    mimeType: $mimeType,
                    originalName: $name !== '' ? $name : 'piece-jointe',
                    subDirectory: self::STORAGE_SUBDIRECTORY,
                    roleMin: self::FILE_ROLE_MIN,
                    moduleId: self::MODULE_ID
                ),
            ];
        }

        return $stored;
    }

    private static function readFile(string $path): ?string
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }

    /**
     * A download name a real mail client will open. The subject can contain
     * anything, so only word characters survive it.
     */
    private function emlFileName(string $subject): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $subject) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'message';
        }

        return mb_substr($slug, 0, 80) . '.eml';
    }
}
