<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\File\EncryptedFileStorageService;
use Core\Mail\MailService;
use Core\Service\TextNormalizerService;

/**
 * Sends one private member document to one family, as an attachment.
 *
 * **The document travels as an attachment, and that is the decision.** It
 * is what resolves the case of the member who has left: their family has no
 * role, no member page and no access at all — and it is often the family
 * that most needs the document. The alternative, a public link carrying a
 * token, was refused: a token that circulates is worth an access anyway, so
 * the exposure would be the same with a route and an exception to the
 * access control on top.
 *
 * **This is not a mail merge.** `mass_mail_attachments` attaches a file to
 * the *message*, not to the recipient — the same wall the payment QR codes
 * hit — so this is one `MailService::send()` per member, which already
 * takes attachments by path.
 *
 * In core rather than in the module that first needed it, because the two
 * callers are core and a module and both open the same window: a document
 * is encrypted at rest and PHPMailer attaches by path, so the plaintext
 * exists on disk for the length of one send and is deleted in a `finally`,
 * success or failure alike (SECURITY.md §13's posture, same as the Desk
 * import's own plaintext window). That window is the reason this lives in
 * one place: it is not code to have two copies of.
 */
class MemberDocumentMailer
{
    public function __construct(
        private MailService $mailService,
        private EncryptedFileStorageService $fileStorage,
        private string $storagePath
    ) {
    }

    /**
     * **The member the document is about is named** (issue #1096): in the
     * subject (« Attestation fiscale 2025 — Emma Dupont ») and in both
     * bodies. A family with three children otherwise receives three
     * identical messages, and has to open each attachment to know whose
     * it is. The caller passes the name of the member the DOCUMENT belongs
     * to — the batch line's, the sheet's — never one worked out from the
     * address it is going to, which several children share.
     *
     * First and last name, nothing else: a subject shows up in a phone's
     * notification and in a mailbox preview. No name — null, empty, or
     * unreadable — and the message says what it always said, with the
     * document's title alone: an unnamed message is ambiguous, a wrongly
     * named one sends a family another child's document by its label.
     *
     * @param ?string $memberName the full name of the member the document
     *        belongs to, as stored; null when it cannot be read
     * @throws \Throwable whatever the transport raised — the caller decides
     *                    what a failure means (the attestations distribution
     *                    records it and never retries; a chef d'unité asking
     *                    for a resend sees it as a message on screen)
     */
    public function send(
        string $title,
        int $fileId,
        string $toAddress,
        string $unitName,
        ?string $memberName = null
    ): void {
        $name = self::displayName($memberName);

        $directory = $this->storagePath . '/temp';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Le serveur n\'a pas pu préparer la pièce jointe.');
        }

        $path = $directory . '/member_document_' . bin2hex(random_bytes(16)) . '.pdf';

        try {
            file_put_contents($path, $this->fileStorage->retrieve($fileId));

            $this->mailService->send(
                $toAddress,
                $name === null ? $title : $title . ' — ' . $name,
                $this->htmlBody($title, $unitName, $name),
                $this->textBody($title, $unitName, $name),
                null,
                [['path' => $path, 'name' => $this->attachmentName($title)]],
                vouchesForRecipient: true
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * The file name the family sees in their mailbox. It carries the
     * document's own title and nothing else: a name travels through a
     * downloads folder and a backup, and there is no reason for it to
     * repeat a person the document's own first page already names.
     */
    private function attachmentName(string $title): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-');

        return ($slug === '' ? 'document' : $slug) . '.pdf';
    }

    /**
     * The name as the screens show it (`|normalize_name`), on one line, or
     * null when there is none to show. Line breaks are dropped before
     * anything else: the name goes into a `Subject` header.
     */
    private static function displayName(?string $memberName): ?string
    {
        if ($memberName === null) {
            return null;
        }

        $name = TextNormalizerService::normalizeName(
            trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $memberName))
        );

        return $name === '' ? null : $name;
    }

    private function htmlBody(string $title, string $unitName, ?string $name): string
    {
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $escapedUnit = htmlspecialchars($unitName, ENT_QUOTES, 'UTF-8');

        if ($name === null) {
            return '<p>Bonjour,</p>'
                . '<p>Vous trouverez en pièce jointe votre document : <strong>' . $escapedTitle . '</strong>.</p>'
                . '<p>Il est également disponible sur la page du membre concerné, si vous avez un accès au site.</p>'
                . '<p>Bien à vous,<br>' . $escapedUnit . '</p>';
        }

        $escapedName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        return '<p>Bonjour,</p>'
            . '<p>Vous trouverez en pièce jointe le document <strong>' . $escapedTitle . '</strong> concernant <strong>'
            . $escapedName . '</strong>.</p>'
            . '<p>Il est également disponible sur le site, dans ses documents privés, si vous y avez un accès.</p>'
            . '<p>Bien à vous,<br>' . $escapedUnit . '</p>';
    }

    private function textBody(string $title, string $unitName, ?string $name): string
    {
        if ($name === null) {
            return "Bonjour,\n\n"
                . 'Vous trouverez en pièce jointe votre document : ' . $title . ".\n\n"
                . "Il est également disponible sur la page du membre concerné, si vous avez un accès au site.\n\n"
                . "Bien à vous,\n" . $unitName . "\n";
        }

        return "Bonjour,\n\n"
            . 'Vous trouverez en pièce jointe le document « ' . $title . ' » concernant ' . $name . ".\n\n"
            . "Il est également disponible sur le site, dans ses documents privés, si vous y avez un accès.\n\n"
            . "Bien à vous,\n" . $unitName . "\n";
    }
}
