<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\ScoutYear\ScoutYearResolver;
use Core\View\EditableContentService;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationYearCodeRepository;

/**
 * Orchestrates a form submission end to end: resolves the target scout
 * year (next year, or the current one when a valid in-year code was
 * supplied), persists the request (encryption confined to Repository\
 * RegistrationRequestRepository), and sends both notification emails.
 *
 * Never refuses a submission over a blind-index match (module spec: the
 * blind index only ever feeds a later iteration's staff-facing duplicate
 * signal), and never logs anything but the request's own id (SECURITY.md
 * §11 / the module's own "no personal data in the journal" rule).
 */
class RegistrationService
{
    public function __construct(
        private RegistrationRequestRepository $requestRepository,
        private RegistrationYearCodeRepository $yearCodeRepository,
        private ScoutYearResolver $scoutYearResolver,
        private ScoutYearService $scoutYearService,
        private SettingService $settingService,
        private MailService $mailService,
        private EditableContentService $editableContentService,
        private JournalService $journalService,
        private string $baseUrl,
        private string $siteName
    ) {
    }

    /**
     * @return array{id: int, label: string, start_date: string, end_date: string, used_code: bool}
     */
    public function resolveTargetYear(?string $submittedCode): array
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();

        if ($this->hasValidYearCode($submittedCode)) {
            return $publicYear + ['used_code' => true];
        }

        $nextLabel = ScoutYearService::nextLabel($publicYear['label']);
        $nextYearId = $this->scoutYearService->ensureYear($nextLabel);
        $nextYear = $this->scoutYearService->findById($nextYearId);

        return ($nextYear ?? $publicYear) + ['used_code' => false];
    }

    public function isFormOpen(): bool
    {
        $manuallyOpen = (bool) $this->settingService->get('registration_form_open', 'registration', '0');

        return $manuallyOpen;
    }

    /**
     * Whether a submission may proceed at all: the manually/scheduled-open
     * flag, OR — even while closed — a valid in-year code for the current
     * public year (module spec: an in-year code is its own, independent
     * open/close mechanism, "ce n'est pas une barrière de sécurité",
     * §8.35 — a family a chief has given a code to must still be able to
     * register while the general form is shut for the next scout year).
     */
    public function canSubmit(?string $submittedCode): bool
    {
        return $this->isFormOpen() || $this->hasValidYearCode($submittedCode);
    }

    private function hasValidYearCode(?string $submittedCode): bool
    {
        if ($submittedCode === null || $submittedCode === '') {
            return false;
        }

        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();

        return $this->yearCodeRepository->isValidActiveCode((int) $publicYear['id'], $submittedCode);
    }

    /**
     * @param array{
     *   parent_name: string, child_last_name: string, child_first_name: string,
     *   gender: string, birth_date: string, street: string, number: string,
     *   postal_code: string, city: string, email: string, phone1: string,
     *   phone2: ?string, remarks: ?string
     * } $fields
     * @param array<int> $siblingMemberIds
     */
    public function submit(
        int $targetScoutYearId,
        string $targetScoutYearLabel,
        array $fields,
        ?int $desiredSectionId,
        array $siblingMemberIds,
        string $slotLabel
    ): int {
        $created = $this->requestRepository->create($targetScoutYearId, $fields, $desiredSectionId, $siblingMemberIds);
        $requestId = $created['id'];
        $trackingToken = $created['tracking_token'];

        $this->journalService->log(
            'registration',
            'registration_request_received',
            'info',
            'Nouvelle demande d\'inscription reçue',
            ['request_id' => $requestId]
        );

        $trackingUrl = rtrim($this->baseUrl, '/') . "/inscriptions/suivi/{$requestId}/{$trackingToken}";

        $this->sendReceiptEmail($fields['email'], $fields['child_first_name'], $targetScoutYearLabel, $trackingUrl);
        $this->sendUnitAlertEmail($fields['child_first_name'], $targetScoutYearLabel, $slotLabel, $trackingUrl);

        return $requestId;
    }

    private function sendReceiptEmail(string $to, string $childFirstName, string $targetYearLabel, string $trackingUrl): void
    {
        $default = '<p>Bonjour,</p>'
            . '<p>Nous avons bien reçu votre demande d\'inscription pour {{prenom_enfant}} pour l\'année scoute {{annee_scoute}}.</p>'
            . '<p><strong>Ceci n\'est pas encore une acceptation.</strong> Votre demande sera examinée par l\'unité.</p>'
            . '<p>Vous pouvez suivre l\'état de votre demande à tout moment via ce lien : <a href="{{lien_suivi}}">{{lien_suivi}}</a></p>'
            . '<p>À bientôt,<br>{{nom_unite}}</p>';
        $body = $this->substitute($this->editableContentService->get('registration_email_receipt_body', $default), [
            'prenom_enfant' => $childFirstName,
            'annee_scoute' => $targetYearLabel,
            'lien_suivi' => $trackingUrl,
            'nom_unite' => $this->siteName,
        ]);

        try {
            $this->mailService->send(
                to: $to,
                subject: "Demande d'inscription reçue",
                bodyHtml: $body,
                bodyText: strip_tags($body)
            );
        } catch (MailException $e) {
            $this->journalService->log(
                'registration',
                'registration_receipt_email_failed',
                'info',
                'Échec de l\'envoi de l\'accusé de réception',
                ['error' => $e->getMessage()]
            );
        }
    }

    private function sendUnitAlertEmail(string $childFirstName, string $targetYearLabel, string $slotLabel, string $trackingUrl): void
    {
        $alertEmail = (string) $this->settingService->get('registration_unit_alert_email', 'registration', '');
        if ($alertEmail === '') {
            return;
        }

        $default = '<p>Nouvelle demande d\'inscription reçue pour {{prenom_enfant}} — créneau : {{creneau}}, année {{annee_scoute}}, reçue le {{date_reception}}.</p>'
            . '<p><a href="{{lien_fiche}}">Voir la demande</a></p>';
        $body = $this->substitute($this->editableContentService->get('registration_email_unit_alert_body', $default), [
            'prenom_enfant' => $childFirstName,
            'annee_scoute' => $targetYearLabel,
            'creneau' => $slotLabel,
            'date_reception' => (new \DateTimeImmutable())->format('d/m/Y'),
            'lien_fiche' => $trackingUrl,
        ]);

        try {
            $this->mailService->send(
                to: $alertEmail,
                subject: "Nouvelle demande d'inscription — {$childFirstName}",
                bodyHtml: $body,
                bodyText: strip_tags($body)
            );
        } catch (MailException $e) {
            $this->journalService->log(
                'registration',
                'registration_unit_alert_email_failed',
                'info',
                'Échec de l\'envoi de l\'alerte à l\'unité',
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * @param array<string, string> $vars
     */
    private function substitute(?string $template, array $vars): string
    {
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{{' . $key . '}}'] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        return strtr($template ?? '', $replacements);
    }
}
