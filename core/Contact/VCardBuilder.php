<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact;

use Core\Member\MemberAddress;

/**
 * Renders a {@see ContactCard} as a vCard.
 *
 * **Version 3.0**, not 4.0. Not a preference: 4.0 has been published since
 * 2011 and is still not what the two address books this feature exists for
 * actually read — iOS and the stock Android contacts app both import 3.0
 * without complaint and both have historically mangled or refused 4.0
 * payloads. A card nobody can open is not a card.
 *
 * The one renderer for both payloads. {@see VCardVariant} decides whether
 * the portrait is carried and how much history is kept; nothing else
 * differs, so the QR code and the downloaded file can never disagree about
 * what a field contains.
 *
 * Nothing here ever touches the filesystem: a card is built in memory and
 * handed to a response. `SECURITY.md` §5 allows personal data on disk only
 * encrypted or strictly temporary — this takes the third option and does
 * not put it there at all.
 */
final class VCardBuilder
{
    /**
     * RFC 2426 § 2.6 folds at 75 octets. The continuation is CRLF + a
     * single space, which the reader strips back out.
     */
    private const FOLD_OCTETS = 75;

    private const CRLF = "\r\n";

    public function build(ContactCard $card, VCardVariant $variant): string
    {
        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'PRODID:-//ScoutMagic//Contact//FR',
            'UID:' . $this->escape($card->uid()),
            'FN:' . $this->escape($card->formattedName()),
            // N is the decomposed name: family;given;additional;prefix;suffix.
            'N:' . $this->structured([$card->lastName, $card->firstName, '', '', '']),
        ];

        if ($card->totem !== null && $card->totem !== '') {
            $lines[] = 'NICKNAME:' . $this->escape($card->totem);
        }

        // The unit, then the section — an organisation and its unit, which
        // is exactly what ORG's two components mean.
        $lines[] = 'ORG:' . $this->structured(
            $card->sectionName !== null && $card->sectionName !== ''
                ? [$card->unitName, $card->sectionName]
                : [$card->unitName]
        );

        if ($card->title !== null && $card->title !== '') {
            $lines[] = 'TITLE:' . $this->escape($card->title);
        }

        foreach ($card->emails as $email) {
            $lines[] = 'EMAIL;TYPE=INTERNET:' . $this->escape($email);
        }

        // **Unlabelled on purpose.** The screen calls these two numbers
        // « Tél. parent 1 » and « Tél. parent 2 » because for an animé
        // they are the household's; Desk itself only ever says "landline"
        // and "mobile", and the card says neither. That mismatch between
        // the screen and the card is a decision of the requester, not an
        // oversight — do not "fix" it by adding TYPE=HOME/TYPE=CELL.
        foreach ($card->phones as $phone) {
            $lines[] = 'TEL:' . $this->escape($phone);
        }

        foreach ($card->addresses as $address) {
            $lines[] = 'ADR;TYPE=HOME:' . $this->address($address);
        }

        $note = $this->note($card, $variant);
        if ($note !== '') {
            $lines[] = 'NOTE:' . $note;
        }

        if ($variant->includesPhoto() && $card->photoJpeg !== null && $card->photoJpeg !== '') {
            $lines[] = 'PHOTO;ENCODING=b;TYPE=JPEG:' . base64_encode($card->photoJpeg);
        }

        $lines[] = 'REV:' . $card->revision->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $lines[] = 'END:VCARD';

        $folded = array_map(fn(string $line): string => $this->fold($line), $lines);

        return implode(self::CRLF, $folded) . self::CRLF;
    }

    /**
     * The current scout year, then one line per year of affiliations,
     * most recent first — capped for the QR variant.
     *
     * Escaped as ONE property value with `\n` separators, which is how a
     * multi-line NOTE travels in vCard 3.0; a literal newline would end
     * the property and produce an unparseable card.
     */
    private function note(ContactCard $card, VCardVariant $variant): string
    {
        $limit = $variant->affiliationLimit();
        $affiliations = $limit === null
            ? $card->affiliations
            : array_slice($card->affiliations, 0, $limit);

        $lines = ['Année scoute ' . $card->scoutYearLabel];
        foreach ($affiliations as $affiliation) {
            $lines[] = $affiliation->format();
        }

        return implode('\\n', array_map(fn(string $line): string => $this->escape($line), $lines));
    }

    /**
     * ADR's seven components: post office box; extended address; street
     * address; locality; region; postal code; country.
     *
     * The Desk `address_type` is NOT emitted as a TYPE parameter: it is
     * free French text typed into Desk (« Adresse principale », « Adresse
     * de correspondance »), and vCard 3.0's TYPE has a closed vocabulary
     * no such string belongs to. Every address travels as HOME.
     */
    private function address(MemberAddress $address): string
    {
        $parts = array_filter(
            [
                $address->street,
                $address->number,
                $address->box !== null && $address->box !== '' ? 'bte ' . $address->box : null,
            ],
            fn(?string $part): bool => $part !== null && $part !== ''
        );

        $street = trim(implode(' ', $parts));

        return $this->structured([
            '',
            $address->complement ?? '',
            $street,
            $address->city ?? '',
            '',
            $address->postalCode ?? '',
            $address->country ?? '',
        ]);
    }

    /**
     * @param list<string> $components
     */
    private function structured(array $components): string
    {
        return implode(';', array_map(fn(string $c): string => $this->escape($c), $components));
    }

    /**
     * RFC 2426 § 2.4.2: backslash, comma and semicolon are escaped, and a
     * newline becomes the two characters `\n`. The order matters —
     * backslash first, or every escape introduced below would be escaped
     * again.
     */
    private function escape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace([';', ','], ['\;', '\\,'], $value);

        return str_replace(["\r\n", "\r", "\n"], '\\n', $value);
    }

    /**
     * Fold to {@see self::FOLD_OCTETS} octets per line, breaking on
     * character boundaries rather than inside a multi-byte sequence: the
     * standard counts octets, but a parser that unfolds line by line
     * before decoding UTF-8 chokes on a half character, and PHOTO lines
     * are long enough that this happens for real.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= self::FOLD_OCTETS) {
            return $line;
        }

        $chunks = [];
        $current = '';
        $limit = self::FOLD_OCTETS;

        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if (strlen($current) + strlen($character) > $limit) {
                $chunks[] = $current;
                $current = '';
                // Every continuation line starts with the folding space,
                // which counts against the octet budget.
                $limit = self::FOLD_OCTETS - 1;
            }
            $current .= $character;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        $first = array_shift($chunks) ?? '';

        $continuations = array_map(
            fn(string $chunk): string => self::CRLF . ' ' . $chunk,
            $chunks
        );

        return $first . implode('', $continuations);
    }
}
