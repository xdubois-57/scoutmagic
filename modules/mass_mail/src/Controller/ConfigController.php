<?php

declare(strict_types=1);

namespace Modules\MassMail\Controller;

use Core\Config\SettingException;
use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\MassMail\Repository\MailingList;
use Modules\MassMail\Service\MailingListException;
use Modules\MassMail\Service\MailingListService;
use Twig\Environment;

class ConfigController extends AbstractController
{
    private const SETTING_BATCH_SIZE = 'batch_size';
    private const SETTING_BATCH_INTERVAL_MINUTES = 'batch_interval_minutes';

    public function __construct(
        protected Environment $twig,
        private MailingListService $mailingListService,
        private SettingService $settingService
    ) {
    }

    /**
     * GET /config/mass-mail
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $customLists = $this->mailingListService->getAllCustomLists();

        return $this->render('@mass_mail/config.html.twig', [
            'default_lists' => $this->mailingListService->getDefaultLists(),
            'custom_lists' => $customLists,
            'custom_list_criteria' => array_reduce(
                $customLists,
                fn(array $carry, MailingList $l) => $carry + [$l->id => [
                    'function_ids' => $this->mailingListService->getCustomListFunctionIds($l->id),
                    'section_ids' => $this->mailingListService->getCustomListSectionIds($l->id),
                ]],
                []
            ),
            'all_functions' => $this->mailingListService->getAllFunctions(),
            'all_sections' => $this->mailingListService->getAllSections(),
            'batch_size' => (string) $this->settingService->get(self::SETTING_BATCH_SIZE, 'mass_mail', '20'),
            'batch_interval_minutes' => (string) $this->settingService->get(self::SETTING_BATCH_INTERVAL_MINUTES, 'mass_mail', '5'),
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * POST /config/mass-mail/lists — create a custom mailing list.
     *
     * @param array<string, string> $params
     */
    public function createList(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $list = $this->mailingListService->createCustomList(
                (string) ($data['name'] ?? ''),
                (string) ($data['description'] ?? ''),
                $this->toIntArray($data['function_ids'] ?? []),
                $this->toIntArray($data['section_ids'] ?? []),
                AuthSession::getUserAccountId()
            );
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'list' => ['id' => $list->id, 'name' => $list->name, 'is_active' => $list->isActive]]);
    }

    /**
     * PATCH /config/mass-mail/lists/{id}
     *
     * @param array<string, string> $params
     */
    public function updateList(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $list = $this->mailingListService->updateCustomList(
                (int) $params['id'],
                (string) ($data['name'] ?? ''),
                (string) ($data['description'] ?? ''),
                $this->toIntArray($data['function_ids'] ?? []),
                $this->toIntArray($data['section_ids'] ?? [])
            );
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'list' => ['id' => $list->id, 'name' => $list->name, 'is_active' => $list->isActive]]);
    }

    /**
     * POST /config/mass-mail/lists/{id}/toggle — activate/deactivate.
     *
     * @param array<string, string> $params
     */
    public function toggleList(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $this->mailingListService->setActive((int) $params['id'], (bool) ($data['active'] ?? false));
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true]);
    }

    /**
     * DELETE /config/mass-mail/lists/{id} — blocked (module spec, same
     * precedent as badges) when the list is used by at least one email.
     *
     * @param array<string, string> $params
     */
    public function deleteList(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $this->mailingListService->deleteCustomList((int) $params['id']);
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/mass-mail/settings — the "Envoyer X emails toutes les Y
     * minutes" sending-speed fields. Backed by the two module.json-declared
     * settings (also editable from the generic /config/settings page) —
     * this is just a friendlier, composed-sentence UI for the same values,
     * both GLOBAL to the whole site (module spec).
     *
     * @param array<string, string> $params
     */
    public function saveSettings(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $batchSize = (int) ($data['batch_size'] ?? 0);
        $intervalMinutes = (int) ($data['batch_interval_minutes'] ?? 0);
        if ($batchSize < 1 || $intervalMinutes < 1) {
            return $this->json(['success' => false, 'error' => 'Valeurs invalides.'], 422);
        }

        try {
            $this->settingService->set(self::SETTING_BATCH_SIZE, (string) $batchSize, 'mass_mail');
            $this->settingService->set(self::SETTING_BATCH_INTERVAL_MINUTES, (string) $intervalMinutes, 'mass_mail');
        } catch (SettingException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true]);
    }

    /**
     * @param mixed $value
     * @return int[]
     */
    private function toIntArray(mixed $value): array
    {
        return is_array($value) ? array_map('intval', $value) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonBody(Request $request): ?array
    {
        $data = json_decode($request->getRawBody(), true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function checkCsrf(array $data): bool
    {
        return CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''));
    }
}
