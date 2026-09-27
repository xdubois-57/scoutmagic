<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

use Core\Config\SettingService;
use Core\Member\SectionService;

/**
 * What the two configuration screens say about a section whose address this
 * site cannot sign for (issue #418).
 *
 * **The rule is not here.** {@see MailIdentity::canAlignFrom()} decides, and
 * {@see MailIdentity::substitutedFromName()} names, exactly as they do for
 * the send itself — this class only asks them on behalf of a screen. That
 * matters because the warning's whole job is to describe what the mailing
 * will do: a screen that worked it out on its own would be free to be wrong
 * about it, and « l'écran était vert » is the failure #418 is about.
 *
 * **Two screens, one sentence.** Correspondances Desk puts it under the
 * offending field; Courrier sortant › Authentification lists every section
 * it applies to. Both read {@see self::warningFor()}, so an operator who
 * checks the second after fixing the first is not told something different.
 *
 * **A site with no sending address warns about nothing.** `canAlignFrom()`
 * answers « no » for every address when the site signs for no domain, which
 * would put this warning under all of them — while the real problem is the
 * missing address, which the outbound screens already say in their own
 * words. Warning here as well would blame the sections for it.
 */
final class SectionSenderAlignment
{
    public function __construct(
        private SettingService $settings,
        private SectionService $sections
    ) {
    }

    /**
     * The sentence to put under a section's e-mail field, or null when
     * there is nothing to warn about.
     *
     * Null covers three different situations and deliberately does not
     * distinguish them, because none of them is a problem: the section has
     * no address at all (its mailing already goes out as the site, and
     * always did), the address is one this site can sign for, or the site
     * has no sending address for anything to be measured against.
     */
    public function warningFor(?string $sectionEmail, ?string $sectionName): ?string
    {
        $identity = $this->identity();
        $email = trim((string) $sectionEmail);
        $domain = $identity->dkimDomain();

        if ($email === '' || $domain === '' || $identity->canAlignFrom($email)) {
            return null;
        }

        $substituted = $identity->substitutedFromName($sectionName);

        return 'Cette adresse n\'est pas sur le domaine d\'envoi du site (' . $domain . '). Les publipostages '
            . 'de cette section partiront de ' . $identity->fromAddress
            . ($substituted === null ? '' : ', au nom de « ' . $substituted . ' »')
            . ', et les réponses arriveront à cette adresse.';
    }

    /**
     * Every section whose address this site cannot sign for, in the order
     * the sections come out of the import.
     *
     * Hidden sections are included: a section hidden from the pickers still
     * has an address, still receives mailings, and hiding it from this list
     * would be hiding the one screen that could explain a refusal.
     *
     * @return list<array{id: int, desk_code: string, name: string, email: string,
     *     substituted_name: ?string, warning: string}>
     */
    public function misaligned(): array
    {
        // **No « does this site sign for anything » check here**, and its
        // absence is the point. `warningFor()` already answers that for
        // every row, so a second one in front of the loop was an ornament:
        // its mutation survived the whole suite, because removing it
        // changed nothing anybody could observe. Two mechanisms for one
        // boundary is how the ornament ends up being the one that gets
        // trusted, and the one that gets mutated away unnoticed.
        $identity = $this->identity();
        $rows = [];
        foreach ($this->sections->getAllWithBranches(includeHidden: true) as $section) {
            $warning = $this->warningFor($section['email'], $section['name']);
            if ($warning === null) {
                continue;
            }

            $rows[] = [
                'id' => $section['id'],
                'desk_code' => $section['desk_code'],
                'name' => (string) ($section['name'] ?? ''),
                'email' => trim((string) ($section['email'] ?? '')),
                'substituted_name' => $identity->substitutedFromName($section['name']),
                'warning' => $warning,
            ];
        }

        return $rows;
    }

    /**
     * Built per call rather than kept in a property, because the sending
     * address can change *during* the request that reads it:
     * `SettingService::set()` clears its own cache, so a screen that saves
     * the address and then renders sees the new one — but only if it asks
     * again. An identity captured in the constructor would have been built
     * before the save and would name the old domain in a warning shown
     * after it.
     */
    private function identity(): MailIdentity
    {
        return MailIdentity::fromSettings($this->settings);
    }
}
