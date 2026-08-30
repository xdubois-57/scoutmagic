<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Config\ScoutYearService;
use Core\Config\SettingException;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\AgeBracket;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestStatusService;
use Modules\Registration\Service\SlotMath;
use Modules\Registration\Service\SlotService;

/**
 * Opens the registration desk and files RegistrationBlueprint's requests.
 *
 * Three collaborations are worth spelling out.
 *
 * **The capacities go through Service\SlotService::seedMissingCapacities()
 * first.** It writes a real `DEFAULT_CAPACITY` into every slot with no row,
 * which is what makes the numbers the page shows the numbers it is actually
 * computing with. Only then are the declared overrides applied through
 * Repository\SlotCapacityRepository::upsert(), which is the one call that can
 * express `null` — and `null` is "pas de limite", never zero. Re-seeding
 * would not overrule them: the seed is deliberately not a repair.
 *
 * **A birth date is computed, not declared.** Which slot a child falls into
 * is decided by Service\SlotMath from the branch's age bracket and the
 * target year's reference date; a date written by hand in the table would
 * land in the wrong slot the day the federation moves a bracket, silently,
 * and the capacity numbers would stop matching the story the table tells.
 *
 * **The request is written through Repository\RegistrationRequestRepository
 * and the status moved through Service\RequestStatusService.** The service
 * layer above the repository — `RegistrationService::submit()` — is the
 * public form: it exists to send the family their tracking link and the unit
 * its alert, and a dataset build has no business emailing fictional families
 * from somebody's test instance. Everything the repository does is the
 * module's own: the encryption of every personal field, the name/date blind
 * index, the normalised address index, the tracking token hash, and the
 * sibling links, all in one transaction. The status transitions then go
 * through the real service, which is what refuses an impossible one and
 * writes the journal line.
 */
final class RegistrationSeeder
{
    private readonly RegistrationRequestRepository $requestRepository;

    private readonly RequestStatusService $statusService;

    private readonly SlotService $slotService;

    private readonly AgeBracketRepository $bracketRepository;

    private readonly SlotCapacityRepository $capacityRepository;

    private readonly SettingService $settingService;

    private readonly ScoutYearService $scoutYearService;

    /** @param array<string, int> $sectionIds section handle => sections.id */
    public function __construct(
        \PDO $pdo,
        EncryptionService $encryption,
        private readonly array $sectionIds,
    ) {
        $this->requestRepository = new RegistrationRequestRepository($pdo, $encryption);
        $this->statusService = new RequestStatusService(
            $this->requestRepository,
            new JournalService(new JournalRepository($pdo)),
        );
        $this->bracketRepository = new AgeBracketRepository($pdo);
        $this->capacityRepository = new SlotCapacityRepository($pdo);
        $this->settingService = new SettingService(new SettingRepository($pdo));
        $this->scoutYearService = new ScoutYearService($pdo);
        $this->slotService = new SlotService(
            $pdo,
            $encryption,
            $this->settingService,
            $this->bracketRepository,
            $this->capacityRepository,
            $this->requestRepository,
        );
    }

    /**
     * @return array{seededCapacities: int, overrides: int, requests: int, accepted: int, formOpen: bool}
     */
    public function seed(): array
    {
        $seeded = $this->slotService->seedMissingCapacities();
        $overrides = $this->applyCapacityOverrides();

        $targetYearId = $this->scoutYearService->ensureYear(RegistrationBlueprint::TARGET_YEAR);
        $brackets = $this->bracketRepository->findAllOrdered();
        $referenceYear = SlotMath::referenceCalendarYear(
            UnitBlueprint::referenceYear(RegistrationBlueprint::TARGET_YEAR),
            $this->slotService->referenceMonthDay(),
        );

        $requests = 0;
        $accepted = 0;

        foreach (RegistrationBlueprint::REQUESTS as $declared) {
            $bracket = $this->bracketFor($brackets, $declared['branch']);
            if ($bracket === null) {
                // The branch is not one this unit imported. Skipping is right:
                // a request in a bracket that does not exist would be counted
                // against nothing and shown nowhere.
                continue;
            }

            $birthYear = SlotMath::birthYearForSlot($bracket, $declared['yearInBranch'], $referenceYear);
            $created = $this->requestRepository->create(
                $targetYearId,
                [
                    'parent_name' => $declared['parent'],
                    'child_last_name' => $declared['lastName'],
                    'child_first_name' => $declared['firstName'],
                    'gender' => $declared['gender'],
                    'birth_date' => sprintf('%04d-%s', $birthYear, $declared['birthMonthDay']),
                    'street' => $declared['street'],
                    'number' => $declared['number'],
                    'postal_code' => $declared['postalCode'],
                    'city' => $declared['city'],
                    'email' => $declared['email'],
                    'phone1' => $declared['phone1'],
                    'phone2' => $declared['phone2'],
                    'remarks' => $declared['remarks'],
                ],
                $declared['section'] !== null ? ($this->sectionIds[$declared['section']] ?? null) : null,
                [],
            );
            $requests++;

            if ($declared['status'] === RegistrationRequest::STATUS_PENDING) {
                continue;
            }

            $request = $this->requestRepository->findById($created['id']);
            if ($request === null) {
                continue;
            }

            try {
                match ($declared['status']) {
                    RegistrationRequest::STATUS_ACCEPTED => $this->statusService->accept($request),
                    RegistrationRequest::STATUS_REFUSED => $this->statusService->refuse($request),
                    RegistrationRequest::STATUS_WITHDRAWN => $this->statusService->withdraw($request),
                };
            } catch (RegistrationException) {
                // An impossible transition is a mistake in the table, not a
                // reason to abandon a build. The counter below is what says
                // it happened: an accepted count short of the declared one.
                continue;
            }

            $accepted += $declared['status'] === RegistrationRequest::STATUS_ACCEPTED ? 1 : 0;
        }

        // Last, and on purpose: the desk opens once it has something behind
        // it. A form opened first would be open on an empty queue for as long
        // as the rest of this method takes.
        try {
            $this->settingService->set('registration_form_open', RegistrationBlueprint::FORM_OPEN, 'registration');
        } catch (SettingException) {
            // The setting is created when the module is activated, and the
            // builder activates every module before it gets here (README
            // §8.1). If it is missing anyway, the desk simply stays shut —
            // which the returned `formOpen` then reports, rather than the
            // whole build dying over one row.
        }

        return [
            'seededCapacities' => $seeded,
            'overrides' => $overrides,
            'requests' => $requests,
            'accepted' => $accepted,
            // Read back rather than echoed: the builder's report should say
            // what the setting HOLDS, not what it was asked to hold.
            'formOpen' => (bool) $this->settingService->get('registration_form_open', 'registration', '0'),
        ];
    }

    /** @return int the number of overrides actually written */
    private function applyCapacityOverrides(): int
    {
        $brackets = $this->bracketRepository->findAllOrdered();
        $written = 0;

        foreach (RegistrationBlueprint::CAPACITY_OVERRIDES as $override) {
            $bracket = $this->bracketFor($brackets, $override['branch']);
            if ($bracket === null) {
                continue;
            }
            $this->capacityRepository->upsert($bracket->ageBranchId, $override['yearInBranch'], $override['capacity']);
            $written++;
        }

        return $written;
    }

    /**
     * @param array<AgeBracket> $brackets
     */
    private function bracketFor(array $brackets, string $branchLabel): ?AgeBracket
    {
        foreach ($brackets as $bracket) {
            if ($bracket->branchLabel === $branchLabel) {
                return $bracket;
            }
        }

        return null;
    }
}
