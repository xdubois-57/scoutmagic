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
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Service\MailboxAdminService;
use Modules\InboundMail\Service\MailboxReservationRefusedException;
use Modules\InboundMail\Service\MailProviderRecognizer;
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
 * **A box is verified before it is saved** (D10): « Vérifier et
 * enregistrer » opens the connection with what the form holds, and an
 * address that does not answer is not written. The form comes back with
 * its fields as typed — all but the password, which is never rendered —
 * and one sentence saying what to check.
 *
 * Journal entries name the mailbox by its id and nothing else (« boîte
 * #12 »). The screens show its address, a box's only name (D7); the
 * journal is not a place for an address, nor for a host, which is half a
 * credential.
 */
class InboundMailConfigController extends AbstractController
{
    private const INDEX = '/config/courrier-entrant';

    private MailProviderRecognizer $providers;

    public function __construct(
        protected Environment $twig,
        private MailboxAdminService $adminService,
        private JournalService $journalService,
        /**
         * The modules a box can be reserved to. Optional because the
         * technical half of this screen — hosts, ports, credentials — works
         * without a single consumer being registered, and a fresh
         * installation has none: every box is then general mail.
         */
        private ?MessageConsumerRegistry $consumerRegistry = null,
        /**
         * Read for one thing: whether a real cron has run lately. On shared
         * hosting without one the relève only happens on page views, and a
         * unit that does not know it reads the delay as a broken box.
         */
        private ?\Core\Config\SettingService $settings = null,
        /** Prefills the connection settings of a known provider (D8). */
        ?MailProviderRecognizer $providers = null
    ) {
        $this->providers = $providers ?? new MailProviderRecognizer();
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
     * GET /config/courrier-entrant — one card per address.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $mailboxes = $this->adminService->listMailboxes();

        $usages = [];
        foreach ($mailboxes as $mailbox) {
            $usages[$mailbox->id] = $this->usageSentence($mailbox);
        }

        return $this->render('@inbound_mail/config/index.html.twig', [
            'mailboxes' => $mailboxes,
            'usages' => $usages,
            'cron_detected' => $this->cronDetected(),
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * What a box is for, in the words of the index: « Courrier général de
     * l'unité », or the module it is reserved to.
     */
    private function usageSentence(Mailbox $mailbox): string
    {
        if (!$mailbox->isDedicated()) {
            return "Courrier général de l'unité";
        }

        $module = $this->consumerRegistry?->find((string) $mailbox->dedicatedTo)?->displayName()
            ?? (string) $mailbox->dedicatedTo;

        return 'Réservée au module « ' . $module . ' »';
    }

    /**
     * GET /config/courrier-entrant/boites/nouvelle
     *
     * @param array<string, string> $params
     */
    public function newMailbox(Request $request, array $params): Response
    {
        return $this->renderForm(null, null);
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

        return $this->renderForm($mailbox, null);
    }

    /**
     * POST /config/courrier-entrant/boites — « Vérifier et enregistrer ».
     *
     * Every refusal renders the form again, filled as it was posted, so
     * nothing typed is lost; nothing is written before the connection has
     * answered (D10).
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::INDEX)) !== null) {
            return $guard;
        }

        $id = (int) $request->getBody('id', 0);
        $existing = $id > 0 ? $this->adminService->findById($id) : null;
        if ($id > 0 && $existing === null) {
            return $this->notFound();
        }

        $input = self::readForm($request);
        $password = (string) $request->getBody('password', '');

        $refusal = $this->refusal($existing, $input, $password);
        if ($refusal !== null) {
            return $this->renderForm($existing, $input, ['title' => null, 'message' => $refusal]);
        }

        $purpose = MailboxPurpose::fromString($input['purpose']);
        $dedicatedTo = $purpose === MailboxPurpose::DEDICATED ? $input['dedicated_to'] : null;

        // The connection first: an address that does not answer is not
        // saved, and the box as stored is left exactly as it was.
        $verified = $this->adminService->testConnectionFromParams(
            $input['host'],
            (int) $input['port'],
            $input['encryption'],
            $input['username'],
            $password,
            $existing?->id,
            new \DateTimeImmutable()
        );
        if (!$verified['ok']) {
            return $this->renderForm($existing, $input, [
                'title' => "La connexion a échoué. Rien n'est enregistré.",
                'message' => $verified['message'],
            ]);
        }

        $folders = MailboxAdminService::foldersWithInbox(MailboxAdminService::parseFolders($input['folders']));

        if ($existing !== null) {
            $this->adminService->update(
                $existing->id,
                $input['host'],
                (int) $input['port'],
                $input['encryption'],
                $input['username'],
                $password,
                $folders,
                // No « Synchroniser cette boîte » any more (D10): a box is
                // suspended with « Désactiver » in the list, and an edit
                // keeps whatever that says.
                $existing->isEnabled,
                $input['sent_folder']
            );

            return $this->afterSave($existing->id, $purpose, $dedicatedTo, 'inbound_mailbox_updated', 'mise à jour');
        }

        try {
            // The purpose travels with the INSERT: a refused reservation
            // creates no box at all, rather than a general one.
            $newId = $this->adminService->create(
                $input['host'],
                (int) $input['port'],
                $input['encryption'],
                $input['username'],
                $password,
                $folders,
                true,
                $input['sent_folder'],
                $purpose,
                $dedicatedTo
            );
        } catch (MailboxReservationRefusedException $refused) {
            return $this->renderForm(null, $input, ['title' => null, 'message' => $refused->getMessage()]);
        }

        return $this->afterSave($newId, $purpose, null, 'inbound_mailbox_created', 'ajoutée');
    }

    /**
     * Why the posted form cannot be verified as it stands, or null.
     *
     * @param array<string, string> $input
     */
    private function refusal(?Mailbox $existing, array $input, string $password): ?string
    {
        if ($input['username'] === '') {
            return "Indiquez l'adresse e-mail de la boîte.";
        }

        if ($input['host'] === '') {
            return 'Indiquez le serveur IMAP : il figure dans l\'aide de votre fournisseur, rubrique IMAP.';
        }

        $port = (int) $input['port'];
        if ($port < 1 || $port > 65535 || (string) $port !== $input['port']) {
            return 'Le port doit être un nombre compris entre 1 et 65535.';
        }

        if (!in_array($input['encryption'], ['ssl', 'tls', 'starttls'], true)) {
            return 'Choisissez le chiffrement : SSL, TLS ou STARTTLS.';
        }

        if ($existing === null && $password === '') {
            return "Indiquez le mot de passe d'application de la boîte.";
        }

        $purpose = MailboxPurpose::fromString($input['purpose']);
        $dedicatedTo = $purpose === MailboxPurpose::DEDICATED ? $input['dedicated_to'] : null;

        // Only a module that reads mail can have an address of its own; a
        // posted id that names nothing is refused rather than stored — unless
        // it is the module the box is already reserved to, switched off since,
        // which an edit keeps as it is.
        $keepsItsModule = $existing !== null && $dedicatedTo !== null && $existing->dedicatedTo === $dedicatedTo;
        if ($dedicatedTo !== null && $dedicatedTo !== '' && !$keepsItsModule
            && $this->consumerRegistry?->find($dedicatedTo) === null
        ) {
            return 'Choisissez le module auquel cette adresse est réservée.';
        }

        // Asked before anything is written: a refused purpose leaves the
        // box exactly as it was (D5, one reserved address per module).
        return $this->adminService->purposeRefusal($existing->id ?? 0, $purpose, $dedicatedTo);
    }

    /**
     * The form as it was posted, trimmed — the password apart, which is
     * never rendered back.
     *
     * @return array{username: string, host: string, port: string, encryption: string, purpose: string,
     *   dedicated_to: string, folders: string, sent_folder: string}
     */
    private static function readForm(Request $request): array
    {
        return [
            'username' => trim((string) $request->getBody('username', '')),
            'host' => trim((string) $request->getBody('host', '')),
            'port' => trim((string) $request->getBody('port', '')),
            'encryption' => trim((string) $request->getBody('encryption', '')),
            'purpose' => (string) $request->getBody('purpose', ''),
            'dedicated_to' => trim((string) $request->getBody('dedicated_to', '')),
            'folders' => (string) $request->getBody('folders', ''),
            'sent_folder' => trim((string) $request->getBody('sent_folder', '')),
        ];
    }

    /**
     * Write the purpose of an edited box, journal the save and say how it
     * went. The purpose was checked before the box was written; a refusal
     * here is the unique index catching a save that raced this one, and is
     * said the same way. A created box already carries its purpose, so
     * `$dedicatedTo` is not written again for it.
     */
    private function afterSave(
        int $id,
        MailboxPurpose $purpose,
        ?string $dedicatedTo,
        string $event,
        string $verb
    ): Response {
        $refusal = $event === 'inbound_mailbox_created'
            ? null
            : $this->adminService->setPurpose($id, $purpose, $dedicatedTo);

        // A refused purpose leaves the box as it was stored, its other
        // fields saved: the journal records that purpose, not the one asked.
        $stored = $refusal === null ? $purpose : ($this->adminService->findById($id)->purpose ?? $purpose);
        $this->journalService->log(
            'inbound_mail',
            $event,
            'info',
            'Boîte de courrier entrant #' . $id . ' ' . $verb . '.',
            ['mailbox_id' => $id, 'purpose' => $stored->value]
        );

        if ($refusal !== null) {
            FlashMessage::set('error', $refusal);
        } else {
            FlashMessage::set(
                'success',
                'Adresse vérifiée et enregistrée. Elle sera relevée au prochain passage de la relève.'
            );
        }

        return $this->redirect(self::INDEX);
    }

    /**
     * POST /config/courrier-entrant/test-connexion — JSON, for « Dossiers
     * lus (facultatif) ».
     *
     * Accepts the connection parameters as JSON, tries them without saving
     * anything, and returns the folders the form may offer — the special
     * ones filtered out (D9), the junk folder kept for a seed box — and the
     * one the server marks as the sent
     * mail. When editing an existing box and the password field was left
     * blank the stored one is reused — the page never re-displays it.
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
        // A seed box must read its junk folder — the one place it is
        // offered (§8.58 and Core\Mail\Feedback\Seed).
        $offerJunk = MailboxPurpose::readsReservedBoxesOnly(trim((string) ($data['dedicated_to'] ?? '')));

        if ($host === '' || $username === '') {
            return $this->json([
                'success' => false,
                'error' => "Renseignez d'abord l'adresse, le mot de passe et le serveur.",
            ]);
        }

        $result = $this->adminService->testConnectionFromParams(
            $host,
            $port,
            $encryption,
            $username,
            $password,
            $existingId > 0 ? $existingId : null,
            new \DateTimeImmutable(),
            $offerJunk
        );

        return $this->json([
            'success' => $result['ok'],
            'error' => $result['ok'] ? null : $result['message'],
            'message' => $result['ok'] ? $result['message'] : null,
            'folders' => $result['offered_folders'] ?? [],
            'sent_folder' => $result['sent_folder'] ?? null,
        ]);
    }

    /**
     * POST /config/courrier-entrant/fournisseur — JSON: the provider an
     * address belongs to and the settings the form prefills (D8).
     *
     * A POST and not a GET: the address is somebody's, and a query string
     * is written to every access log on the way.
     *
     * @param array<string, string> $params
     */
    public function recognizeProvider(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['recognized' => false, 'provider' => null]);
        }

        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $provider = $this->providers->recognize(trim((string) ($data['address'] ?? '')));

        return $this->json([
            'recognized' => $provider !== null,
            'provider' => $provider?->toArray(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function toggle(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::INDEX)) !== null) {
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
        FlashMessage::set('success', $enable ? 'Adresse réactivée.' : 'Adresse désactivée.');

        return $this->redirect(self::INDEX);
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
        if (($guard = $this->guardCsrf($request, self::INDEX)) !== null) {
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
        FlashMessage::set('success', 'Adresse supprimée.');

        return $this->redirect(self::INDEX);
    }

    /**
     * The add/edit form, from the stored box, from what was just posted,
     * or blank — with the outcome of a refused « Vérifier et enregistrer »
     * on top.
     *
     * @param array<string, string>|null $input what was posted, null on a GET
     * @param array{title: ?string, message: string}|null $failure
     */
    private function renderForm(?Mailbox $mailbox, ?array $input, ?array $failure = null): Response
    {
        $modules = $this->offeredModules($mailbox);
        $general = array_values(array_filter(
            $this->adminService->generalAddresses(),
            static fn(string $address): bool => $address !== $mailbox?->displayName()
        ));

        $form = $input ?? [
            'username' => $mailbox->username ?? '',
            'host' => $mailbox->host ?? '',
            // Empty for a new box: an unknown provider's settings are
            // typed, never guessed.
            'port' => $mailbox !== null ? (string) $mailbox->port : '',
            'encryption' => $mailbox->encryption ?? '',
            'purpose' => $mailbox !== null
                ? ($mailbox->isDedicated() ? MailboxPurpose::DEDICATED->value : MailboxPurpose::SHARED->value)
                : $this->adminService->suggestedPurpose($modules !== [])->value,
            'dedicated_to' => $mailbox?->isDedicated() === true ? (string) $mailbox->dedicatedTo : '',
            'folders' => implode("\n", $mailbox->folders ?? []),
            'sent_folder' => $mailbox->sentFolder ?? '',
        ];

        // Prefill only what is empty: a server the operator typed is
        // theirs, and a recognised provider never overwrites it.
        $provider = $form['username'] !== '' ? $this->providers->recognize($form['username']) : null;
        if ($provider !== null && $form['host'] === '') {
            $form['host'] = $provider->host;
            $form['port'] = (string) $provider->port;
            $form['encryption'] = $provider->encryption;
        }

        // The inbox is always read and is not one of the « autres dossiers ».
        $others = array_values(array_filter(
            MailboxAdminService::parseFolders($form['folders']),
            static fn(string $folder): bool => strcasecmp($folder, 'INBOX') !== 0
        ));

        return $this->render('@inbound_mail/config/mailbox_form.html.twig', [
            'mailbox' => $mailbox,
            'form' => $form,
            'other_folders' => $others,
            'provider' => $provider,
            'modules' => $modules,
            'general_addresses' => $general,
            'is_first_address' => $mailbox === null && $this->adminService->listMailboxes() === [],
            'failure' => $failure,
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * The modules a box may be reserved to: the active ones that read mail
     * and have no address of their own yet (D5) — the seed boxes, which may
     * be several, always — plus this box's own module, which it keeps.
     *
     * @return list<array{id: string, name: string}>
     */
    private function offeredModules(?Mailbox $mailbox): array
    {
        $taken = [];
        foreach ($this->adminService->listMailboxes() as $other) {
            if ($other->isDedicated() && $other->id !== $mailbox?->id
                && !MailboxPurpose::readsReservedBoxesOnly((string) $other->dedicatedTo)
            ) {
                $taken[(string) $other->dedicatedTo] = true;
            }
        }

        // The box's own module is always offered — even when another box
        // holds it too (duplicates from before the unique index; the save
        // then refuses it through purposeRefusal() before any write), and
        // even when it is switched off and so registers no consumer.
        // Otherwise the select would post its first option, or the
        // « réservée » choice would be disabled, and an unrelated save would
        // silently hand the box to another module or make it general mail.
        $current = $mailbox?->isDedicated() === true ? (string) $mailbox->dedicatedTo : '';

        $modules = [];
        foreach ($this->consumerRegistry?->all() ?? [] as $consumer) {
            $id = $consumer->consumerId();
            if (!isset($taken[$id]) || $id === $current) {
                $modules[] = ['id' => $id, 'name' => $consumer->displayName()];
            }
        }

        if ($current !== '' && !in_array($current, array_column($modules, 'id'), true)) {
            $modules[] = ['id' => $current, 'name' => $current . ' (module désactivé)'];
        }

        return $modules;
    }
}
