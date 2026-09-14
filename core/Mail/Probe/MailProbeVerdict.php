<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Probe;

/**
 * Where a probe landed, as the person who went and looked reports it
 * (roadmap IT-04).
 *
 * **Entered by hand, and that is not a limitation to be engineered
 * away.** No site can observe another provider's spam folder: the only
 * instrument that can tell « boîte de réception » from « indésirables »
 * is somebody opening the mailbox. A machine answer here would be a
 * guess wearing a verdict's clothes.
 *
 * Three cases and no fourth. « Jamais reçu » is deliberately one of them
 * rather than an absence: an operator who looked and found nothing has
 * learned something, and a row saying so is what makes the history
 * readable months later. A probe nobody has answered yet has no verdict
 * at all — that is the `null` this enum does not model.
 */
enum MailProbeVerdict: string
{
    /** It arrived where a member would see it. */
    case Inbox = 'inbox';

    /** It arrived, in the folder almost nobody opens. */
    case Spam = 'spam';

    /** The operator looked, including in the spam folder, and found nothing. */
    case Never = 'never';

    public function label(): string
    {
        return match ($this) {
            self::Inbox => 'Réception',
            self::Spam => 'Indésirables',
            self::Never => 'Jamais reçu',
        };
    }

    /** The Bootstrap contextual class the badge carries. */
    public function badge(): string
    {
        return match ($this) {
            self::Inbox => 'text-bg-success',
            self::Spam => 'text-bg-warning',
            self::Never => 'text-bg-danger',
        };
    }

    /**
     * What the operator should do next, said once per verdict.
     *
     * « Ne le sortez pas des indésirables » is the one that matters and
     * the one nobody would guess: marking the message as legitimate is a
     * positive engagement signal, so it teaches the receiver something
     * about the next message and quietly ruins the measurement this page
     * exists to take.
     */
    public function guidance(): string
    {
        return match ($this) {
            self::Inbox => 'Rien à faire : par ce chemin, vos messages arrivent là où on les lit.',
            self::Spam => 'Laissez le message dans les indésirables. L\'en sortir apprendrait quelque chose au '
                . 'fournisseur et fausserait la mesure suivante. Essayez plutôt une autre voie ou un autre '
                . 'fournisseur, et comparez les deux lignes.',
            self::Never => 'Ni reçu ni classé : regardez la page « Fournisseurs » — un message qui ne part pas et '
                . 'un message rejeté à l\'arrivée ne se réparent pas au même endroit.',
        };
    }

    public static function tryFromInput(string $value): ?self
    {
        return self::tryFrom(trim($value));
    }

    /** @return list<self> in the order the screen offers them. */
    public static function ordered(): array
    {
        return [self::Inbox, self::Spam, self::Never];
    }
}
