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
use Core\Mail\Transport\DeferredMailQueue;
use Core\Mail\Transport\DeferredMailRepository;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailReserve;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\ProviderHealthRepository;
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
    public const RELAUNCH_URL = '/config/courrier-sortant/relance';

    public function __construct(
        protected Environment $twig,
        private MailProviderDirectory $directory,
        private LaneChainRepository $chains,
        private SendCounterRepository $counters,
        private TransportService $transport,
        private SettingService $settings,
        private MailReserve $reserve,
        private ProviderHealthRepository $health,
        private DeferredMailRepository $deferred,
        private DeferredMailQueue $queue
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
                // In small on the card, because the reserve is subtracted
                // from THIS provider's quota and the person reading the
                // « 412 / 1000 aujourd'hui » two lines above needs to know
                // that the mailing lane stopped at 930 on purpose (D14).
                'reserve' => $this->reserveOf($provider),
                'circuit' => $this->circuitOf($provider),
            ];
        }

        return $this->render('config/outbound_mail/providers.html.twig', [
            'providers' => $cards,
            'queue' => $this->queueSummary(),
            'page_url' => self::PAGE_URL,
            'routing_url' => self::ROUTING_URL,
            'relaunch_url' => self::RELAUNCH_URL,
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
     * POST /config/courrier-sortant/relance — put abandoned messages back
     * in the queue (D17).
     *
     * The window and the lanes both come from the form because they are
     * both decisions: « the ones from this morning, and only the
     * receipts » is a sentence a volunteer can mean, and one button that
     * relaunched everything would be used by nobody twice.
     *
     * @param array<string, string> $params
     */
    public function relaunch(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PAGE_URL)) !== null) {
            return $guard;
        }

        $lanes = [];
        foreach ((array) $request->getBody('lanes', []) as $value) {
            $lane = is_scalar($value) ? MailLane::tryFrom((string) $value) : null;
            if ($lane !== null) {
                $lanes[] = $lane;
            }
        }

        if ($lanes === []) {
            FlashMessage::set('error', 'Choisissez au moins une voie à relancer.');

            return $this->redirect(self::PAGE_URL);
        }

        try {
            $revived = $this->queue->relaunch($lanes, (string) $request->getBody('window', DeferredMailQueue::DEFAULT_WINDOW));
        } catch (\Throwable) {
            FlashMessage::set('error', 'La relance n’a pas pu être effectuée.');

            return $this->redirect(self::PAGE_URL);
        }

        FlashMessage::set(
            $revived > 0 ? 'success' : 'info',
            $revived > 0
                ? sprintf(
                    '%d message%s remis en file. Ils repartiront à la prochaine passe, dans quelques minutes.',
                    $revived,
                    $revived > 1 ? 's ont été' : ' a été'
                )
                : 'Aucun message abandonné dans cette fenêtre.'
        );

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
                // Under the authentication lane and nowhere else: the
                // reserve is subtracted from the MAILING lane's ceiling,
                // but it exists FOR this one, and it is here that a
                // volunteer asking « will people still be able to log in
                // during the newsletter » is looking (D14).
                'reserves' => $lane === MailLane::Authentication ? $this->reservesForLane($items) : [],
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
        $payload = $this->jsonPayload($request);
        if ($payload === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        if (($guard = $this->guardCsrfJson($request, (string) ($payload['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $lane = MailLane::tryFrom((string) ($params['lane'] ?? ''));
        if ($lane === null) {
            return $this->json(['success' => false, 'error' => 'Voie inconnue.'], 404);
        }

        $ids = $payload['ids'] ?? null;
        if (!is_array($ids)) {
            return $this->json(['success' => false, 'error' => 'Ordre invalide.'], 400);
        }

        $this->transport->reorderLane(
            $lane,
            array_map(
                static fn(mixed $id): int => is_scalar($id) ? (int) $id : -1,
                array_values($ids)
            ),
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
        $payload = $this->jsonPayload($request);
        if ($payload === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        if (($guard = $this->guardCsrfJson($request, (string) ($payload['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $lane = MailLane::tryFrom((string) ($params['lane'] ?? ''));
        if ($lane === null) {
            return $this->json(['success' => false, 'error' => 'Voie inconnue.'], 404);
        }

        try {
            $this->transport->setEntryEnabled(
                $lane,
                (int) ($payload['id'] ?? -1),
                (bool) ($payload['active'] ?? false),
                AuthSession::getUserAccountId()
            );
        } catch (TransportException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 409);
        }

        return $this->json(['success' => true]);
    }

    /**
     * The body of a request this screen's JavaScript sent, decoded.
     *
     * **`Request::getBody()` cannot be used here, and the reason is not
     * obvious from the call site.** `public/assets/js/list-editor.js`
     * posts through `ScoutMagicApi.postJson()`, which sends
     * `Content-Type: application/json` — and PHP never populates `$_POST`
     * for a JSON body, so `Request::fromGlobals()` builds its `body` from
     * an empty array. Read that way, every field silently comes back as
     * its default: a reorder would apply an empty order and answer
     * `success`, and a toggle would look up entry `-1` and refuse every
     * provider anybody tried to enable.
     *
     * So the raw body is decoded explicitly, the way every other
     * list-editor endpoint in this codebase already does
     * (`ConfigModulesController`, `SectionDocumentController`). Null means
     * « this is not a JSON object », which is a 400 rather than a set of
     * defaults quietly standing in for what the caller meant.
     *
     * @return array<string, mixed>|null
     */
    private function jsonPayload(Request $request): ?array
    {
        $decoded = json_decode($request->getRawBody(), true);

        return is_array($decoded) ? $decoded : null;
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
     * The reserve each entry of a lane carries, with its provenance.
     *
     * Printed as sentences rather than a number, because the number is
     * the part nobody can check: « 74 » is arbitrary until it says « votre
     * pointe hors publipostage des 30 derniers jours (54), plus une marge
     * de 20 », and then it is arithmetic somebody can disagree with.
     *
     * @param array<int, array{id: int, name: string}> $items
     * @return array<int, array{name: string, messages: int, provenance: string}>
     */
    private function reservesForLane(array $items): array
    {
        $providers = $this->directory->all();

        $reserves = [];
        foreach ($items as $item) {
            $provider = $providers[$item['id']] ?? null;
            if ($provider === null) {
                continue;
            }

            $reserve = $this->reserveOf($provider);
            if ($reserve !== null) {
                $reserves[] = [
                    'name' => $provider->name,
                    'messages' => $reserve['messages'],
                    'provenance' => $reserve['provenance'],
                ];
            }
        }

        return $reserves;
    }

    /**
     * What this provider is holding back for the sign-in links, in one
     * sentence (D14).
     *
     * Null when the reserve does not apply — a provider that is not
     * shared between the mailing lane and another one has nothing to
     * protect anybody from, and a card saying « réserve : aucune » would
     * be three lines explaining a number that is not there.
     *
     * @return array{messages: int, provenance: string}|null
     */
    private function reserveOf(MailProvider $provider): ?array
    {
        try {
            $reserve = $this->reserve->forProvider($provider);
        } catch (\Throwable) {
            return null;
        }

        return $reserve->applies()
            ? ['messages' => $reserve->messages, 'provenance' => $reserve->provenance()]
            : null;
    }

    /**
     * Whether the breaker is currently holding this provider out (D15).
     *
     * Shown because the alternative is a screen that says a provider is
     * active and configured while nothing goes through it — and the
     * person looking at that screen concludes their configuration is
     * wrong and starts changing it.
     *
     * @return array{open: bool, until: ?string, failures: int, openings: int}|null
     */
    private function circuitOf(MailProvider $provider): ?array
    {
        try {
            $health = $this->health->forProvider($provider->id);
        } catch (\Throwable) {
            return null;
        }

        if (
            $health->consecutiveFailures === 0
            && $health->openedUntil === null
            && $health->openCount === 0
        ) {
            // Nothing has ever gone wrong with this provider. `openCount`
            // has to be in the test: a provider that recovered has its
            // consecutive count cleared and its lockout lifted, and
            // returning null there would hide the one figure worth
            // showing — how often it has come back broken.
            return null;
        }

        return [
            'open' => $health->isOpen(),
            'until' => $health->openedUntil,
            'failures' => $health->consecutiveFailures,
            'openings' => $health->openCount,
        ];
    }

    /**
     * The queue, as the page shows it — « un report n'est pas un
     * silence » (D9).
     *
     * The authentication lane is in the list with a permanent zero rather
     * than left out, because its absence would read as « nothing is
     * waiting there » when the truth is « nothing can ever wait there »,
     * and those are the two halves of the decision this page exists to
     * make legible.
     *
     * @return array{lanes: array<int, array{key: string, label: string, waiting: int, defers: bool}>,
     *     waiting: int, abandoned: array{recent: int, day: int, week: int, older: int, total: int},
     *     lifetime_hours: int, retention_days: int, windows: array<int, array{key: string, label: string}>,
     *     default_window: string}
     */
    private function queueSummary(): array
    {
        try {
            $pending = $this->deferred->pendingCountByLane();
            $abandoned = $this->queue->abandonedByAge();
        } catch (\Throwable) {
            $pending = [];
            $abandoned = ['recent' => 0, 'day' => 0, 'week' => 0, 'older' => 0, 'total' => 0];
        }

        $lanes = [];
        foreach (MailLane::ordered() as $lane) {
            $lanes[] = [
                'key' => $lane->value,
                'label' => $lane->label(),
                'waiting' => $pending[$lane->value] ?? 0,
                'defers' => $this->queue->defers($lane),
            ];
        }

        return [
            'lanes' => $lanes,
            'waiting' => array_sum($pending),
            'abandoned' => $abandoned,
            'lifetime_hours' => $this->queue->lifetimeHours(),
            'retention_days' => $this->queue->abandonedRetentionDays(),
            'windows' => [
                ['key' => 'recent', 'label' => 'Les 6 dernières heures'],
                ['key' => 'day', 'label' => 'Les 24 dernières heures'],
                ['key' => 'week', 'label' => 'La semaine écoulée'],
            ],
            'default_window' => DeferredMailQueue::DEFAULT_WINDOW,
        ];
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
