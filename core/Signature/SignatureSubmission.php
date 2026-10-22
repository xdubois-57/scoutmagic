<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Signature;

use Core\Http\Request;

/**
 * What the signature dialog posted (issue #926, lot C1;
 * `partials/signature_dialog.html.twig`), read once and not yet decoded:
 * whether to sign with the signature the account keeps, or with the one
 * traced or imported in the dialog, and whether to remember that one.
 *
 * **It never carries an account.** Whose signature this is, is the
 * session's to say (`AuthSession::getUserAccountId()`, passed to
 * `PersonalSignatureService::forSigning()`); a `user_account_id` posted
 * alongside is not even read here, so no form can name somebody else.
 *
 * Decoding the image is not done here either: `forSigning()` and
 * `witness()` do it, before the module takes any lock of its own.
 */
final readonly class SignatureSubmission
{
    /** Said when PHP refused the posted image for its size. */
    public const TOO_HEAVY_TO_SEND = 'Cette image est trop lourde pour être envoyée : choisissez-en une plus légère.';

    /**
     * Built by {@see self::fromRequest()} in production. A test builds one
     * directly; `$uploadedPath`, when given, must be a file PHP received
     * with the request — `uploadedPath()` is the one place that checks it.
     */
    public function __construct(
        public bool $useStored,
        #[\SensitiveParameter] public ?string $drawing = null,
        public ?string $uploadedPath = null,
        public bool $rememberRequested = false
    ) {
    }

    /**
     * Reads the dialog's fields: `signature_source` (`stored` or `new`),
     * `signature_data` (the PNG the pad exported), `signature_file` (an
     * import) and `remember_signature` (the « Mémoriser » box).
     *
     * `$allowImport` false ignores any file — a witness signs on the spot
     * (ticket §6a); `$allowRemember` false ignores the box. A signature
     * from the account is never « remembered » again, whatever was posted.
     * A file is read only when `is_uploaded_file()` says PHP received it
     * with this request: a path is never taken from anywhere else.
     *
     * @throws SignatureException when PHP refused the file for its size
     */
    public static function fromRequest(Request $request, bool $allowImport = true, bool $allowRemember = true): self
    {
        if ((string) $request->getBody('signature_source', '') === 'stored') {
            return new self(true);
        }

        $drawing = trim((string) $request->getBody('signature_data', ''));

        $uploaded = $allowImport ? self::uploadedPath($request) : null;

        return new self(
            false,
            $drawing !== '' ? $drawing : null,
            $uploaded,
            $allowRemember && (string) $request->getBody('remember_signature', '') === '1'
        );
    }

    /**
     * The temporary path of the image posted as `signature_file`, or null
     * when none was sent — and only when `is_uploaded_file()` says PHP
     * received it with this request.
     *
     * A file PHP refused for its size arrives with an error and no path:
     * read as « nothing sent », the visitor who chose a heavy photo would
     * be told to draw or import a signature, which they just did. The
     * limit is the host's (`upload_max_filesize`), which a host may set
     * below the 5 MB this site accepts, so the sentence names none.
     *
     * @throws SignatureException when PHP refused the file for its size
     */
    public static function uploadedPath(Request $request): ?string
    {
        $file = $request->getFile('signature_file');
        if (!is_array($file)) {
            return null;
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new SignatureException(self::TOO_HEAVY_TO_SEND);
        }
        $path = (string) ($file['tmp_name'] ?? '');

        return $path !== '' && is_uploaded_file($path) ? $path : null;
    }

    /** Whether the dialog sent anything to sign with. */
    public function isEmpty(): bool
    {
        return !$this->useStored && $this->drawing === null && $this->uploadedPath === null;
    }

    /**
     * @return array{useStored: bool, drawing: bool, uploadedPath: bool, rememberRequested: bool}
     */
    public function __debugInfo(): array
    {
        return [
            'useStored' => $this->useStored,
            'drawing' => $this->drawing !== null,
            'uploadedPath' => $this->uploadedPath !== null,
            'rememberRequested' => $this->rememberRequested,
        ];
    }
}
