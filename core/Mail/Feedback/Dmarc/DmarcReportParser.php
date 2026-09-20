<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Dmarc;

/**
 * Reads the XML inside a DMARC aggregate report (RFC 7489, roadmap IT-06).
 *
 * **Written by a stranger, so it is read like one.** The document arrives
 * from whoever chose to send it to a mailbox anybody can write to. A
 * doctype is refused outright rather than disarmed — nothing in a DMARC
 * report needs one, and an XML parser that resolves external entities is
 * how a remote document reads local files. That is the same refusal
 * {@see \Core\Storage\Location\Backend\WebDav\WebDavResource} makes, for
 * the same reason.
 *
 * **Nothing that cannot be read produces anything.** No defaults, no
 * best guesses: a report that will not parse is a report the unit does not
 * get. Inventing a line would put an IP address on a screen that says « a
 * stranger is sending in your name », which is the one sentence on that
 * page a volunteer might act on.
 */
class DmarcReportParser
{
    /**
     * A report with more lines than this is not read.
     *
     * The XML is already bounded to four megabytes by
     * {@see BoundedArchive}, which caps the count implicitly — this makes
     * it explicit, and keeps one absurd report from becoming thousands of
     * database writes.
     */
    public const MAX_RECORDS = 5000;

    /**
     * The report this XML holds, or null when it holds none.
     *
     * Null is an ordinary answer, never an exception: an unreadable
     * attachment from a stranger must not interrupt a synchronisation
     * carrying everybody else's mail.
     */
    public function parse(string $xml): ?DmarcReport
    {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            return null;
        }

        $metadata = $document->report_metadata;
        $published = $document->policy_published;

        $domain = trim((string) ($published->domain ?? ''));
        $reportId = trim((string) ($metadata->report_id ?? ''));
        if ($domain === '' || $reportId === '') {
            return null;
        }

        $begin = $this->instantFrom($metadata->date_range->begin ?? null);
        $end = $this->instantFrom($metadata->date_range->end ?? null);
        if ($begin === null || $end === null) {
            return null;
        }

        $records = $this->recordsOf($document);
        if ($records === []) {
            return null;
        }

        return new DmarcReport(
            organisation: trim((string) ($metadata->org_name ?? '')) ?: 'inconnu',
            reportId: $reportId,
            domain: strtolower($domain),
            begin: $begin,
            end: $end,
            policy: strtolower(trim((string) ($published->p ?? ''))) ?: 'none',
            records: $records
        );
    }

    /**
     * @return list<DmarcRecord>
     */
    private function recordsOf(\SimpleXMLElement $document): array
    {
        $records = [];

        foreach ($document->record as $record) {
            if (count($records) >= self::MAX_RECORDS) {
                break;
            }

            $row = $record->row ?? null;
            if ($row === null) {
                continue;
            }

            $sourceIp = trim((string) ($row->source_ip ?? ''));
            // **An address that is not an address is not kept.** This one
            // is shown on a screen and grouped on, so a reporter's typo or
            // somebody's injected string must not become a row.
            if (filter_var($sourceIp, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $count = (int) ($row->count ?? 0);
            if ($count <= 0) {
                continue;
            }

            $evaluated = $row->policy_evaluated ?? null;

            $records[] = new DmarcRecord(
                sourceIp: $sourceIp,
                count: $count,
                disposition: $this->dispositionOf($evaluated),
                dkimPassed: strtolower(trim((string) ($evaluated->dkim ?? ''))) === 'pass',
                spfPassed: strtolower(trim((string) ($evaluated->spf ?? ''))) === 'pass',
                headerFrom: strtolower(trim((string) ($record->identifiers->header_from ?? '')))
            );
        }

        return $records;
    }

    /**
     * What the receiver did with the message. Anything unrecognised reads
     * as `none`, which is what a receiver does by default and the only
     * safe way to be wrong: claiming a message was rejected when it was
     * delivered would have somebody chase a delivery problem that is not
     * there.
     */
    private function dispositionOf(?\SimpleXMLElement $evaluated): string
    {
        $disposition = strtolower(trim((string) ($evaluated->disposition ?? '')));

        return in_array($disposition, ['none', 'quarantine', 'reject'], true) ? $disposition : 'none';
    }

    /**
     * The `date_range` bounds are Unix timestamps. A missing or
     * nonsensical one loses the report rather than being guessed at: the
     * screen groups by period, and a report filed under 1970 would sit
     * there for ever saying nothing.
     */
    private function instantFrom(?\SimpleXMLElement $value): ?\DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $timestamp = (int) $raw;
        if ($timestamp <= 0) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
    }
}
