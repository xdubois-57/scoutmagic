<?php

declare(strict_types=1);

namespace Modules\MassMail\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\File\UploadException;
use Core\File\UploadHandler;
use Core\Http\Controller\AbstractController;
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
use Modules\MassMail\Service\MailingListService;
use Modules\MassMail\Service\MassMailAccessService;
use Modules\MassMail\Service\MassMailException;
use Modules\MassMail\Service\MassMailService;
use Modules\MassMail\Service\SenderAuthorization;
use Twig\Environment;

class MassMailController extends AbstractController
{
    private const ATTACHMENT_ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private const ATTACHMENT_MAX_SIZE_BYTES = 10 * 1024 * 1024;
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
        private FileRepository $fileRepository
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

        $customLists = $this->mailingListService->getActiveCustomLists();
        $customListsById = [];
        foreach ($customLists as $list) {
            $customListsById[$list->id] = $list->name;
        }

        $scoutYearsById = array_column($this->scoutYearService->getAll(), 'label', 'id');
        $authorization = $this->buildAuthorization();

        return $this->render('@mass_mail/list.html.twig', [
            'emails' => $result['emails'],
            'recipient_counts' => $recipientCounts,
            'scout_years_by_id' => $scoutYearsById,
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
            'custom_lists' => $customLists,
            'custom_lists_by_id' => $customListsById,
            'default_lists' => $this->mailingListService->getDefaultLists(),
            'scout_years' => $this->buildScoutYearOptions(),
            'current_user_email' => AuthSession::getEmail() ?? '',
            'unrestricted' => $authorization->isChefDUniteOrAbove,
            'user_section_ids' => $authorization->allowedListSectionIds,
            'forced_section_id' => $authorization->forcedSenderSectionId,
            'previous_year_cutoff' => (string) ($this->settingService->get(self::SETTING_PREVIOUS_YEAR_CUTOFF, 'mass_mail') ?: self::DEFAULT_PREVIOUS_YEAR_CUTOFF),
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * GET /mass-mail/{id} — email + attachments as JSON, for the
     * create/edit dialog.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $email = $this->massMailService->findById((int) $params['id']);
        if ($email === null) {
            return $this->json(['success' => false, 'error' => 'Email introuvable.'], 404);
        }

        $attachments = array_map(function ($a) {
            $file = $this->fileRepository->findById($a->fileId);
            return ['id' => $a->id, 'file_id' => $a->fileId, 'name' => $file?->originalName ?? '?'];
        }, $this->massMailService->getAttachments($email->id));

        return $this->json([
            'success' => true,
            'email' => $this->serializeEmail($email),
            'attachments' => $attachments,
            'counts' => $this->massMailService->getStatusCounts($email->id),
        ]);
    }

    /**
     * POST /mass-mail — create a draft.
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $email = $this->massMailService->createDraft(
                (string) ($data['subject'] ?? ''),
                (string) ($data['body_html'] ?? ''),
                (int) ($data['section_id'] ?? 0),
                (string) ($data['list_type'] ?? ''),
                isset($data['list_id']) && $data['list_id'] !== '' ? (int) $data['list_id'] : null,
                isset($data['list_section_id']) && $data['list_section_id'] !== '' ? (int) $data['list_section_id'] : null,
                $this->toIntArray($data['scout_year_ids'] ?? []),
                AuthSession::getUserAccountId(),
                $this->buildAuthorization()
            );
        } catch (MassMailException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'email' => $this->serializeEmail($email)]);
    }

    /**
     * PATCH /mass-mail/{id} — update a draft.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $email = $this->massMailService->updateDraft(
                (int) $params['id'],
                (string) ($data['subject'] ?? ''),
                (string) ($data['body_html'] ?? ''),
                (int) ($data['section_id'] ?? 0),
                (string) ($data['list_type'] ?? ''),
                isset($data['list_id']) && $data['list_id'] !== '' ? (int) $data['list_id'] : null,
                isset($data['list_section_id']) && $data['list_section_id'] !== '' ? (int) $data['list_section_id'] : null,
                $this->toIntArray($data['scout_year_ids'] ?? []),
                $this->buildAuthorization()
            );
        } catch (MassMailException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'email' => $this->serializeEmail($email)]);
    }

    /**
     * POST /mass-mail/{id}/status — draft→test, test→draft, or test→sending
     * depending on {action: 'to_test'|'to_draft'|'start_sending'}.
     *
     * @param array<string, string> $params
     */
    public function changeStatus(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $id = (int) $params['id'];
        $actorId = AuthSession::getUserAccountId();
        $action = (string) ($data['action'] ?? '');

        try {
            $email = match ($action) {
                'to_test' => $this->massMailService->moveToTest($id, $actorId),
                'to_draft' => $this->massMailService->backToDraft($id, $actorId),
                'start_sending' => $this->massMailService->startSending($id, $actorId),
                default => throw new MassMailException('Action inconnue.'),
            };
        } catch (MassMailException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'email' => $this->serializeEmail($email)]);
    }

    /**
     * POST /mass-mail/{id}/test-send — send a one-off test copy.
     *
     * @param array<string, string> $params
     */
    public function testSend(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $this->massMailService->sendTestEmail((int) $params['id'], (string) ($data['to'] ?? ''));
        } catch (MassMailException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (MailException $e) {
            return $this->json(['success' => false, 'error' => 'Échec de l\'envoi : ' . $e->getMessage()], 500);
        }

        return $this->json(['success' => true]);
    }

    /**
     * POST /mass-mail/{id}/attachments — upload one attachment (multipart).
     *
     * @param array<string, string> $params
     */
    public function uploadAttachment(Request $request, array $params): Response
    {
        $csrf = (string) $request->getBody('_csrf_token', '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        $emailId = (int) $params['id'];
        $uploadedFile = $request->getFile('file');
        if ($uploadedFile === null) {
            return $this->json(['success' => false, 'error' => 'Aucun fichier envoyé.'], 400);
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
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'file_id' => $fileId]);
    }

    /**
     * DELETE /mass-mail/attachments/{id}
     *
     * @param array<string, string> $params
     */
    public function deleteAttachment(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $this->massMailService->removeAttachment((int) $params['id']);
        } catch (MassMailException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true]);
    }

    /**
     * GET /mass-mail/{id}/tracking — detailed per-recipient tracking page.
     * Not in any menu (module.json label: "") — reached only via the list
     * page's chart button or the dialog's "Voir le suivi détaillé" link.
     *
     * @param array<string, string> $params
     */
    public function tracking(Request $request, array $params): Response
    {
        try {
            $data = $this->massMailService->getTrackingData((int) $params['id']);
        } catch (MassMailException $e) {
            return new Response('Not Found', 404);
        }

        return $this->render('@mass_mail/tracking.html.twig', [
            'email' => $data['email'],
            'counts' => $data['counts'],
            'recipients' => $data['recipients'],
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
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
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
     * @return array{previous: array{id: int, label: string, available: bool}, current: array{id: int, label: string, available: bool}, next: array{id: int, label: string, available: bool}}
     */
    private function buildScoutYearOptions(): array
    {
        $current = $this->scoutYearService->getCurrentYear();
        $previousLabel = ScoutYearService::previousLabel($current['label']);
        $nextLabel = ScoutYearService::nextLabel($current['label']);
        $nextId = $this->scoutYearService->ensureYear($nextLabel);

        return [
            'previous' => ['id' => $this->scoutYearService->ensureYear($previousLabel), 'label' => $previousLabel, 'available' => true],
            'current' => ['id' => $current['id'], 'label' => $current['label'], 'available' => true],
            // A future scout year is only selectable once Desk has actually
            // been imported for it (module addendum) — nothing to send to
            // otherwise.
            'next' => ['id' => $nextId, 'label' => $nextLabel, 'available' => $this->importJournalRepository->findByYear($nextId) !== []],
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
        $forcedSectionId = SectionPickerHelper::resolveDefault(null, $linkedMembers, $this->sectionService->getAllWithBranches());

        return new SenderAuthorization(false, $userSectionIds, $forcedSectionId);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEmail(Email $email): array
    {
        return [
            'id' => $email->id,
            'subject' => $email->subject,
            'body_html' => $email->bodyHtml,
            'section_id' => $email->sectionId,
            'list_type' => $email->listType,
            'list_id' => $email->listId,
            'list_section_id' => $email->listSectionId,
            'scout_year_ids' => $email->scoutYearIds,
            'status' => $email->status,
            'sent_at' => $email->sentAt,
        ];
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

    /**
     * @return int[]
     */
    private function toIntArray(mixed $value): array
    {
        return is_array($value) ? array_map('intval', $value) : [];
    }
}
