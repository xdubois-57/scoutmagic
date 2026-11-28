<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

/**
 * A form answer refused because of ONE field, which it names by id so the
 * page can show the sentence next to that field rather than only above the
 * form (issue #1486: an answer longer than FormField::MAX_ANSWER_LENGTH).
 *
 * User-facing like its parent, and for the same reason: every message is a
 * French sentence naming the field's label as the respondent reads it, and
 * the limit — never the value typed, which is somebody's answer.
 */
class FieldAnswerException extends NewsException
{
    public function __construct(string $message, public readonly int $fieldId)
    {
        parent::__construct($message);
    }
}
