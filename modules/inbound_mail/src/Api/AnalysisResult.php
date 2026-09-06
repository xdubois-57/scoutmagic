<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * What one consumer makes of one message.
 *
 * **This replaced "the first module that claims it wins".** Under that
 * rule two modules could not both recognise an email — the second was
 * never even asked — and there was nowhere to record "this looks like
 * mine but I would not bet on it". Both are now expressible: zero or more
 * `MessageLink` (certainties, created unattended) and zero or more
 * `MessageCandidate` (propositions, waiting for somebody to confirm).
 *
 * An empty result is the ordinary answer and a complete one. Most modules
 * have nothing to say about most of the unit's mail.
 */
class AnalysisResult
{
    /**
     * @param MessageLink[] $links
     * @param MessageCandidate[] $candidates
     * @param bool $readingFailed see {@see self::$readingFailed}
     */
    public function __construct(
        public readonly array $links = [],
        public readonly array $candidates = [],
        /**
         * « Je n'ai pas pu lire ce message, redemande-moi. »
         *
         * Not a third kind of answer about the mail — an answer about the
         * READING. A consumer sets it when something it depends on failed
         * in a way that may not hold next time: an OCR provider that
         * returned an error, a scan that could not be rasterised. Every
         * other empty answer, including « ce document est illisible », is
         * final and leaves this false.
         *
         * The deferred pass is the only thing that reads it, and what it
         * does with it is bounded by
         * `Repository\InboundMessageRepository::requeueStoredAnalysis()`.
         * Nothing here promises a retry; a consumer that sets it on every
         * empty answer gets its budget spent and the same silence back.
         */
        public readonly bool $readingFailed = false
    ) {
    }

    /** "Not mine" — the common answer, and a complete one. */
    public static function nothing(): self
    {
        return new self();
    }

    /**
     * One certainty about the message as a whole: the shape almost every
     * consumer's ordinary answer takes.
     */
    public static function linkedTo(
        string $consumerId,
        string $businessReference,
        LinkOrigin $origin,
        int $attachmentId = 0
    ): self {
        return new self([new MessageLink($consumerId, $businessReference, $origin, $attachmentId)]);
    }

    /**
     * « Rien trouvé, et c'est parce que je n'ai pas pu lire. »
     *
     * The answer #172 was missing: an unattributed booking whose contract
     * was a scan, an OCR call that came back with an error, and a message
     * marked « aucune période de séjour lisible » for ever — on a document
     * whose dates the very same reading found the moment a chief pressed
     * « Créer un camp depuis ce message ».
     */
    public static function readingFailed(): self
    {
        return new self([], [], true);
    }

    /** One proposition and nothing else. */
    public static function proposing(MessageCandidate $candidate): self
    {
        return new self([], [$candidate]);
    }

    public function isEmpty(): bool
    {
        return $this->links === [] && $this->candidates === [];
    }
}
