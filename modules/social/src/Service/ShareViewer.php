<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Core\Security\Role;

/**
 * Who is looking at an album form or an article editor, for the two
 * providers that offer « Partager » there.
 *
 * It exists because `actionsFor()` is handed an id and nothing else
 * (ARCHITECTURE.md §7.6): the gallery and the news module render what
 * they are given and never learn who gave it, so the viewer travels with
 * the provider, from the composition root, which resolves it once per
 * request like every other per-request value.
 */
final class ShareViewer
{
    public const CONFIG_PATH = '/config/reseaux-sociaux';

    /**
     * Why no « Partager » button. Said rather than left to be guessed:
     * a button that disappears without a word reads as a bug
     * (docs/chantiers/CHANTIER-medias-sociaux.md, IT-01).
     */
    public const NO_DESTINATION = 'Aucune destination n\'est disponible pour l\'instant : il faut un compte Meta relié,'
        . ' ou un groupe de discussion où vous avez le droit de publier.';

    public function __construct(
        public readonly ?string $email,
        public readonly string $role,
        public readonly ?int $userAccountId,
    ) {
    }

    /**
     * Whether this person can act on the Meta half of the explanation. The
     * configuration screen is superadmin's, so offering its link to a
     * chief would send them into a 403 — a link nobody can follow is worse
     * than no link (SECURITY.md §3: hiding a control is a courtesy, the
     * route's own floor is the boundary).
     */
    public function mayConfigureMeta(): bool
    {
        return Role::fromString($this->role)->hasAccess(Role::SUPERADMIN);
    }
}
