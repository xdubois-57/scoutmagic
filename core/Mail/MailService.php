<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

use Core\Journal\JournalService;
use PHPMailer\PHPMailer\PHPMailer;

class MailService
{
    public function __construct(
        private string $mode,
        private string $fromAddress,
        private string $fromName,
        private string $shortName,
        private DkimManager $dkimManager,
        private string $dkimSelector,
        private ?string $smtpHost = null,
        private ?int $smtpPort = null,
        private ?string $smtpUser = null,
        private ?string $smtpPassword = null,
        // How an already-configured message is actually delivered
        // (ARCHITECTURE.md §8.7). A real default rather than a nullable
        // dependency, so there is exactly one delivery path and the ~95
        // call sites of send() never learn a transport exists. The
        // composition root swaps it (and only it) to capture mail instead
        // of sending it.
        private MailTransportInterface $transport = new PhpMailerTransport(),
        /**
         * Where a delivery that did NOT happen is written down.
         *
         * Nullable, and only because of the one caller that has no
         * journal to give: Core\Http\Controller\SetupController tests
         * the values sitting in the setup form, on an installation whose
         * database may not exist yet. Every composition root passes it.
         */
        private ?JournalService $journal = null
    ) {
    }

    /**
     * The configured delivery transport, `smtp` or `local`.
     *
     * Exposed for diagnostics (Core\Statistics\StatisticsPayloadBuilder,
     * ARCHITECTURE.md §8.47) — a transport name, never a host, a port, a
     * user or a password.
     */
    public function getDeliveryMode(): string
    {
        return $this->mode === 'smtp' ? 'smtp' : 'local';
    }

    /**
     * Whether outgoing mail can plausibly be delivered: a From address is
     * set and, in SMTP mode, a host and credentials are present.
     *
     * A boolean, deliberately — the same diagnostics caller must be able to
     * report "email is configured" without any of the values that make it so
     * ever leaving the installation.
     */
    public function isDeliveryConfigured(): bool
    {
        if (trim($this->fromAddress) === '') {
            return false;
        }

        if ($this->getDeliveryMode() !== 'smtp') {
            return true;
        }

        return trim((string) $this->smtpHost) !== ''
            && trim((string) $this->smtpUser) !== ''
            && (string) $this->smtpPassword !== '';
    }

    /**
     * Send a transactional email.
     *
     * @param array<int, array{path: string, name: string}> $attachments Absolute filesystem path + display name pairs.
     * @param string|null $fromAddressOverride Use this address as the visible From instead of the site's configured
     *                                          default (e.g. mass_mail sending "as" the sender section). DKIM still
     *                                          signs under the site's own configured domain regardless — that's the
     *                                          only domain with a verified key/DNS record, and mismatching it against
     *                                          an arbitrary override domain would produce an invalid signature.
     * @param array<string, string> $extraHeaders Raw header name => value pairs added as-is (e.g. mass_mail's
     *                                             List-Unsubscribe / List-Unsubscribe-Post, RFC 8058) — the caller is
     *                                             responsible for values being header-safe (no newlines).
     * @throws MailException on failure
     */
    public function send(
        string $to,
        string $subject,
        string $bodyHtml,
        string $bodyText,
        ?string $replyTo = null,
        array $attachments = [],
        ?string $fromAddressOverride = null,
        ?string $fromNameOverride = null,
        array $extraHeaders = []
    ): void {
        $mail = new PHPMailer(true);

        try {
            $mail->CharSet = 'UTF-8';

            // Transport mode
            if ($this->mode === 'smtp') {
                $mail->isSMTP();
                $mail->Host = $this->smtpHost ?? '';
                $mail->Port = $this->smtpPort ?? 587;
                $mail->SMTPAuth = true;
                $mail->Username = $this->smtpUser ?? '';
                $mail->Password = $this->smtpPassword ?? '';
                $mail->SMTPSecure = $mail->Port === 465
                    ? PHPMailer::ENCRYPTION_SMTPS
                    : PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->isMail();
            }

            // Sender — an override (e.g. the mailing's own sender section)
            // replaces the visible From, but never the envelope Sender,
            // which stays the site's own address for bounce handling.
            $fromAddress = $fromAddressOverride ?? $this->fromAddress;
            $fromName = $fromNameOverride ?? $this->fromName;
            $mail->setFrom($fromAddress, $fromName);
            $mail->Sender = $this->fromAddress;

            // Recipient
            $mail->addAddress($to);

            // Reply-To
            if ($replyTo !== null) {
                $mail->addReplyTo($replyTo);
            }

            // Attachments
            foreach ($attachments as $attachment) {
                $mail->addAttachment($attachment['path'], $attachment['name']);
            }

            foreach ($extraHeaders as $name => $value) {
                $mail->addCustomHeader($name, $value);
            }

            // DKIM signing — deliberately always the site's own configured
            // address/domain, never $fromAddressOverride (see the param doc above).
            if ($this->dkimManager->hasKey()) {
                $domain = $this->extractDomain($this->fromAddress);
                $mail->DKIM_domain = $domain;
                $mail->DKIM_selector = $this->dkimSelector;
                $mail->DKIM_private = $this->dkimManager->getPrivateKeyPath();
                $mail->DKIM_identity = $this->fromAddress;
            }

            // Subject with prefix
            $mail->Subject = "[{$this->shortName}] {$subject}";

            // Multipart body
            $mail->isHTML(true);
            $mail->Body = $bodyHtml;
            $mail->AltBody = $bodyText;

            // The delivery step, and only the delivery step: everything
            // above stays here so a captured message is byte-for-byte the
            // message that would have gone out.
            $this->transport->deliver($mail);
        } catch (\Exception $e) {
            $reason = $mail->ErrorInfo ?: $e->getMessage();
            $this->journalFailure($reason);

            throw new MailException($reason);
        }
    }

    /**
     * An e-mail that did not leave, written down — **whatever** the
     * caller then does with the exception.
     *
     * Here and not in the callers, because the callers are the problem.
     * There are some ninety send() sites; a handful journal a failure
     * themselves, a handful more swallow the exception on purpose
     * (`catch (MailException) {}` — a notification is not worth failing
     * the action it accompanies), and the rest turn it into a message on
     * a screen that is gone as soon as the page is reloaded. The result
     * was an administrator watching « l'e-mail de test n'a pas pu
     * partir » and finding nothing at all in /admin/journal — which is
     * also what the diagnostic archive carries, so the failure was
     * invisible from both ends at once. One entry at the single point
     * every one of those paths goes through is the only version of this
     * that cannot be forgotten by the next call site.
     *
     * `error`, not `warning`: something the site decided to send did not
     * go out. Nothing above this line judges whether the caller thought
     * it important, and nothing should — the whole point is that the
     * journal stops depending on that judgement.
     *
     * The entry never fails the send. It is already the failure path, and
     * a journal insert that throws (no table yet, a database that just
     * went away — the very conditions under which mail also stops
     * working) must not replace a MailException the caller knows how to
     * read with a PDOException it does not.
     */
    private function journalFailure(string $reason): void
    {
        try {
            $this->journal?->log(
                'core',
                'mail_send_failed',
                'error',
                'Échec d\'envoi d\'un e-mail',
                [
                    // Which of the two configurations was in play, and
                    // whether it was complete at all — « not configured »
                    // and « the relay refused » are the same sentence on
                    // screen and completely different problems.
                    'mode' => $this->getDeliveryMode(),
                    'configured' => $this->isDeliveryConfigured(),
                    'origin' => self::callerOutsideThisNamespace(),
                    'reason' => self::redact($reason),
                ]
            );
        } catch (\Throwable) {
            // Swallowed on purpose — see the docblock.
        }
    }

    /**
     * What the transport said, with e-mail addresses taken out.
     *
     * PHPMailer's `ErrorInfo` quotes the conversation, and the useful
     * half of it — « 550 5.1.1 User unknown », « Could not authenticate »,
     * « SMTP connect() failed » — arrives glued to the address that
     * failed. That address is personal data, this journal is readable by
     * every admin and travels to the maintainer inside the diagnostic
     * archive, and SECURITY.md's rule for journal entries has no
     * exception for « it was in the error message ». The SMTP code and
     * the server's words are what diagnoses the problem anyway; the
     * address only says which member happened to be next in the queue.
     *
     * Bounded too: `event_log.context` is JSON on a shared-hosting
     * database, and a transport that answers with a wall of text is
     * exactly the transport that is misbehaving.
     */
    private static function redact(string $reason): string
    {
        $clean = (string) preg_replace(
            '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u',
            '[adresse]',
            trim($reason)
        );

        return mb_substr($clean, 0, 400);
    }

    /**
     * The first frame that is not this class — « which feature was trying
     * to send », in a service that is deliberately told nothing about it.
     *
     * send() takes a recipient, a subject and a body and nothing that
     * says why, which is right: ninety call sites should not each have to
     * name themselves, and the subject is the one field that regularly
     * carries somebody's name. A class name is neither personal data nor
     * something that drifts, and it is the difference between « an e-mail
     * failed » and « the support probe's e-mail failed ».
     */
    private static function callerOutsideThisNamespace(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
            $class = (string) ($frame['class'] ?? '');
            if ($class === '' || str_starts_with($class, 'Core\\Mail\\')) {
                continue;
            }

            return $class . '::' . $frame['function'];
        }

        return 'inconnu';
    }

    private function extractDomain(string $email): string
    {
        $parts = explode('@', $email);
        return $parts[1] ?? '';
    }
}
