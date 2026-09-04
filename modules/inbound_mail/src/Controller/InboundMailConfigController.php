<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\CsrfGuard;
use Modules\InboundMail\Api\MailboxPurpose;
use Modules\InboundMail\Api\ReadMode;
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Service\MailboxAdminService;
use Modules\InboundMail\Service\MailboxScopeService;
use Modules\InboundMail\Service\ManualRefreshService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use Twig\Environment;

/**
 * `Configuration > Courrier entrant` — **superadmin and nothing else**
 * (§7.4).
 *
 * The route's `role_min` is what enforces that; this class never re-derives
 * a role. What it does enforce is the rule the role cannot: **no secret
 * leaves here**. The page renders the mailbox list from `Mailbox` objects,
 * which have no password field at all, and the password input is always
 * empty — a blank one on save means "keep the stored one", never "clear
 * it", because there is no way to show an operator what they would be
 * retyping.
 *
 * Journal entries name the mailbox by id and by the name the operator gave
 * it, never by its account or host: the journal is not a place for
 * credentials, and a host is half of one.
 */
class InboundMailConfigController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private MailboxAdminService $adminService,
        private JournalService $journalService,
        /**
         * What each module may do with each box (IT-05). Optional because
         * the technical half of this screen — hosts, ports, credentials —
         * works without a single consumer being registered, and a fresh
         * installation has none.
         */
        private ?MailboxScopeService $scopeService = null,
        private ?MessageConsumerRegistry $consumerRegistry = null,
        /**
         * Runs a synchronisation on demand for « Rafraîchir maintenant ».
         * Null means the button is not offered rather than offered and
         * inert — a button that does nothing is worse than none.
         */
        private ?ManualRefreshService $refreshService = null,
        /**
         * Read for one thing: whether a real cron has run lately. On shared
         * hosting without one the relève only happens on page views, and a
         * unit that does not know it reads the delay as a broken box.
         */
        private ?\Core\Config\SettingService $settings = null
    ) {
    }

    /**
     * Whether a real cron has run recently — the same signal and window
     * the push-notification and rental pages use: `cron_last_run` is
     * stamped only by `public/cron.php`, never by a web request.
     */
    private function cronDetected(): bool
    {
        if ($this->settings === null) {
            return true;
        }

        $lastRun = (int) ($this->settings->get('cron_last_run') ?: 0);

        return $lastRun > 0 && (time() - $lastRun) < 600;
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $mailboxes = $this->adminService->listMailboxes();

        // One summary row per box, assembled here rather than in the
        // template: « quels modules, et jusqu'où » is three joins deep and
        // a template that reached for it would be querying inside a loop.
        $summaries = [];
        foreach ($mailboxes as $mailbox) {
            $summaries[$mailbox->id] = $this->scopeSummary($mailbox);
        }

        return $this->render('@inbound_mail/config/index.html.twig', [
            'mailboxes' => $mailboxes,
            'scope_summaries' => $summaries,
            'stored_counts' => $this->adminService->storedMessageCounts(),
            'can_refresh' => $this->refreshService !== null,
            'cron_detected' => $this->cronDetected(),
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * GET /config/courrier-entrant/boites/{id}/portee
     *
     * @param array<string, string> $params
     */
    public function editScopes(Request $request, array $params): Response
    {
        $mailbox = $this->adminService->findById((int) ($params['id'] ?? 0));
        if ($mailbox === null || $this->scopeService === null || $this->consumerRegistry === null) {
            return $this->notFound();
        }

        return $this->render('@inbound_mail/config/mailbox_scopes.html.twig', [
            'mailbox' => $mailbox,
            'consumers' => $this->consumerRegistry->all(),
            'scopes' => $this->scopeService->scopesFor($mailbox),
            'read_modes' => ReadMode::cases(),
            'retention_days' => $this->adminService->retentionDays(),
            'breadcrumb_trail' => [
                ['label' => 'Courrier entrant', 'url' => '/config/courrier-entrant'],
            ],
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * POST /config/courrier-entrant/boites/{id}/portee
     *
     * The purpose decides which half of the form is read. Both halves are
     * always submitted — the page shows one and hides the other rather than
     * removing it, so that the choice still works without JavaScript — and
     * the server picks, because the server is the only place that choice
     * can be enforced.
     *
     * @param array<string, string> $params
     */
    public function saveScopes(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $back = '/config/courrier-entrant/boites/' . $id . '/portee';

        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }

        $mailbox = $this->adminService->findById($id);
        if ($mailbox === null || $this->scopeService === null || $this->consumerRegistry === null) {
            return $this->notFound();
        }

        $purpose = MailboxPurpose::fromString((string) $request->getBody('purpose', ''));

        if ($purpose === MailboxPurpose::DEDICATED) {
            $dedicatedTo = trim((string) $request->getBody('dedicated_to', ''));
            if ($this->consumerRegistry->find($dedicatedTo) === null) {
                FlashMessage::set('error', 'Choisissez le module auquel cette boîte est dédiée.');

                return $this->redirect($back);
            }

            $this->scopeService->saveDedicated($id, $dedicatedTo);
        } else {
            $raw = $request->getBody('scope');
            $this->scopeService->saveSharedScopes($id, is_array($raw) ? self::normalizeAnswers($raw) : []);
        }

        // The mailbox by id and by the name its operator gave it, and the
        // purpose. Never the account, never the host (§7.4).
        $this->journalService->log(
            'inbound_mail',
            'inbound_mailbox_scopes_saved',
            'info',
            'Portée des modules mise à jour pour la boîte : ' . $mailbox->name,
            ['mailbox_id' => $id, 'purpose' => $purpose->value]
        );
        FlashMessage::set('success', 'Portée enregistrée.');

        return $this->redirect('/config/courrier-entrant');
    }

    /**
     * POST /config/courrier-entrant/rafraichir
     *
     * A synchronisation on demand, so a superadmin who has just configured
     * a box does not have to wait a quarter of an hour to find out whether
     * it works.
     *
     * **Behind a lock**, because it runs inside the request: two clicks a
     * second apart would open two IMAP sessions on the same box, read the
     * same messages twice and race each other on the cursor. The lock is a
     * setting rather than a table — one row, no schema, and readable from
     * the scheduled path too.
     *
     * @param array<string, string> $params
     */
    public function refreshNow(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/courrier-entrant')) !== null) {
            return $guard;
        }

        if ($this->refreshService === null) {
            return $this->notFound();
        }

        $outcome = $this->refreshService->refresh(new \DateTimeImmutable());

        FlashMessage::set($outcome['ok'] ? 'success' : 'warning', $outcome['message']);

        return $this->redirect('/config/courrier-entrant');
    }

    /**
     * @return array<string, array{analyze: bool, read: string}>
     */
    private static function normalizeAnswers(mixed $raw): array
    {
        $answers = [];
        foreach (is_array($raw) ? $raw : [] as $consumerId => $answer) {
            if (!is_array($answer)) {
                continue;
            }

            $answers[(string) $consumerId] = [
                // A checkbox absent from the body is a checkbox unchecked.
                'analyze' => ($answer['analyze'] ?? null) !== null,
                'read' => (string) ($answer['read'] ?? ReadMode::NONE->value),
            ];
        }

        return $answers;
    }

    /**
     * The pills of one row of the index: which modules sort this box, and
     * how far each one's users may read.
     *
     * @return array<int, array{name: string, read: ReadMode}>
     */
    private function scopeSummary(Mailbox $mailbox): array
    {
        if ($this->scopeService === null || $this->consumerRegistry === null) {
            return [];
        }

        $scopes = $this->scopeService->scopesFor($mailbox);
        $summary = [];

        foreach ($this->consumerRegistry->all() as $consumer) {
            $scope = $scopes[$consumer->consumerId()] ?? null;
            if ($scope === null || !$scope->analyzes) {
                continue;
            }

            $summary[] = ['name' => $consumer->displayName(), 'read' => $scope->effectiveReadMode()];
        }

        return $summary;
    }

    /**
     * GET /config/courrier-entrant/boites/nouvelle
     *
     * @param array<string, string> $params
     */
    public function newMailbox(Request $request, array $params): Response
    {
        return $this->render('@inbound_mail/config/mailbox_form.html.twig', $this->formContext(null));
    }

    /**
     * GET /config/courrier-entrant/boites/{id}/modification
     *
     * @param array<string, string> $params
     */
    public function editMailbox(Request $request, array $params): Response
    {
        $mailbox = $this->adminService->findById((int) ($params['id'] ?? 0));
        if ($mailbox === null) {
            return $this->notFound();
        }

        return $this->render('@inbound_mail/config/mailbox_form.html.twig', $this->formContext($mailbox));
    }

    /**
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/courrier-entrant')) !== null) {
            return $guard;
        }

        $id = (int) $request->getBody('id', 0);
        $name = trim((string) $request->getBody('name', ''));
        $host = trim((string) $request->getBody('host', ''));
        $port = (int) $request->getBody('port', 993);
        $encryption = (string) $request->getBody('encryption', 'ssl');
        $username = trim((string) $request->getBody('username', ''));
        $password = (string) $request->getBody('password', '');
        $folders = MailboxAdminService::parseFolders((string) $request->getBody('folders', ''));
        $isEnabled = $request->getBody('is_enabled') !== null;

        if ($name === '' || $host === '' || $username === '') {
            FlashMessage::set('error', 'Le nom, l\'hôte et le compte sont obligatoires.');

            return $this->redirect('/config/courrier-entrant');
        }

        if ($port < 1 || $port > 65535) {
            FlashMessage::set('error', 'Le port doit être compris entre 1 et 65535.');

            return $this->redirect('/config/courrier-entrant');
        }

        if ($id > 0) {
            $this->adminService->update($id, $name, $host, $port, $encryption, $username, $password, $folders,
                $isEnabled);
            $this->journalService->log(
                'inbound_mail',
                'inbound_mailbox_updated',
                'info',
                'Boîte de courrier entrant mise à jour : ' . $name,
                ['mailbox_id' => $id]
            );
            FlashMessage::set('success', 'Boîte mise à jour.');

            return $this->redirect('/config/courrier-entrant');
        }

        if ($password === '') {
            FlashMessage::set('error', 'Le mot de passe est obligatoire pour une nouvelle boîte.');

            return $this->redirect('/config/courrier-entrant');
        }

        $newId = $this->adminService->create($name, $host, $port, $encryption, $username, $password, $folders,
            $isEnabled);
        $this->journalService->log(
            'inbound_mail',
            'inbound_mailbox_created',
            'info',
            'Boîte de courrier entrant ajoutée : ' . $name,
            ['mailbox_id' => $newId]
        );
        FlashMessage::set('success', 'Boîte ajoutée. Testez la connexion avant de compter dessus.');

        return $this->redirect('/config/courrier-entrant');
    }

    /**
     * @param array<string, string> $params
     */
    public function testConnection(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/courrier-entrant')) !== null) {
            return $guard;
        }

        $result = $this->adminService->testConnection((int) ($params['id'] ?? 0), new \DateTimeImmutable());

        FlashMessage::set($result['ok'] ? 'success' : 'danger', $result['message']);

        return $this->redirect('/config/courrier-entrant');
    }

    /**
     * JSON endpoint for the form's « Tester la connexion » button.
     *
     * Accepts the connection parameters as JSON, tries them without saving
     * anything, and returns the list of folders the server exposes. When
     * editing an existing box and the password field was left blank the
     * stored one is reused — the page never re-displays it.
     *
     * @param array<string, string> $params
     */
    public function testConnectionFromForm(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Données invalides.']);
        }

        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $host = trim((string) ($data['host'] ?? ''));
        $port = (int) ($data['port'] ?? 993);
        $encryption = (string) ($data['encryption'] ?? 'ssl');
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $existingId = (int) ($data['mailbox_id'] ?? 0);

        if ($host === '' || $username === '') {
            return $this->json(['success' => false, 'error' => "L'hôte et le compte sont obligatoires."]);
        }

        $result = $this->adminService->testConnectionFromParams(
            $host,
            $port,
            $encryption,
            $username,
            $password,
            $existingId > 0 ? $existingId : null,
            new \DateTimeImmutable()
        );

        return $this->json([
            'success' => $result['ok'],
            'error' => $result['ok'] ? null : $result['message'],
            'message' => $result['ok'] ? $result['message'] : null,
            'folders' => $result['folders'],
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function toggle(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/courrier-entrant')) !== null) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $enable = $request->getBody('enable') !== null;

        $this->adminService->setEnabled($id, $enable);
        $this->journalService->log(
            'inbound_mail',
            'inbound_mailbox_toggled',
            'info',
            $enable ? 'Boîte de courrier entrant réactivée.' : 'Boîte de courrier entrant désactivée.',
            ['mailbox_id' => $id]
        );
        FlashMessage::set('success', $enable ? 'Boîte réactivée.' : 'Boîte désactivée.');

        return $this->redirect('/config/courrier-entrant');
    }

    /**
     * Deleting a box removes its configuration and its cursors. It does
     * **not** touch the messages already claimed by a consumer module:
     * those belong to that module's business objects now, and follow that
     * module's retention (§7.10). Losing a booking's correspondence because
     * somebody retired the mailbox it arrived through would be a surprise
     * nobody asked for.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/courrier-entrant')) !== null) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $this->adminService->delete($id);
        $this->journalService->log(
            'inbound_mail',
            'inbound_mailbox_deleted',
            'warning',
            'Boîte de courrier entrant supprimée.',
            ['mailbox_id' => $id]
        );
        FlashMessage::set('success', 'Boîte supprimée.');

        return $this->redirect('/config/courrier-entrant');
    }

    /**
     * @return array<string, mixed>
     */
    private function formContext(?Mailbox $mailbox): array
    {
        return [
            'mailbox' => $mailbox,
            'csrf_token' => CsrfGuard::generateToken(),
        ];
    }
}
