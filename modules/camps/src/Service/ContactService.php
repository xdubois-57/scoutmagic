<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Core\Journal\JournalService;
use Modules\Camps\Repository\Contact;
use Modules\Camps\Repository\ContactRepository;

/**
 * The people to call about a stay, and their erasure.
 *
 * They are external third parties — a site's owner, its caretaker — with
 * no relationship to the unit and no account here. They never asked to be
 * in this database, which is why erasing them properly is a first-class
 * feature of this module rather than something a superadmin does in SQL.
 */
class ContactService
{
    /** The roles a contact can hold, in the order the picker offers them. */
    public const ROLES = [
        'proprietaire' => 'Propriétaire',
        'gestionnaire' => 'Gestionnaire',
        'sur_place' => 'Contact sur place',
        'autre' => 'Autre',
    ];

    public function __construct(
        private ContactRepository $contacts,
        private AuditService $audit,
        private ?JournalService $journal = null
    ) {
    }

    /**
     * @param array<string, string|null> $fields
     */
    public function create(int $campId, array $fields, ?int $actorUserAccountId): int
    {
        $values = $this->validate($fields);

        $id = $this->contacts->create(
            $campId,
            $values['name'],
            $values['role_label'],
            $values['email'],
            $values['phone'],
            $values['other_details'],
        );

        $this->audit->record(
            CampService::ENTITY_TYPE,
            $campId,
            'contact',
            null,
            $this->describe($values),
            AuditSource::Human,
            'Contact ajouté',
            null,
            $actorUserAccountId
        );

        return $id;
    }

    /**
     * @param array<string, string|null> $fields
     */
    public function update(Contact $contact, array $fields, ?int $actorUserAccountId): void
    {
        $values = $this->validate($fields);
        $before = $this->describe([
            'name' => $contact->name,
            'role_label' => $contact->roleLabel,
            'email' => $contact->email,
            'phone' => $contact->phone,
        ]);

        $this->contacts->update(
            $contact->id,
            $values['name'],
            $values['role_label'],
            $values['email'],
            $values['phone'],
            $values['other_details'],
        );

        $after = $this->describe($values);
        if ($before === $after) {
            return;
        }

        $this->audit->record(
            CampService::ENTITY_TYPE,
            $contact->campId,
            'contact',
            $before,
            $after,
            AuditSource::Human,
            null,
            null,
            $actorUserAccountId
        );
    }

    public function delete(Contact $contact, ?int $actorUserAccountId): void
    {
        $this->contacts->delete($contact->id);

        $this->audit->record(
            CampService::ENTITY_TYPE,
            $contact->campId,
            'contact',
            $this->describe([
                'name' => $contact->name,
                'role_label' => $contact->roleLabel,
                'email' => $contact->email,
                'phone' => $contact->phone,
            ]),
            null,
            AuditSource::Human,
            'Contact supprimé',
            null,
            $actorUserAccountId
        );
    }

    /**
     * What anonymising this contact would touch, so the confirmation
     * screen can say it BEFORE anything happens. Erasure is irreversible
     * and reaches beyond the camp the chief is looking at — showing the
     * count afterwards would be showing it too late.
     *
     * @return array{contacts: Contact[], camp_ids: int[]}
     */
    public function anonymisationScope(Contact $contact): array
    {
        $rows = $this->contacts->findSamePerson($contact);
        $campIds = array_values(array_unique(array_map(
            static fn(Contact $c): int => $c->campId,
            $rows
        )));

        return ['contacts' => $rows, 'camp_ids' => $campIds];
    }

    /**
     * Erases a person from the whole module: every contact row sharing
     * their e-mail, and the values those rows left in the affected camps'
     * change histories.
     *
     * The history rows themselves stay — that a contact was added, when
     * and by whom is not the personal data. Their VALUES were, and
     * Core\Audit::anonymiseValues() is what removes those; without that
     * call the timeline would go on displaying the name the person just
     * asked to have removed, which is the failure this whole feature
     * exists to prevent.
     *
     * @return array{contacts: int, camps: int}
     */
    public function anonymise(Contact $contact, ?int $actorUserAccountId): array
    {
        $scope = $this->anonymisationScope($contact);
        $contactIds = array_map(static fn(Contact $c): int => $c->id, $scope['contacts']);

        $contactsChanged = $this->contacts->anonymise($contactIds);
        $this->audit->anonymiseValues(CampService::ENTITY_TYPE, $scope['camp_ids'], ['contact']);

        foreach ($scope['camp_ids'] as $campId) {
            $this->audit->record(
                CampService::ENTITY_TYPE,
                $campId,
                'contact',
                null,
                null,
                AuditSource::System,
                'Contact anonymisé à la demande de la personne concernée',
                null,
                $actorUserAccountId
            );
        }

        // Counts only, never the values — the journal is the
        // installation's administrative log and forbids personal data
        // (ARCHITECTURE.md §8.6). Recording WHICH address was erased in
        // the log of the erasure would defeat the erasure.
        $this->journal?->log(
            'camps',
            'contact_anonymised',
            'security',
            sprintf(
                'Anonymisation d\'un contact de camp : %d fiche(s) de contact, %d séjour(s) concerné(s).',
                $contactsChanged,
                count($scope['camp_ids'])
            ),
            [],
            $actorUserAccountId
        );

        return ['contacts' => $contactsChanged, 'camps' => count($scope['camp_ids'])];
    }

    /**
     * @param array<string, string|null> $fields
     * @return array{name: ?string, role_label: ?string, email: ?string, phone: ?string, other_details: ?string}
     */
    public function validate(array $fields): array
    {
        $email = $this->clean($fields['email'] ?? null);
        $phone = $this->clean($fields['phone'] ?? null);

        if ($email === null && $phone === null) {
            throw new CampsException(
                'Un contact a besoin d\'au moins une adresse e-mail ou un numéro de téléphone.'
            );
        }
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new CampsException('Cette adresse e-mail n\'est pas une adresse valide.');
        }

        $roleLabel = $this->clean($fields['role_label'] ?? null);
        if ($roleLabel !== null && !in_array($roleLabel, self::ROLES, true)) {
            throw new CampsException('Ce rôle de contact n\'existe pas.');
        }

        return [
            'name' => $this->clean($fields['name'] ?? null),
            'role_label' => $roleLabel,
            'email' => $email,
            'phone' => $phone,
            'other_details' => $this->clean($fields['other_details'] ?? null),
        ];
    }

    /**
     * A contact as one line for the change history.
     *
     * `other_details` is deliberately absent: it is free text where
     * someone writes "le GSM du fils, ne pas appeler après 20h", and a
     * timeline is not the place to repeat that on every edit.
     *
     * @param array<string, string|null> $values
     */
    private function describe(array $values): string
    {
        $parts = array_values(array_filter([
            $values['name'] ?? null,
            $values['role_label'] ?? null,
            $values['email'] ?? null,
            $values['phone'] ?? null,
        ], static fn(?string $v): bool => $v !== null && $v !== ''));

        return $parts !== [] ? implode(', ', $parts) : 'Contact sans détail';
    }

    private function clean(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value !== null && $value !== '' ? $value : null;
    }
}
