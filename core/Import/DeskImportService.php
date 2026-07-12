<?php

declare(strict_types=1);

namespace Core\Import;

use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;

class DeskImportService
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption,
        private DeskCsvParser $parser,
        private MappingResolver $mappingResolver,
        private MemberRepository $memberRepository,
        private MemberYearRepository $memberYearRepository,
        private ImportJournalRepository $importJournalRepository,
        private UserAccountRepository $userAccountRepository
    ) {
    }

    /**
     * Import a Desk CSV file for a given scout year.
     */
    public function import(string $filePath, int $scoutYearId, int $importedBy): ImportResult
    {
        $parsed = $this->parser->parse($filePath);
        $warnings = [];

        $this->pdo->beginTransaction();

        try {
            foreach ($parsed->members as $member) {
                $this->importMember($member, $scoutYearId, $warnings);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // Record in import_journal
        $newFunctions = $this->mappingResolver->getNewFunctionsCount();
        $this->importJournalRepository->create(
            $scoutYearId,
            $importedBy,
            $parsed->lineCount,
            count($parsed->members),
            $newFunctions
        );

        // Delete CSV file
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        return new ImportResult(
            memberCount: count($parsed->members),
            lineCount: $parsed->lineCount,
            newFunctionsCount: $newFunctions,
            warnings: $warnings
        );
    }

    /**
     * @param string[] $warnings
     */
    private function importMember(ParsedMember $member, int $scoutYearId, array &$warnings): void
    {
        // Upsert member
        $memberId = $this->memberRepository->upsertByDeskId($member->deskId);

        // Resolve fee category
        $feeCategoryId = null;
        if ($member->feeCode !== null) {
            $feeCategoryId = $this->mappingResolver->resolveFee($member->feeCode);
        }

        // Encrypt personal data
        $emailBlindIndex = null;
        $emailEncrypted = null;
        if ($member->email !== null) {
            $normalizedEmail = strtolower($member->email);
            $emailBlindIndex = $this->encryption->blindIndex($normalizedEmail);
            $emailEncrypted = $this->encryption->encrypt($normalizedEmail);

            // Auto-create user account
            $this->ensureUserAccount($normalizedEmail, $emailBlindIndex);
        }

        $encryptedData = [
            'first_name_encrypted' => $this->encryption->encrypt($member->firstName),
            'last_name_encrypted' => $this->encryption->encrypt($member->lastName),
            'gender_encrypted' => $member->gender !== null ? $this->encryption->encrypt($member->gender) : null,
            'birth_date_encrypted' => $member->birthDate !== null ? $this->encryption->encrypt($member->birthDate) : null,
            'phone_encrypted' => $member->phone !== null ? $this->encryption->encrypt($member->phone) : null,
            'mobile_encrypted' => $member->mobile !== null ? $this->encryption->encrypt($member->mobile) : null,
            'email_encrypted' => $emailEncrypted,
            'email_blind_index' => $emailBlindIndex,
            'totem_encrypted' => $member->totem !== null ? $this->encryption->encrypt($member->totem) : null,
            'quali_encrypted' => $member->quali !== null ? $this->encryption->encrypt($member->quali) : null,
            'patrol_encrypted' => $member->patrol !== null ? $this->encryption->encrypt($member->patrol) : null,
            'formation_level' => $member->formationLevel,
            'federation_mail_consent' => $member->federationMailConsent,
            'unit_mail_consent' => $member->unitMailConsent,
            'fee_category_id' => $feeCategoryId,
            'unit_code' => $member->unitCode,
        ];

        // Upsert member_year
        $memberYearId = $this->memberYearRepository->upsert($memberId, $scoutYearId, $encryptedData);

        // Replace addresses
        $addresses = [];
        foreach ($member->addresses as $addr) {
            $addresses[] = [
                'address_type' => $addr->type,
                'street_encrypted' => $addr->street !== null ? $this->encryption->encrypt($addr->street) : null,
                'number_encrypted' => $addr->number !== null ? $this->encryption->encrypt($addr->number) : null,
                'box_encrypted' => $addr->box !== null ? $this->encryption->encrypt($addr->box) : null,
                'complement_encrypted' => $addr->complement !== null ? $this->encryption->encrypt($addr->complement) : null,
                'postal_code_encrypted' => $addr->postalCode !== null ? $this->encryption->encrypt($addr->postalCode) : null,
                'city_encrypted' => $addr->city !== null ? $this->encryption->encrypt($addr->city) : null,
                'country_encrypted' => $addr->country !== null ? $this->encryption->encrypt($addr->country) : null,
            ];
        }
        $this->memberYearRepository->replaceAddresses($memberYearId, $addresses);

        // Replace functions
        $functions = [];
        foreach ($member->functions as $fn) {
            $functionId = $this->mappingResolver->resolveFunction($fn->functionCode);

            $branchId = null;
            if ($fn->branchCode !== null) {
                $branchId = $this->mappingResolver->resolveBranch($fn->branchCode);
            }

            $sectionId = null;
            if ($fn->sectionCode !== null && $branchId !== null) {
                $sectionId = $this->mappingResolver->resolveSection($fn->sectionCode, $branchId, $fn->sectionName);
            }

            $functions[] = [
                'function_id' => $functionId,
                'section_id' => $sectionId,
                'age_branch_id' => $branchId,
                'start_date' => $this->parseDate($fn->startDate),
                'end_date' => $this->parseDate($fn->endDate),
                'mandate_end' => $this->parseDate($fn->mandateEnd),
                'is_main_function' => $fn->isMainFunction,
            ];
        }
        $this->memberYearRepository->replaceFunctions($memberYearId, $functions);
    }

    private function ensureUserAccount(string $email, string $blindIndex): void
    {
        $existing = $this->userAccountRepository->findByBlindIndex($blindIndex);
        if ($existing === null) {
            $this->userAccountRepository->create($email, false);
        }
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Try DD/MM/YYYY format
        $parts = explode('/', $value);
        if (count($parts) === 3) {
            return sprintf('%s-%s-%s', $parts[2], $parts[1], $parts[0]);
        }

        // Already in YYYY-MM-DD?
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        return null;
    }
}
