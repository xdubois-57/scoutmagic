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
use Core\Security\AuthSession;
use Modules\OfficialDocuments\Security\OwnMemberOnly;
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
        private HealthSheetService $sheets
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

        return $this->render('@official_documents/health_sheet.html.twig', [
            'member' => $member,
            'sheet' => $this->sheets->forMember($member->memberId)->toArray(),
            'conditions' => HealthSheet::CONDITIONS,
            'last_used_at' => $this->sheets->lastUsedAt($member->memberId),
            'breadcrumb_trail' => [
                ['label' => $member->getDisplayName(), 'url' => '/members/' . $member->memberYearId],
            ],
        ]);
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
}
