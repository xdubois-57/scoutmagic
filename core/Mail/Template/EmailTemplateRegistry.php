<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Template;

use Core\Module\ModuleManifest;

/**
 * Every automatic e-mail the site can send, core and modules together.
 *
 * Same shape and same reasoning as Core\Cookie\CookieRegistry and
 * Core\Notification\NotificationRegistry: core declares its own in
 * getCoreTemplates() below, a module declares its own in the `emails`
 * section of its module.json (validated by
 * Core\Module\ModuleManifest::validateEmail()), and
 * Core\Module\ModuleManager hands them to this registry as it loads each
 * enabled module. One list answers for both, so nothing has to know
 * whether an e-mail came from core or from a module.
 *
 * **Declaring an e-mail changes nothing about how it is sent.** A service
 * that renders its Twig template directly goes on doing exactly that; the
 * declaration is what makes the e-mail appear in the inventory and, once
 * its sender is migrated, what lets EmailTemplateRenderer answer for it.
 * The migration is deliberately one sender at a time — an e-mail nobody
 * has declared must keep working, and an e-mail declared but not yet
 * migrated must keep working too.
 */
class EmailTemplateRegistry
{
    /** @var array<string, list<EmailTemplate>> module id => its declared templates */
    private array $moduleTemplates = [];

    /**
     * Module id => the name its manifest declares. Core never knows any
     * module's name (ARCHITECTURE.md §7.5), so the only place it can come
     * from is the manifest that declared the e-mails, handed over at the
     * same moment — the same reasoning as ConfigModulesController's own
     * id-to-name map.
     *
     * @var array<string, string>
     */
    private array $moduleNames = [];

    /** @var array<string, EmailTemplate>|null id => template, core and modules merged */
    private ?array $cache = null;

    /**
     * The e-mails core sends. Nine of them, and four are authentication
     * e-mails declared `editable: false` — see EmailTemplate.
     *
     * `site_name` is deliberately not a variable anywhere: the header, the
     * footer and the unit's name belong to email/base.html.twig, which
     * stays code and is never customisable.
     *
     * @return list<EmailTemplate>
     */
    public static function getCoreTemplates(): array
    {
        return [
            new EmailTemplate(
                id: 'magic_link',
                label: 'Lien de connexion',
                description: "Envoyé quand quelqu'un demande un lien de connexion depuis la page de connexion.",
                defaultSubject: 'Votre lien de connexion',
                template: 'email/magic_link.html.twig',
                variables: [
                    new EmailTemplateVariable('magic_link_url', 'Lien de connexion', 'https://exemple.be/auth/verify?token=…'),
                    new EmailTemplateVariable('expiry_minutes', 'Durée de validité (minutes)', '15'),
                ],
                editable: false
            ),
            new EmailTemplate(
                id: 'password_reset',
                label: 'Réinitialisation du mot de passe',
                description: "Envoyé quand quelqu'un demande à réinitialiser son mot de passe.",
                defaultSubject: 'Réinitialisation de votre mot de passe',
                template: 'email/password_reset.html.twig',
                variables: [
                    new EmailTemplateVariable('reset_url', 'Lien de réinitialisation', 'https://exemple.be/password-reset/12'),
                    new EmailTemplateVariable('expiry_minutes', 'Durée de validité (minutes)', '30'),
                ],
                editable: false
            ),
            new EmailTemplate(
                id: 'member_email_confirmation',
                label: "Confirmation d'une adresse secondaire",
                description: "Envoyé à une adresse qu'un membre vient d'ajouter à sa fiche, pour qu'il la confirme.",
                defaultSubject: 'Confirmez votre adresse email',
                template: 'email/member_email_confirmation.html.twig',
                variables: [
                    new EmailTemplateVariable('confirm_url', 'Lien de confirmation', 'https://exemple.be/members/emails/confirm/7'),
                    new EmailTemplateVariable('expiry_hours', 'Durée de validité (heures)', '48'),
                ],
                editable: false
            ),
            new EmailTemplate(
                id: 'member_email_unsubscribe_confirmation',
                label: 'Confirmation de désinscription',
                description: "Envoyé à l'adresse qui vient de se désinscrire des envois groupés, pour lui confirmer que c'est fait.",
                defaultSubject: 'Vous êtes désinscrit de nos envois groupés',
                template: 'email/member_email_unsubscribe_confirmation.html.twig',
                variables: [
                    new EmailTemplateVariable('member_names', 'Membre(s) concerné(s)', 'Camille Dupont, Louis Dupont'),
                    new EmailTemplateVariable('staffdu_email', "Adresse du Staff d'U", 'staff@exemple.be'),
                ]
            ),
            new EmailTemplate(
                id: 'member_email_unsubscribe_staffdu',
                label: "Désinscription — avis au Staff d'U",
                description: "Envoyé au Staff d'U quand une adresse se désinscrit des envois groupés.",
                defaultSubject: "Une adresse s'est désinscrite des envois groupés",
                template: 'email/member_email_unsubscribe_staffdu.html.twig',
                variables: [
                    new EmailTemplateVariable('unsubscribed_email', 'Adresse désinscrite', 'famille@exemple.be'),
                    new EmailTemplateVariable('member_names', 'Membre(s) concerné(s)', 'Camille Dupont, Louis Dupont'),
                ]
            ),
            new EmailTemplate(
                id: 'notification',
                label: 'Notification par email',
                description: "Envoyé pour une notification dont le destinataire a laissé le canal « email » actif.",
                defaultSubject: 'Nouvelle notification',
                template: 'email/notification.html.twig',
                variables: [
                    new EmailTemplateVariable('title', 'Titre de la notification', 'Sauvegarde terminée'),
                    new EmailTemplateVariable('body', 'Texte de la notification', 'La sauvegarde que vous avez demandée est prête.'),
                    new EmailTemplateVariable('url', 'Lien vers la page concernée', 'https://exemple.be/config/maintenance'),
                    new EmailTemplateVariable('preferences_url', 'Lien vers les préférences', 'https://exemple.be/notifications/preferences'),
                ]
            ),
            new EmailTemplate(
                id: 'super_admin_granted',
                label: 'Accès administrateur accordé',
                description: "Envoyé à la personne à qui l'accès super-administrateur vient d'être donné.",
                defaultSubject: 'Vous êtes administrateur du site',
                template: 'email/super_admin_granted.html.twig',
                variables: [
                    new EmailTemplateVariable('granted_by', "Qui a accordé l'accès", 'akela@exemple.be'),
                    new EmailTemplateVariable('login_url', 'Lien de connexion', 'https://exemple.be/login'),
                ]
            ),
            new EmailTemplate(
                id: 'super_admin_revoked',
                label: 'Accès administrateur retiré',
                description: "Envoyé à la personne à qui l'accès super-administrateur vient d'être retiré.",
                defaultSubject: 'Votre accès administrateur a été retiré',
                template: 'email/super_admin_revoked.html.twig'
            ),
            new EmailTemplate(
                id: 'super_admin_deactivated',
                label: 'Compte suspendu',
                description: "Envoyé à la personne dont le compte vient d'être suspendu.",
                defaultSubject: 'Votre accès au site a été suspendu',
                template: 'email/super_admin_deactivated.html.twig'
            ),
        ];
    }

    /**
     * Register a module's declared e-mails
     * (Core\Module\ModuleManager::loadModule()). Invalidates the merged
     * cache so a later getAll()/find() picks them up.
     *
     * @param array<int, array{id: string, label: string, description: string, default_subject: string, template: string, editable: bool, variables: array<int, array{name: string, label: string, example: string}>}> $emails
     */
    public function registerModuleTemplates(string $moduleId, string $moduleName, array $emails): void
    {
        $templates = [];
        foreach ($emails as $email) {
            $variables = [];
            foreach ($email['variables'] as $variable) {
                $variables[] = new EmailTemplateVariable($variable['name'], $variable['label'], $variable['example']);
            }

            $templates[] = new EmailTemplate(
                id: $email['id'],
                label: $email['label'],
                description: $email['description'],
                defaultSubject: $email['default_subject'],
                template: $email['template'],
                variables: $variables,
                editable: $email['editable'],
            );
        }

        $this->moduleTemplates[$moduleId] = $templates;
        $this->moduleNames[$moduleId] = $moduleName;
        $this->cache = null;
    }

    /**
     * The same registration, from the manifest itself — what a scheduled
     * task's handler needs. A handler runs outside the composition root
     * (no ModuleManager, its own Twig with only its own namespace), so it
     * builds a registry holding core's templates plus its own module's,
     * and this is the one line that does it.
     */
    public function registerModuleManifest(ModuleManifest $manifest): void
    {
        $this->registerModuleTemplates($manifest->id, $manifest->name, $manifest->emails);
    }

    /**
     * Every declared e-mail, core first then the modules in load order.
     *
     * @return list<EmailTemplate>
     */
    public function getAll(): array
    {
        $this->build();

        return array_values($this->cache ?? []);
    }

    public function find(string $id): ?EmailTemplate
    {
        $this->build();

        return $this->cache[$id] ?? null;
    }

    /**
     * The declared e-mails grouped by the module that declares them, core
     * under the empty string — what the Configuration > E-mails page
     * lists, so it never has to parse an id itself.
     *
     * @return array<string, list<EmailTemplate>>
     */
    public function groupedByModule(): array
    {
        $grouped = [];
        foreach ($this->getAll() as $template) {
            $grouped[$template->moduleId() ?? ''][] = $template;
        }

        return $grouped;
    }

    /**
     * What to call a group on the page: the site's own e-mails, or the
     * module's declared name. An id that registered nothing keeps the id —
     * a group that cannot happen, since a group only exists because a
     * module registered into it, but a label is not worth an exception.
     */
    public function moduleLabel(string $moduleId): string
    {
        if ($moduleId === '') {
            return 'Emails du site';
        }

        return $this->moduleNames[$moduleId] ?? $moduleId;
    }

    private function build(): void
    {
        if ($this->cache !== null) {
            return;
        }

        $cache = [];
        foreach (self::getCoreTemplates() as $template) {
            $cache[$template->id] = $template;
        }
        foreach ($this->moduleTemplates as $templates) {
            foreach ($templates as $template) {
                $cache[$template->id] = $template;
            }
        }

        $this->cache = $cache;
    }
}
