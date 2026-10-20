<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SpreadsheetResponse;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\View\DateFilterExtension;
use Modules\MassMail\Repository\Contact;
use Modules\MassMail\Repository\ListBlock;
use Modules\MassMail\Service\ContactGroupImportException;
use Modules\MassMail\Service\ContactGroupImportPreview;
use Modules\MassMail\Service\ContactGroupImportService;
use Modules\MassMail\Service\ListBlockService;
use Modules\MassMail\Service\MailingListException;
use Twig\Environment;

/**
 * The contact group dialog's routes (`views/partials/
 * _contact_group_dialog.html.twig`, `public/assets/js/
 * mass-mail-contact-groups.js`) — all JSON or a file, all `role_min:
 * admin`, under `/admin/listes-de-diffusion` like the page they serve.
 *
 * A contact group changes only by file (ARCHITECTURE.md §8.71nonies), so
 * the routes are the two halves of that round trip and nothing else:
 *
 * - **analyse** (`POST …/lists/{id}/contact-groups/analyse` for a group
 *   not created yet, `POST …/contact-groups/{id}/analyse` for an existing
 *   one) reads the uploaded file and answers what « Enregistrer » would
 *   do. It writes nothing, and the upload is deleted in a `finally`
 *   whatever happens;
 * - **save** (`POST …/lists/{id}/contact-groups`, `PATCH
 *   …/contact-groups/{id}`) carries the name and the analysed rows back,
 *   and is the only one that writes;
 * - **read** — the contacts of a group, its export and the empty template.
 *
 * Removing a group is not here: one route removes a block of either kind,
 * and it belongs to the list's page.
 */
class ContactGroupController extends AbstractController
{
    /** The same ceiling as the Desk and mail-merge uploads. */
    private const IMPORT_MAX_SIZE_BYTES = 5 * 1024 * 1024;

    private const NOT_FOUND_GROUP = 'Groupe introuvable.';
    private const NOT_FOUND_LIST = 'Liste introuvable.';
    private const UNREADABLE_CONTACTS = 'Les contacts à enregistrer sont illisibles — choisissez à nouveau le fichier.';

    public function __construct(
        protected Environment $twig,
        private ListBlockService $blockService,
        private ContactGroupImportService $importService
    ) {
    }

    /**
     * GET /admin/listes-de-diffusion/contact-groups/{id}/contacts — every
     * contact of a group, decrypted and sorted, in ONE call: the dialog
     * searches, filters and slices them in the browser, there being no
     * `ORDER BY` and no `LIKE` on ciphertext.
     *
     * @param array<string, string> $params
     */
    public function contacts(Request $request, array $params): Response
    {
        $block = $this->contactGroup((int) $params['id']);
        if ($block === null) {
            return $this->json(['success' => false, 'error' => self::NOT_FOUND_GROUP], 404);
        }

        $contacts = $this->blockService->findContacts($block->id);
        $unsubscribed = count(array_filter($contacts, static fn(Contact $c): bool => $c->isUnsubscribed()));

        return $this->json([
            'success' => true,
            'group' => $this->presentGroup($block) + [
                'count' => count($contacts),
                'unsubscribed' => $unsubscribed,
            ],
            'contacts' => array_map(
                static fn(Contact $c): array => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'email' => $c->email,
                    'unsubscribed_at' => $c->unsubscribedAt,
                ],
                $contacts
            ),
        ]);
    }

    /**
     * GET /admin/listes-de-diffusion/contact-groups/{id}/export — the file
     * a chief edits and sends back.
     *
     * Generated in the request and streamed, never written where anything
     * could later serve it: nothing is stored, so there is nothing for
     * `FileAccessGuard` to guard.
     *
     * @param array<string, string> $params
     */
    public function export(Request $request, array $params): Response
    {
        $block = $this->contactGroup((int) $params['id']);
        if ($block === null) {
            return $this->notFound();
        }

        return SpreadsheetResponse::download(
            $this->importService->export($block->id),
            'groupe-de-contacts-' . $block->id . '.xlsx'
        );
    }

    /**
     * GET /admin/listes-de-diffusion/contact-groups/template — « Télécharger
     * un modèle vide »: the export of a group that holds nobody, so the
     * headers a new file needs are the very ones the round trip reads.
     *
     * @param array<string, string> $params
     */
    public function template(Request $request, array $params): Response
    {
        return SpreadsheetResponse::download($this->importService->export(null), 'modele-groupe-de-contacts.xlsx');
    }

    /**
     * POST /admin/listes-de-diffusion/lists/{id}/contact-groups/analyse —
     * the file of a group not created yet. Writes nothing.
     *
     * @param array<string, string> $params
     */
    public function analyseNew(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return $this->json(['success' => false, 'errors' => ['Requête invalide.']], 400);
        }
        if (!$this->listExists((int) $params['id'])) {
            return $this->json(['success' => false, 'errors' => [self::NOT_FOUND_LIST]], 404);
        }

        return $this->analyseUpload($request, null);
    }

    /**
     * POST /admin/listes-de-diffusion/contact-groups/{id}/analyse — the
     * corrected file of an existing group. Writes nothing.
     *
     * @param array<string, string> $params
     */
    public function analyseExisting(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return $this->json(['success' => false, 'errors' => ['Requête invalide.']], 400);
        }
        $block = $this->contactGroup((int) $params['id']);
        if ($block === null) {
            return $this->json(['success' => false, 'errors' => [self::NOT_FOUND_GROUP]], 404);
        }

        return $this->analyseUpload($request, $block->id);
    }

    /**
     * POST /admin/listes-de-diffusion/lists/{id}/contact-groups —
     * « Enregistrer » on a new group: the name and the rows the analysis
     * returned, written together or not at all.
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $listId = (int) $params['id'];
        if (!$this->listExists($listId)) {
            return $this->json(['success' => false, 'error' => self::NOT_FOUND_LIST], 404);
        }

        // A group is never created without its file, so the rows must be
        // THERE — an empty array included, a header-only file being a
        // previewed, legitimate group of nobody.
        if (!is_array($data['contacts'] ?? null)) {
            return $this->json(['success' => false, 'error' => self::UNREADABLE_CONTACTS], 422);
        }

        try {
            $block = $this->importService->createGroup(
                $listId,
                (string) ($data['name'] ?? ''),
                $this->toContactArray($data['contacts']),
                AuthSession::getUserAccountId()
            );
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'group' => $this->presentGroup($block)]);
    }

    /**
     * PATCH /admin/listes-de-diffusion/contact-groups/{id} — « Enregistrer »
     * on an existing group: the name, and the analysed rows when a file was
     * chosen. No `contacts` key means the name only; a `contacts` key that
     * is not an array of objects is refused, never read as « replace by
     * nothing ».
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $block = $this->contactGroup((int) $params['id']);
        if ($block === null) {
            return $this->json(['success' => false, 'error' => self::NOT_FOUND_GROUP], 404);
        }

        if (array_key_exists('contacts', $data) && !is_array($data['contacts'])) {
            return $this->json(['success' => false, 'error' => self::UNREADABLE_CONTACTS], 422);
        }

        try {
            $contacts = is_array($data['contacts'] ?? null) ? $this->toContactArray($data['contacts']) : null;
            $result = $this->importService->updateGroup(
                $block->id,
                (string) ($data['name'] ?? ''),
                $contacts,
                AuthSession::getUserAccountId()
            );
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json([
            'success' => true,
            'group' => $this->presentGroup($result['block']),
            'summary' => $result['summary'],
        ]);
    }

    /**
     * The analysis proper, shared by both routes once the token and the
     * target are known good. **The uploaded file is deleted in the
     * `finally`**, success or failure (SECURITY.md §5): nothing of it
     * survives the request, which is why the save carries the rows back.
     */
    private function analyseUpload(Request $request, ?int $blockId): Response
    {
        $file = $request->getFile('file');
        if ($file === null || ($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
            return $this->json(['success' => false, 'errors' => ['Aucun fichier reçu.']], 400);
        }
        if (!str_ends_with(mb_strtolower((string) ($file['name'] ?? '')), '.xlsx')) {
            return $this->json(
                ['success' => false, 'errors' => ['Seuls les fichiers Excel .xlsx sont acceptés.']],
                422
            );
        }
        if ((int) ($file['size'] ?? 0) > self::IMPORT_MAX_SIZE_BYTES) {
            return $this->json(
                ['success' => false, 'errors' => ['Le fichier dépasse la taille maximale de 5 Mo.']],
                422
            );
        }

        try {
            $preview = $this->importService->analyse($blockId, (string) $file['tmp_name']);
        } catch (ContactGroupImportException $e) {
            return $this->json(['success' => false, 'errors' => $e->errors], 422);
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'errors' => [$e->getMessage()]], 422);
        } finally {
            @unlink((string) $file['tmp_name']);
        }

        return $this->json($this->presentPreview($preview));
    }

    /**
     * @return array{success: true, summary: array<string, int>, contacts: array<int, array{name: ?string,
     *     email: string}>, errors: string[], duplicates: int}
     */
    private function presentPreview(ContactGroupImportPreview $preview): array
    {
        return [
            'success' => true,
            'summary' => $preview->summary,
            'contacts' => $preview->contacts,
            'errors' => $preview->errors,
            'duplicates' => $preview->duplicates,
        ];
    }

    /**
     * @return array{id: int, list_id: int, kind: string, name: ?string, file_replaced_at: ?string,
     *     file_replaced_label: string}
     */
    private function presentGroup(ListBlock $block): array
    {
        return [
            'id' => $block->id,
            'list_id' => $block->listId,
            'kind' => $block->kind,
            'name' => $block->name,
            'file_replaced_at' => $block->fileReplacedAt,
            // « 12 septembre 2026 », the same long form a page prints with
            // |french_date — formatted here so the script never reinvents
            // the month names.
            'file_replaced_label' => DateFilterExtension::frenchDate($block->fileReplacedAt),
        ];
    }

    /**
     * A contact group, or null for an unknown id AND for a member group:
     * to these routes a member group is not a contact group that refuses,
     * it is no contact group at all.
     */
    private function contactGroup(int $blockId): ?ListBlock
    {
        $block = $this->blockService->findById($blockId);

        return $block !== null && $block->isContactGroup() ? $block : null;
    }

    private function listExists(int $listId): bool
    {
        try {
            $this->blockService->requireList($listId);
        } catch (MailingListException) {
            return false;
        }

        return true;
    }

    /**
     * An entry that is not an object is REFUSED, never dropped: dropping
     * it turns a garbled payload into a shorter group, and a shorter group
     * is a replacement that deletes rows nobody saw in the preview. The
     * service's own `sanitise()` refuses a badly shaped address — this is
     * the same rule one nesting level out.
     *
     * @param array<mixed> $value
     * @return array<int, array{name: ?string, email: string}>
     * @throws MailingListException
     */
    private function toContactArray(array $value): array
    {
        $contacts = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw new MailingListException(self::UNREADABLE_CONTACTS);
            }
            $name = $entry['name'] ?? null;
            $contacts[] = [
                'name' => is_string($name) && trim($name) !== '' ? $name : null,
                'email' => is_string($entry['email'] ?? null) ? $entry['email'] : '',
            ];
        }

        return $contacts;
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
