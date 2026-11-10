<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Config\ScoutYearService;
use Core\ScoutYear\ScoutYearResolver;

/**
 * The scout years whose private documents a member sees on their own page
 * (« Espace des animés », issue #1094): the year the site is on and the two
 * before it, newest first.
 *
 * **Anchored on the site's year, never on the sheet being viewed.** A tax
 * certificate is published for the season it covers, usually after that
 * season has ended; a family opening this year's sheet must still find
 * last year's certificate. So the anchor is the public year
 * (ScoutYearResolver::getCurrentPublicYear(), the year a member is served),
 * and changing which year of the sheet is shown changes nothing here.
 *
 * Years are walked by label, one season back at a time, and looked up
 * without being created (ScoutYearService::findByLabel()): a season the
 * site never held is simply absent from the window — it can hold no
 * document either — rather than replaced by an older one, so the window
 * always means « these three seasons ». Older documents are not deleted;
 * the Staff d'U still sees every year on the admin sheet.
 */
final class PrivateDocumentYears
{
    /** How many seasons the member's own page shows, the current one included. */
    public const WINDOW = 3;

    public function __construct(
        private ScoutYearResolver $scoutYearResolver,
        private ScoutYearService $scoutYearService
    ) {
    }

    /**
     * @return list<array{id: int, label: string}> newest first
     */
    public function window(): array
    {
        $label = (string) $this->scoutYearResolver->getCurrentPublicYear()['label'];

        $years = [];
        for ($i = 0; $i < self::WINDOW; $i++) {
            $year = $this->scoutYearService->findByLabel($label);
            if ($year !== null) {
                $years[] = ['id' => (int) $year['id'], 'label' => (string) $year['label']];
            }
            $label = ScoutYearService::previousLabel($label);
        }

        return $years;
    }
}
