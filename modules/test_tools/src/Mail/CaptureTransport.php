<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\TestTools\Mail;

use Core\File\EncryptedFileStorageService;
use Core\Journal\JournalService;
use Core\Mail\MailErrorRedaction;
use Core\Mail\MailPurpose;
use Core\Mail\MailTransportInterface;
use Modules\TestTools\Repository\CapturedEmailRepository;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * The mail sandbox's transport (ARCHITECTURE.md §8.63): assemble the
 * message exactly as it would have been sent, then store it instead of
 * putting it on the wire.
 *
 * Capture has **exactly one exception, and it is a category, never an
 * address**: the sign-in link (`MailPurpose::MagicLink`). Without it the
 * sandbox deadlocks the installation it is meant to make testable — with
 * capture armed the sign-in e-mail lands on the sandbox page instead of in
 * an inbox, so nobody can sign in to the site being tested, and nobody who
 * signed in that way can get back to disarm the capture. The exception is
 * off by default and the operator turns it on knowingly
 * (`mail_capture_deliver_magic_links`).
 *
 * There is still no whitelist of addresses that really go out and no
 * per-recipient exception, and there never will be: an operator who has to
 * reason about which half of the mail left the server has a tool that
 * cannot answer "what did this feature actually send?". The one exception
 * survives that test because it stays answerable — a sign-in link that
 * went out is **still filed in the sandbox**, flagged `delivered`, rather
 * than becoming invisible. Nothing leaves the server without leaving a
 * trace behind: the row when it can be written, and an `error`-level
 * journal entry naming the gap when the filing itself fails.
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

    /**
     * @param MailTransportInterface $passThrough How an exempted message is
     *   actually delivered — the ordinary transport, built by the factory,
     *   so an exempted mail goes out through exactly the code path it
     *   would have used had the sandbox not been armed at all.
     * @param bool $deliversMagicLinks Whether the sign-in link is exempted
     *   right now. Read once, at the moment the composition root built this
     *   transport, exactly like the arm switch itself.
     * @param JournalService|null $journal Where a delivery that happened but
     *   could not be filed is written down — see sendThenCapture(). Nullable
     *   for the same reason MailService's is: a caller that has no journal to
     *   give must still be able to build one of these.
     */
    public function __construct(
        private CapturedEmailRepository $repository,
        private EncryptedFileStorageService $fileStorage,
        private MailTransportInterface $passThrough,
        private bool $deliversMagicLinks = false,
        private ?JournalService $journal = null
    ) {
    }

    /**
     * Whether this transport lets sign-in links out.
     *
     * Exposed so the wiring that reads the setting
     * (`CaptureTransportFactory`) can be asserted end to end. The only
     * other way to observe it is to actually deliver a message, which is
     * the one thing a test must never do.
     */
    public function deliversMagicLinks(): bool
    {
        return $this->deliversMagicLinks;
    }

    public function deliver(PHPMailer $mail, MailPurpose $purpose): void
    {
        // Read off the instance BEFORE anything assembles or sends: the
        // source paths are frequently temporary files that will not exist
        // by the time the sandbox is opened, so each attachment is copied
        // into encrypted storage now. This also means the sandbox never
        // has to parse MIME to offer a download.
        $attachments = $this->storeAttachments($mail);

        if ($purpose === MailPurpose::MagicLink && $this->deliversMagicLinks) {
            $this->sendThenCapture($mail, $attachments);

            return;
        }

        $this->captureWithoutSending($mail, $attachments);
    }

    /**
     * The ordinary path: assemble the message and file it, and nothing
     * reaches the network.
     *
     * @param array<int, array{file_name: string, mime_type: string, size_bytes: int, file_id: int|null}> $attachments
     */
    private function captureWithoutSending(PHPMailer $mail, array $attachments): void
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
        // This is also why it is done HERE and not in deliver(): a message
        // the sandbox lets through must be delivered in the mode the
        // installation is actually configured for, and `local` mode hands
        // off to mail() rather than to an SMTP server that may not exist.
        $mail->isSMTP();

        try {
            // preSend() is the entire library minus the network hop:
            // recipient validation, MIME assembly, attachment encoding,
            // header construction and the DKIM signature. postSend() — the
            // half that talks SMTP or hands off to mail() — is never
            // called from this method, here or anywhere else in this
            // module; the one path that does send delegates to the
            // ordinary transport instead.
            $mail->preSend();
        } catch (\Exception $e) {
            // A failure during assembly is captured too, then rethrown so
            // the caller sees the same MailException it would have seen. A
            // test tool that silently swallows a broken mail is worse than
            // useless.
            $this->recordFailure($mail, $attachments, $e);

            throw $e;
        }

        $this->record($mail, $mail->getSentMIMEMessage(), $attachments, delivered: false);
    }

    /**
     * The exempted path: hand the message to the ordinary transport, then
     * file what it actually sent.
     *
     * Sending FIRST is what makes the stored copy honest. `send()` leaves
     * the assembled message on the instance, so `getSentMIMEMessage()`
     * afterwards returns the very bytes that went out — nothing is
     * re-assembled, and no boundary, Message-ID or DKIM signature can
     * differ between what the recipient received and what the sandbox
     * shows. Assembling first and sending afterwards would have produced
     * two different messages and no way to tell which one was which.
     *
     * @param array<int, array{file_name: string, mime_type: string, size_bytes: int, file_id: int|null}> $attachments
     */
    private function sendThenCapture(PHPMailer $mail, array $attachments): void
    {
        try {
            $this->passThrough->deliver($mail, MailPurpose::MagicLink);
        } catch (\Exception $e) {
            // Same contract as a failed assembly: the row is written, the
            // exception continues to the caller. `delivered` stays false,
            // because it did not.
            $this->recordFailure($mail, $attachments, $e);

            throw $e;
        }

        // PAST THIS LINE THE MESSAGE HAS LEFT THE SERVER, and everything
        // that follows is bookkeeping. Letting a failed write out of here
        // would make MailService journal `mail_send_failed` and hand
        // AuthService a MailException for a sign-in link that is already
        // in somebody's inbox — the site telling a visitor their e-mail
        // could not be sent while they are reading it. A storage or
        // database fault is not a delivery fault and must not be reported
        // as one.
        //
        // It is not swallowed either: the journal is the one place that
        // can still say a message went out the sandbox does not have.
        try {
            $this->record($mail, $mail->getSentMIMEMessage(), $attachments, delivered: true);
        } catch (\Throwable $e) {
            $this->journalUnfiledDelivery($e);
        }
    }

    /**
     * A message that left the server and could not be filed.
     *
     * `error`, like MailService's own failure entry, and for the same
     * reason: something the site did is missing from the record it keeps
     * of what it did. No address in the entry, and the transport's words
     * go through the shared redaction — the exception here is as likely
     * to quote a recipient as any other mail error.
     *
     * The entry can never fail the send, exactly as MailService's cannot:
     * this is already the fallback path, and the conditions that break an
     * encrypted write (a database that just went away) are the ones that
     * break a journal insert too.
     */
    private function journalUnfiledDelivery(\Throwable $e): void
    {
        try {
            $this->journal?->log(
                self::MODULE_ID,
                'mail_capture_delivery_unfiled',
                'error',
                'Un lien de connexion est bien parti mais n\'a pas pu être rangé dans le bac à sable.',
                ['reason' => MailErrorRedaction::withoutAddresses($e->getMessage())]
            );
        } catch (\Throwable) {
            // Swallowed on purpose — see the docblock.
        }
    }

    /**
     * Files one message and the three encrypted files behind it.
     *
     * @param array<int, array{file_name: string, mime_type: string, size_bytes: int, file_id: int|null}> $attachments
     */
    private function record(PHPMailer $mail, string $message, array $attachments, bool $delivered): void
    {
        // The two body parts, taken from the library rather than carved
        // back out of the message it just assembled. Same reasoning as the
        // attachments: PHPMailer already knows what it built, so the
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
            recipient: self::recipientOf($mail),
            fromAddress: $mail->From,
            replyTo: self::replyToOf($mail),
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
            attachments: $attachments,
            delivered: $delivered
        );
    }

    /**
     * Files a message that never made it — a broken assembly, or a
     * delivery the mail server refused. There is no raw message to store
     * in either case, only the library's own error.
     *
     * @param array<int, array{file_name: string, mime_type: string, size_bytes: int, file_id: int|null}> $attachments
     */
    private function recordFailure(PHPMailer $mail, array $attachments, \Exception $e): void
    {
        $this->repository->create(
            capturedAt: new \DateTimeImmutable(),
            subject: $mail->Subject,
            recipient: self::recipientOf($mail),
            fromAddress: $mail->From,
            replyTo: self::replyToOf($mail),
            sizeBytes: 0,
            hasDkim: false,
            mimeFileId: null,
            bodyHtmlFileId: null,
            bodyTextFileId: null,
            // Through the SAME redaction the journal uses. PHPMailer glues
            // the address that failed to the SMTP code that explains it,
            // and a per-recipient refusal (« 550 5.1.1 <x@y> User
            // unknown ») is the ordinary case on the exempted path, not an
            // edge one. Writing it verbatim would put a recipient in clear
            // in the very table that encrypts `recipient` as a BLOB
            // because a recipient is personal data.
            errorMessage: MailErrorRedaction::withoutAddresses(
                $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage()
            ),
            attachments: $attachments,
            delivered: false
        );
    }

    private static function recipientOf(PHPMailer $mail): string
    {
        $recipients = $mail->getToAddresses();

        return $recipients[0][0] ?? '';
    }

    private static function replyToOf(PHPMailer $mail): ?string
    {
        $replyToAddresses = array_values($mail->getReplyToAddresses());

        return $replyToAddresses[0][0] ?? null;
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
