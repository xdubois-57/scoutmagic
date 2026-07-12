<?php

declare(strict_types=1);

namespace Core\View;

use Core\Security\HtmlSanitizer;

class EditableContentService
{
    private HtmlSanitizer $sanitizer;

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
        $row = $this->repository->findByKey($key);

        if ($row === null) {
            return $default;
        }

        return $row['content_value'] ?? $default;
    }

    /**
     * Update the content for a key. Creates the record if it doesn't exist.
     * For rich_text: sanitizes HTML BEFORE storing (SECURITY.md §7).
     */
    public function set(string $key, string $value, string $type, int $modifiedBy): void
    {
        if ($type === 'rich_text') {
            $value = $this->sanitizer->sanitize($value);
        }

        $this->repository->upsert($key, $type, $value, null, $modifiedBy);
    }
}
