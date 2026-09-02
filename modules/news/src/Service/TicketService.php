<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Modules\News\Repository\FormResponse;
use Modules\News\Repository\FormResponseRepository;

/**
 * The ticket a form response IS — its reference, its QR, and the door's
 * two writes (used, and un-used).
 *
 * There is no ticket object and no ticket table. A response gains a
 * reference and a used-at stamp, exactly as the previous site added two
 * columns to FORM_DATA rather than a second numbering to keep in step
 * with the first.
 */
class TicketService
{
    /**
     * The reference's alphabet, and every character in it is a decision.
     *
     * **Uppercase and digits only.** A QR code changes size in steps, by
     * volume AND by encoding mode: uppercase letters, digits and a
     * handful of punctuation marks (the dash among them) fit the
     * ALPHANUMERIC mode at 5.5 bits per character, while a single
     * lowercase letter forces the whole payload into BYTE mode at 8. Ten
     * characters plus two dashes stay in QR version 1 — twenty-one
     * modules, the smallest code that exists. The URL this used to be
     * would have needed version 3, twenty-nine modules: at equal printed
     * size each module is 38% narrower, which is what decides whether a
     * code reads under a parish hall's lighting, on a cracked screen, at
     * arm's length in a queue.
     *
     * **No ambiguous character.** `I` and `O` are gone; `1` and `0` stay,
     * and are then unmistakable. This reference gets typed by hand every
     * time the camera refuses, and a confusion at the door costs more
     * than a shorter alphabet does.
     *
     * 34 characters over 10 positions is about 2 x 10^15 references.
     */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';

    /** Ten characters, grouped 4-4-2 for the eye: X7K2-9QMF-A3. */
    public const LENGTH = 10;

    /**
     * A drawn reference can collide with one already issued — vanishingly
     * unlikely, but the unique index is what makes it impossible rather
     * than unlikely, and this is how many times we redraw before giving
     * up. Five failures in a row is not bad luck, it is a broken index or
     * an exhausted keyspace, and both deserve an exception rather than a
     * loop.
     */
    private const CLAIM_ATTEMPTS = 5;

    /**
     * Error correction M, not L.
     *
     * Twelve alphanumeric characters fit QR version 1 at level M (which
     * holds twenty), so the redundancy is free here — it costs no module
     * at all — and it is what keeps a code readable off a smeared screen
     * held at an angle. The SEPA QR next to it in the same e-mail uses L
     * for the opposite reason: its payload is long enough that the level
     * really does cost versions.
     */
    private const QR_SIZE_PX = 400;

    public function __construct(private FormResponseRepository $responses)
    {
    }

    /**
     * Issues a reference for a response that has none, and returns it —
     * canonical, no dash. A response that already has one keeps it: a
     * reference already sent in an e-mail is the only thing its holder
     * has, so lowering and raising the form's flag must never invalidate
     * what was promised.
     *
     * @throws NewsException when a free reference cannot be drawn.
     */
    public function issueFor(FormResponse $response): string
    {
        if ($response->hasTicket()) {
            return (string) $response->ticketReference;
        }

        for ($attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++) {
            $candidate = self::generateReference();
            if ($this->responses->claimTicketReference($response->id, $candidate)) {
                return $candidate;
            }

            // The row may simply have been given a reference by a
            // concurrent request between our read and our write — that is
            // a success, not a collision, and re-reading says which.
            $fresh = $this->responses->findById($response->id);
            if ($fresh !== null && $fresh->hasTicket()) {
                return (string) $fresh->ticketReference;
            }
        }

        throw new NewsException("Impossible de générer une référence de billet. Réessayez dans un instant.");
    }

    /**
     * Gives every response of a form the reference it lacks, and returns
     * those that just got one.
     *
     * This is what raising the flag on a form that already has responses
     * runs: the people who signed up first would otherwise turn up
     * empty-handed. Lowering the flag runs nothing — the tickets already
     * issued stay valid and stay scannable. We stop delivering; we do not
     * revoke what was promised.
     *
     * @return FormResponse[] the responses whose ticket was just issued, re-read so they carry it.
     */
    public function backfillForForm(int $formId): array
    {
        $issued = [];
        foreach ($this->responses->findByFormIdWithoutTicket($formId) as $response) {
            $this->issueFor($response);
            $fresh = $this->responses->findById($response->id);
            if ($fresh !== null && $fresh->hasTicket()) {
                $issued[] = $fresh;
            }
        }

        return $issued;
    }

    /**
     * The response a scanned or typed reference names, anywhere on the
     * site — deliberately not scoped to one form, because that is what
     * lets the door screen answer « ce billet est pour le Marché de Noël »
     * rather than « introuvable », which would send somebody looking for
     * a fault that does not exist.
     */
    public function findByReference(string $input): ?FormResponse
    {
        $canonical = self::canonicalize($input);
        if ($canonical === null) {
            return null;
        }

        return $this->responses->findByTicketReference($canonical);
    }

    /**
     * Marks the holder in. Returns the moment recorded.
     *
     * **It never touches the receivable.** Paying and coming in are two
     * distinct facts: the door shows whether the money arrived so the
     * staff can ask, and the reconciliation happens cold, on the
     * responses screen. Asking for a second confirmation on an unpaid
     * ticket would slow the queue at the exact moment it must not, to
     * produce a trace we get another way.
     */
    public function markUsed(FormResponse $response, ?\DateTimeImmutable $now = null): string
    {
        $usedAt = ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->responses->setTicketUsedAt($response->id, $usedAt);

        return $usedAt;
    }

    /** Takes the mark back — a scan by mistake, a validation made too early. */
    public function markUnused(FormResponse $response): void
    {
        $this->responses->setTicketUsedAt($response->id, null);
    }

    /**
     * A reference as it is shown and encoded: X7K2-9QMF-A3.
     *
     * The dash belongs to the QR format's alphanumeric set, so grouping
     * costs nothing in size and buys a reference somebody can read aloud
     * across a queue.
     */
    public static function format(string $canonical): string
    {
        if (strlen($canonical) !== self::LENGTH) {
            return $canonical;
        }

        return substr($canonical, 0, 4) . '-' . substr($canonical, 4, 4) . '-' . substr($canonical, 8, 2);
    }

    /**
     * What the door screen accepts as a reference, whatever shape it
     * arrives in: scanned with its dashes, typed without them, typed in
     * lowercase, pasted with a stray space.
     *
     * Returns null for anything that is not a reference at all, so a
     * caller can tell « this is not a reference » from « this reference
     * matches nothing » — two different sentences at the door.
     */
    public static function canonicalize(string $input): ?string
    {
        $upper = strtoupper(trim($input));
        $stripped = (string) preg_replace('/[^A-Z0-9]/', '', $upper);

        if (strlen($stripped) !== self::LENGTH) {
            return null;
        }
        if (strspn($stripped, self::ALPHABET) !== self::LENGTH) {
            return null;
        }

        return $stripped;
    }

    /**
     * The ticket's QR — the REFERENCE alone, never a URL.
     *
     * A URL would let a phone's native camera open a page, but the scan
     * happens inside this site's own screen, which knows perfectly well
     * what to do with a bare reference; and the buyer has no reason to
     * scan their own ticket, having the link in their e-mail already.
     * What a URL would cost is the version-1 code the whole alphabet
     * above was chosen for.
     *
     * @return string raw PNG bytes
     */
    public static function qrPng(string $canonical): string
    {
        return (new Builder(
            writer: new PngWriter(),
            data: self::format($canonical),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: self::QR_SIZE_PX,
            margin: 10
        ))->build()->getString();
    }

    public static function qrDataUri(string $canonical): string
    {
        return 'data:image/png;base64,' . base64_encode(self::qrPng($canonical));
    }

    /**
     * `random_int()` rather than `rand()`: the reference is not a
     * credential — no public route reads it, the door screen is behind a
     * session — but a guessable one still lets somebody walk in on
     * another family's booking, and a CSPRNG costs nothing here.
     */
    private static function generateReference(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;

        $reference = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $reference .= $alphabet[random_int(0, $max)];
        }

        return $reference;
    }
}
