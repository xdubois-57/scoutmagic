<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Exception\UserFacingMessage;
use Core\File\FileRepository;
use Core\File\UploadException;
use Core\File\UploadHandler;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Import\ImportJournalRepository;
use Core\Mail\MailException;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Core\View\SectionPickerHelper;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Service\AudienceImportException;
use Modules\MassMail\Service\AudienceImportService;
use Modules\MassMail\Service\MailingListService;
use Modules\MassMail\Service\MassMailAccessService;
use Modules\MassMail\Api\MassMailException;
use Modules\MassMail\Service\MassMailService;
use Modules\MassMail\Service\SenderAuthorization;
use Twig\Environment;

class MassMailController extends AbstractController
{
    private const ATTACHMENT_ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private const ATTACHMENT_MAX_SIZE_BYTES = 10 * 1024 * 1024;
    private const AUDIENCE_MAX_SIZE_BYTES = 5 * 1024 * 1024;
    private const SETTING_PREVIOUS_YEAR_CUTOFF = 'previous_year_active_cutoff';
    private const DEFAULT_PREVIOUS_YEAR_CUTOFF = '07-31';

    public function __construct(
        protected Environment $twig,
        private MassMailService $massMailService,
        private MailingListService $mailingListService,
        private MassMailAccessService $massMailAccessService,
        private MemberService $memberService,
        private SectionService $sectionService,
        private ScoutYearService $scoutYearService,
        private ImportJournalRepository $importJournalRepository,
        private SettingService $settingService,
        private UploadHandler $uploadHandler,
        private FileRepository $fileRepository,
        private AudienceImportService $audienceImportService
    ) {
    }

    /**
     * GET /mass-mail — the "Envoi de mails" list page. Filters are plain
     * GET query params (full page reload on change) — module spec asks for
     * server-side SQL search/filtering, not a live AJAX search endpoint.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $search = trim((string) $request->getQuery('search', ''));
        $status = (string) $request->getQuery('status', '');
        $status = in_array($status, Email::STATUSES, true) ? $status : null;
        $sectionId = (int) $request->getQuery('section_id', 0);
        $sectionId = $sectionId > 0 ? $sectionId : null;
        $page = max(1, (int) $request->getQuery('page', 1));

        $result = $this->massMailService->findFiltered($search, $status, $sectionId, $page);

        $recipientCounts = [];
        foreach ($result['emails'] as $email) {
            $recipientCounts[$email->id] = $this->massMailService->getRecipientCount($email->id);
        }

        $sections = $this->sectionService->getAllWithBranches();
        $sectionsById = array_column($sections, 'name', 'id');

        $customListsById = [];
        foreach ($this->mailingListService->getActiveCustomLists() as $list) {
            $customListsById[$list->id] = $list->name;
        }

        // Nothing here describes the composer any more: the list is a
        // list, and everything the compose screen needs is built by that
        // screen's own request (buildComposeContext()).
        return $this->render('@mass_mail/list.html.twig', [
            'emails' => $result['emails'],
            'recipient_counts' => $recipientCounts,
            'scout_years_by_id' => array_column($this->scoutYearService->getAll(), 'label', 'id'),
            'total' => $result['total'],
            'per_page' => $result['per_page'],
            'page' => $page,
            'total_pages' => max(1, (int) ceil($result['total'] / $result['per_page'])),
            'search' => $search,
            'status' => $status,
            'section_id' => $sectionId,
            'statuses' => Email::STATUSES,
            'sections' => $sections,
            'sections_by_id' => $sectionsById,
            'custom_lists_by_id' => $customListsById,
        ]);
    }

    /**
     * GET /mass-mail/{id} — the composition page of ONE email
     * (ARCHITECTURE.md §8.71bis).
     *
     * This is what `Service\MergeDraftService::createMergeDraft()` has
     * always promised and never had: a page. Until it existed the route
     * answered JSON, so « Écrire aux répondants » sent a chief to a raw
     * payload — the composer was a dialog opened from the list, and a
     * dialog has no address to send anybody to.
     *
     * A draft LASTS: it is written over several sittings, bookmarked, and
     * its link handed to a colleague for a re-read. That is a page
     * everywhere else on this site — an article, a campaign, an invoice —
     * and the dialog was the exception.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $email = $this->massMailService->findById((int) $params['id']);
        if ($email === null) {
            return $this->notFound();
        }

        return $this->render('@mass_mail/compose.html.twig',
            $this->buildComposeContext($email, $this->formFromEmail($email), null));
    }

    /**
     * POST /mass-mail/{id} — save the draft from the composition page.
     *
     * An ordinary form POST, redirecting back to the page it came from —
     * not the dialog's JSON PATCH beside it. A refused save re-renders
     * the page carrying what was typed rather than flashing an error onto
     * an empty form: a mail-merge body is long, and losing it to a
     * mistyped subject would be unforgivable.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if (($guard = $this->guardCsrf($request, '/mass-mail/' . $id)) !== null) {
            return $guard;
        }

        $email = $this->massMailService->findById($id);
        if ($email === null) {
            return $this->notFound();
        }

        $form = $this->formFromRequest($request);
        [$listType, $listId, $listSectionId] = self::parseListSelection((string) $form['list']);

        try {
            $this->massMailService->updateDraft(
                $id,
                (string) $form['subject'],
                (string) $form['body_html'],
                (int) $form['section_id'],
                $listType,
                $listId,
                $listSectionId,
                $listType === Email::LIST_TYPE_MAIL_MERGE ? [] : $this->toIntArray($form['scout_year_ids']),
                $this->buildAuthorization(),
                $form['audience_id'] !== null ? (int) $form['audience_id'] : null,
                AuthSession::getUserAccountId()
            );
        } catch (MassMailException $e) {
            return $this->render(
                '@mass_mail/compose.html.twig',
                $this->buildComposeContext($email, $form, $e->getMessage())
            )->setStatusCode(422);
        }

        FlashMessage::set('success', 'Brouillon enregistré.');

        return $this->redirect('/mass-mail/' . $id);
    }

    /**
     * GET /mass-mail/{id}/recipients — the « Destinataires » view of the
     * nav rail: who this email is going to, before it is sent.
     *
     * The question the composition screen cannot answer while you are
     * writing in it, and the one asked last: a wrong list looks exactly
     * like a right one until the mail is out. For a mail merge it is the
     * imported file, row by row; for every other list it is the count the
     * list resolves to RIGHT NOW, re-asked on each visit because the list
     * behind it is live.
     *
     * @param array<string, string> $params
     */
    public function recipients(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $email = $this->massMailService->findById($id);
        if ($email === null) {
            return $this->notFound();
        }

        $audience = null;
        $rows = [];
        $estimate = null;
        if ($email->listType === Email::LIST_TYPE_MAIL_MERGE && $email->audienceId !== null) {
            try {
                $audience = $this->massMailService->getAudienceSummary(
                    $email->audienceId,
                    AuthSession::getUserAccountId(),
                    $this->buildAuthorization()
                )['audience'];
                $rows = $this->massMailService->getAudienceRows($email->audienceId);
            } catch (MassMailException $e) {
                FlashMessage::set('error', $e->getMessage());
            }
        }

        try {
            $estimate = $this->massMailService->estimateRecipientCount($id);
        } catch (MassMailException) {
            $estimate = null;
        }

        return $this->render('@mass_mail/recipients.html.twig', [
            'email' => $email,
            'breadcrumb_current' => 'Destinataires',
            'breadcrumb_trail' => [['label' => $email->subject, 'url' => '/mass-mail/' . $email->id]],
            'audience' => $audience,
            'audience_rows' => $rows,
            'estimate' => $estimate,
            'list_label' => $this->describeList($email),
            'scout_year_labels' => $this->scoutYearLabels($email),
            'frozen_counts' => $this->massMailService->getStatusCounts($email->id),
        ]);
    }

    /**
     * GET /mass-mail/{id}/recipient-count — how many people this would
     * reach if sent right now.
     *
     * Its own request rather than a field of show(): the list behind an
     * email is live, and the point of the number is that it is true at the
     * moment the manager is asked to confirm, not at the moment they
     * opened the dialog.
     *
     * @param array<string, string> $params
     */
    public function recipientCount(Request $request, array $params): Response
    {
        try {
            $estimate = $this->massMailService->estimateRecipientCount((int) $params['id']);
        } catch (MassMailException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 404);
        }

        return $this->json(['success' => true] + $estimate);
    }

    /**
     * GET /mass-mail/new — the creation page.
     *
     * The same screen as `show()`, with nothing in it yet. The dialog it
     * replaces carried the sender section, the mailing list, the audience
     * upload, the scout years, the subject and a rich-text editor with
     * its own toolbar — 766 lines of JavaScript, most of it that one
     * form. A creation screen is a page here like every other detail
     * (ARCHITECTURE.md §8.71bis).
     *
     * @param array<string, string> $params
     */
    public function createForm(Request $request, array $params): Response
    {
        return $this->render(
            '@mass_mail/compose.html.twig',
            $this->buildComposeContext(null, $this->emptyForm(), null)
        );
    }

    /**
     * POST /mass-mail — create the draft and go straight to its page.
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/mass-mail/new')) !== null) {
            return $guard;
        }

        $form = $this->formFromRequest($request);
        [$listType, $listId, $listSectionId] = self::parseListSelection((string) $form['list']);

        try {
            $email = $this->massMailService->createDraft(
                (string) $form['subject'],
                (string) $form['body_html'],
                (int) $form['section_id'],
                $listType,
                $listId,
                $listSectionId,
                $listType === Email::LIST_TYPE_MAIL_MERGE ? [] : $this->toIntArray($form['scout_year_ids']),
                AuthSession::getUserAccountId(),
                $this->buildAuthorization(),
                $form['audience_id'] !== null ? (int) $form['audience_id'] : null
            );
        } catch (MassMailException $e) {
            return $this->render(
                '@mass_mail/compose.html.twig',
                $this->buildComposeContext(null, $form, $e->getMessage())
            )->setStatusCode(422);
        }

        FlashMessage::set('success', 'Brouillon créé.');

        return $this->redirect('/mass-mail/' . $email->id);
    }

    /**
     * POST /mass-mail/audiences — upload + parse a mail-merge Excel file
     * (multipart). All-or-nothing: a refused file stores NOTHING and the
     * response lists every offending line at once. The uploaded .xlsx
     * itself is parsed straight from the PHP upload tmp file and never
     * stored (same rule as the Desk CSV import) — the encrypted audience
     * rows are all that remains.
     *
     * @param array<string, string> $params
     */
    public function importAudience(Request $request, array $params): Response
    {
        $csrf = (string) $request->getBody('_csrf_token', '');
        if (($guard = $this->guardCsrfJson($request, $csrf)) !== null) {
            return $guard;
        }

        $uploadedFile = $request->getFile('file');
        if ($uploadedFile === null || ($uploadedFile['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
            return $this->json(['success' => false, 'errors' => ['Aucun fichier reçu.']], 400);
        }
        $originalName = (string) ($uploadedFile['name'] ?? '');
        if (!str_ends_with(mb_strtolower($originalName), '.xlsx')) {
            return $this->json(
                ['success' => false, 'errors' => ['Seuls les fichiers Excel .xlsx sont acceptés.']],
                422
            );
        }
        if ((int) ($uploadedFile['size'] ?? 0) > self::AUDIENCE_MAX_SIZE_BYTES) {
            return $this->json(
                ['success' => false, 'errors' => ['Le fichier dépasse la taille maximale de 5 Mo.']],
                422
            );
        }

        try {
            $result = $this->audienceImportService->import(
                (string) $uploadedFile['tmp_name'],
                $originalName,
                AuthSession::getUserAccountId()
            );
        } catch (AudienceImportException $e) {
            return $this->json(['success' => false, 'errors' => $e->errors], 422);
        } finally {
            @unlink((string) $uploadedFile['tmp_name']);
        }

        return $this->json([
            'success' => true,
            'audience' => $this->serializeAudience($result->audience),
            'warnings' => $result->warnings,
        ]);
    }

    /**
     * GET /mass-mail/audiences/{id} — the compose dialog's audience
     * summary (columns for the variable dropdown, row count, first-row
     * sample values). Same access rule as attaching the audience: its
     * importer, or a chef d'unité (or above).
     *
     * @param array<string, string> $params
     */
    public function showAudience(Request $request, array $params): Response
    {
        try {
            $summary = $this->massMailService->getAudienceSummary(
                (int) $params['id'],
                AuthSession::getUserAccountId(),
                $this->buildAuthorization()
            );
        } catch (MassMailException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 404);
        }

        return $this->json([
            'success' => true,
            'audience' => $this->serializeAudience($summary['audience']),
            'sample' => $summary['sample'],
        ]);
    }

    /**
     * GET /mass-mail/{id}/merge-preview?offset=N — the per-recipient test
     * preview: the Nth audience row's rendered subject/body plus the
     * unknown-token / missing-value warnings.
     *
     * @param array<string, string> $params
     */
    public function mergePreview(Request $request, array $params): Response
    {
        try {
            $preview = $this->massMailService->getMergePreview((int) $params['id'],
                (int) $request->getQuery('offset', 0));
        } catch (MassMailException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'preview' => $preview]);
    }

    /**
     * POST /mass-mail/{id}/status — draft→test, test→draft, or test→sending
     * depending on {action: 'to_test'|'to_draft'|'start_sending'}.
     *
     * @param array<string, string> $params
     */
    public function changeStatus(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if (($guard = $this->guardCsrf($request, '/mass-mail/' . $id)) !== null) {
            return $guard;
        }

        $action = (string) $request->getBody('action', '');

        try {
            match ($action) {
                'to_test' => $this->massMailService->moveToTest($id, AuthSession::getUserAccountId()),
                'to_draft' => $this->massMailService->backToDraft($id, AuthSession::getUserAccountId()),
                'start_sending' => $this->massMailService->startSending($id, AuthSession::getUserAccountId()),
                default => throw new MassMailException('Action inconnue.'),
            };
        } catch (MassMailException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/mass-mail/' . $id);
        }

        FlashMessage::set('success', match ($action) {
            'to_test' => 'Email passé en mode test.',
            'to_draft' => 'Email repassé en brouillon.',
            default => "L'envoi est lancé : les emails partent par lots en arrière-plan.",
        });

        return $this->redirect('/mass-mail/' . $id);
    }

    /**
     * POST /mass-mail/{id}/test-send — send a one-off test copy.
     *
     * @param array<string, string> $params
     */
    public function testSend(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if (($guard = $this->guardCsrf($request, '/mass-mail/' . $id)) !== null) {
            return $guard;
        }

        try {
            $this->massMailService->sendTestEmail(
                $id,
                (string) $request->getBody('to', ''),
                max(0, (int) $request->getBody('merge_offset', 0))
            );
        } catch (MassMailException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/mass-mail/' . $id);
        } catch (MailException $e) {
            // Core\Mail\MailException is built from PHPMailer's ErrorInfo
            // (Core\Mail\MailService::send()) — raw SMTP English, every
            // time, which is why it is not a Core\Exception\UserFacingException
            // and why this goes through the helper rather than being
            // concatenated.
            FlashMessage::set('error', UserFacingMessage::from(
                $e,
                "L'email de test n'a pas pu être envoyé — vérifiez la configuration d'envoi du site (Configuration > "
                    . "Email), puis réessayez."
            ));

            return $this->redirect('/mass-mail/' . $id);
        }

        FlashMessage::set('success', 'Email de test envoyé.');

        return $this->redirect('/mass-mail/' . $id);
    }

    /**
     * POST /mass-mail/{id}/attachments — upload one attachment (multipart).
     *
     * @param array<string, string> $params
     */
    public function uploadAttachment(Request $request, array $params): Response
    {
        $csrf = (string) $request->getBody('_csrf_token', '');
        if (($guard = $this->guardCsrfJson($request, $csrf)) !== null) {
            return $guard;
        }

        $emailId = (int) $params['id'];
        $uploadedFile = $request->getFile('file');
        if ($uploadedFile === null) {
            FlashMessage::set('error', 'Aucun fichier envoyé.');

            return $this->redirect('/mass-mail/' . $emailId);
        }

        try {
            $fileId = $this->uploadHandler->handle(
                $uploadedFile,
                'mass_mail/attachments',
                self::ATTACHMENT_ALLOWED_MIMES,
                self::ATTACHMENT_MAX_SIZE_BYTES,
                'chief',
                'mass_mail',
                AuthSession::getUserAccountId()
            );
            $this->massMailService->addAttachment($emailId, $fileId);
        } catch (UploadException|MassMailException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/mass-mail/' . $emailId);
        }

        FlashMessage::set('success', 'Pièce jointe ajoutée.');

        return $this->redirect('/mass-mail/' . $emailId);
    }

    /**
     * DELETE /mass-mail/attachments/{id}
     *
     * @param array<string, string> $params
     */
    public function deleteAttachment(Request $request, array $params): Response
    {
        $emailId = (int) $request->getBody('email_id', 0);
        if (($guard = $this->guardCsrf($request, '/mass-mail/' . $emailId)) !== null) {
            return $guard;
        }

        try {
            $this->massMailService->removeAttachment((int) $params['id']);
        } catch (MassMailException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/mass-mail/' . $emailId);
        }

        FlashMessage::set('success', 'Pièce jointe supprimée.');

        return $this->redirect('/mass-mail/' . $emailId);
    }

    /**
     * GET /mass-mail/{id}/tracking — detailed per-recipient tracking page,
     * the « Suivi » tab of the email's own nav rail (§8.71bis).
     *
     * Not in any menu (module.json label: ""): it belongs to one email,
     * and it used to hang off nothing at all — reachable only from a
     * chart button in a table row, and from a link inside a dialog.
     *
     * @param array<string, string> $params
     */
    public function tracking(Request $request, array $params): Response
    {
        try {
            $data = $this->massMailService->getTrackingData((int) $params['id']);
        } catch (MassMailException) {
            return $this->notFound();
        }

        return $this->render('@mass_mail/tracking.html.twig', [
            'email' => $data['email'],
            'counts' => $data['counts'],
            'recipients' => $data['recipients'],
            // The email itself is a page now, so it is a real ancestor —
            // dynamic, hence a controller trail rather than the route's
            // static `breadcrumb.ancestors` (which cannot carry an id).
            'breadcrumb_current' => "Suivi de l'envoi",
            'breadcrumb_trail' => [['label' => $data['email']->subject, 'url' => '/mass-mail/' . $data['email']->id]],
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * POST /mass-mail/recipients/{id}/resend
     *
     * @param array<string, string> $params
     */
    public function resend(Request $request, array $params): Response
    {
        // The tracking page's own fetch (mass-mail-tracking.js) — the one
        // JSON write left in this controller, and the only one whose
        // caller is a table row rather than a form.
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $this->massMailService->resendToRecipient((int) $params['id'], AuthSession::getUserAccountId());
        } catch (MassMailException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true]);
    }

    /**
     * Everything the composition page renders, for one email and one set
     * of field values.
     *
     * The values are passed in rather than read off the email so a
     * refused save can re-render exactly what the chief typed. The rest —
     * which sections they may send from, which lists they may target,
     * which scout years exist — is authorization and reference data, and
     * is always rebuilt from the server.
     *
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private function buildComposeContext(?Email $email, array $form, ?string $error): array
    {
        $authorization = $this->buildAuthorization();
        $sections = $this->sectionService->getAllWithBranches();
        $attachments = [];
        if ($email !== null) {
            foreach ($this->massMailService->getAttachments($email->id) as $attachment) {
                $file = $this->fileRepository->findById($attachment->fileId);
                $attachments[] = [
                    'id' => $attachment->id,
                    'file_id' => $attachment->fileId,
                    'name' => $file->originalName ?? '?'
                ];
            }
        }

        $audience = null;
        $audienceSample = [];
        $audienceId = $form['audience_id'] !== null ? (int) $form['audience_id'] : null;
        if ($audienceId !== null) {
            try {
                $summary = $this->massMailService->getAudienceSummary($audienceId, AuthSession::getUserAccountId(),
                    $authorization);
                $audience = $summary['audience'];
                $audienceSample = $summary['sample'];
            } catch (MassMailException) {
                // A purged or someone else's audience is not an error page:
                // the composer simply offers the import zone again, and the
                // save below refuses it on its own terms if it is reattached.
                $audience = null;
            }
        }

        return [
            'email' => $email,
            'form' => $form,
            'submit_error' => $error,
            'breadcrumb_current' => $email !== null ? $email->subject : 'Nouvel email',
            'editable' => $email === null || $email->isEditable(),
            'sections' => $sections,
            'unrestricted' => $authorization->isChefDUniteOrAbove,
            'forced_section_id' => $authorization->forcedSenderSectionId,
            'list_options' => $this->buildListOptions($authorization, (string) $form['list']),
            'scout_years' => $this->buildScoutYearOptions(),
            'previous_year_cutoff' => (string) ($this->settingService->get(self::SETTING_PREVIOUS_YEAR_CUTOFF,
                'mass_mail') ?: self::DEFAULT_PREVIOUS_YEAR_CUTOFF),
            'audience' => $audience,
            'audience_sample' => $audienceSample,
            'attachments' => $attachments,
            'counts' => $email !== null
                ? $this->massMailService->getStatusCounts($email->id)
                : ['total' => 0, 'sent' => 0, 'pending' => 0, 'error' => 0],
            'current_user_email' => AuthSession::getEmail() ?? '',
            'csrf_token' => CsrfGuard::generateToken(),
        ];
    }

    /**
     * The « Liste de diffusion » picker, server-side.
     *
     * A list the account may not target is rendered DISABLED rather than
     * dropped: a draft created by a chef d'unité and reopened by a section
     * chief must still say which list it targets, and a select that
     * silently omits the stored value would save a different email than
     * the one on screen. The server re-checks every selection anyway
     * (Service\MassMailService), so this is presentation, never a
     * boundary (SECURITY.md §3).
     *
     * @return array<
     *     int,
     *     array{value: string, label: string, selected: bool, disabled: bool, description: string, type: string}
     * >
     */
    private function buildListOptions(SenderAuthorization $authorization, string $selected): array
    {
        $options = [];
        foreach ($this->mailingListService->getDefaultLists() as $list) {
            $value = $list['list_type'] . ':' . ($list['list_section_id'] ?? '');
            $options[] = [
                'value' => $value,
                'label' => $list['label'],
                'selected' => $value === $selected,
                'disabled' => !self::isListAllowed($authorization, $list['list_type'], $list['list_section_id']),
                'description' => $list['description'],
                'type' => $list['list_type'],
            ];
        }
        foreach ($this->mailingListService->getActiveCustomLists() as $list) {
            $value = Email::LIST_TYPE_CUSTOM . ':' . $list->id;
            $options[] = [
                'value' => $value,
                'label' => $list->name,
                'selected' => $value === $selected,
                'disabled' => !self::isListAllowed($authorization, Email::LIST_TYPE_CUSTOM, null),
                'description' => $list->description,
                'type' => Email::LIST_TYPE_CUSTOM,
            ];
        }
        $options[] = [
            'value' => Email::LIST_TYPE_MAIL_MERGE . ':',
            'label' => 'Publipostage — fichier Excel',
            'selected' => str_starts_with($selected, Email::LIST_TYPE_MAIL_MERGE . ':'),
            'disabled' => false,
            'description' => 'Un fichier Excel importé définit les destinataires : un email par ligne, avec des '
                . 'variables par colonne.',
            'type' => Email::LIST_TYPE_MAIL_MERGE,
        ];

        return $options;
    }

    /**
     * The same rule Service\MassMailService enforces on save, restated
     * for the picker: mail merge is open to every chief (the file names
     * the recipients, not a section list), the animateurs list too, a
     * section list only for one's own section, everything else to a chef
     * d'unité or above.
     */
    private static function isListAllowed(
        SenderAuthorization $authorization,
        string $listType,
        ?int $listSectionId
    ): bool
    {
        if ($authorization->isChefDUniteOrAbove) {
            return true;
        }

        return match ($listType) {
            Email::LIST_TYPE_DEFAULT_CHIEFS, Email::LIST_TYPE_MAIL_MERGE => true,
            Email::LIST_TYPE_DEFAULT_SECTION => in_array($listSectionId, $authorization->allowedListSectionIds, true),
            default => false,
        };
    }

    /**
     * `type:id` → the three columns the service takes. One select rather
     * than three fields, because the three are never independent: a
     * custom list has no section, a section list has no list id.
     *
     * @return array{0: string, 1: ?int, 2: ?int}
     */
    private static function parseListSelection(string $value): array
    {
        $parts = explode(':', $value, 2);
        $type = $parts[0];
        $key = ($parts[1] ?? '') !== '' ? (int) $parts[1] : null;

        if ($type === Email::LIST_TYPE_CUSTOM) {
            return [$type, $key, null];
        }
        if ($type === Email::LIST_TYPE_DEFAULT_SECTION) {
            return [$type, null, $key];
        }

        return [$type, null, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function formFromEmail(Email $email): array
    {
        return [
            'section_id' => $email->sectionId,
            'list' => $email->listType
                . ':'
                . ($email->listType === Email::LIST_TYPE_CUSTOM ? $email->listId : ($email->listSectionId ?? '')),
            'scout_year_ids' => $email->scoutYearIds,
            'subject' => $email->subject,
            'body_html' => $email->bodyHtml,
            'audience_id' => $email->audienceId,
        ];
    }

    /**
     * A blank composition form: the account's own section (the only one a
     * non chef d'unité may send from anyway), the first list it is allowed
     * to target, and the current scout year.
     *
     * The first ALLOWED list, not simply the first: a browser does not
     * reliably skip a disabled first option when it picks the implicit
     * default, so a restricted account would otherwise open the page with
     * a list it cannot use already selected.
     *
     * @return array<string, mixed>
     */
    private function emptyForm(): array
    {
        $authorization = $this->buildAuthorization();
        $sections = $this->sectionService->getAllWithBranches();
        $sectionId = $authorization->forcedSenderSectionId ?? (int) ($sections[0]['id'] ?? 0);

        $list = '';
        foreach ($this->buildListOptions($authorization, '') as $option) {
            if (!$option['disabled']) {
                $list = $option['value'];
                break;
            }
        }

        return [
            'section_id' => $sectionId,
            'list' => $list,
            'scout_year_ids' => [$this->scoutYearService->getCurrentYear()['id']],
            'subject' => '',
            'body_html' => '',
            'audience_id' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formFromRequest(Request $request): array
    {
        $audienceId = (string) $request->getBody('audience_id', '');

        return [
            'section_id' => (int) $request->getBody('section_id', 0),
            'list' => (string) $request->getBody('list', ''),
            'scout_year_ids' => $this->toIntArray($request->getBody('scout_year_ids', [])),
            'subject' => (string) $request->getBody('subject', ''),
            'body_html' => (string) $request->getBody('body_html', ''),
            'audience_id' => $audienceId !== '' ? (int) $audienceId : null,
        ];
    }

    /**
     * The list an email targets, in the words the list picker uses —
     * for a screen that only reads it.
     */
    private function describeList(Email $email): string
    {
        if ($email->listType === Email::LIST_TYPE_MAIL_MERGE) {
            return 'Publipostage — fichier Excel';
        }

        foreach ($this->mailingListService->getDefaultLists() as $list) {
            if ($list['list_type'] === $email->listType && $list['list_section_id'] === $email->listSectionId) {
                return $list['label'];
            }
        }
        foreach ($this->mailingListService->getActiveCustomLists() as $list) {
            if ($email->listType === Email::LIST_TYPE_CUSTOM && $list->id === $email->listId) {
                return $list->name;
            }
        }

        return 'Liste personnalisée';
    }

    /**
     * @return string[]
     */
    private function scoutYearLabels(Email $email): array
    {
        $byId = array_column($this->scoutYearService->getAll(), 'label', 'id');

        return array_values(array_map(
            static fn(int $id): string => (string) ($byId[$id] ?? '?'),
            $email->scoutYearIds
        ));
    }

    /**
     * @return array{
     *     previous: array{id: int, label: string, available: bool, warning: ?string},
     *     current: array{id: int, label: string, available: bool, warning: ?string},
     *     next: array{id: int, label: string, available: bool, warning: ?string}
     * }
     */
    private function buildScoutYearOptions(): array
    {
        $current = $this->scoutYearService->getCurrentYear();
        $previousLabel = ScoutYearService::previousLabel($current['label']);
        $nextLabel = ScoutYearService::nextLabel($current['label']);
        $nextId = $this->scoutYearService->ensureYear($nextLabel);

        // A future scout year used to be selectable ONLY once Desk had been
        // imported for it (module addendum) — there was nothing to send to
        // otherwise. With the registration module enabled there now is: the
        // projection knows who is expected next year long before Desk does
        // (Modules\Registration\Api\ProjectedPopulationProvider), which is
        // exactly what the warning below is about. Without that module the
        // old rule stands unchanged.
        // Computing the projected audience means running the registration
        // module's whole projection (every animé of the year, decrypted);
        // an import for next year already makes it available, so the
        // projection is only asked when nothing cheaper answers.
        $nextImported = $this->importJournalRepository->findByYear($nextId) !== [];
        $nextIsProjected = !$nextImported
            && $this->mailingListService->futureAudienceWarning($nextId) !== null
            && $this->mailingListService->resolveMembers('default_active_members', null, null, $nextId) !== [];

        return [
            'previous' => [
                'id' => $this->scoutYearService->ensureYear($previousLabel),
                'label' => $previousLabel,
                'available' => true,
                'warning' => null
            ],
            'current' => ['id' => $current['id'], 'label' => $current['label'], 'available' => true, 'warning' => null],
            'next' => [
                'id' => $nextId,
                'label' => $nextLabel,
                'available' => $nextImported || $nextIsProjected,
                // Null for the current year and for any past one: a warning
                // shown on every ordinary send is a warning nobody reads.
                'warning' => $this->mailingListService->futureAudienceWarning($nextId),
            ],
        ];
    }

    /**
     * Whether the current account is a chef d'unité (role 'admin') or
     * above (unrestricted), and if not, which section(s) they may target
     * a mailing list for and which single section they must send from
     * (their highest-role linked member's own section — same resolution
     * as Core\View\SectionPickerHelper's "default section", reused here
     * as a hard lock rather than a mere pre-fill). Resolved against the
     * account's CURRENT real section membership regardless of which scout
     * year the email itself targets — a chief's authorization follows who
     * they are today, not a hypothetical future assignment.
     */
    private function buildAuthorization(): SenderAuthorization
    {
        if (Role::fromString(AuthSession::getRole())->hasAccess(Role::ADMIN)) {
            return new SenderAuthorization(true, [], null);
        }

        $email = AuthSession::getEmail() ?? '';
        $currentYearId = $this->scoutYearService->getCurrentYear()['id'];

        $userSectionIds = $this->massMailAccessService->getUserSectionIds($email, $currentYearId);
        $linkedMembers = $this->memberService->getLinkedMembers($email, $currentYearId);
        $forcedSectionId = SectionPickerHelper::resolveDefault(null, $linkedMembers,
            $this->sectionService->getAllWithBranches());

        return new SenderAuthorization(false, $userSectionIds, $forcedSectionId);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAudience(\Modules\MassMail\Repository\Audience $audience): array
    {
        return [
            'id' => $audience->id,
            'filename' => $audience->sourceFilename,
            'sheet_name' => $audience->sheetName,
            'columns' => $audience->columns,
            'row_count' => $audience->rowCount,
            'created_at' => $audience->createdAt,
        ];
    }

    /**
     * @return int[]
     */
    private function toIntArray(mixed $value): array
    {
        return is_array($value) ? array_map('intval', $value) : [];
    }
}
