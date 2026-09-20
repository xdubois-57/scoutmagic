<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Http\Controller\AbstractController;
use Core\Security\AuthSession;
use Core\Security\HtmlSanitizer;
use Core\Security\Role;

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

    /**
     * @param EditableContentAuthorizer[] $authorizers the keys whose write
     *        role is narrower than the endpoint that would otherwise
     *        decide — see {@see assertMayWrite()}. Optional and trailing
     *        so the many call sites that build this service with one
     *        argument keep working; an empty list is the behaviour this
     *        service had before free-text pages existed.
     */
    public function __construct(
        private EditableContentRepository $repository,
        private array $authorizers = []
    ) {
        $this->sanitizer = new HtmlSanitizer();
    }

    /**
     * **The one gate every write to `editable_contents` passes.**
     *
     * The entry points check first, so each can refuse in its own shape:
     * `EditableContentController` answers a JSON 403, and
     * `UploadController` refuses the upload. Those checks are the good
     * error messages; THIS one is the guarantee.
     *
     * The reason it exists at all is that a per-door check is only ever
     * as complete as the list of doors somebody remembered. Free-text
     * pages made `page_content_{id}` the first key on this site whose
     * read floor can exceed the `admin` floor of the endpoints that write
     * it (ARCHITECTURE.md §8.115), and the gap was reachable through a
     * second door — `POST /upload` with `context=editable_image` — that
     * had never needed guarding before. A third one added later fails
     * closed here instead of quietly reopening it.
     *
     * @throws EditableContentForbiddenException
     */
    private function assertMayWrite(string $key): void
    {
        foreach ($this->authorizers as $authorizer) {
            $required = $authorizer->roleMinForKey($key);
            if ($required === null) {
                continue;
            }

            if (!Role::fromString(AuthSession::getRole())->hasAccess(Role::fromString($required))) {
                throw new EditableContentForbiddenException(AbstractController::FORBIDDEN_MESSAGE);
            }
        }
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
     *
     * **Returns what was actually stored**, which is not always what was
     * sent. The editors repaint the page with their own copy of the text
     * the moment the save succeeds, and the sanitizer had already dropped
     * part of it: a heading applied in the editor stayed on screen until
     * the next page load, then vanished (issue #306). Handing the stored
     * string back is the whole fix, and it is the only one that cannot
     * drift — a second allowlist in JavaScript would be a guess at this
     * one, checked by nothing the day this list changes.
     */
    public function set(string $key, string $value, string $type, int $modifiedBy): string
    {
        $this->assertMayWrite($key);

        $value = $this->sanitizer->sanitize($value);

        $this->repository->upsert($key, $type, $value, null, $modifiedBy);
        unset($this->rows[$key]);

        return $value;
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
