<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step;

/**
 * One thing a step lets the person do, as data: the page renders it, the
 * step's check decides whether it exists and what it says.
 *
 * Three kinds:
 * - LINK: a plain link to another page. Never confirmed.
 * - POST: a form posting $fields to $url — one of the existing
 *   /admin/scout-year/* routes, which stay the no-JavaScript fallback of
 *   the JSON variant. Immediate in effect, so it carries the confirmation
 *   wording ScoutMagicConfirm.ask() shows: the consequence, and that no
 *   e-mail is sent.
 * - REVIEW: opens the pre-switch review dialog on the page itself
 *  ; $url is null.
 *
 * $disabled is display only: the server re-checks every POST regardless.
 *
 * The named constructors are linkTo()/postTo()/openReview() rather than
 * link()/post()/review() on purpose: Twig resolves `action.link` to a
 * method called link() before it tries isLink(), so a static factory of
 * that name would shadow the predicate a template means.
 */
final class StepAction
{
    public const KIND_LINK = 'link';
    public const KIND_POST = 'post';
    public const KIND_REVIEW = 'review';

    /**
     * @param array<string, string> $fields hidden fields of a POST action (the
     *        CSRF token is the page's own)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $label,
        public readonly ?string $url = null,
        public readonly array $fields = [],
        public readonly ?string $confirmTitle = null,
        public readonly ?string $confirmMessage = null,
        public readonly ?string $confirmLabel = null,
        public readonly bool $disabled = false
    ) {
    }

    public static function linkTo(string $id, string $label, string $url): self
    {
        return new self($id, self::KIND_LINK, $label, $url);
    }

    /**
     * @param array<string, string> $fields
     */
    public static function postTo(
        string $id,
        string $label,
        string $url,
        array $fields,
        string $confirmTitle,
        string $confirmMessage,
        bool $disabled = false
    ): self {
        return new self($id, self::KIND_POST, $label, $url, $fields, $confirmTitle, $confirmMessage, $label, $disabled);
    }

    public static function openReview(string $id, string $label): self
    {
        return new self($id, self::KIND_REVIEW, $label);
    }

    public function isLink(): bool
    {
        return $this->kind === self::KIND_LINK;
    }

    public function isPost(): bool
    {
        return $this->kind === self::KIND_POST;
    }

    public function isReview(): bool
    {
        return $this->kind === self::KIND_REVIEW;
    }
}
