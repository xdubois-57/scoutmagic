<?php

declare(strict_types=1);

namespace Core\Mail;

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
        private ?string $smtpPassword = null
    ) {
    }

    /**
     * Send a transactional email.
     *
     * @throws MailException on failure
     */
    public function send(
        string $to,
        string $subject,
        string $bodyHtml,
        string $bodyText,
        ?string $replyTo = null
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

            // Sender
            $mail->setFrom($this->fromAddress, $this->fromName);
            $mail->Sender = $this->fromAddress;

            // Recipient
            $mail->addAddress($to);

            // Reply-To
            if ($replyTo !== null) {
                $mail->addReplyTo($replyTo);
            }

            // DKIM signing
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

            $mail->send();
        } catch (\Exception $e) {
            throw new MailException($mail->ErrorInfo ?: $e->getMessage());
        }
    }

    private function extractDomain(string $email): string
    {
        $parts = explode('@', $email);
        return $parts[1] ?? '';
    }
}
