<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\TestTools\Service;

/**
 * The sandbox list's view state, read from the query string and nowhere
 * else — no cookie, no session, no local storage. Arriving with no query
 * string always yields the same default view, exactly like
 * Modules\SupportDashboard\Service\SupportDashboardFilters.
 */
final class MailSandboxFilters
{
    public const PAGE_SIZE = 25;

    /**
     * How many retained messages the opt-in body search decrypts and reads,
     * newest first. Every body is encrypted at rest, so this scan has no
     * index to lean on; the bound is what keeps it affordable and the page
     * states it in as many words rather than hiding it.
     */
    public const BODY_SEARCH_BOUND = 200;

    private function __construct(
        public readonly string $subject,
        public readonly string $recipient,
        public readonly bool $searchBodies,
        public readonly int $page
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        return new self(
            trim((string) ($query['subject'] ?? '')),
            trim((string) ($query['recipient'] ?? '')),
            (string) ($query['bodies'] ?? '') === '1',
            max(1, (int) ($query['page'] ?? 1))
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * self::PAGE_SIZE;
    }

    public function subjectFilter(): ?string
    {
        return $this->subject !== '' ? $this->subject : null;
    }

    public function recipientFilter(): ?string
    {
        return $this->recipient !== '' ? $this->recipient : null;
    }

    /**
     * Whether the body scan should actually run: opted in AND given
     * something to look for.
     */
    public function scansBodies(): bool
    {
        return $this->searchBodies && $this->subject !== '';
    }

    public function isFiltered(): bool
    {
        return $this->subject !== '' || $this->recipient !== '';
    }

    /**
     * The current filters as a query string, page excluded — what a
     * pagination link appends its own page to.
     */
    public function queryWithoutPage(): string
    {
        $parts = [];
        if ($this->subject !== '') {
            $parts['subject'] = $this->subject;
        }
        if ($this->recipient !== '') {
            $parts['recipient'] = $this->recipient;
        }
        if ($this->searchBodies) {
            $parts['bodies'] = '1';
        }

        return http_build_query($parts);
    }
}
