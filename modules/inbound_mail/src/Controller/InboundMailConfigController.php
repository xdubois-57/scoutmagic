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
use Modules\InboundMail\Service\MailboxAdminService;
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
        private JournalService $journalService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('@inbound_mail/config/index.html.twig', [
            'mailboxes' => $this->adminService->listMailboxes(),
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
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
            $this->adminService->update($id, $name, $host, $port, $encryption, $username, $password, $folders, $isEnabled);
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

        $newId = $this->adminService->create($name, $host, $port, $encryption, $username, $password, $folders, $isEnabled);
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
}
