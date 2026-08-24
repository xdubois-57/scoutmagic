<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Controller;

use Core\Config\SettingService;
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
 * "Courrier non classé" — what a dedicated mailbox collected and nobody
 * could attribute to a stay.
 *
 * Reachable from the camps list rather than from a menu entry of its own:
 * an installation with no dedicated mailbox never has anything here, and
 * a permanent menu entry that is permanently empty teaches people to
 * ignore it.
 */
class CampsMailController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private CampRepository $camps,
        private PlaceRepository $places,
        private SettingService $settings,
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
        return $this->render('@camps/unsorted_mail.html.twig', [
            'messages' => $this->messages(),
            'camp_options' => $this->campOptions(),
            'retention_months' => (int) ($this->settings->get('camps_unsorted_retention_months', 'camps', '6') ?? 6),
            'has_dedicated_mailbox' => $this->settings->get('camps_dedicated_mailbox_ids', 'camps', '') !== '',
            'breadcrumb_current' => 'Courrier non classé',
            'breadcrumb_trail' => [['label' => 'Camps', 'url' => '/chefs/camps']],
        ]);
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

        $moved = $this->inboundMail->move(
            CampsMessageConsumer::CONSUMER_ID,
            CampsMessageConsumer::UNSORTED_REFERENCE,
            CampsMessageConsumer::referenceFor($campId),
            $messageId
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
            // detach() from 'unsorted' deletes, per inbound_mail's own
            // semantics: there is no unattached queue to fall back into,
            // and 'unsorted' IS the fallback.
            $this->inboundMail->detach(
                CampsMessageConsumer::CONSUMER_ID,
                CampsMessageConsumer::UNSORTED_REFERENCE,
                (int) ($params['id'] ?? 0)
            );
            FlashMessage::set('success', 'Message supprimé.');
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
            return (new Response('', 404))->setBody('Not Found');
        }
        $proposal = $this->proposals->findById((int) ($params['id'] ?? 0));
        if ($proposal === null) {
            return (new Response('', 404))->setBody('Not Found');
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

        $rows = [];
        foreach ($this->inboundMail->findForReference(
            CampsMessageConsumer::CONSUMER_ID,
            CampsMessageConsumer::UNSORTED_REFERENCE
        ) as $message) {
            $rows[] = [
                'message' => $message,
                'excerpt' => mb_substr(trim($message->bodyText), 0, 220),
                'attachment_count' => count($message->attachments),
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
