<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Service;

use Modules\Retro\Repository\Comment;

/**
 * What the closing e-mail says a retrospective contained, as one block of
 * plain text.
 *
 * Text rather than a list handed to the template, because `board_content`
 * is a DECLARED variable of `retro.board_closed` (module.json): a unit
 * that reworded that e-mail substitutes plain strings, and a loop would
 * have left it announcing a closed retrospective without ever saying what
 * was in it.
 *
 * Its own class because two callers need exactly this string — the manual
 * close (Service\BoardService) and the scheduled one (Task\
 * AutoCloseHandler, which is deliberately self-contained and builds its
 * own everything) — and two copies of the same formatting is two e-mails
 * that drift apart.
 */
final class BoardEmailSummary
{
    /** The three columns, in the order the board shows them. */
    private const COLUMNS = [
        'good' => 'Ce qui a bien marché',
        'improve' => 'À améliorer',
        'suggestion' => 'Autres suggestions',
    ];

    private const EMPTY_COLUMN = 'Aucun mot dans cette colonne.';

    /**
     * @param array<int, Comment> $visibleComments the hidden ones are
     *        already filtered out by the caller (module spec: "except
     *        hidden words")
     */
    public static function fromComments(array $visibleComments): string
    {
        $byColumn = array_fill_keys(array_keys(self::COLUMNS), []);
        foreach ($visibleComments as $comment) {
            if (isset($byColumn[$comment->columnKey])) {
                $byColumn[$comment->columnKey][] = $comment->body;
            }
        }

        $blocks = [];
        foreach (self::COLUMNS as $key => $label) {
            $lines = [$label];
            if ($byColumn[$key] === []) {
                $lines[] = self::EMPTY_COLUMN;
            } else {
                foreach ($byColumn[$key] as $body) {
                    $lines[] = '- ' . $body;
                }
            }
            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }
}
