<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Mail;

use Core\File\PdfTextExtractor;
use Core\File\StoredFileReader;
use Modules\InboundMail\Api\InboundAttachment;

/**
 * What a message's attachments say, as text.
 *
 * **Why this had to exist.** A booking is almost never written in the body
 * of the e-mail that carries it. The real ones look like the message that
 * prompted this: a one-word body — « Bonjour, » — and a PDF contract
 * holding the place, the dates and the price. Reading only
 * `subject + bodyText`, this module saw nothing at all in such a message,
 * refused it for want of dates, and left no trace of having looked.
 *
 * **Deferred pass only, and that is not an accident.** Extracting a PDF's
 * text layer is expensive and unbounded, so it belongs exactly where
 * `Task\AnalyzeStoredMessagesHandler` already puts everything that needs an
 * attachment's bytes — never inside the synchronisation loop, which sees
 * attachment metadata and nothing else.
 *
 * **Bounded three ways**, because a message is somebody else's file: the
 * number of attachments read, each one's size, and the total text handed
 * back. A three-hundred-page appendix must not become a model prompt or a
 * regex's input.
 *
 * Only the shapes whose text is really text: a PDF's embedded layer, and
 * a plain-text part. No OCR — a scanned contract has no text layer, and
 * rasterising every attachment of every message to find out would cost far
 * more than the stays it would win.
 */
class AttachmentTextReader
{
    /** How many attachments of one message are read. */
    public const MAX_ATTACHMENTS = 3;

    /** Bigger than this and the bytes are not even fetched. */
    public const MAX_FILE_BYTES = 5_000_000;

    /**
     * How much text one message may contribute in total. A booking states
     * its dates and its venue in the first page; the rest is the general
     * conditions.
     */
    public const MAX_CHARS = 12000;

    public function __construct(
        private StoredFileReader $files,
        private ?PdfTextExtractor $pdf = null
    ) {
    }

    /**
     * @param InboundAttachment[] $attachments
     * @return string the readable text, empty when there is none
     */
    public function read(array $attachments): string
    {
        $pdf = $this->pdf ?? new PdfTextExtractor();
        $pieces = [];
        $read = 0;

        foreach ($attachments as $attachment) {
            if ($read >= self::MAX_ATTACHMENTS) {
                break;
            }
            if (!self::isReadable($attachment)) {
                continue;
            }

            $read++;
            $bytes = $this->files->read($attachment->fileId);
            if ($bytes === null) {
                continue;
            }

            $text = str_starts_with($attachment->mimeType, 'text/')
                ? $bytes
                : $pdf->extractText($bytes);

            if ($text !== null && trim($text) !== '') {
                $pieces[] = trim($text);
            }
        }

        return mb_substr(implode("\n", $pieces), 0, self::MAX_CHARS);
    }

    /**
     * A scanned contract is a picture of a contract: it has no text layer,
     * and this reader does no OCR. Saying so by mime type rather than by
     * trying and failing keeps the cost off every image the unit receives.
     */
    private static function isReadable(InboundAttachment $attachment): bool
    {
        return $attachment->sizeBytes <= self::MAX_FILE_BYTES
            && (
                $attachment->mimeType === 'application/pdf'
                || str_starts_with($attachment->mimeType, 'text/')
            );
    }
}
