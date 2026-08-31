<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Modules\Camps\Mail\CampsMessageConsumer;
use Modules\Camps\Mail\MailFieldCompletionService;
use Modules\Camps\Repository\FieldProposalRepository;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Service\CampLabels;
use Modules\InboundMail\Api\InboundMailInterface;
use Twig\Environment;

/**
 * « Courrier des camps » — the business triage list, this module's view of
 * it (§8.58, IT-07).
 *
 * **It used to be « Courrier non classé »**, backed by a reserved
 * `unsorted` business reference: a bucket masquerading as a stay, with its
 * own retention, its own purge task and its own screen, all duplicating
 * what `inbound_mail` now does once for every module. The reference is
 * gone; what a dedicated box collects and nobody could attribute is simply
 * stored like every other message, and this screen reads it through
 * `InboundMailInterface::findForTriage()` — the same call every other
 * consumer will make.
 *
 * The list is therefore what a chief can *do something about*: what this
 * module attached to a stay, what it merely proposed, and — on a box the
 * superadmin declared dedicated to camps — everything else that box holds.
 * A shared box contributes only what concerns camps, which is exactly the
 * point of the configuration screen.
 *
 * Reachable from the camps list rather than from a menu entry of its own:
 * an installation with no mailbox never has anything here, and a permanent
 * menu entry that is permanently empty teaches people to ignore it.
 */
class CampsMailController extends AbstractController
{
    /** How much of a message the card shows before it has to be opened. */
    private const EXCERPT_LENGTH = 220;

    /**
     * One screenful. A dedicated box that has been collecting for three
     * years holds thousands of messages, and a page that renders all of
     * them is a page nobody waits for. The Chef d'Unité's own screen
     * (`/courrier`) is the one with pagination; this one is a work list.
     */
    private const MAX_MESSAGES = 100;

    public function __construct(
        protected Environment $twig,
        private CampRepository $camps,
        private PlaceRepository $places,
        private ?InboundMailInterface $inboundMail = null,
        private ?FieldProposalRepository $proposals = null,
        private ?MailFieldCompletionService $fieldCompletion = null
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function unsorted(Request $request, array $params): Response
    {
        $rows = $this->messages();

        return $this->render('@camps/unsorted_mail.html.twig', [
            'messages' => $rows,
            'camp_options' => $this->campOptions(),
            'has_inbound_mail' => $this->inboundMail !== null && $this->inboundMail->isCollecting(),
            'breadcrumb_current' => 'Courrier des camps',
            'breadcrumb_trail' => [['label' => 'Camps', 'url' => '/chefs/camps']],
        ]);
    }

    /**
     * Confirm one of this module's propositions.
     *
     * Through `InboundMailInterface`, which re-checks that the proposition
     * belongs to this consumer AND targets a stay this caller may reach —
     * the controller's own list is a convenience, never the guard.
     *
     * @param array<string, string> $params
     */
    public function confirmProposition(Request $request, array $params): Response
    {
        return $this->decideProposition($request, $params, true);
    }

    /**
     * @param array<string, string> $params
     */
    public function dismissProposition(Request $request, array $params): Response
    {
        return $this->decideProposition($request, $params, false);
    }

    /**
     * @param array<string, string> $params
     */
    private function decideProposition(Request $request, array $params, bool $confirm): Response
    {
        if (($guard = $this->guardCsrf($request, '/chefs/camps/courrier')) !== null) {
            return $guard;
        }

        if ($this->inboundMail === null) {
            return $this->notFound();
        }

        $messageId = (int) ($params['id'] ?? 0);
        $candidateId = (int) $request->getBody('candidate_id', 0);
        $references = $this->stayReferences();

        $done = $confirm
            ? $this->inboundMail->confirmCandidate(
                CampsMessageConsumer::CONSUMER_ID,
                $references,
                $messageId,
                $candidateId,
                AuthSession::getUserAccountId()
            )
            : $this->inboundMail->dismissCandidate(
                CampsMessageConsumer::CONSUMER_ID,
                $references,
                $messageId,
                $candidateId
            );

        FlashMessage::set(
            $done ? 'success' : 'error',
            $done
                ? ($confirm ? 'Message rattaché au séjour.' : 'Proposition écartée.')
                : 'Cette proposition n\'existe plus.'
        );

        return $this->redirect('/chefs/camps/courrier');
    }

    /**
     * Every stay this module has, as references.
     *
     * A chief may reach all of them — a stay has no manager list of its
     * own, and `canRead()` says the same thing. The list exists so that
     * `inbound_mail` can scope without knowing what a stay is.
     *
     * @return string[]
     */
    private function stayReferences(): array
    {
        $references = [];
        foreach ($this->places->findAllVisible() as $place) {
            foreach ($this->camps->findByPlace($place->id) as $camp) {
                $references[] = CampsMessageConsumer::referenceFor($camp->id);
            }
        }

        return $references;
    }

    /**
     * Moves a message onto a stay.
     *
     * A plain move() between two references of this consumer — no
     * extension to inbound_mail was needed for any of this, which is what
     * the reserved 'unsorted' reference buys.
     *
     * @param array<string, string> $params
     */
    public function attach(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/chefs/camps/courrier')) !== null) {
            return $guard;
        }
        $messageId = (int) ($params['id'] ?? 0);
        $campId = (int) $request->getBody('camp_id', 0);

        if ($this->inboundMail === null || $campId <= 0 || $this->camps->findById($campId) === null) {
            FlashMessage::set('error', 'Choisissez le séjour auquel rattacher ce message.');

            return $this->redirect('/chefs/camps/courrier');
        }

        // An association, not a move: there is no `unsorted` reference to
        // move the message OFF any more, and a message that is already on
        // another stay is being corrected rather than relocated — which
        // the chief does by detaching it from that stay.
        $moved = $this->inboundMail->attach(
            CampsMessageConsumer::CONSUMER_ID,
            CampsMessageConsumer::referenceFor($campId),
            $messageId,
            AuthSession::getUserAccountId()
        );

        FlashMessage::set(
            $moved ? 'success' : 'error',
            $moved ? 'Message rattaché au séjour.' : 'Ce message n\'a pas pu être rattaché.'
        );

        return $this->redirect($moved ? '/chefs/camps/sejours/' . $campId : '/chefs/camps/courrier');
    }

    /**
     * @param array<string, string> $params
     */
    public function discard(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/chefs/camps/courrier')) !== null) {
            return $guard;
        }

        if ($this->inboundMail !== null) {
            // detach() does not destroy the message: it falls back into the
            // unit's general mail, where a chef d'unité can still re-orient
            // it and where inbound_mail's own retention removes it. The
            // wording says what actually happens.
            $this->inboundMail->detach(
                CampsMessageConsumer::CONSUMER_ID,
                (string) $request->getBody('business_reference', ''),
                (int) ($params['id'] ?? 0)
            );
            FlashMessage::set('success', 'Message retiré de ce séjour.');
        }

        return $this->redirect('/chefs/camps/courrier');
    }

    /**
     * @param array<string, string> $params
     */
    public function applyProposal(Request $request, array $params): Response
    {
        return $this->decideProposal($request, $params, true);
    }

    /**
     * @param array<string, string> $params
     */
    public function dismissProposal(Request $request, array $params): Response
    {
        return $this->decideProposal($request, $params, false);
    }

    /**
     * @param array<string, string> $params
     */
    private function decideProposal(Request $request, array $params, bool $accept): Response
    {
        if ($this->proposals === null || $this->fieldCompletion === null) {
            return $this->notFound();
        }
        $proposal = $this->proposals->findById((int) ($params['id'] ?? 0));
        if ($proposal === null) {
            return $this->notFound();
        }

        $target = '/chefs/camps/sejours/' . $proposal->campId;
        if (($guard = $this->guardCsrf($request, $target)) !== null) {
            return $guard;
        }

        $actorId = AuthSession::getUserAccountId();
        if ($accept) {
            $this->fieldCompletion->accept($proposal, $actorId);
            FlashMessage::set('success', 'Information appliquée au séjour.');
        } else {
            // Recorded too: six months later somebody will ask why the
            // page does not say what the mail says.
            $this->fieldCompletion->dismiss($proposal, $actorId);
            FlashMessage::set('success', 'Information ignorée.');
        }

        return $this->redirect($target);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function messages(): array
    {
        if ($this->inboundMail === null) {
            return [];
        }

        $messages = $this->inboundMail->findForTriage(
            CampsMessageConsumer::CONSUMER_ID,
            $this->stayReferences(),
            self::MAX_MESSAGES
        );

        $candidates = $this->inboundMail->findCandidatesFor(
            CampsMessageConsumer::CONSUMER_ID,
            array_map(static fn($message) => $message->id, $messages)
        );

        $rows = [];
        foreach ($messages as $message) {
            $body = trim($message->bodyText);
            $rows[] = [
                'message' => $message,
                'excerpt' => mb_substr($body, 0, self::EXCERPT_LENGTH),
                // Whether the excerpt actually cut something off, so the
                // screen can promise "there is more" only when there is.
                'truncated' => mb_strlen($body) > self::EXCERPT_LENGTH,
                'has_body' => $body !== '' || trim($message->bodyHtml) !== '',
                'attachment_count' => count($message->attachments),
                // Only THIS module's links and propositions. Another
                // module's business on the same message is not this
                // screen's, and showing it would leak one module's guesses
                // into another's audience.
                'links' => $message->linksFor(CampsMessageConsumer::CONSUMER_ID),
                'candidates' => $candidates[$message->id] ?? [],
            ];
        }

        return $rows;
    }

    /**
     * Every stay, most recent first — the picker for "rattacher à un
     * camp". Deliberately not filtered to upcoming ones: a message about
     * a camp two summers ago is exactly the kind of thing that ends up
     * unsorted.
     *
     * @return array<int, array{value: string, label: string, selected: bool}>
     */
    private function campOptions(): array
    {
        $options = [['value' => '', 'label' => 'Choisir un séjour…', 'selected' => true]];
        foreach ($this->places->findAllVisible() as $place) {
            foreach ($this->camps->findByPlace($place->id) as $camp) {
                $options[] = [
                    'value' => (string) $camp->id,
                    'label' => $place->name . ' — '
                        . CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly),
                    'selected' => false,
                ];
            }
        }

        return $options;
    }
}
