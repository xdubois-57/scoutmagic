<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * A Desk export refused as a whole because some of its lines break the
 * rules of {@see DeskImportValidator} (issue #854). Nothing was written.
 *
 * Its message is French and written for the administrator who deposited
 * the file — the summary, then one sentence per error, each built by the
 * validator from this class's own wording plus the line number, the Tiers
 * code and the offending cell. It is an {@see ImportException}, so every
 * place that already shows one shows this one too; `ImportController`
 * catches it first to lay the errors out as a list.
 *
 * Never journaled: the journal gets counts per rule
 * ({@see self::countsByRule()}), never these sentences (SECURITY.md §13).
 */
class DeskImportRejectedException extends ImportException
{
    /**
     * How many errors the page lists before summing up the rest as
     * « … et N autres ». Every error is still counted in the summary and in
     * the journal. A cap rather than everything, because a file whose whole
     * birth-date column was reformatted by a spreadsheet holds one error
     * per line — six hundred identical sentences for a large unit, and
     * tens of thousands for a 10 MB file — when the hundreds the page
     * shows already say what to repair.
     */
    public const MAX_LISTED_ERRORS = 200;

    /**
     * @param non-empty-list<DeskImportError> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode("\n", [$this->summary(), ...$this->listedMessages()]));
    }

    public function summary(): string
    {
        $count = count($this->errors);

        return sprintf(
            'Import refusé : le fichier contient %d erreur%s, et rien n\'a été importé. '
                . 'Corrigez-%s dans Desk, exportez à nouveau et déposez le nouveau fichier.',
            $count,
            $count > 1 ? 's' : '',
            $count > 1 ? 'les' : 'la'
        );
    }

    /**
     * The sentences the page lists, at most MAX_LISTED_ERRORS of them,
     * followed by « … et N autres erreurs. » when there were more.
     *
     * @return list<string>
     */
    public function listedMessages(): array
    {
        $listed = array_map(
            static fn(DeskImportError $error): string => $error->message,
            array_slice($this->errors, 0, self::MAX_LISTED_ERRORS)
        );

        $hidden = count($this->errors) - count($listed);
        if ($hidden > 0) {
            $listed[] = sprintf('… et %1$d autre%2$s erreur%2$s.', $hidden, $hidden > 1 ? 's' : '');
        }

        return $listed;
    }

    /**
     * How many errors each rule found, keyed by DeskImportRule::journalKey()
     * — the only trace of a refused file the journal may keep.
     *
     * @return array<string, int>
     */
    public function countsByRule(): array
    {
        $counts = [];
        foreach ($this->errors as $error) {
            $key = $error->rule->journalKey();
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }
}
