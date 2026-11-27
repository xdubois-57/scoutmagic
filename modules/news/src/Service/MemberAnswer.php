<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

/**
 * The stored form of an answer to a field whose options are the
 * account's members (`options_source = members`): the Desk ids chosen,
 * as a JSON array of strings — `["12345"]` for a dropdown or a radio,
 * `["12345","67890"]` for checkboxes, the empty string for no choice.
 *
 * **Why JSON, and why for every type.** A manual checkbox answer is its
 * options joined by « , », which is only safe because the editor wrote
 * those options; nothing says a Desk id can never hold the separator.
 * JSON escapes whatever a string holds, so decoding gives back exactly
 * the list encoded. One shape for the three types means a reader needs
 * the field's source and nothing else — not its type, which an editor
 * can change after answers exist.
 *
 * **Never a name.** The label is looked up when the answer is read
 * (MemberAnswerResolver), so a homonym cannot collide and a renamed
 * member is not a changed answer.
 */
final class MemberAnswer
{
    /** What is shown in place of a member nobody here may name any more. */
    public const UNAVAILABLE_LABEL = 'Membre indisponible';

    /**
     * @param string[] $deskIds
     */
    public static function encode(array $deskIds): string
    {
        $deskIds = array_values(array_unique(array_filter(
            $deskIds,
            static fn(string $deskId): bool => $deskId !== ''
        )));
        if ($deskIds === []) {
            return '';
        }

        return json_encode($deskIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * The Desk ids an answer holds. Anything that is not the shape
     * encode() writes reads as no choice at all, never as a guess.
     *
     * @return list<string>
     */
    public static function decode(?string $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }

        try {
            $decoded = json_decode($stored, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [];
        }

        $deskIds = [];
        foreach ($decoded as $deskId) {
            if (!is_string($deskId) || $deskId === '') {
                return [];
            }
            $deskIds[] = $deskId;
        }

        return $deskIds;
    }
}
