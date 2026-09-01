<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Mail;

use Core\File\PdfRasterizer;
use Core\File\PdfTextExtractor;
use Core\File\StoredFileReader;
use Modules\InboundMail\Api\InboundAttachment;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;

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
 * **Three ways in, in order of cost.** A PDF's embedded text layer and a
 * plain-text part are free, exact and offline, so they are tried first and
 * are almost always enough. What is left over is the scanned contract: a
 * photograph of a document, as a PDF with no text layer or as a JPEG
 * straight off a phone. Those are read by the model at `LlmTier::OCR` —
 * the same tier, and the same rasterise-then-transcribe path, that
 * `Finance\Task\ExtractReceiptDataHandler` already uses for a
 * photographed receipt.
 *
 * **One OCR call per message, and only when nothing else answered.** It is
 * the expensive door, it is the one that sends a picture of somebody's
 * contract to a provider, and a message whose PDF already gave up its text
 * must never pay for it. Without a connector — module absent, disabled, no
 * model on the OCR or CHEAP tier — this degrades to exactly what it did
 * before: the readable shapes, and nothing else (§7.5).
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

    /**
     * How many attachments of one message may be transcribed by the model.
     *
     * One. A booking's document is the first attachment; the rest are a
     * plan, a logo and the general conditions, and paying to transcribe
     * all four would turn one unread e-mail into four provider calls.
     */
    public const MAX_OCR_CALLS = 1;

    /** How much of a transcription is kept. A contract states its dates on the first page. */
    public const MAX_OCR_CHARS = 4000;

    /** The picture formats a phone or a scanner actually produces. */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Below this, a picture is not a document.
     *
     * Almost every message a unit receives carries an image: a signature
     * logo, a social-media icon, a tracking pixel. They are a few kilobytes
     * each and there is nothing written on them, and without this floor
     * each of them would buy itself a transcription. A scanned A4 page is
     * hundreds of kilobytes at any resolution worth reading.
     */
    public const MIN_PICTURE_BYTES = 50_000;

    public function __construct(
        private StoredFileReader $files,
        private ?PdfTextExtractor $pdf = null,
        /**
         * Optional `llm_connector` consumer (§7.5). Null means no OCR:
         * a scanned contract then reads as nothing, exactly as it did
         * before this door existed.
         */
        private ?LlmConnectorInterface $llm = null,
        private ?PdfRasterizer $rasterizer = null
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
        /** @var array<int, array{attachment: InboundAttachment, bytes: string}> */
        $unreadable = [];

        foreach ($attachments as $attachment) {
            if ($read >= self::MAX_ATTACHMENTS) {
                break;
            }
            if (!self::isReadable($attachment) && !self::isPicture($attachment)) {
                continue;
            }

            $read++;
            $bytes = $this->files->read($attachment->fileId);
            if ($bytes === null) {
                continue;
            }

            if (self::isPicture($attachment)) {
                // A photograph has no text layer to try. It goes straight
                // on the OCR list, and only gets there if nothing cheaper
                // answered.
                $unreadable[] = ['attachment' => $attachment, 'bytes' => $bytes];
                continue;
            }

            $text = str_starts_with($attachment->mimeType, 'text/')
                ? $bytes
                : $pdf->extractText($bytes);

            if ($text !== null && trim($text) !== '') {
                $pieces[] = trim($text);
                continue;
            }

            // A PDF with no text layer: a scan, or a photograph saved as a
            // PDF. Kept for the OCR pass below rather than transcribed now,
            // so a second attachment that DOES have a text layer spares the
            // call entirely.
            $unreadable[] = ['attachment' => $attachment, 'bytes' => $bytes];
        }

        if ($pieces === []) {
            $pieces = $this->transcribe($unreadable);
        }

        return mb_substr(implode("\n", $pieces), 0, self::MAX_CHARS);
    }

    /**
     * What the model reads off a picture of a document.
     *
     * Deliberately a TRANSCRIPTION and nothing else: the answer feeds the
     * same date and price patterns as a text layer would, and the same
     * place-naming prompt, so a scanned contract and a digital one go
     * through identical readings from here on. Asking the model to
     * *interpret* would put a second, invisible reading next to the one
     * this module documents.
     *
     * Every failure is empty text, never an exception: a provider that is
     * down must cost this module a stay, never a synchronisation pass.
     *
     * @param array<int, array{attachment: InboundAttachment, bytes: string}> $pending
     * @return string[]
     */
    private function transcribe(array $pending): array
    {
        if ($this->llm === null || $pending === []) {
            return [];
        }

        // The tier the transcription itself uses. isAvailable() answers a
        // wider question and would say yes to a connector that cannot
        // serve this one.
        if (!$this->llm->isTierAvailable(LlmTier::OCR) && !$this->llm->isTierAvailable(LlmTier::CHEAP)) {
            return [];
        }

        $texts = [];
        foreach (array_slice($pending, 0, self::MAX_OCR_CALLS) as $entry) {
            $image = self::isPicture($entry['attachment'])
                ? $entry['bytes']
                : ($this->rasterizer ?? new PdfRasterizer())->firstPageToJpeg($entry['bytes']);
            if ($image === null) {
                continue;
            }

            try {
                $response = $this->llm->complete(new LlmRequest(
                    tier: LlmTier::OCR,
                    prompt: 'Transcris le texte visible sur ce document, tel quel et en entier. '
                        . 'N\'interprète rien, ne résume rien, n\'ajoute rien : recopie ce qui est écrit, '
                        . 'y compris les dates, les montants et le nom du lieu. '
                        . 'Si le document est illisible, réponds une chaîne vide.',
                    attachments: [[
                        'data' => base64_encode($image),
                        'mime_type' => self::isPicture($entry['attachment'])
                            ? $entry['attachment']->mimeType
                            : 'image/jpeg',
                    ]]
                ));
            } catch (LlmException) {
                continue;
            }

            $text = trim($response->content);
            if ($text !== '') {
                $texts[] = mb_substr($text, 0, self::MAX_OCR_CHARS);
            }
        }

        return $texts;
    }

    /** The shapes whose text is really text, and costs nothing to read. */
    private static function isReadable(InboundAttachment $attachment): bool
    {
        return $attachment->sizeBytes <= self::MAX_FILE_BYTES
            && (
                $attachment->mimeType === 'application/pdf'
                || str_starts_with($attachment->mimeType, 'text/')
            );
    }

    /**
     * A picture of a document — the OCR door's own subject.
     *
     * Answered by mime type and bounded by size before a single byte is
     * fetched: the signature logo on every message a unit receives must
     * not become a provider call, and a twenty-megabyte scan must not be
     * loaded into memory to find out how big it is.
     */
    private static function isPicture(InboundAttachment $attachment): bool
    {
        return $attachment->sizeBytes <= self::MAX_FILE_BYTES
            && $attachment->sizeBytes >= self::MIN_PICTURE_BYTES
            && in_array($attachment->mimeType, self::IMAGE_TYPES, true);
    }
}
