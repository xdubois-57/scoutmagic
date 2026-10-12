<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Modules\Registration\Service\FamilyEmailCopyService;
use Modules\Registration\Service\RegistrationException;
use Twig\Environment;

/**
 * POST /config/inscriptions/copie — the box « Copie des e-mails
 * d'inscription » of « Réglages » (chantier #843, D9 / IT-06).
 *
 * A controller of its own rather than one more action on
 * RegistrationConfigController, which renders the box: the box is one
 * self-contained decision (a switch and the address it governs, one
 * « Enregistrer »), and the rules are Service\FamilyEmailCopyService's.
 */
class RegistrationCopySettingsController extends AbstractController
{
    private const PATH_SETTINGS = '/config/inscriptions/reglages';

    public function __construct(
        protected Environment $twig,
        private FamilyEmailCopyService $familyEmailCopy,
        private JournalService $journalService
    ) {
    }

    /**
     * The switch and the address as one: a switched-on copy with an
     * invalid address, or with no address and no default one for the
     * unit, saves nothing. A disabled field is not submitted — the stored
     * address is then kept as it is.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PATH_SETTINGS)) !== null) {
            return $guard;
        }

        $enabled = (string) $request->getBody('cc_enabled', '0') === '1';
        $address = $request->getBody('cc_address', null);

        try {
            $this->familyEmailCopy->save($enabled, is_string($address) ? $address : null);
        } catch (RegistrationException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::PATH_SETTINGS);
        }

        // Whether an address was typed, never which one (SECURITY.md §11).
        $this->journalService->log(
            'registration',
            'registration_email_copy_saved',
            'info',
            $enabled ? 'Copie des e-mails aux familles activée' : 'Copie des e-mails aux familles désactivée',
            ['enabled' => $enabled, 'custom_address' => $this->familyEmailCopy->configuredAddress() !== ''],
            (int) AuthSession::getUserAccountId()
        );

        FlashMessage::set('success', 'Réglages enregistrés.');

        return $this->redirect(self::PATH_SETTINGS);
    }
}
