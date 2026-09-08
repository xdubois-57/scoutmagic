<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Security\HtmlSanitizer;

class EditableContentService
{
    private HtmlSanitizer $sanitizer;

    /**
     * Rows for the lifetime of this instance, misses included — a page
     * with a dozen editable() blocks used to cost a query each.
     *
     * @var array<string, array<string, mixed>|null>
     */
    private array $rows = [];

    public function __construct(
        private EditableContentRepository $repository
    ) {
        $this->sanitizer = new HtmlSanitizer();
    }

    /**
     * Get the content value for a key. Returns the stored value or $default.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $row = $this->findRow($key);

        if ($row === null) {
            return $default;
        }

        return $row['content_value'] ?? $default;
    }

    /**
     * Update the content for a key. Creates the record if it doesn't exist.
     * Sanitizes HTML BEFORE storing, whatever the type (SECURITY.md §7).
     *
     * **Whatever the type**, because the type is not a property of the
     * value: it comes from the request body on both writing routes
     * (Core\Http\Controller\EditableContentController), validated only
     * against the two names, while the READ path ignores it entirely —
     * `get()` returns the string and `partials/rich_text_field.html.twig`
     * renders it through `|raw`, on public pages included. Sanitizing one
     * of the two types therefore let a caller choose whether the barrier
     * applied: `type=image` with an HTML body stored arbitrary markup that
     * came back verbatim on /rgpd, on the home banner and on a rental's
     * public page.
     *
     * An `image` value is a `files` id (Core\Photo\PhotoIngestionService),
     * which the sanitizer leaves untouched — so this costs that type
     * nothing and closes the hole for good.
     */
    public function set(string $key, string $value, string $type, int $modifiedBy): void
    {
        $value = $this->sanitizer->sanitize($value);

        $this->repository->upsert($key, $type, $value, null, $modifiedBy);
        unset($this->rows[$key]);
    }

    /**
     * Removes a content key entirely — see EditableContentRepository::delete().
     */
    public function delete(string $key): void
    {
        $this->repository->delete($key);
        unset($this->rows[$key]);
    }

    /**
     * Get the last updated timestamp for a content key.
     * Returns the modified_at value as a string (Y-m-d H:i:s format) or null if not found.
     */
    public function getLastUpdated(string $key): ?string
    {
        $row = $this->findRow($key);

        return $row['modified_at'] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRow(string $key): ?array
    {
        if (!array_key_exists($key, $this->rows)) {
            $this->rows[$key] = $this->repository->findByKey($key);
        }

        return $this->rows[$key];
    }
}
