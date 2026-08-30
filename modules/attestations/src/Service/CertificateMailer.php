<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Service;

use Core\File\EncryptedFileStorageService;
use Core\Mail\MailService;
use Modules\Attestations\Repository\Batch;

/**
 * One message, one certificate, one family.
 *
 * **The document travels as an attachment, and that is the decision.** It
 * is what resolves the case of the member who has left: their family has no
 * role, no member page and no access at all — and it is the family that
 * most needs the document. The alternative, a public link carrying a token,
 * was refused: a token that circulates is worth an access anyway, so the
 * exposure would be the same with a route and an exception to the access
 * control on top.
 *
 * **This is not a mail merge.** `mass_mail_attachments` attaches a file to
 * the *message*, not to the recipient — the same wall the payment QR codes
 * hit — so this is one `MailService::send()` per member, which already
 * takes attachments by path.
 *
 * The certificate is encrypted at rest and PHPMailer attaches by path, so
 * the plaintext exists on disk for the length of one send and is deleted in
 * a `finally`, success or failure alike. Same posture as the Desk import's
 * own plaintext window (SECURITY.md §13).
 */
class CertificateMailer
{
    public function __construct(
        private MailService $mailService,
        private EncryptedFileStorageService $fileStorage,
        private string $storagePath
    ) {
    }

    /**
     * @throws \Throwable whatever the transport raised; the caller records
     *                    the failure and does not retry
     */
    public function send(Batch $batch, int $fileId, string $toAddress, string $unitName): void
    {
        $directory = $this->storagePath . '/temp';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new AttestationsException(
                'Le serveur n\'a pas pu préparer la pièce jointe. Réessayez dans un instant.'
            );
        }

        $path = $directory . '/attestation_' . bin2hex(random_bytes(16)) . '.pdf';

        try {
            file_put_contents($path, $this->fileStorage->retrieve($fileId));

            $this->mailService->send(
                $toAddress,
                $batch->label,
                $this->htmlBody($batch, $unitName),
                $this->textBody($batch, $unitName),
                null,
                [['path' => $path, 'name' => $this->attachmentName($batch)]]
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * The file name the family sees in their mailbox. It carries the
     * batch's label and nothing else: a name travels through a downloads
     * folder and a backup, and there is no reason for it to repeat a person
     * the document's own first page already names.
     */
    private function attachmentName(Batch $batch): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $batch->label), '-');

        return ($slug === '' ? 'attestation' : $slug) . '.pdf';
    }

    private function htmlBody(Batch $batch, string $unitName): string
    {
        return '<p>Bonjour,</p>'
            . '<p>Vous trouverez en pièce jointe votre document : <strong>'
            . htmlspecialchars($batch->label, ENT_QUOTES, 'UTF-8')
            . '</strong>.</p>'
            . '<p>Il est également disponible sur la page du membre concerné, si vous avez un accès au site.</p>'
            . '<p>Bien à vous,<br>' . htmlspecialchars($unitName, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    private function textBody(Batch $batch, string $unitName): string
    {
        return "Bonjour,\n\n"
            . 'Vous trouverez en pièce jointe votre document : ' . $batch->label . ".\n\n"
            . "Il est également disponible sur la page du membre concerné, si vous avez un accès au site.\n\n"
            . "Bien à vous,\n" . $unitName . "\n";
    }
}
