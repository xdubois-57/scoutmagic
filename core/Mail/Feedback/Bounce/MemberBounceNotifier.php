<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Bounce;

use Core\Member\MemberEmailRepository;
use Core\Notification\NotificationService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;

/**
 * Tells the people behind a bounced address that it bounced (roadmap
 * IT-05).
 *
 * **A bounce names a mailbox and nobody in particular**, so the work here
 * is going from an address to the accounts that ought to hear about it.
 * Two routes, and the first covers the common case on its own:
 *
 * 1. The account whose sign-in identity **is** that address. A parent's
 *    account is usually the address the unit writes to, so this is most
 *    bounces.
 * 2. The accounts of the other valid addresses belonging to the members
 *    who list this one — a parent with two secondary addresses, one of
 *    which has started failing.
 *
 * **The gap, stated rather than papered over**: a member whose account is
 * their Desk address and whose *secondary* address bounces is reached by
 * neither route, because the Desk address lives in `member_years` and is
 * always supplied by the caller rather than looked up here. They are not
 * left uninformed — the reason and the button wait on their own address
 * page, and the super-admin sees the block on the Courrier sortant page —
 * but they are not prompted. Closing it would mean threading a Desk
 * lookup through this class for a case the address page already answers.
 *
 * Nothing here ever puts the address in a notification: the member has
 * one line per address on their own page and can see which is which,
 * while a notification body is stored, pushed, and read on a lock screen
 * (SECURITY.md §11).
 */
class MemberBounceNotifier implements BounceNotifier
{
    /** The shared identity purpose — the one accounts and addresses are indexed under. */
    private const BLIND_INDEX_PURPOSE = 'email';

    public function __construct(
        private NotificationService $notifications,
        private MemberEmailRepository $memberEmails,
        private UserAccountRepository $accounts,
        private EncryptionService $encryption
    ) {
    }

    public function notify(BounceState $state, bool $blocking): void
    {
        $recipients = $this->recipientsFor($state->email);
        if ($recipients === []) {
            return;
        }

        $this->notifications->dispatch(
            $blocking ? 'core.mail_bounce_blocked' : 'core.mail_bounce_temporary',
            $recipients,
            $blocking ? $this->blockedPayload($state) : $this->temporaryPayload($state)
        );
    }

    /**
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    private function recipientsFor(string $email): array
    {
        $blindIndex = $this->blindIndex($email);

        $memberIds = $this->memberEmails->findMemberIdsByValidBlindIndex($blindIndex);

        // Grouped by member id, so two loops rather than one.
        $indexes = [$blindIndex];
        foreach ($this->memberEmails->findValidByMemberIds($memberIds) as $rowsForMember) {
            foreach ($rowsForMember as $row) {
                $indexes[] = $this->blindIndex($row->email);
            }
        }

        $accountIds = $this->accounts->findIdsByBlindIndexes($indexes);

        $recipients = [];
        foreach (array_values(array_unique($accountIds)) as $accountId) {
            $recipients[] = ['userAccountId' => $accountId, 'memberId' => null];
        }

        return $recipients;
    }

    /**
     * @return array{title: string, body: string, url?: ?string}
     */
    private function temporaryPayload(BounceState $state): array
    {
        return [
            'title' => 'Un message n’a pas pu être remis',
            // The category, which is the actionable half, and never the
            // address nor the server's own sentence.
            'body' => $state->category->label() . '. ' . $state->category->guidance(),
            'url' => null,
        ];
    }

    /**
     * @return array{title: string, body: string, url?: ?string}
     */
    private function blockedPayload(BounceState $state): array
    {
        return [
            'title' => 'Une de tes adresses est suspendue',
            'body' => $state->category->label() . '. '
                . 'L’unité a cessé d’écrire à cette adresse. ' . $state->category->guidance(),
            'url' => null,
        ];
    }

    private function blindIndex(string $email): string
    {
        return $this->encryption->blindIndex(
            EncryptionService::normalizeEmailForIndex($email),
            self::BLIND_INDEX_PURPOSE
        );
    }
}
