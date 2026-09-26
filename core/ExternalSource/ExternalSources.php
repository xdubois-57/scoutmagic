<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ExternalSource;

/**
 * The register of every page outside this site that the site depends on
 * (issue #355): the federation's pages whose content feeds a feature or a
 * shipped default, and the provider consoles and legal pages a help text
 * sends an administrator to.
 *
 * WHY A REGISTER. When lesscouts.be moves a page nothing here notices: the
 * « Chercher les montants » button just stops finding anything, and a
 * default link starts answering 404 on every installed site. So every such
 * URL is listed once, here, with where it is used and what it must still
 * say, and three things read the list:
 *
 * - `scripts/check-external-sources.php`, which fetches each page and
 *   exits non-zero on any divergence — run by the sixth release gate, by
 *   the weekly `.github/workflows/external-sources-check.yml`, and at the
 *   start of « fixe le backlog » (AGENTS.md);
 * - `Tests\Architecture\ExternalSourcesAreRegisteredTest`, which refuses a
 *   federation or console URL anywhere in shipped code that is not listed
 *   here, a listed file that no longer names its URL, and a shipped
 *   default (`module.json`, `schema/core.sql`) that differs from the value
 *   below — those two cannot reference PHP, so they are pinned instead;
 * - the code itself, wherever it can: the federal URLs are read from the
 *   constants below rather than written out again.
 *
 * Out of scope on purpose, as the issue says: API endpoints, map tiles and
 * example URLs. A dead endpoint fails its own feature loudly; a dead help
 * link or a moved federation page fails nobody but the reader.
 */
final class ExternalSources
{
    /** Read by the fees module's « Chercher les montants » button. */
    public const FEES_PAGE = 'https://www.lesscouts.be/fr/ressources-scouts/administratif-1/'
        . 'inscriptions-et-cotisations/inscriptions-et-cotisations';

    /** Default explanation link of every age branch. */
    public const SCOUT_PATH_PAGE = 'https://lesscouts.be/fr/site-parents/le-parcours-scout';

    /** The federation's data protection policy, named by the RGPD page. */
    public const DATA_PROTECTION_PAGE = 'https://www.lesscouts.be/fr/ressources-scouts/administratif-1/'
        . 'web-et-vie-privee/protection-des-donnees-personnelles';

    /** Linked from the federation logo on the contact page. */
    public const FEDERATION_PAGE = 'https://lesscouts.be/fr/le-scoutisme/la-federation-les-scouts';

    /** Where the badges go on the uniform — for issue #473, not used yet. */
    public const BADGE_PLACEMENT_PAGE = 'https://lesscouts.be/fr/news/uniforme-ou-coudre-les-ecussons';

    /** The id of the one source the checker extracts the federal scale from. */
    public const FEES_PAGE_ID = 'federal-fees';

    private const LLM_CONFIG = 'modules/llm_connector/views/config/index.html.twig';
    private const STORAGE_FORM = 'core/View/templates/config/storage/location_form.html.twig';
    private const RGPD_DEFAULT = 'core/View/rgpd_default.html';

    /**
     * Every registered source, federation first.
     *
     * @return list<ExternalSource>
     */
    public static function all(): array
    {
        return [...self::federal(), ...self::providers()];
    }

    public static function byId(string $id): ?ExternalSource
    {
        foreach (self::all() as $source) {
            if ($source->id === $id) {
                return $source;
            }
        }

        return null;
    }

    public static function byUrl(string $url): ?ExternalSource
    {
        foreach (self::all() as $source) {
            if ($source->url === $url) {
                return $source;
            }
        }

        return null;
    }

    /**
     * @return list<ExternalSource>
     */
    private static function federal(): array
    {
        return [
            new ExternalSource(
                id: self::FEES_PAGE_ID,
                url: self::FEES_PAGE,
                kind: ExternalSourceKind::Content,
                purpose: 'Federal fees page: read by « Chercher les montants » (Justesse des tarifs), '
                    . 'source of the normal, couple and family amounts.',
                usedIn: [
                    'modules/fees/module.json',
                    'modules/fees/src/Service/FederalScaleLookupService.php',
                ],
                expectedContent: ['Inscriptions et cotisations', 'Cotisation normale'],
                dependentDefault: 'setting fees_federal_scale_url (modules/fees/module.json)',
                // FederalScaleLookupService::fetchPage() refuses redirects on
                // purpose, so a redirect here breaks the button as surely as
                // a 404 would.
                redirectIsDivergence: true,
            ),
            new ExternalSource(
                id: 'federal-scout-path',
                url: self::SCOUT_PATH_PAGE,
                kind: ExternalSourceKind::Content,
                purpose: 'The scout path explained to parents: default explanation link of every age branch.',
                usedIn: ['schema/core.sql'],
                expectedContent: ['Le parcours scout'],
                dependentDefault: 'column default age_branches.explanation_url (schema/core.sql)',
            ),
            new ExternalSource(
                id: 'federal-data-protection',
                url: self::DATA_PROTECTION_PAGE,
                kind: ExternalSourceKind::Content,
                purpose: 'The federation\'s data protection policy, cited by the RGPD page and its AI prompt.',
                usedIn: ['core/View/RgpdContentService.php', self::RGPD_DEFAULT],
                expectedContent: ['Protection des données personnelles'],
                dependentDefault: 'default RGPD content (core/View/rgpd_default.html)',
            ),
            new ExternalSource(
                id: 'federal-federation',
                url: self::FEDERATION_PAGE,
                kind: ExternalSourceKind::Content,
                purpose: 'Presentation of the federation, linked from the logo on the contact page.',
                usedIn: ['core/Http/Controller/PageController.php'],
                expectedContent: ['La fédération Les Scouts'],
            ),
            new ExternalSource(
                id: 'federal-badge-placement',
                url: self::BADGE_PLACEMENT_PAGE,
                kind: ExternalSourceKind::Content,
                purpose: 'Where to sew the badges on the uniform (upcoming, issue #473).',
                usedIn: [],
                expectedContent: ['écussons'],
                upcoming: true,
            ),
            self::link(
                'federal-adult-quality-code',
                'https://www.lesscouts.be/fr/le-scoutisme/la-federation-les-scouts/les-positions-federales/'
                    . 'code-qualite-des-adultes',
                'The federation\'s quality code for adults, linked from the default RGPD content.',
                [self::RGPD_DEFAULT]
            ),
            self::link(
                'federal-data-protection-file',
                'https://lesscouts.be/api/file/3240',
                'The federation\'s data protection document, linked from the default RGPD content.',
                [self::RGPD_DEFAULT]
            ),
            self::link(
                'federal-desk',
                'https://desk.lesscouts.be',
                'Desk, the federation\'s membership application, named by the default RGPD content.',
                [self::RGPD_DEFAULT]
            ),
            self::link(
                'federal-home',
                'https://www.lesscouts.be',
                'The federation\'s home page, named by the default RGPD content.',
                [self::RGPD_DEFAULT]
            ),
        ];
    }

    /**
     * @return list<ExternalSource>
     */
    private static function providers(): array
    {
        return [
            self::link('anthropic-console', 'https://console.anthropic.com/', 'Anthropic console', [self::LLM_CONFIG]),
            self::link(
                'anthropic-api-keys',
                'https://console.anthropic.com/settings/keys',
                'Anthropic API keys page',
                [self::LLM_CONFIG]
            ),
            self::link(
                'anthropic-privacy',
                'https://www.anthropic.com/legal/privacy',
                'Anthropic privacy policy',
                [self::LLM_CONFIG]
            ),
            self::link(
                'anthropic-commercial-terms',
                'https://www.anthropic.com/legal/commercial-terms',
                'Anthropic commercial terms',
                [self::LLM_CONFIG]
            ),
            self::link(
                'anthropic-privacy-short',
                'https://www.anthropic.com/privacy',
                'Anthropic privacy policy (short address)',
                [self::RGPD_DEFAULT]
            ),
            self::link('mistral-console', 'https://console.mistral.ai/', 'Mistral console', [self::LLM_CONFIG]),
            self::link(
                'mistral-api-keys',
                'https://console.mistral.ai/api-keys',
                'Mistral API keys page',
                [self::LLM_CONFIG]
            ),
            self::link(
                'mistral-privacy',
                'https://legal.mistral.ai/terms/privacy-policy',
                'Mistral privacy policy',
                [self::LLM_CONFIG]
            ),
            self::link(
                'mistral-dpa',
                'https://legal.mistral.ai/terms/data-processing-addendum',
                'Mistral data processing addendum',
                [self::LLM_CONFIG]
            ),
            self::link(
                'mistral-terms-privacy',
                'https://mistral.ai/terms/#privacy-policy',
                'Mistral privacy policy (terms page anchor)',
                [self::RGPD_DEFAULT]
            ),
            self::link('scaleway-console', 'https://console.scaleway.com/', 'Scaleway console', [self::LLM_CONFIG]),
            self::link(
                'scaleway-api-keys',
                'https://console.scaleway.com/iam/api-keys',
                'Scaleway IAM API keys',
                [self::LLM_CONFIG, self::STORAGE_FORM]
            ),
            self::link(
                'scaleway-buckets',
                'https://console.scaleway.com/object-storage/buckets',
                'Scaleway Object Storage buckets',
                [self::STORAGE_FORM]
            ),
            self::link(
                'scaleway-applications',
                'https://console.scaleway.com/iam/applications',
                'Scaleway IAM applications',
                [self::STORAGE_FORM]
            ),
            self::link(
                'scaleway-policies',
                'https://console.scaleway.com/iam/policies',
                'Scaleway IAM policies',
                [self::STORAGE_FORM]
            ),
            self::link(
                'scaleway-privacy',
                'https://www.scaleway.com/en/privacy-policy/',
                'Scaleway privacy policy',
                [self::LLM_CONFIG, self::RGPD_DEFAULT]
            ),
            self::link(
                'scaleway-contracts',
                'https://www.scaleway.com/en/contracts/',
                'Scaleway contracts',
                [self::LLM_CONFIG]
            ),
            self::link(
                'hetzner-console',
                'https://console.hetzner.cloud/',
                'Hetzner Cloud console (Object Storage)',
                [self::STORAGE_FORM]
            ),
            self::link(
                'cloudflare-dashboard',
                'https://dash.cloudflare.com/',
                'Cloudflare dashboard (R2)',
                [self::STORAGE_FORM]
            ),
            self::link(
                'cloudflare-privacy',
                'https://www.cloudflare.com/privacypolicy/',
                'Cloudflare privacy policy',
                [self::RGPD_DEFAULT]
            ),
            self::link(
                'ovh-manager',
                'https://www.ovh.com/manager/',
                'OVHcloud manager (Object Storage)',
                [self::STORAGE_FORM]
            ),
            self::link(
                'ovh-create-app',
                'https://eu.api.ovh.com/createApp/',
                'OVH API application creation (SOS Staff d\'U telephony)',
                ['modules/sos_staff/views/config.html.twig']
            ),
            self::link(
                'google-cloud-console',
                'https://console.cloud.google.com/',
                'Google Cloud console (Drive backup OAuth client)',
                [self::STORAGE_FORM]
            ),
            self::link(
                'google-app-passwords',
                'https://myaccount.google.com/apppasswords',
                'Google app passwords (inbound mail over IMAP)',
                ['modules/inbound_mail/views/config/index.html.twig']
            ),
        ];
    }

    /**
     * @param list<string> $usedIn
     */
    private static function link(string $id, string $url, string $purpose, array $usedIn): ExternalSource
    {
        return new ExternalSource($id, $url, ExternalSourceKind::Link, $purpose, $usedIn);
    }
}
