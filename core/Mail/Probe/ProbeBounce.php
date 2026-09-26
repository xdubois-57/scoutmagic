<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Probe;

use Core\Mail\Feedback\Bounce\BounceCategory;

/**
 * What the far end said about a probe that came back (roadmap IT-04,
 * issue #419).
 *
 * **The category and the status code, and never the diagnostic text.**
 * {@see \Core\Mail\Feedback\Bounce\DeliveryStatusReport} reads that text —
 * it is where a status code hides when the structured field is missing —
 * and then drops it, because it quotes the address back and is written in
 * the far end's language. `mail_probes` encrypts its destination for that
 * same reason, so a diagnostic kept beside it would hand back in clear
 * what the column next door protects. The category's own label says
 * enough: « Adresse inexistante » is the answer an operator needed.
 *
 * **The category is nullable although every stored row had one.** A value
 * a later version no longer knows must not cost the line — `5.1.1` still
 * says something without it — which is the choice {@see MailProbe} already
 * makes for a retired lane.
 */
final class ProbeBounce
{
    public function __construct(
        public readonly ?BounceCategory $category,
        /** The enhanced status code as the far end sent it, e.g. `5.1.1`. */
        public readonly string $statusCode,
        public readonly \DateTimeImmutable $at
    ) {
    }

    /**
     * One line for the probe's row: the category if this version still
     * knows it, and the code either way.
     */
    public function label(): string
    {
        return $this->category === null
            ? 'Rejeté (' . $this->statusCode . ')'
            : $this->category->label() . ' (' . $this->statusCode . ')';
    }
}
