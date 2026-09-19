<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Bounce;

/**
 * One recipient's verdict, read out of a `message/delivery-status`
 * (RFC 3464, roadmap IT-05).
 *
 * **Everything here was written by somebody else's server.** That is the
 * whole posture of this class: every field is optional, every field may be
 * malformed, and a report that cannot be understood produces nothing
 * rather than a default. The cost of the two mistakes is not symmetric —
 * missing a bounce delays a diagnosis, while inventing one suspends a
 * parent's address on the strength of a line the site misread.
 *
 * **The diagnostic text is deliberately not a property.** It is read (it
 * is where a status code hides when the structured field is missing) and
 * then dropped. It quotes the address back, it is written in the far end's
 * language, and nothing downstream may put it on a screen or in a log —
 * see {@see BounceCategory}.
 */
final class DeliveryStatusReport
{
    /**
     * The `report-type=delivery-status` part, as MIME names it. A bounce
     * carries it inside a `multipart/report`.
     */
    public const CONTENT_TYPE = 'message/delivery-status';

    private function __construct(
        /** The address that failed, lower-cased, without the `rfc822;` prefix. */
        public readonly string $recipient,
        public readonly BounceSeverity $severity,
        public readonly BounceCategory $category,
        /**
         * The enhanced status code as sent, kept because it is the key the
         * « first time for THIS error » rule is indexed on — « boîte
         * pleine » resolved and full again six months later has to be able
         * to notify a second time, and `5.2.2` is what says so.
         */
        public readonly string $statusCode
    ) {
    }

    /**
     * Read every failed recipient out of one delivery-status body.
     *
     * RFC 3464 §2.1: per-message fields first, then one field group per
     * recipient, groups separated by a blank line. Only groups that
     * actually failed are returned — `Action: delivered` and
     * `Action: relayed` appear in the same report as a failure when one
     * message had several recipients, and treating those as bounces would
     * block the addresses that worked.
     *
     * @return list<self>
     */
    public static function parseAll(string $body): array
    {
        $reports = [];

        foreach (self::groups($body) as $group) {
            $report = self::fromGroup($group);
            if ($report !== null) {
                $reports[] = $report;
            }
        }

        return $reports;
    }

    /**
     * Split on blank lines, after unfolding continuation lines.
     *
     * RFC 5322 folding means a long `Diagnostic-Code` arrives across
     * several lines, the later ones starting with whitespace. Unfolding
     * first is what stops a folded diagnostic from being mistaken for the
     * start of a new field — or, worse, for a blank-line boundary.
     *
     * @return list<string>
     */
    private static function groups(string $body): array
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $body);
        // Unfold: a newline followed by space or tab is a continuation.
        $unfolded = (string) preg_replace('/\n[ \t]+/', ' ', $normalised);

        $groups = preg_split('/\n\s*\n/', $unfolded) ?: [];

        return array_values(array_filter(
            array_map('trim', $groups),
            static fn(string $group): bool => $group !== ''
        ));
    }

    /**
     * One field group → one report, or null when it is not a failure this
     * site can act on.
     */
    private static function fromGroup(string $group): ?self
    {
        $fields = self::fields($group);

        $recipient = self::address($fields['final-recipient'] ?? $fields['original-recipient'] ?? '');
        if ($recipient === '') {
            return null;
        }

        // `Action` is the field that says what happened, and RFC 3464 §2.3.3
        // enumerates it. Only `failed` is a bounce; `delayed` is the far end
        // still trying, and acting on it would mark an address that may
        // still be delivered to minutes later.
        $action = strtolower(trim($fields['action'] ?? ''));
        if ($action !== 'failed') {
            return null;
        }

        $status = self::statusCode($fields);
        if ($status === '') {
            return null;
        }

        $severity = BounceSeverity::fromStatusCode($status);
        if ($severity === null) {
            return null;
        }

        return new self($recipient, $severity, BounceCategory::fromStatusCode($status), $status);
    }

    /**
     * The enhanced status code, from the structured field when there is
     * one and from the diagnostic text when there is not.
     *
     * The fallback earns its place: plenty of servers send
     * `Diagnostic-Code: smtp; 550 5.1.1 User unknown` with no `Status:`
     * field at all, and refusing to read those would silently ignore a
     * large share of real bounces. The pattern is anchored on the shape of
     * a code rather than on any wording, so it carries no language
     * assumption.
     *
     * @param array<string, string> $fields
     */
    private static function statusCode(array $fields): string
    {
        $status = trim($fields['status'] ?? '');
        if (preg_match('/^[245]\.\d{1,3}\.\d{1,3}$/', $status) === 1) {
            return $status;
        }

        // `(?<![\d.])` and `(?![\d.])` rather than `\b`: a word boundary
        // is happy to start inside a dotted number, so
        // `connect to relay[92.5.1.10]` yields `5.1.10` — read as a
        // PERMANENT « adresse inexistante » when the failure was a
        // transient connection timeout. Two of those suspend an address,
        // which is exactly the « inventing one » this class rules out.
        if (preg_match('/(?<![\d.])([245]\.\d{1,3}\.\d{1,3})(?![\d.])/', $fields['diagnostic-code'] ?? '', $found) === 1) {
            return $found[1];
        }

        return '';
    }

    /**
     * `rfc822; user@example.com` → `user@example.com`, lower-cased.
     *
     * The address type prefix is optional in practice even though the RFC
     * requires it, so it is stripped when present rather than demanded.
     * An address that does not survive `FILTER_VALIDATE_EMAIL` answers
     * empty: this value is about to be blind-indexed against the site's
     * own addresses, and a malformed one can only ever fail to match — but
     * it would match a *stored* malformed one, so it is refused outright.
     */
    private static function address(string $raw): string
    {
        $value = trim($raw);
        if (str_contains($value, ';')) {
            $value = trim(substr($value, strpos($value, ';') + 1));
        }

        // Some servers wrap it: <user@example.com>
        $value = trim($value, '<> ');

        return is_string(filter_var($value, FILTER_VALIDATE_EMAIL))
            ? strtolower($value)
            : '';
    }

    /**
     * Field name (lower-cased) → value, last occurrence winning.
     *
     * @return array<string, string>
     */
    private static function fields(string $group): array
    {
        $fields = [];

        foreach (explode("\n", $group) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $fields[$name] = trim(substr($line, $colon + 1));
        }

        return $fields;
    }
}
