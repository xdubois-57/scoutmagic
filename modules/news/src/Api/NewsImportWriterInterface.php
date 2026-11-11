<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Api;

/**
 * Writes history from the unit's previous site into the news module —
 * the write contract of the data migration module's « Actualités et
 * formulaires » import (chantier « Migration de données », lot D), and
 * nothing else's.
 *
 * **No side effect.** An article is created online at its historical date,
 * through the repositories rather than through the editor's service: no
 * « est en ligne » notice to an author, no scheduled task, no announcement,
 * no e-mail. Its form is created closed for good (`is_force_closed`), with
 * no digest address, no ticket, no finance account nor category — so the
 * daily digest, the ticket mailings, the door screen and the receivables
 * never see it.
 *
 * **The same rules as an article written here, otherwise.** The body goes
 * where the module reads it — the form's first `text` field — through the
 * same HTML sanitizer; the cover and the body's images go through the same
 * upload handler (quota, real type, EXIF stripped, derivatives), with the
 * same `role_min` an editor's upload gets; the short link a poster needs is
 * created; the import is journaled.
 *
 * **And the lock** (D11): the article is marked imported in its own row,
 * which makes it, its form and its responses read-only for everybody,
 * whether the data migration module is enabled or not.
 *
 * What it does not do is decide: which archive item becomes an article,
 * what an unknown visibility becomes (never `public`), which form belongs to
 * which article. That is the import's, in `data_migration`. A value outside
 * the rules below is refused with an \InvalidArgumentException, never
 * corrected.
 */
interface NewsImportWriterInterface
{
    /** The visibilities an article may carry — the module's own five. */
    public const VISIBILITIES = ['public', 'identified', 'chief', 'admin', 'direct_link'];

    /** The visibility an import gives an article whose own it cannot recognise (never `public`). */
    public const PROTECTIVE_VISIBILITY = 'identified';

    /** The question types a form may carry — the module's own twelve. */
    public const FIELD_TYPES = [
        'short_text', 'long_text', 'number', 'date', 'phone', 'email',
        'dropdown', 'radio', 'checkbox', 'switch', 'confirmation', 'text',
    ];

    /** Types that ask nothing: a block of text, a statement to confirm. */
    public const NON_INPUT_FIELD_TYPES = ['confirmation', 'text'];

    /** Types whose answer is picked from a list of options. */
    public const OPTION_FIELD_TYPES = ['dropdown', 'radio', 'checkbox'];

    /** What an image may be, as its bytes say — the editor's own list and ceiling. */
    public const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public const MAX_TITLE_LENGTH = 255;
    public const MAX_SUMMARY_LENGTH = 300;
    public const MAX_LABEL_LENGTH = 255;
    public const MAX_SOURCE_LENGTH = 255;

    /**
     * Creates the article, online since its historical date and locked,
     * with its body and images, and the closed form that holds its body.
     * Returns its id.
     *
     * Writes on the shared connection without opening a transaction: the
     * caller's is the one that rolls it back. Files already stored when it
     * fails are removed from the disk before it throws.
     *
     * @throws \InvalidArgumentException a value outside the rules above
     * @throws \RuntimeException an image the upload handler refused, a full disk
     */
    public function importArticle(ImportedArticle $article): int;

    /**
     * Whether $articleId is an imported article whose form asks nothing
     * yet — the one kind of article {@see importForm()} accepts.
     */
    public function acceptsImportedForm(int $articleId): bool;

    /**
     * Adds the old site's questions, in order, after the article's body,
     * to its form — which stays closed. No response is ever written.
     * Returns the form's id.
     *
     * @param list<ImportedFormField> $fields
     *
     * @throws \InvalidArgumentException the article is not one {@see acceptsImportedForm()} accepts, or a field breaks a rule
     */
    public function importForm(int $articleId, array $fields, int $importedBy): int;
}
