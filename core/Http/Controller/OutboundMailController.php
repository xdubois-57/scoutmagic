<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\SettingService;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\SendCounterRepository;
use Core\Mail\Transport\TransportException;
use Core\Mail\Transport\TransportService;
use Core\Security\AuthSession;
use Twig\Environment;

/**
 * Configuration > Courrier sortant (ARCHITECTURE.md §8.106).
 *
 * The pendant of `/config/courrier-entrant`, which `inbound_mail`
 * exposes — with one asymmetry that is assumed rather than accidental
 * (D1): receiving mail is optional and lives in a module, sending is not
 * and lives in the core. The chain that carries the sign-in links cannot
 * depend on a module somebody may disable, or signing in to the site
 * becomes a thing an administrator can switch off by accident.
 *
 * Two sub-pages in this iteration — Fournisseurs and Acheminement — on
 * the shared `partials/page_picker.html.twig` rail. They arrive together
 * with the transport itself rather than later, for a simple reason: a
 * chain nobody can configure is a chain nobody can test.
 */
class OutboundMailController extends AbstractController
{
    public const PAGE_URL = '/config/courrier-sortant';
    public const ROUTING_URL = '/config/courrier-sortant/acheminement';

    public function __construct(
        protected Environment $twig,
        private MailProviderDirectory $directory,
        private LaneChainRepository $chains,
        private SendCounterRepository $counters,
        private TransportService $transport,
        private SettingService $settings
    ) {
    }

    /**
     * GET /config/courrier-sortant — the providers.
     *
     * @param array<string, string> $params
     */
    public function providers(Request $request, array $params): Response
    {
        $usedToday = $this->counters->totalsForDay();
        $chains = $this->chains->all();

        $cards = [];
        foreach ($this->directory->all() as $provider) {
            $cards[] = [
                'id' => $provider->id,
                'name' => $provider->name,
                'host' => $provider->hostSummary(),
                'is_local' => $provider->isLocal(),
                'daily_quota' => $provider->dailyQuota,
                'used_today' => $usedToday[$provider->id] ?? 0,
                'batch_size' => $provider->batchSize,
                'batch_interval_minutes' => $provider->batchIntervalMinutes,
                'lanes' => $this->enabledLaneLabels($chains, $provider->id),
                'configured' => $provider->isUsable(),
            ];
        }

        return $this->render('config/outbound_mail/providers.html.twig', [
            'providers' => $cards,
            'page_url' => self::PAGE_URL,
            'routing_url' => self::ROUTING_URL,
        ]);
    }

    /**
     * GET /config/courrier-sortant/fournisseurs/nouveau
     *
     * @param array<string, string> $params
     */
    public function createForm(Request $request, array $params): Response
    {
        return $this->render('config/outbound_mail/provider_form.html.twig', [
            'provider' => null,
            'page_url' => self::PAGE_URL,
        ]);
    }

    /**
     * GET /config/courrier-sortant/fournisseurs/{id}
     *
     * @param array<string, string> $params
     */
    public function editForm(Request $request, array $params): Response
    {
        $provider = $this->directory->find((int) ($params['id'] ?? -1));
        if ($provider === null) {
            return $this->notFound();
        }

        return $this->render('config/outbound_mail/provider_form.html.twig', [
            'provider' => [
                'id' => $provider->id,
                'name' => $provider->name,
                'host' => $provider->host,
                'port' => $provider->port,
                'username' => $provider->username,
                'daily_quota' => $provider->dailyQuota,
                'batch_size' => $provider->batchSize,
                'batch_interval_minutes' => $provider->batchIntervalMinutes,
                'is_local' => $provider->isLocal(),
            ],
            'page_url' => self::PAGE_URL,
        ]);
    }

    /**
     * POST /config/courrier-sortant/fournisseurs
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PAGE_URL . '/fournisseurs/nouveau')) !== null) {
            return $guard;
        }

        try {
            $this->transport->addProvider(
                (string) $request->getBody('name', ''),
                (string) $request->getBody('host', ''),
                (int) $request->getBody('port', 587),
                (string) $request->getBody('username', ''),
                (string) $request->getBody('password', ''),
                $this->optionalInt($request->getBody('daily_quota', '')),
                (int) $request->getBody('batch_size', 50),
                (int) $request->getBody('batch_interval_minutes', 10),
                AuthSession::getUserAccountId()
            );
        } catch (TransportException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::PAGE_URL . '/fournisseurs/nouveau');
        }

        FlashMessage::set(
            'success',
            'Fournisseur ajouté. Il est désactivé dans les trois voies : activez-le depuis « Acheminement » '
                . 'pour qu’il serve à quelque chose.'
        );

        return $this->redirect(self::ROUTING_URL);
    }

    /**
     * POST /config/courrier-sortant/fournisseurs/{id}
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? -1);
        $back = self::PAGE_URL . '/fournisseurs/' . $id;

        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }

        if ($id === MailProvider::LOCAL_ID) {
            return $this->saveLocalCadence($request);
        }

        try {
            $this->transport->updateProvider(
                $id,
                (string) $request->getBody('name', ''),
                (string) $request->getBody('host', ''),
                (int) $request->getBody('port', 587),
                (string) $request->getBody('username', ''),
                // An empty password field means « keep the stored one »,
                // never « clear it »: the form cannot show an operator
                // what they would be retyping.
                ($password = (string) $request->getBody('password', '')) === '' ? null : $password,
                $this->optionalInt($request->getBody('daily_quota', '')),
                (int) $request->getBody('batch_size', 50),
                (int) $request->getBody('batch_interval_minutes', 10),
                AuthSession::getUserAccountId()
            );
        } catch (TransportException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect($back);
        }

        FlashMessage::set('success', 'Fournisseur enregistré.');

        return $this->redirect(self::PAGE_URL);
    }

    /**
     * POST /config/courrier-sortant/fournisseurs/{id}/suppression
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PAGE_URL)) !== null) {
            return $guard;
        }

        try {
            $this->transport->deleteProvider((int) ($params['id'] ?? -1), AuthSession::getUserAccountId());
        } catch (TransportException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::PAGE_URL);
        }

        FlashMessage::set('success', 'Fournisseur supprimé.');

        return $this->redirect(self::PAGE_URL);
    }

    /**
     * GET /config/courrier-sortant/acheminement — the three chains.
     *
     * @param array<string, string> $params
     */
    public function routing(Request $request, array $params): Response
    {
        $providers = $this->directory->all();
        $chains = $this->chains->all();

        $lanes = [];
        foreach (MailLane::ordered() as $lane) {
            $items = [];
            $rank = 0;
            foreach ($chains[$lane->value] ?? [] as $entry) {
                $provider = $providers[$entry->providerId] ?? null;
                if ($provider === null) {
                    continue;
                }

                if ($entry->enabled) {
                    $rank++;
                }

                $items[] = [
                    'id' => $provider->id,
                    'is_active' => $entry->enabled,
                    'name' => $provider->name,
                    'host' => $provider->hostSummary(),
                    'rank' => $entry->enabled ? $rank : null,
                    'is_local' => $provider->isLocal(),
                    'configured' => $provider->isUsable(),
                    // The local send in the mailing lane is legal and
                    // discouraged, which is a different sentence from
                    // forbidden: a unit with no relay at all has nothing
                    // else, and the screen says why it is a bad idea
                    // rather than refusing.
                    'discouraged' => $provider->isLocal() && $lane === MailLane::Bulk && $entry->enabled,
                ];
            }

            $lanes[] = [
                'key' => $lane->value,
                'label' => $lane->label(),
                'detail' => $lane->detail(),
                'items' => $items,
                'honours_cadence' => $lane->honoursCadence(),
            ];
        }

        return $this->render('config/outbound_mail/routing.html.twig', [
            'lanes' => $lanes,
            'page_url' => self::PAGE_URL,
            'routing_url' => self::ROUTING_URL,
        ]);
    }

    /**
     * POST /config/courrier-sortant/acheminement/{lane}/ordre
     *
     * @param array<string, string> $params
     */
    public function reorder(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrfJson($request)) !== null) {
            return $guard;
        }

        $lane = MailLane::tryFrom((string) ($params['lane'] ?? ''));
        if ($lane === null) {
            return $this->json(['success' => false, 'error' => 'Voie inconnue.'], 404);
        }

        $ids = $request->getBody('ids', []);
        if (!is_array($ids)) {
            return $this->json(['success' => false, 'error' => 'Ordre invalide.'], 400);
        }

        $this->transport->reorderLane(
            $lane,
            array_map(static fn($id): int => (int) $id, array_values($ids)),
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/courrier-sortant/acheminement/{lane}/activation
     *
     * @param array<string, string> $params
     */
    public function toggle(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrfJson($request)) !== null) {
            return $guard;
        }

        $lane = MailLane::tryFrom((string) ($params['lane'] ?? ''));
        if ($lane === null) {
            return $this->json(['success' => false, 'error' => 'Voie inconnue.'], 404);
        }

        try {
            $this->transport->setEntryEnabled(
                $lane,
                (int) $request->getBody('id', -1),
                (bool) $request->getBody('active', false),
                AuthSession::getUserAccountId()
            );
        } catch (TransportException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 409);
        }

        return $this->json(['success' => true]);
    }

    /**
     * The local send has no row, so « saving » it writes the two settings
     * that carry its cadence — and nothing else: it has no host, no
     * credentials and, deliberately, no quota (D6).
     */
    private function saveLocalCadence(Request $request): Response
    {
        $batchSize = max(1, (int) $request->getBody('batch_size', MailProviderDirectory::DEFAULT_LOCAL_BATCH_SIZE));
        $interval = max(
            1,
            (int) $request->getBody('batch_interval_minutes', MailProviderDirectory::DEFAULT_LOCAL_BATCH_INTERVAL)
        );

        try {
            $this->settings->set(MailProviderDirectory::SETTING_LOCAL_BATCH_SIZE, (string) $batchSize);
            $this->settings->set(MailProviderDirectory::SETTING_LOCAL_BATCH_INTERVAL, (string) $interval);
        } catch (\Core\Config\SettingException $e) {
            // SettingException is user-facing by construction, so its own
            // sentence is the one to show — a setting that vanished under
            // the form is something the person can act on by reloading.
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::PAGE_URL . '/fournisseurs/' . MailProvider::LOCAL_ID);
        }

        $this->directory->refresh();

        FlashMessage::set('success', 'Cadence de l’envoi local enregistrée.');

        return $this->redirect(self::PAGE_URL);
    }

    /**
     * Which lanes currently use this provider, by their French names.
     *
     * @param array<string, array<int, \Core\Mail\Transport\LaneEntry>> $chains
     * @return array<int, string>
     */
    private function enabledLaneLabels(array $chains, int $providerId): array
    {
        $labels = [];
        foreach (MailLane::ordered() as $lane) {
            foreach ($chains[$lane->value] ?? [] as $entry) {
                if ($entry->providerId === $providerId && $entry->enabled) {
                    $labels[] = $lane->label();
                }
            }
        }

        return $labels;
    }

    /**
     * An empty quota field is « no known daily ceiling », which is null —
     * never zero, which a form would otherwise store as « this provider
     * accepts nothing ».
     */
    private function optionalInt(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' ? null : (int) $value;
    }
}
