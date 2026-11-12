<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Modules\Registration\Api\ImportedRequest;
use Modules\Registration\Api\ImportedSecondaryEmail;
use Modules\Registration\Api\RequestImportWriter;
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;

/**
 * Files a request of an old site with no side effect — the module's
 * implementation of Api\RequestImportWriter (chantier #1142, lot H).
 *
 * It goes through the same repositories as a request filed on the public
 * form, so encryption, blind indexes and the request's native tracking
 * token are the ones every request gets; what it leaves out is every
 * method that sends: no RegistrationService::submit() (acknowledgement,
 * unit alert), no RequestDecisionService::apply() (decision e-mail
 * planned), no SecondaryEmailService::addEmail() (confirmation e-mail).
 *
 * The status is written ONCE, as it was on the old site, and not walked
 * through the transitions RequestStatusService allows: a request refused
 * there arrives refused, not « pending, then refused ». `encoded` goes
 * through linkToMemberAndEncode(), the module's one write of that state,
 * and only with a member the caller resolved by its Desk id.
 *
 * A decision (acceptance, refusal, encoding) was told to the family by
 * the old site: it is recorded « Communiquée par l'ancien site » (D2,
 * DecisionEmailRepository::insertCommunicatedElsewhere()), which no send
 * path reads and which makes the decision visible on the tracking page.
 */
class RequestImportService implements RequestImportWriter
{
    /**
     * Internal notes have no ceiling on the fiche, but a column does: an
     * encrypted BLOB holds 64 KiB. Far above any note a chief types.
     */
    public const MAX_NOTES_LENGTH = 10000;

    private const TEXT_FIELDS = [
        'parent_name', 'child_last_name', 'child_first_name', 'gender', 'birth_date', 'street', 'number',
        'postal_code', 'city', 'email', 'phone1', 'phone2', 'remarks', 'previous_unit_answer', 'previous_unit_name',
    ];

    private const OPTIONAL_FIELDS = ['phone2', 'remarks', 'previous_unit_answer', 'previous_unit_name'];

    public function __construct(
        private RegistrationRequestRepository $requests,
        private DecisionEmailRepository $decisionEmails,
        private RegistrationSecondaryEmailRepository $secondaryEmails,
        private SettingService $settings,
        private JournalService $journal,
        private RegistrationRequestValidator $validator = new RegistrationRequestValidator()
    ) {
    }

    public function problems(ImportedRequest $request): array
    {
        $problems = [];
        foreach (self::TEXT_FIELDS as $key) {
            $value = $request->fields[$key] ?? null;
            if ($value !== null && !is_string($value)) {
                $problems[] = 'Valeur non reconnue : ' . RegistrationRequestValidator::FIELD_LABELS[$key];
            }
        }
        if ($problems !== []) {
            return $problems;
        }

        // The rules of the public form, minus its two questions that only
        // a visitor of THIS site can answer (see the validator): the old
        // site's privacy policy, and a question it may never have asked.
        foreach ($this->validator->violations(self::fields($request), null, false) as $violation) {
            $problems[] = RegistrationRequestValidator::importReason($violation);
        }

        if (!in_array($request->status, ImportedRequest::STATUSES, true)) {
            $problems[] = 'Statut non reconnu';
        }
        if ($request->status === ImportedRequest::STATUS_REFUSED
            && $request->refusalReason !== null
            && !in_array($request->refusalReason, ImportedRequest::REFUSAL_REASONS, true)
        ) {
            $problems[] = 'Motif de refus non reconnu';
        }
        if ($request->status === ImportedRequest::STATUS_ENCODED) {
            if ($request->linkedMemberId === null) {
                $problems[] = 'Demande encodée sans membre Desk reconnu';
            } elseif ($this->requests->findByLinkedMemberId($request->linkedMemberId) !== null) {
                $problems[] = 'Membre Desk déjà lié à une autre demande';
            }
        }
        if ($request->internalNotes !== null && mb_strlen($request->internalNotes) > self::MAX_NOTES_LENGTH) {
            $problems[] = 'Champ trop long : notes internes';
        }

        return $problems;
    }

    public function looksLikeExistingRequest(ImportedRequest $request): bool
    {
        $fields = self::fields($request);

        return $this->requests->hasLiveRequestForChild(
            $request->scoutYearId,
            (string) $fields['child_last_name'],
            (string) $fields['child_first_name'],
            (string) $fields['birth_date']
        );
    }

    public function write(ImportedRequest $request): int
    {
        if ($this->problems($request) !== []) {
            throw new \InvalidArgumentException('This request cannot be imported as it is.');
        }

        return $this->requests->transactional(function () use ($request): int {
            $fields = self::fields($request);

            // The link's lifetime counts from the historical filing, as it
            // does for every request (TrackingLinkValidity): the token is
            // the native one, created here and sent to nobody.
            $created = $this->requests->create(
                $request->scoutYearId,
                [
                    'parent_name' => (string) $fields['parent_name'],
                    'child_last_name' => (string) $fields['child_last_name'],
                    'child_first_name' => (string) $fields['child_first_name'],
                    'gender' => (string) $fields['gender'],
                    'birth_date' => (string) $fields['birth_date'],
                    'street' => (string) $fields['street'],
                    'number' => (string) $fields['number'],
                    'postal_code' => (string) $fields['postal_code'],
                    'city' => (string) $fields['city'],
                    'email' => (string) $fields['email'],
                    'phone1' => (string) $fields['phone1'],
                    'phone2' => $fields['phone2'],
                    'remarks' => $fields['remarks'],
                    'previous_unit_answer' => $fields['previous_unit_answer'],
                    'previous_unit_name' => $fields['previous_unit_name'],
                ],
                $request->desiredSectionId,
                array_values(array_unique($request->siblingMemberIds)),
                TrackingLinkValidity::monthsFrom($this->settings->get(
                    TrackingLinkValidity::SETTING,
                    'registration',
                    (string) TrackingLinkValidity::DEFAULT_MONTHS
                )),
                $request->receivedAt
            );
            $id = $created['id'];

            if ($request->intendedSectionId !== null) {
                $this->requests->updateIntendedSection($id, $request->intendedSectionId);
            }
            if ($request->feeCategoryId !== null) {
                $this->requests->updateFeeCategory($id, $request->feeCategoryId);
            }
            $notes = self::notes($request->internalNotes);
            if ($notes !== null) {
                $this->requests->updateInternalNotes($id, $notes);
            }

            $this->applyStatus($id, $request);
            $this->fileSecondaryEmails($id, $request, (string) $fields['email']);

            // An id and a status: never a name, never an address (SECURITY.md §11).
            $this->journal->log(
                'registration',
                'registration_request_imported',
                'info',
                "Demande d'inscription reprise de l'ancien site",
                ['request_id' => $id, 'status' => $request->status]
            );

            return $id;
        });
    }

    /**
     * The status of the old site, in one write, and the « Communiquée par
     * l'ancien site » line of its decision when it holds one (D2). A final
     * status starts the retention clock at the old site's decision when
     * the archive gives it, at this import otherwise — never earlier than
     * what is known.
     */
    private function applyStatus(int $id, ImportedRequest $request): void
    {
        $now = new \DateTimeImmutable();
        $decidedAt = $request->decidedAt;

        if ($request->status === ImportedRequest::STATUS_ENCODED) {
            $this->requests->linkToMemberAndEncode($id, (int) $request->linkedMemberId, $decidedAt ?? $now);
        } elseif ($request->status !== ImportedRequest::STATUS_PENDING) {
            $this->requests->updateStatus(
                $id,
                $request->status,
                in_array($request->status, RegistrationRequest::FINAL_STATUSES, true) ? ($decidedAt ?? $now) : null,
                $request->refusalReason
            );
        }

        $decision = DecisionEmail::decisionForStatus($request->status);
        if ($decision !== null
            && $this->decisionEmails->insertCommunicatedElsewhere($id, $decision, $decidedAt, $now) === null
        ) {
            throw new \LogicException('The communicated decision of an imported request was not recorded.');
        }
    }

    /**
     * Each secondary address in its old state, without a confirmation
     * e-mail. One the module cannot hold — not an address, a state other
     * than valid or pending, the request's own primary address, a second
     * copy — is left out rather than turned into something it was not.
     */
    private function fileSecondaryEmails(int $id, ImportedRequest $request, string $primaryEmail): void
    {
        $seen = [RegistrationRequestRepository::normalizeEmail($primaryEmail) => true];
        foreach ($request->secondaryEmails as $secondary) {
            $email = trim($secondary->email);
            $key = RegistrationRequestRepository::normalizeEmail($email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
                || !in_array(
                    $secondary->status,
                    [ImportedSecondaryEmail::STATUS_PENDING, ImportedSecondaryEmail::STATUS_VALID],
                    true
                )
                || isset($seen[$key])
            ) {
                continue;
            }
            $seen[$key] = true;
            $this->secondaryEmails->createImported($id, $email, $secondary->status);
        }
    }

    /**
     * The request's fields as the public form hands them over: trimmed,
     * the optional ones null when empty, the unit's name kept only with a
     * « oui » — the same shape Controller\PublicRegistrationController::
     * extractFields() builds, so the validator reads one shape.
     *
     * @return array<string, ?string>
     */
    private static function fields(ImportedRequest $request): array
    {
        $fields = [];
        foreach (self::TEXT_FIELDS as $key) {
            $value = $request->fields[$key] ?? null;
            $value = is_string($value) ? trim($value) : '';
            $fields[$key] = $value === '' && in_array($key, self::OPTIONAL_FIELDS, true) ? null : $value;
        }
        if ($fields['previous_unit_answer'] !== RegistrationRequest::PREVIOUS_UNIT_YES) {
            $fields['previous_unit_name'] = null;
        }

        return $fields;
    }

    private static function notes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }
        $notes = trim(str_replace(["\r\n", "\r"], "\n", $notes));

        return $notes !== '' ? $notes : null;
    }
}
