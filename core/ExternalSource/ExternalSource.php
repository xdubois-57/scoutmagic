<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ExternalSource;

/**
 * One page outside this site that the site depends on — a federation page
 * whose content feeds a feature or a default, or a provider console/legal
 * page an administrator is sent to from a help text.
 *
 * `usedIn` is not documentation only: `Tests\Architecture\
 * ExternalSourcesAreRegisteredTest` fails when a listed file does not name
 * the URL (or the registry constant carrying it), and when a file names a
 * guarded URL without being listed here. So the "where does this page
 * matter" column cannot drift from the code.
 */
final class ExternalSource
{
    /**
     * @param string $id stable identifier, used in reports and issue titles
     * @param list<string> $usedIn repository-relative files that name this URL
     * @param list<string> $expectedContent strings the page must contain
     *        (case-insensitive, after the markup is stripped) — content
     *        sources only
     * @param string|null $dependentDefault the shipped default that holds
     *        this URL and would need changing if it moved, when there is one.
     *        Changing it reaches installed sites for every value nobody
     *        customised (ARCHITECTURE.md §8.123); ExternalSourcesAreRegistered-
     *        Test reads its shape: `column default <table>.<column> (<file>)`,
     *        `setting <key> (<manifest>)`, or `<what> (<file read live>)`
     * @param bool $upcoming registered ahead of the code that will use it;
     *        exempt from the "every content source is used" check only
     * @param bool $redirectIsDivergence true when the code reading this page
     *        refuses redirects (FederalScaleLookupService does), so a page
     *        that answers with a redirect is as broken as one that is gone
     */
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly ExternalSourceKind $kind,
        public readonly string $purpose,
        public readonly array $usedIn,
        public readonly array $expectedContent = [],
        public readonly ?string $dependentDefault = null,
        public readonly bool $upcoming = false,
        public readonly bool $redirectIsDivergence = false,
    ) {
    }
}
