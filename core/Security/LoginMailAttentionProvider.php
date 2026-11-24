<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Core\Journal\JournalRepository;

/**
 * « Les e-mails de connexion ne partent pas » (issue #1267).
 *
 * The sign-in link and the « mot de passe oublié » link answer a visitor
 * exactly the same whether or not the e-mail could be sent: anything else
 * would tell whoever typed an address that it has an account, at the moment
 * mail is failing. So a send that fails is invisible to the person waiting for
 * it, and to everybody else unless something says so. This is that something:
 * it counts the failures the two services journal and shows them on the
 * attention-points page until a day has passed without one.
 *
 * It complements `Core\Alert\Check\AuthenticationLaneCheck`, which reads the
 * configuration of the mail lane and warns that nothing CAN send; this one
 * warns that sends have FAILED, which also catches a lane that looks fine and
 * is refused by the relay.
 *
 * Bounded, as the interface requires: two counts on the indexed journal, over
 * the last day. No address and no reason is shown — the journal lines hold the
 * reason, address taken out, for the administrator who opens them.
 */
final class LoginMailAttentionProvider implements AttentionPointProvider
{
    /** How far back a failure still counts. */
    private const WINDOW_HOURS = 24;

    public function __construct(private readonly JournalRepository $journal)
    {
    }

    public function sourceLabel(): string
    {
        return 'Cœur';
    }

    /** @return AttentionPoint[] */
    public function collect(int $scoutYearId): array
    {
        $since = (new \DateTimeImmutable('-' . self::WINDOW_HOURS . ' hours'))->format('Y-m-d H:i:s');
        $failures = $this->journal->countEventsSince('core', AuthService::EVENT_SEND_FAILED, $since)
            + $this->journal->countEventsSince('core', PasswordResetService::EVENT_SEND_FAILED, $since);

        if ($failures === 0) {
            return [];
        }

        return [new AttentionPoint(
            title: 'Les e-mails de connexion ne partent pas : ' . $failures . ' échec' . ($failures > 1 ? 's' : '')
                . ' ces dernières 24 heures.',
            why: 'Le site répond la même chose à un visiteur, que l\'e-mail ait pu partir ou non : '
                . 'celui qui attend son lien de connexion ou de réinitialisation ne verra jamais d\'erreur '
                . 'et ne comprendra pas pourquoi rien n\'arrive. Cet avertissement disparaît après vingt-quatre '
                . 'heures sans nouvel échec.',
            actionLabel: 'Voir le courrier sortant',
            actionUrl: '/config/courrier-sortant'
        )];
    }
}
