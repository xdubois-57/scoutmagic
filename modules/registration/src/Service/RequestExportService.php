<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Export\TabularSpreadsheet;
use Core\Service\DateInput;
use Modules\Registration\Repository\RegistrationRequest;

/**
 * Turns the rows of "Configuration > Inscriptions" into an .xlsx a chef
 * d'unité can actually work on — sort it, annotate it, hand a section its
 * own slice.
 *
 * Three things this deliberately does NOT do:
 *
 * - **It writes no spreadsheet plumbing of its own.** Every cell goes out
 *   through Core\Export\TabularSpreadsheet, which types each one as an
 *   explicit string so an address, a name or a family's free-text remark
 *   beginning with `=`, `+`, `-` or `@` cannot open as a live formula
 *   (SECURITY.md §23). That text arrives from a public form, so the
 *   threat is not hypothetical.
 * - **It never touches encryption.** A RegistrationRequest is already
 *   decrypted — Repository\RegistrationRequestRepository is the one place
 *   allowed to do that (SECURITY.md §5) — and this service only reads
 *   business values off it.
 * - **It never exports `internal_notes`.** Those are the staff's own
 *   remarks about a family, and an exported file leaves the site's
 *   protections entirely: it travels by email, lands in a shared folder,
 *   and outlives whoever produced it. The rest of the request is what the
 *   family themselves wrote and what the unit decided about it.
 *
 * The contact column is named « Email » on purpose: ARCHITECTURE.md §8.62
 * asks every export of people to keep its identifier/contact headers
 * inside the mail-merge importer's alias set, so a unit can feed this file
 * straight back in as an audience instead of hand-editing it.
 */
final class RequestExportService
{
    private const STATUS_LABELS = [
        RegistrationRequest::STATUS_PENDING => 'En attente',
        RegistrationRequest::STATUS_ACCEPTED => 'Acceptée',
        RegistrationRequest::STATUS_REFUSED => 'Refusée',
        RegistrationRequest::STATUS_WITHDRAWN => 'Retirée',
        RegistrationRequest::STATUS_ENCODED => 'Encodée dans Desk',
    ];

    /**
     * @return array<int, string>
     */
    public static function headers(): array
    {
        return [
            'Reçue le',
            'État',
            'Nom de l\'enfant',
            'Prénom de l\'enfant',
            'Date de naissance',
            'Genre',
            'Créneau',
            'Section souhaitée',
            'Section prévue',
            'Fratrie',
            'Parent',
            'Email',
            'Téléphone',
            'Téléphone 2',
            'Rue',
            'Numéro',
            'Code postal',
            'Localité',
            'Remarques',
            'Email envoyé le',
        ];
    }

    /**
     * $rows is the page's own already-filtered list, in its own order —
     * the export must reflect exactly what the screen shows, filters
     * included, so it takes the built rows rather than re-querying and
     * re-filtering on its own. Each entry carries the shape
     * Controller\RegistrationConfigController::buildRequestRows() emits.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function buildSpreadsheet(array $rows): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $sheetRows = [];
        foreach ($rows as $row) {
            $registrationRequest = $row['request'];
            if (!$registrationRequest instanceof RegistrationRequest) {
                continue;
            }

            $sheetRows[] = [
                $registrationRequest->receivedAt->format('d/m/Y'),
                self::STATUS_LABELS[$registrationRequest->status] ?? $registrationRequest->status,
                $registrationRequest->childLastName,
                $registrationRequest->childFirstName,
                $this->frenchDate($registrationRequest->birthDate),
                $registrationRequest->gender,
                (string) ($row['slot_label'] ?? ''),
                (string) ($row['desired_section_label'] ?? ''),
                (string) ($row['intended_section_label'] ?? ''),
                (string) ($row['sibling_count'] ?? 0),
                $registrationRequest->parentName,
                $registrationRequest->email,
                $registrationRequest->phone1,
                $registrationRequest->phone2 ?? '',
                $registrationRequest->street,
                $registrationRequest->number,
                $registrationRequest->postalCode,
                $registrationRequest->city,
                $registrationRequest->remarks ?? '',
                $this->emailSentLabel($registrationRequest),
            ];
        }

        return TabularSpreadsheet::buildSpreadsheet(self::headers(), $sheetRows, 'Demandes');
    }

    /**
     * The confirmation mail is what a family actually received, so the
     * column says which one and when — mirroring the "Email" column on
     * the screen rather than inventing a second vocabulary for it.
     */
    private function emailSentLabel(RegistrationRequest $registrationRequest): string
    {
        $sentAt = match ($registrationRequest->status) {
            RegistrationRequest::STATUS_ACCEPTED,
            RegistrationRequest::STATUS_ENCODED => $registrationRequest->acceptedEmailSentAt,
            RegistrationRequest::STATUS_REFUSED => $registrationRequest->refusedEmailSentAt,
            default => null,
        };

        if ($sentAt !== null) {
            return $sentAt->format('d/m/Y');
        }

        return in_array(
            $registrationRequest->status,
            [
                RegistrationRequest::STATUS_ACCEPTED,
                RegistrationRequest::STATUS_ENCODED,
                RegistrationRequest::STATUS_REFUSED,
            ],
            true
        ) ? 'Non envoyé' : '';
    }

    /**
     * birth_date is stored as `Y-m-d` and written as text, not as a real
     * Excel date: TabularSpreadsheet types every cell as a string, which
     * is what keeps a crafted value from becoming a formula. A French
     * reader gets a French-looking date either way.
     *
     * Parsed through Core\Service\DateInput, the site's one date-parsing
     * entry point: parsing by hand raises a ValueError rather than
     * returning false on a value carrying a NUL byte, so the usual
     * `!== false` guard lets that single input through as an uncaught
     * exception. Tests\Security\DateParsingConvergenceTest holds the
     * line.
     */
    private function frenchDate(string $storedDate): string
    {
        $date = DateInput::fromStorage($storedDate);

        return $date !== null ? $date->format('d/m/Y') : $storedDate;
    }
}
