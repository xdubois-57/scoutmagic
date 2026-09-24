<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A Twig comment is a comment, and `AGENTS.md` § Language says comments are
 * English.
 *
 * Issue #327 measured the gap rather than asserting it: 122 substantial
 * French comments across 38 template files, against roughly a thousand
 * English ones. This detector, which is stricter than the issue's, finds
 * **144 across 45** — #325 having translated `partials/file_link.html.twig`
 * and part of `config/maintenance.html.twig` in between, which is the
 * file-by-file correction this rule expects rather than an exception to
 * it. So English IS the convention here — the divergence is old,
 * concentrated and minority — but a rule stated as absolute over a corpus
 * that is 86 % compliant tells the next contributor nothing about which
 * half is authoritative. That ambiguity is what produced two review
 * findings on #325, and a wrong call in between them.
 *
 * **The decision this test records.** The issue left two ways out: bring
 * the comments to English over time, or amend `AGENTS.md` to make template
 * comments an exception. This takes the first, because the rule names
 * exactly one exception — a reply on a review thread — and a second one
 * invented to fit the corpus would be the corpus overruling the rule. The
 * other way remains cheap if the maintainer prefers it: delete this test
 * and write the exception down.
 *
 * **The list below is a ratchet, not a baseline.** It is the state of the
 * repository on the day the rule landed, and the assertion cuts both ways:
 * a new French comment fails, and a file whose comments were translated
 * but left listed fails too. So it can only shrink, and an empty list
 * means the rule is fully enforced — the same mechanism as
 * Tests\Core\View\UxConventionsTest. What it must never be used for is
 * quieting a French comment somebody adds today: `CLAUDE.md` is explicit
 * that silencing a finding of your own by adding it to a baseline is not
 * allowed, and every entry here predates the rule.
 *
 * Nothing is translated in bulk. Forty-five files rewritten in one commit
 * would cost the `git blame` of every one of them, for prose in the
 * maintainer's own voice; the issue asks for it file by file, as pull
 * requests touch them anyway.
 *
 * **What this does NOT promise.** It is a floor, not a proof. A short
 * comment carrying few function words — « Zone dangereuse — seulement là
 * où passer outre a un sens. » scores 0.10 — stays under the threshold,
 * and no statistical detector will ever settle every case. The claim is
 * the ordinary one: a French comment written the way the 144 below are
 * written fails this test. Read the rule, do not test against the
 * detector.
 */
final class TwigCommentsAreEnglishTest extends TestCase
{
    /**
     * Comments shorter than this are left alone: a three-word note carries
     * too little signal for any detector, and chasing it would turn a
     * mechanical rule into an argument.
     */
    private const MINIMUM_WORDS = 8;

    /**
     * What share of a comment's words must be French function words.
     *
     * A DENSITY, not a count, and the difference is the whole accuracy of
     * this. Counting hits made length the real test: « La conservation est
     * annoncée AVANT le dépôt, pas découverte après. » is unmistakably
     * French and scores four, so a threshold of six let it — and anything
     * else short — through, while the class claimed new French comments
     * fail. As a share it is 0.40 and nothing about it is borderline.
     *
     * 0.16 comes from the corpus rather than from taste. Measured over all
     * 1045 substantial comments, every one scoring 0.167 or more is French
     * and the highest-scoring English one reaches 0.130 — so the threshold
     * sits in the gap, with the margin on the side that matters.
     */
    private const MINIMUM_FRENCH_DENSITY = 0.16;

    /**
     * Unambiguous French function words only. `a`, `on`, `en` and `plus`
     * were in an earlier version and had to go: they are ordinary English
     * words too, and they alone pushed « A GET form: a reading has to
     * survive being bookmarked and sent on. » to within a hair of the
     * threshold.
     */
    private const FRENCH_MARKERS = [
        'le', 'la', 'les', 'des', 'une', 'un', 'qui', 'que', 'pour', 'dans',
        'est', 'pas', 'ce', 'cette', 'sur', 'du', 'au', 'aux', 'et', 'ne',
        'de', 'elle', 'il', 'se', 'par', 'avec', 'ou', 'sa', 'ses', 'leur',
        'mais', 'donc', 'sont', 'être', 'été',
    ];

    /**
     * Repo-relative template path => number of French comments in it, as
     * measured when the rule landed (issue #327).
     *
     * `partials/file_link.html.twig` is absent and `config/maintenance.html.twig`
     * stands at twelve rather than fourteen because #325 translated them
     * while this was being written — which is exactly the shape the
     * correction is meant to take, and what a shrinking list looks like.
     *
     * @var array<string, int>
     */
    private const FRENCH_COMMENT_ALLOWLIST = [
        'core/View/templates/admin/attention.html.twig' => 1,
        'core/View/templates/admin/duplicates.html.twig' => 1,
        'core/View/templates/admin/import.html.twig' => 1,
        'core/View/templates/admin/import_barrier.html.twig' => 4,
        'core/View/templates/admin/import_history.html.twig' => 1,
        'core/View/templates/admin/import_report.html.twig' => 1,
        'core/View/templates/admin/members/show.html.twig' => 7,
        'core/View/templates/base.html.twig' => 2,
        'core/View/templates/config/maintenance.html.twig' => 10,
        'core/View/templates/config/support.html.twig' => 18,
        'core/View/templates/members/show.html.twig' => 1,
        'core/View/templates/setup/index.html.twig' => 2,
        'modules/camps/views/camp.html.twig' => 1,
        'modules/camps/views/camp_form.html.twig' => 3,
        'modules/camps/views/documents.html.twig' => 1,
        'modules/camps/views/place_form.html.twig' => 1,
        'modules/finance/views/campaigns/list.html.twig' => 1,
        'modules/finance/views/dashboard.html.twig' => 1,
        'modules/finance/views/receipts/_grid.html.twig' => 1,
        'modules/finance/views/reconciliation.html.twig' => 1,
        'modules/inbound_mail/views/config/index.html.twig' => 1,
        'modules/leadership/views/obligations.html.twig' => 1,
        'modules/leadership/views/partials/_person_list.html.twig' => 1,
        'modules/leadership/views/training.html.twig' => 1,
        'modules/presences/views/anime.html.twig' => 4,
        'modules/presences/views/index.html.twig' => 7,
        'modules/presences/views/partials/_presence_line.html.twig' => 1,
        'modules/presences/views/sheet.html.twig' => 4,
        'modules/registration/views/_passage_statistics.html.twig' => 1,
        'modules/registration/views/config.html.twig' => 6,
        'modules/registration/views/forecast.html.twig' => 3,
        'modules/support_dashboard/views/_nav.html.twig' => 1,
        'modules/support_dashboard/views/index.html.twig' => 12,
        'modules/support_dashboard/views/partials/detail.html.twig' => 2,
        'modules/support_dashboard/views/partials/probes_table.html.twig' => 4,
        'modules/support_dashboard/views/ticket.html.twig' => 9,
        'modules/support_dashboard/views/tickets.html.twig' => 9,
        'modules/usage_stats/views/modules.html.twig' => 3,
        'modules/usage_stats/views/overview.html.twig' => 5,
        'modules/usage_stats/views/pages.html.twig' => 3,
        'modules/usage_stats/views/partials/_month_picker.html.twig' => 1,
        'modules/usage_stats/views/partials/_nav.html.twig' => 1,
    ];

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Every Twig template the language rule covers: the core templates and
     * every module's views.
     *
     * @return string[] repo-relative paths, sorted
     */
    private static function templates(): array
    {
        $roots = array_merge(
            [self::repoRoot() . '/core/View/templates'],
            glob(self::repoRoot() . '/modules/*/views') ?: []
        );

        $paths = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($files as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                    $paths[] = substr($file->getPathname(), strlen(self::repoRoot()) + 1);
                }
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * Quoted interface text is stripped before scoring, and that is a rule
     * rather than a convenience: `AGENTS.md` says user-facing text IS
     * French, so an English comment quoting the label it describes — « Ne
     * plus me proposer », say — is correct on both counts. Scoring the
     * quotation would punish the comment for obeying the other half of the
     * same rule.
     */
    private static function frenchDensity(string $comment): float
    {
        $prose = preg_replace('/«.*?»/su', ' ', $comment) ?? $comment;
        $prose = mb_strtolower($prose, 'UTF-8');

        // An elided article — l', d', qu', n' — is French and has no
        // English equivalent, so it counts as a marker in its own right.
        $hits = preg_match_all("/\b(?:l|d|n|j|m|s|t|c|qu)['’]/u", $prose);

        $words = preg_split('/[^a-zà-ÿ]+/u', $prose, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < self::MINIMUM_WORDS) {
            return 0.0;
        }

        foreach ($words as $word) {
            if (in_array($word, self::FRENCH_MARKERS, true)) {
                $hits++;
            }
        }

        return $hits / count($words);
    }

    private static function looksFrench(string $comment): bool
    {
        return self::frenchDensity($comment) >= self::MINIMUM_FRENCH_DENSITY;
    }

    public function testNoTwigTemplateGainsAFrenchComment(): void
    {
        $found = [];
        foreach (self::templates() as $path) {
            $source = (string) file_get_contents(self::repoRoot() . '/' . $path);
            if (preg_match_all('/\{#(.*?)#\}/s', $source, $comments) === 0) {
                continue;
            }

            $count = 0;
            foreach ($comments[1] as $comment) {
                if (self::looksFrench($comment)) {
                    $count++;
                }
            }

            if ($count > 0) {
                $found[$path] = $count;
            }
        }

        ksort($found);
        $expected = self::FRENCH_COMMENT_ALLOWLIST;
        ksort($expected);

        $this->assertSame(
            $expected,
            $found,
            'A Twig comment is a comment, and AGENTS.md § Language says comments are English. '
            . 'If a template gained one, write it in English; if you translated a template, take its '
            . 'entry out of FRENCH_COMMENT_ALLOWLIST (lower the count, or remove the line) — the list '
            . 'only ever shrinks, and it is never where a comment written today belongs.'
        );
    }

    /**
     * The detector has to be shown working, or the assertion above could
     * pass for the wrong reason — an allowlist matching a scan that finds
     * nothing anywhere looks exactly like a rule being enforced.
     */
    public function testTheDetectorTellsTheTwoLanguagesApart(): void
    {
        $this->assertTrue(self::looksFrench(
            "Le lien s'ouvre dans un onglet neuf pour que la fenêtre de "
            . "l'application ne parte pas sur le fichier."
        ));

        // The real comment (admin/import.html.twig) that the first version
        // of this detector let through: French beyond argument, but only
        // ten words long, so counting markers made length the test rather
        // than language. As a density it is nowhere near the line.
        $this->assertTrue(self::looksFrench(
            'La conservation est annoncée AVANT le dépôt, pas découverte après.'
        ));

        $this->assertFalse(self::looksFrench(
            'The link opens in a new tab so the installed application window does not navigate away '
            . 'to the file itself.'
        ));

        // The false positive worth guarding: an English comment quoting the
        // French interface it describes. Several real comments do this.
        $this->assertFalse(self::looksFrench(
            'The button reads « Enregistrer les modifications » here rather than « Valider », '
            . 'because the form saves in place and never leaves the page.'
        ));

        // The English comment that came closest to tripping the earlier
        // marker list, and the reason `a` and `on` are no longer in it.
        $this->assertFalse(self::looksFrench(
            'A GET form: a reading has to survive being bookmarked and sent on.'
        ));

        // And the floor: too short to judge, so left alone either way.
        $this->assertFalse(self::looksFrench('Le bouton de la page.'));
    }
}
