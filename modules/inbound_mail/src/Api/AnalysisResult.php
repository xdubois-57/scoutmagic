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
     */
    public function __construct(
        public readonly array $links = [],
        public readonly array $candidates = []
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
