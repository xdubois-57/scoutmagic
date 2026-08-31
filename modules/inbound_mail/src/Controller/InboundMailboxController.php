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
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\InboundMail\Service\GeneralMailboxService;
use Modules\InboundMail\Service\InboundMailService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use Twig\Environment;

/**
 * `/courrier` — the unit's whole mail, for the **Chef d'Unité and nobody
 * else**.
 *
 * This screen is one of the three things that make storing every message
 * defensible (§8.58). The other two are the retention that removes what
 * nothing points at, and the fact that exactly one role answers for the
 * archive — which is this route's `role_min`, and why there is no second
 * route here at a lower one.
 *
 * **Nothing here searches the content.** The filters are metadata only
 * (D16): mailbox, whether the message is associated, whether it is
 * automatic. Searching a subject or a body would mean either decrypting
 * the whole table on every keystroke or keeping a plaintext index of
 * everything anybody ever wrote to the unit, and the second is how an
 * archive with a retention becomes an archive without one.
 */
class InboundMailboxController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private GeneralMailboxService $mailbox,
        private InboundMailService $inboundMail,
        private MessageConsumerRegistry $consumers,
        private JournalService $journal
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $association = (string) $request->getQuery('association', 'none');
        if (!in_array($association, ['none', 'some', 'all'], true)) {
            $association = 'none';
        }

        $mailboxId = (int) $request->getQuery('boite', 0);
        $includeBulk = (string) $request->getQuery('automatique', '') === '1';

        $page = $this->mailbox->page(
            [
                'mailbox_id' => $mailboxId > 0 ? $mailboxId : null,
                'association' => $association,
                'include_bulk' => $includeBulk,
            ],
            GeneralMailboxService::decodeCursor((string) $request->getQuery('apres', ''))
        );

        return $this->render('@inbound_mail/mailbox/index.html.twig', [
            'messages' => $page['messages'],
            'next_cursor' => $page['next_cursor'],
            'mailboxes' => $this->mailbox->mailboxNames(),
            'consumer_names' => $this->consumerNames(),
            'candidates' => $this->candidatesFor($page['messages']),
            'filter_association' => $association,
            'filter_mailbox' => $mailboxId,
            'include_bulk' => $includeBulk,
            'bulk_count' => $this->mailbox->bulkCount(),
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $message = $this->mailbox->find((int) ($params['id'] ?? 0));
        if ($message === null) {
            return $this->notFound();
        }

        return $this->render('@inbound_mail/mailbox/show.html.twig', [
            'message' => $message,
            'candidates' => $this->mailbox->candidatesFor($message->id),
            'consumer_names' => $this->consumerNames(),
            'mailboxes' => $this->mailbox->mailboxNames(),
            'breadcrumb_trail' => [['label' => 'Courrier', 'url' => '/courrier']],
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function confirmCandidate(Request $request, array $params): Response
    {
        $messageId = (int) ($params['id'] ?? 0);
        $back = '/courrier/' . $messageId;

        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }

        $done = $this->mailbox->confirmCandidate(
            $messageId,
            (int) $request->getBody('candidate_id', 0),
            AuthSession::getUserAccountId()
        );

        if ($done) {
            // Internal ids only. Never the sender, the subject, the target's
            // label or a word of what anybody wrote (§7.9).
            $this->journal->log(
                'inbound_mail',
                'inbound_candidate_confirmed',
                'info',
                'Proposition de rattachement confirmée',
                ['message_id' => $messageId],
                null
            );
        }

        FlashMessage::set(
            $done ? 'success' : 'error',
            $done ? 'Message associé.' : 'Cette proposition n\'existe plus.'
        );

        return $this->redirect($back);
    }

    /**
     * @param array<string, string> $params
     */
    public function dismissCandidate(Request $request, array $params): Response
    {
        $messageId = (int) ($params['id'] ?? 0);
        $back = '/courrier/' . $messageId;

        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }

        $done = $this->mailbox->dismissCandidate($messageId, (int) $request->getBody('candidate_id', 0));

        FlashMessage::set(
            $done ? 'success' : 'error',
            $done ? 'Proposition écartée.' : 'Cette proposition n\'existe plus.'
        );

        return $this->redirect($back);
    }

    /**
     * Remove one association. The message stays — detaching is almost
     * always a correction, and destroying what is being corrected makes
     * re-filing it impossible (§8.58).
     *
     * @param array<string, string> $params
     */
    public function detach(Request $request, array $params): Response
    {
        $messageId = (int) ($params['id'] ?? 0);
        $back = '/courrier/' . $messageId;

        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }

        $done = $this->inboundMail->detach(
            (string) $request->getBody('consumer_id', ''),
            (string) $request->getBody('business_reference', ''),
            $messageId
        );

        if ($done) {
            $this->journal->log(
                'inbound_mail',
                'inbound_link_removed',
                'info',
                'Association retirée depuis le courrier de l\'unité',
                ['message_id' => $messageId],
                null
            );
        }

        FlashMessage::set(
            $done ? 'success' : 'error',
            $done
                ? 'Association retirée. Le message reste dans le courrier de l\'unité.'
                : 'Cette association n\'existe plus.'
        );

        return $this->redirect($back);
    }

    /**
     * @return array<string, string>
     */
    private function consumerNames(): array
    {
        $names = [];
        foreach ($this->consumers->all() as $consumer) {
            $names[$consumer->consumerId()] = $consumer->displayName();
        }

        return $names;
    }

    /**
     * The propositions of a whole page, so the list can show a chip
     * without the template querying inside its own loop.
     *
     * @param \Modules\InboundMail\Api\InboundMessage[] $messages
     * @return array<int, \Modules\InboundMail\Api\MessageCandidate[]>
     */
    private function candidatesFor(array $messages): array
    {
        $byMessage = [];
        foreach ($messages as $message) {
            $byMessage[$message->id] = $this->mailbox->candidatesFor($message->id);
        }

        return $byMessage;
    }
}
