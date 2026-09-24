<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * One module's mail triage screen, as data (issue #462, D9): the list the
 * shared template `@inbound_mail/partials/triage.html.twig` renders, for
 * camps and rentals alike.
 *
 * Two screens that each built their own list drifted apart the day they
 * were written — which filters exist, what counts as « à trier », what an
 * excerpt is. Both now build it here, from `InboundMailInterface` alone.
 *
 * **It invents no access.** Every read goes through the interface with
 * the consumer's own id and the references the caller says the requester
 * may reach — the same `$ownReferences` the triage list, the set-aside
 * list and every write are scoped by. What a consumer's screen adds (the
 * names of its objects, its picker) comes from that consumer.
 */
final class TriageList
{
    public const STATUS_UNLINKED = 'non_rattaches';
    public const STATUS_LINKED = 'rattaches';
    public const STATUS_ALL = 'tous';
    public const STATUS_DISMISSED = 'ecartes';

    /**
     * A one-line excerpt's worth: the whole message opens in a dialog.
     */
    public const EXCERPT_LENGTH = 220;

    /**
     * The filter a request asked for, « à trier » by default: what somebody
     * comes to this screen to do is decide about the mail nothing could
     * attribute.
     */
    public static function status(string $raw): string
    {
        return in_array($raw, [self::STATUS_LINKED, self::STATUS_ALL, self::STATUS_DISMISSED], true)
            ? $raw
            : self::STATUS_UNLINKED;
    }

    /**
     * The rows of one filter. « Rattachés » and « à trier » are read off
     * this consumer's own links, never anybody else's.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function filtered(array $rows, string $status): array
    {
        if ($status === self::STATUS_ALL || $status === self::STATUS_DISMISSED) {
            return $rows;
        }

        $wantLinked = $status === self::STATUS_LINKED;

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => (($row['links'] ?? []) !== []) === $wantLinked
        ));
    }

    /**
     * The screen's list for one consumer: its messages, its links and its
     * standing propositions on each, and what a row needs to render.
     *
     * Only THIS consumer's links and propositions: another module's
     * business on the same message is not this screen's, and showing it
     * would leak one module's guesses into another's audience.
     *
     * @param string[] $ownReferences references the requester may manage
     * @param callable(InboundMessage): array<string, mixed> $extra what the
     *   consumer adds to each row — its picker's options, most often
     * @return list<array<string, mixed>>
     */
    public static function rows(
        InboundMailInterface $mail,
        string $consumerId,
        array $ownReferences,
        int $limit,
        bool $dismissed = false,
        ?callable $extra = null
    ): array {
        $messages = $mail->findForTriage($consumerId, $ownReferences, $limit, $dismissed);
        $candidates = $mail->findCandidatesFor(
            $consumerId,
            array_map(static fn(InboundMessage $message): int => $message->id, $messages)
        );

        $rows = [];
        foreach ($messages as $message) {
            $body = trim($message->bodyText);
            $rows[] = [
                'message' => $message,
                'excerpt' => mb_substr($body, 0, self::EXCERPT_LENGTH),
                // Whether the excerpt actually cut something off, so the
                // screen promises "there is more" only when there is.
                'truncated' => mb_strlen($body) > self::EXCERPT_LENGTH,
                'has_body' => $body !== '' || trim($message->bodyHtml) !== '',
                'attachment_count' => count($message->attachments),
                'links' => $message->linksFor($consumerId),
                'candidates' => $candidates[$message->id] ?? [],
            ] + ($extra !== null ? $extra($message) : []);
        }

        return $rows;
    }

    /**
     * The number on each filter tab. « Rien à trier » and « le filtre cache
     * tout » look identical on an empty list; only a number tells them
     * apart. The set-aside count is its own read, because a set-aside
     * message is not in the list — which is the point of setting it aside.
     *
     * @param list<array<string, mixed>> $rows the list, set-aside excluded
     * @return array<string, int>
     */
    public static function counts(array $rows, int $dismissed): array
    {
        return [
            self::STATUS_UNLINKED => count(self::filtered($rows, self::STATUS_UNLINKED)),
            self::STATUS_LINKED => count(self::filtered($rows, self::STATUS_LINKED)),
            self::STATUS_ALL => count($rows),
            self::STATUS_DISMISSED => $dismissed,
        ];
    }

    /**
     * Everything the template needs for one filter of the screen.
     *
     * Automatic mail — newsletters, bounces, acknowledgements — is out of
     * the work list unless asked for, exactly as it is on the chief's own
     * `/courrier`: a newsletter under « À trier » is not a decision anybody
     * has to make. The set-aside list is its own read (`$dismissedRows`),
     * made only when that filter is the one asked for: it answers a
     * different question, and loading it on every visit would pay for a
     * list nobody normally opens.
     *
     * @param list<array<string, mixed>> $everything the list, set-aside excluded
     * @param callable(): list<array<string, mixed>> $dismissedRows
     * @return array{messages: list<array<string, mixed>>, status: string, include_bulk: bool,
     *     bulk_count: int, counts: array<string, int>}
     */
    public static function screen(
        array $everything,
        string $status,
        bool $includeBulk,
        callable $dismissedRows,
        int $dismissedCount
    ): array {
        $human = array_values(array_filter(
            $everything,
            static fn(array $row): bool => !$row['message']->isBulk
        ));
        $all = $includeBulk ? $everything : $human;

        return [
            'messages' => $status === self::STATUS_DISMISSED ? $dismissedRows() : self::filtered($all, $status),
            'status' => $status,
            'include_bulk' => $includeBulk,
            'bulk_count' => count($everything) - count($human),
            'counts' => self::counts($all, $dismissedCount),
        ];
    }

    /**
     * What « Relancer l'analyse » found, said plainly.
     *
     * @param array{examined: int, linked: int, proposed: int} $report
     */
    public static function reanalysisMessage(array $report): string
    {
        if ($report['examined'] === 0) {
            return 'Aucun message en attente : tout ce qui est conservé est déjà rattaché.';
        }

        $found = [];
        if ($report['linked'] > 0) {
            $found[] = $report['linked'] . ' rattachement' . ($report['linked'] > 1 ? 's' : '');
        }
        if ($report['proposed'] > 0) {
            $found[] = $report['proposed'] . ' proposition' . ($report['proposed'] > 1 ? 's' : '');
        }

        return sprintf(
            '%d message%s réexaminé%s : %s. La lecture des pièces jointes se poursuit en arrière-plan.',
            $report['examined'],
            $report['examined'] > 1 ? 's' : '',
            $report['examined'] > 1 ? 's' : '',
            $found === [] ? 'rien de neuf pour l\'instant' : implode(' et ', $found)
        );
    }
}
