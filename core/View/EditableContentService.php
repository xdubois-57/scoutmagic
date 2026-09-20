<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Exception\UserFacingMessage;
use Core\Page\TextPageContentAuthorizer;
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
     * **The one gate every write to `editable_contents` passes**, and
     * the only place that decides who may write what.
     *
     * Two things make it trustworthy, and both were learned the hard way.
     *
     * **It is the single door.** A per-endpoint check is only ever as
     * complete as the list of endpoints somebody remembered: free-text
     * pages made this table's first content whose read floor exceeds the
     * `admin` floor of the endpoints writing it (ARCHITECTURE.md
     * §8.115), and the gap turned out to be reachable through
     * `POST /upload` with `context=editable_image` as well. Everything
     * funnels through {@see set()}, so a new entry point added later
     * fails closed rather than quietly reopening it.
     *
     * **It asks the row, not the key.** `content_key` is compared with
     * `utf8mb4_unicode_ci`, which equates spellings differing in case,
     * accents, trailing spaces, fullwidth forms and every
     * primary-ignorable character. Deciding ownership by parsing the key
     * means reproducing that equivalence exactly, and four attempts each
     * left a gap. {@see EditableContentRepository::ownerPageIdForKey()}
     * asks with the same `WHERE content_key = ?` the write itself uses:
     * whatever the collation takes this key to be, the row that answers
     * is the row that will be written.
     *
     * @throws EditableContentForbiddenException
     */
    private function assertMayWrite(string $key): void
    {
        if ($this->authorizers === []) {
            return;
        }

        $ownerId = $this->repository->ownerPageIdForKey($key);
        if ($ownerId === null) {
            // A row nobody owns — every page-anchored key on this site —
            // or a key with no row at all. The endpoint's own floor is
            // the whole answer, exactly as it was before free-text pages
            // existed.
            return;
        }

        foreach ($this->authorizers as $authorizer) {
            $required = $authorizer->roleMinForOwner(TextPageContentAuthorizer::OWNER_KIND, $ownerId);
            if ($required === null) {
                continue;
            }

            if (!Role::fromString(AuthSession::getRole())->hasAccess(Role::fromString($required))) {
                throw new EditableContentForbiddenException(UserFacingMessage::FORBIDDEN);
            }
        }
    }

    /**
     * The role required to write $key, or null when nothing narrows it.
     *
     * Exposed so an entry point can refuse in its own shape — a JSON 403
     * from `EditableContentController`, a refused upload from
     * `UploadController` — rather than letting the exception above
     * surface. The exception stays the guarantee; this is the good error
     * message.
     */
    public function roleMinToWrite(string $key): ?string
    {
        $ownerId = $this->authorizers === [] ? null : $this->repository->ownerPageIdForKey($key);
        if ($ownerId === null) {
            return null;
        }

        foreach ($this->authorizers as $authorizer) {
            $required = $authorizer->roleMinForOwner(TextPageContentAuthorizer::OWNER_KIND, $ownerId);
            if ($required !== null) {
                return $required;
            }
        }

        return null;
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
