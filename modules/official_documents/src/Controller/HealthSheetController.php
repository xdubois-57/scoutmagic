<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\MemberProfile;
use Core\Security\AuthSession;
use Modules\OfficialDocuments\Api\OfficialDocumentsException;
use Modules\OfficialDocuments\Pdf\HealthSheetLayout;
use Modules\OfficialDocuments\Security\OwnMemberOnly;
use Modules\OfficialDocuments\Service\HealthSheetPdfService;
use Modules\OfficialDocuments\Service\HealthSheetService;
use Modules\OfficialDocuments\Value\HealthSheet;
use Twig\Environment;

/**
 * The health sheet: one screen, one save, one « Tout effacer ».
 *
 * `role_min: identified` is never the real protection here — see
 * `Modules\OfficialDocuments\Security\OwnMemberOnly`, which every action
 * goes through and which is where the self-only rule actually lives.
 *
 * **Nothing in this class puts a health answer in a message.** A refusal
 * names nobody, a save says only that it saved, and there is no validation
 * that could echo a value back: every field is optional, so there is
 * nothing to refuse (`HealthSheet`). That is not an omission to be tidied
 * up later — it is the chantier's rule, and the reason this controller is
 * as dull as it is.
 */
class HealthSheetController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private OwnMemberOnly $access,
        private HealthSheetService $sheets,
        private HealthSheetPdfService $pdfService
    ) {
    }

    /**
     * GET /members/{id}/fiche-sante
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $memberYearId = (int) $params['id'];
        $member = $this->access->profileFor($memberYearId);
        if ($member === null) {
            return $this->forbidden(OwnMemberOnly::REFUSAL, $request);
        }

        return $this->screen($member, null);
    }

    /**
     * POST /members/{id}/fiche-sante
     *
     * Saves whatever was typed, including nothing. A redirect back to the
     * screen rather than a re-render: there is no refusal to report, so
     * the only thing a re-render would buy is a page a reload would
     * re-submit.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        $memberYearId = (int) $params['id'];
        $member = $this->access->profileFor($memberYearId);
        if ($member === null) {
            return $this->forbidden(OwnMemberOnly::REFUSAL, $request);
        }

        $path = '/members/' . $memberYearId . '/fiche-sante';
        if (($guard = $this->guardCsrf($request, $path)) !== null) {
            return $guard;
        }

        $this->sheets->save(
            $member->memberId,
            HealthSheet::fromBody($request->getBodyAll()),
            new \DateTimeImmutable('now')
        );

        FlashMessage::set('success', 'Fiche santé enregistrée.');

        return $this->redirect($path);
    }

    /**
     * POST /members/{id}/fiche-sante/effacer
     *
     * A plain dialog guards this on the page, not the retyped keyword the
     * Maintenance screen asks for: that one protects a whole installation,
     * this one is a family acting on their own data.
     *
     * @param array<string, string> $params
     */
    public function clear(Request $request, array $params): Response
    {
        $memberYearId = (int) $params['id'];
        $member = $this->access->profileFor($memberYearId);
        if ($member === null) {
            return $this->forbidden(OwnMemberOnly::REFUSAL, $request);
        }

        $path = '/members/' . $memberYearId . '/fiche-sante';
        if (($guard = $this->guardCsrf($request, $path)) !== null) {
            return $guard;
        }

        $this->sheets->clear($member->memberId, AuthSession::getUserAccountId());

        // The same sentence whether there was a sheet or not. « Il n'y
        // avait rien à effacer » would be the site telling whoever is at
        // the keyboard something about this child's record.
        FlashMessage::set('success', 'Fiche santé effacée.');

        return $this->redirect($path);
    }

    /**
     * POST /members/{id}/fiche-sante/pdf — answer the filled-in form.
     *
     * A POST and not a GET, for the reason the parental authorization is
     * one: a query string is what a proxy log, a browser history and a
     * `Referer` header keep, and everything on this document is a child's
     * health.
     *
     * Nothing is saved here. The sheet that gets drawn is the sheet on
     * file, so a family that edited the form without saving downloads what
     * they last saved — which is the same document they would get
     * tomorrow, and the only one this action can honestly produce.
     *
     * @param array<string, string> $params
     */
    public function download(Request $request, array $params): Response
    {
        $memberYearId = (int) $params['id'];
        $member = $this->access->profileFor($memberYearId);
        if ($member === null) {
            return $this->forbidden(OwnMemberOnly::REFUSAL, $request);
        }

        $path = '/members/' . $memberYearId . '/fiche-sante';
        if (($guard = $this->guardCsrf($request, $path)) !== null) {
            return $guard;
        }

        try {
            $result = $this->pdfService->render($member, $this->sheets->forMember($member->memberId));
        } catch (OfficialDocumentsException $e) {
            // Back to the screen with its message rather than a redirect:
            // there is nothing to re-type here, but a flash that survives
            // one request is a worse place for « prévenez votre chef
            // d'unité » than the page it is about.
            return $this->screen($member, $e->getMessage());
        }

        // Printing IS using the sheet, so the retention clock restarts —
        // a family that downloads their form every September has not
        // abandoned it (IT-05's purge reads this same date).
        $this->sheets->markUsed($member->memberId, new \DateTimeImmutable('now'));

        return (new Response($result['pdf']))
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="fiche-sante-' . $memberYearId . '.pdf"')
            // Nothing about this document is cacheable.
            ->setHeader('Cache-Control', 'private, no-store');
    }

    /**
     * Overflowing answers as the words the parent has in front of them.
     *
     * The template is handed French sentences and nothing else: a name that
     * reached it unmapped would print `contact2_email` on a page a family
     * reads. Anything without a label is dropped rather than shown raw —
     * and `HealthSheetLabelsTest` is what makes that case impossible rather
     * than merely silent.
     *
     * @param array<int, string> $names
     * @return list<string>
     */
    private static function readable(array $names): array
    {
        $labels = [];
        foreach ($names as $name) {
            $label = HealthSheet::LABELS[$name] ?? null;
            if ($label !== null) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * The screen, with whatever it has to say today.
     *
     * The overflow list is the one piece of state the page carries beyond
     * the sheet itself: the federation's form gives « allergies » two
     * printed lines and no more, and a parent who wrote four has to be
     * told HERE rather than discover it on paper. Answered as the names
     * the form fields carry, so the template can point at the right box.
     *
     * The generation is run on every visit for exactly that reason — the
     * warning has to be there before somebody clicks, not after. It costs
     * one in-memory render of a two-page PDF and writes nothing anywhere.
     */
    private function screen(MemberProfile $member, ?string $error): Response
    {
        // Read once: every read of a sheet is a pass through the cipher.
        $sheet = $this->sheets->forMember($member->memberId);

        $overflowing = [];
        if ($error === null) {
            try {
                // Only what the family can DO something about. The same
                // overflow-checked path writes the member's own street and
                // e-mail address, which this screen does not expose:
                // telling a parent to shorten one of those would be telling
                // them to shorten something they cannot reach. The value is
                // written either way — cramped rather than dropped — so
                // what it costs is a tight line on an otherwise correct
                // form. What a chef would change, they change in Desk.
                $overflowing = self::readable(array_diff(
                    $this->pdfService->render($member, $sheet)['overflowing'],
                    HealthSheetLayout::siteSuppliedNames()
                ));
            } catch (OfficialDocumentsException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('@official_documents/health_sheet.html.twig', [
            'member' => $member,
            'sheet' => $sheet->toArray(),
            'conditions' => HealthSheet::CONDITIONS,
            'last_used_at' => $this->sheets->lastUsedAt($member->memberId),
            'overflowing' => $overflowing,
            'document_error' => $error,
            'breadcrumb_trail' => [
                ['label' => $member->getDisplayName(), 'url' => '/members/' . $member->memberYearId],
            ],
        ]);
    }
}
