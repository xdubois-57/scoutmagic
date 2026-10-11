<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Core\Service\DateInput;
use Modules\News\Repository\Article;

/**
 * The French sentences of an article's publication (issue #845), in one
 * place: who it is published for, the long dates, the questions the
 * editor asks before publishing or taking it back.
 *
 * Pure: everything it says is computed from its arguments, so each
 * sentence is tested on its own rather than by reading a rendered page.
 */
final class PublicationWording
{
    private const WEEKDAYS = [
        1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche',
    ];

    private const MONTHS = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
        7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
    ];

    /**
     * Who an article is for, after « pour » — the editor's own visibility
     * labels, in a sentence.
     */
    public static function audience(string $visibility): string
    {
        return match ($visibility) {
            Article::VISIBILITY_PUBLIC => 'tout le monde',
            Article::VISIBILITY_IDENTIFIED => 'les membres connectés',
            Article::VISIBILITY_CHIEF => 'les animateurs',
            Article::VISIBILITY_ADMIN => 'les chefs d\'unité',
            Article::VISIBILITY_DIRECT_LINK => 'qui a le lien',
            default => 'ses lecteurs',
        };
    }

    /** « mardi 20 octobre à 8 h 00 » — the year only when it is not this one. */
    public static function longDateTime(string $value, ?\DateTimeImmutable $now = null): string
    {
        $date = self::read($value);
        if ($date === null) {
            return '';
        }

        return self::longDate($date, $now) . ' à ' . (int) $date->format('G') . ' h ' . $date->format('i');
    }

    /** « 20 octobre 2026 ». */
    public static function dayMonthYear(string $value): string
    {
        $date = self::read($value);
        if ($date === null) {
            return '';
        }

        return (int) $date->format('j') . ' ' . self::MONTHS[(int) $date->format('n')] . ' ' . $date->format('Y');
    }

    /**
     * What « Publier » asks, for each visibility the editor offers — the
     * dialog follows the radio the author has chosen, which can still
     * change before the click (public/assets/js/news-publication.js). The
     * title is written as `{titre}` and filled in by the page from the
     * title field, for the same reason.
     *
     * @return array<string, array{title: string, message: string}>
     */
    public static function publishQuestions(?string $publishedAt, ?\DateTimeImmutable $now = null): array
    {
        $date = self::read($publishedAt);
        $now ??= new \DateTimeImmutable();
        $dateLine = $date !== null && $date <= $now
            ? 'Elle garde sa date de publication du ' . self::dayMonthYear((string) $publishedAt) . '.'
            : 'Sa date de publication sera celle d\'aujourd\'hui, et ne changera plus.';
        $form = ' Son formulaire s\'ouvrira aux réponses.';

        $questions = [];
        foreach (Article::VISIBILITIES as $visibility) {
            $where = match ($visibility) {
                Article::VISIBILITY_PUBLIC => '« {titre} » apparaîtra tout de suite dans les actualités et sur la page '
                    . 'd\'accueil, pour tout le monde, sans connexion.',
                Article::VISIBILITY_IDENTIFIED => '« {titre} » apparaîtra tout de suite dans les actualités et sur la '
                    . 'page d\'accueil des animés, de leurs parents et du staff, une fois connectés.',
                Article::VISIBILITY_CHIEF => '« {titre} » s\'ouvrira tout de suite aux animateurs, une fois connectés.',
                Article::VISIBILITY_ADMIN => '« {titre} » s\'ouvrira tout de suite aux chefs d\'unité, une fois '
                    . 'connectés.',
                default => '« {titre} » n\'apparaîtra dans aucune liste : elle s\'ouvrira tout de suite à qui a son '
                    . 'lien, ou le QR code de son affiche.',
            };
            $questions[$visibility] = [
                'title' => 'Publier pour ' . self::audience($visibility) . ' ?',
                'message' => $where . $form . "\n" . $dateLine,
            ];
        }

        return $questions;
    }

    /**
     * What « Repasser en brouillon » asks: what disappears, what is kept,
     * and — for an article that has been online — that it keeps its date.
     */
    public static function unpublishQuestion(
        string $title,
        int $responseCount,
        ?string $publishedAt,
        ?\DateTimeImmutable $now = null
    ): string {
        $lines = ['« ' . $title . ' » disparaîtra des pages du site jusqu\'à sa republication.'];
        if ($responseCount > 0) {
            $lines[] = 'Son formulaire a reçu ' . $responseCount . ' réponse' . ($responseCount > 1 ? 's' : '') . '. '
                . ($responseCount > 1 ? 'Elles sont conservées' : 'Elle est conservée')
                . ', avec leurs paiements et leurs billets, mais le formulaire sera fermé jusqu\'à la republication.';
        }

        $date = self::read($publishedAt);
        if ($date !== null && $date <= ($now ?? new \DateTimeImmutable())) {
            $lines[] = 'Republiée, elle gardera sa date de publication du '
                . self::longDate($date, null, withWeekday: false) . '.';
        }

        return implode("\n", $lines);
    }

    private static function longDate(
        \DateTimeImmutable $date,
        ?\DateTimeImmutable $now,
        bool $withWeekday = true
    ): string {
        $text = ($withWeekday ? self::WEEKDAYS[(int) $date->format('N')] . ' ' : '')
            . (int) $date->format('j') . ' ' . self::MONTHS[(int) $date->format('n')];
        if ($date->format('Y') !== ($now ?? new \DateTimeImmutable())->format('Y')) {
            $text .= ' ' . $date->format('Y');
        }

        return $text;
    }

    private static function read(?string $value): ?\DateTimeImmutable
    {
        return DateInput::fromStorage($value);
    }
}
