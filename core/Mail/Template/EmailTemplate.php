<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Template;

/**
 * A declared automatic e-mail — the single source of truth it is ever
 * described from, whether it is a core one (EmailTemplateRegistry) or a
 * module one (module.json "emails" section, aggregated by
 * Core\Module\ModuleManager).
 *
 * The exact parallel of Core\Notification\NotificationType for
 * notifications and of Core\Cookie\CookieRegistry for cookies: core
 * declares its own, modules declare theirs in their manifest, and one
 * registry answers for both.
 *
 * `template` names the Twig file shipped with the application. It stays
 * the thing that is rendered as long as nobody has customised the e-mail;
 * a customisation replaces the SUBJECT and the BODY only, and is never
 * itself evaluated as Twig.
 *
 * `editable` is false for every authentication e-mail. An administrator
 * who broke the magic link, the password reset or an address confirmation
 * would lock themselves out with no way back in, so those are declared —
 * they belong in the inventory — and refused an editor, on the server and
 * not merely in the page.
 */
final class EmailTemplate
{
    /**
     * @param list<EmailTemplateVariable> $variables
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $description,
        public readonly string $defaultSubject,
        public readonly string $template,
        public readonly array $variables = [],
        public readonly bool $editable = true
    ) {
    }

    /**
     * The module this template belongs to, or null for a core one — read
     * off the id, which is prefixed `"{module_id}."` for a module and
     * unprefixed for core (ModuleManifest::validateEmail() enforces it).
     */
    public function moduleId(): ?string
    {
        $dot = strpos($this->id, '.');

        return $dot === false ? null : substr($this->id, 0, $dot);
    }

    /**
     * The example values, keyed by variable name — what the preview and
     * the test send render with.
     *
     * @return array<string, string>
     */
    public function exampleValues(): array
    {
        $values = [];
        foreach ($this->variables as $variable) {
            $values[$variable->name] = $variable->example;
        }

        return $values;
    }

    public function hasVariable(string $name): bool
    {
        foreach ($this->variables as $variable) {
            if ($variable->name === $name) {
                return true;
            }
        }

        return false;
    }
}
