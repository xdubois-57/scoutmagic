<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help\Assistant;

use Core\Help\HelpTopic;

/**
 * What the assistant answers: some text, the topics it actually read, and
 * whether it found anything at all.
 *
 * `$text` is a MODEL's output and therefore untrusted content. It is
 * never rendered raw: Core\View\MarkdownRenderer with
 * HelpController::RENDER_OPTIONS escapes the HTML, exactly as a topic
 * body is rendered. Nothing here is allowed to shortcut that.
 *
 * `$topics` are real HelpTopic objects, re-resolved through
 * HelpService::findById() at the caller's own role — never the ids the
 * model returned. An id it invented never becomes one of these.
 */
final class AssistantAnswer
{
    /**
     * @param HelpTopic[] $topics the topics actually consulted, in the
     *        order they were selected, already role-checked
     * @param bool $foundNothing true when the corpus has no answer — a
     *        real outcome the surfaces word differently from an error,
     *        and never an empty answer pretending to be one
     * @param bool $fromCache whether this answer cost an LLM call
     */
    public function __construct(
        public readonly string $text,
        public readonly array $topics,
        public readonly bool $foundNothing,
        public readonly bool $fromCache = false,
    ) {
    }

    /**
     * @return string[]
     */
    public function topicIds(): array
    {
        return array_map(static fn (HelpTopic $t): string => $t->id, $this->topics);
    }
}
