<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Twig\Environment;

/**
 * Configuration > Camps (role_min superadmin, declared in module.json).
 */
class CampsConfigController extends AbstractController
{
    private const MODULE = 'camps';

    public function __construct(
        protected Environment $twig,
        private SettingService $settings
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('@camps/config.html.twig', [
            'default_country' => (string) ($this->settings->get('camps_default_country', self::MODULE, 'Belgique') ?? ''),
            'past_stays_per_page' => (int) ($this->settings->get('camps_past_stays_per_page', self::MODULE, '20') ?? 20),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/camps')) !== null) {
            return $guard;
        }

        $this->settings->set('camps_default_country', trim((string) $request->getBody('camps_default_country', '')), self::MODULE);

        // Clamped rather than rejected: this is a display convenience, and
        // an out-of-range value here should not stop an administrator
        // saving the rest of the page.
        $perPage = (int) $request->getBody('camps_past_stays_per_page', 20);
        $perPage = max(5, min(100, $perPage));
        $this->settings->set('camps_past_stays_per_page', (string) $perPage, self::MODULE);

        FlashMessage::set('success', 'Réglages enregistrés.');

        return $this->redirect('/config/camps');
    }
}
