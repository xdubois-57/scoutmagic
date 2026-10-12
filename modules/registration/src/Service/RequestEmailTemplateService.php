<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Security\HtmlSanitizer;
use Core\Template\TokenCatalogue;
use Core\Template\TokenEngine;
use Core\Template\TokenSyntax;
use Core\View\EditableContentService;

/**
 * The four e-mails of the request flow and their bodies (issue #843, D10):
 * the acknowledgement of receipt and the alert to the unit, which ship with
 * a default body, and the acceptance and the refusal, which do not.
 *
 * **The one definition of « À rédiger ».** {@see isToWrite()} is what the
 * « Gabarits » page shows as a template's state AND what
 * {@see RequestEmailService} refuses to send without — the guard that
 * makes an automation unable to claim it sent a decision it could not
 * write. Two readings of "is this body written?" would let the page say
 * « Personnalisé » over a body the sender still considers empty, or the
 * reverse; there is one, here.
 *
 * Bodies live in `editable_contents` under their historical keys, written
 * through {@see EditableContentService}, which sanitises every value before
 * it is stored (SECURITY.md §7). A body that renders as nothing is never
 * stored: saving one is refused, and the shipped default comes back by
 * deleting the row, so « Par défaut » always means "follows the text this
 * version ships", never a stale copy of an older one.
 *
 * Substitution goes through the shared `Core\Template` engine: a
 * `{{ variable }}` with any spacing, percent-encoded when it sits in a
 * link (the sanitiser encodes URL attributes), or split by inline markup
 * the editor dropped inside it. A name nobody declared stays visible.
 */
final class RequestEmailTemplateService
{
    public const KEY_RECEIPT = 'registration_email_receipt_body';
    public const KEY_UNIT_ALERT = 'registration_email_unit_alert_body';
    public const KEY_ACCEPTED = 'registration_email_accepted_body';
    public const KEY_REFUSED = 'registration_email_refused_body';

    public const STATE_DEFAULT = 'default';
    public const STATE_CUSTOM = 'custom';
    public const STATE_TO_WRITE = 'to_write';

    /** The French label of each state, as the page and its badge print it. */
    public const STATE_LABELS = [
        self::STATE_DEFAULT => 'Par défaut',
        self::STATE_CUSTOM => 'Personnalisé',
        self::STATE_TO_WRITE => 'À rédiger',
    ];

    /** @var list<RequestEmailTemplate>|null */
    private static ?array $catalogue = null;

    public function __construct(private EditableContentService $editableContentService)
    {
    }

    /**
     * The four templates, in the order « Gabarits » lists them.
     *
     * @return list<RequestEmailTemplate>
     */
    public function all(): array
    {
        return self::$catalogue ??= self::buildCatalogue();
    }

    public function findBySlug(string $slug): ?RequestEmailTemplate
    {
        foreach ($this->all() as $template) {
            if ($template->slug === $slug) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @throws \LogicException for a key the catalogue does not hold — a
     *         programming error, never something a visitor can cause
     */
    public function findByKey(string $key): RequestEmailTemplate
    {
        foreach ($this->all() as $template) {
            if ($template->key === $key) {
                return $template;
            }
        }

        throw new \LogicException("Unknown request e-mail template: {$key}");
    }

    /**
     * The body this e-mail goes out with: the unit's own when it wrote
     * one, the shipped default otherwise — or null when there is neither,
     * which is exactly « À rédiger ».
     */
    public function bodyFor(string $key): ?string
    {
        return $this->customBody($key) ?? $this->findByKey($key)->defaultBody;
    }

    /**
     * **The single definition of « À rédiger »**: nothing would go out.
     * See the class docblock.
     */
    public function isToWrite(string $key): bool
    {
        return $this->bodyFor($key) === null;
    }

    public function stateOf(string $key): string
    {
        if ($this->isToWrite($key)) {
            return self::STATE_TO_WRITE;
        }

        $template = $this->findByKey($key);
        $custom = $this->customBody($key);
        $isDefaultAgain = $template->defaultBody !== null && $custom !== null
            && self::isSameText($custom, $template->defaultBody);
        if ($custom === null || $isDefaultAgain) {
            return self::STATE_DEFAULT;
        }

        return self::STATE_CUSTOM;
    }

    /**
     * Stores a template's new body.
     *
     * Sanitised before it is judged, because what is judged must be what
     * would be STORED: the sanitiser drops whole elements, and a body that
     * arrived with text can leave with none. A body that renders as nothing
     * is refused rather than stored — an empty e-mail is never what
     * somebody meant. A body that is the shipped default again is not
     * stored either: the row is removed, and the template follows the
     * default from then on.
     *
     * @throws RegistrationException for a body with no text in it
     */
    public function save(string $key, string $html, int $modifiedBy): void
    {
        $template = $this->findByKey($key);
        $body = (new HtmlSanitizer())->sanitize($html);

        if (self::isBlank($body)) {
            throw new RegistrationException(
                'Le texte de l\'e-mail ne peut pas être vide.'
                . ($template->hasDefault()
                    ? ' Pour revenir au texte par défaut, utilisez « Rétablir le texte par défaut ».'
                    : '')
            );
        }

        if ($template->defaultBody !== null && self::isSameText($body, $template->defaultBody)) {
            $this->editableContentService->delete($key);

            return;
        }

        $this->editableContentService->set($key, $body, 'rich_text', $modifiedBy);
    }

    /**
     * Gives a template its shipped default back, by forgetting the unit's
     * own body.
     *
     * @throws RegistrationException for a template that has no default —
     *         the acceptance and the refusal must be written
     */
    public function restoreDefault(string $key): void
    {
        if (!$this->findByKey($key)->hasDefault()) {
            throw new RegistrationException('Cet e-mail n\'a pas de texte par défaut : il doit être rédigé.');
        }

        $this->editableContentService->delete($key);
    }

    /**
     * The `{{ … }}` a body uses that its template does not declare — they
     * would reach the recipient as literal braces, so the edit page names
     * them.
     *
     * Read with the free-text syntax, not the identifier one substitution
     * uses: `{{ Prenom_enfant }}` or `{{ prenom enfant }}` is exactly the
     * typo worth pointing out, and the identifier pattern does not even
     * see it. Encoded and split spellings are restored first, as
     * substitute() restores them.
     *
     * @return string[]
     */
    public function unknownVariablesIn(RequestEmailTemplate $template, string $body): array
    {
        $engine = self::engine();
        $body = $engine->repairTokensSplitByMarkup($engine->decodeEncodedTokens($body));

        return (new TokenEngine(TokenSyntax::freeText()))->unknownTokens(
            $body,
            static fn(string $name): bool => $template->variables->has($name)
        );
    }

    /**
     * Replaces each `{{ variable }}` of a stored body with its value,
     * escaped for HTML. Unknown names are left as they are written.
     *
     * **The result goes through the sanitiser once more**, and only for
     * links. Escaping keeps a value from becoming markup, but a variable
     * placed inside an `href` — which the editor's « Lien » button allows —
     * puts a value somebody typed on the public form (a child's first
     * name) where the sanitiser's scheme check ran BEFORE it was there: a
     * first name spelled `javascript:…` would become the link itself.
     * Sanitising the substituted body drops such a link; it changes
     * nothing else, since every value is already escaped text.
     *
     * @param array<string, string> $values
     */
    public static function substitute(string $body, array $values): string
    {
        $engine = self::engine();
        $body = $engine->repairTokensSplitByMarkup($engine->decodeEncodedTokens($body));
        $body = $engine->substitute($body, static fn(string $name): ?string => $values[$name] ?? null, true);

        return (new HtmlSanitizer())->sanitize($body);
    }

    /**
     * Whether a body renders as nothing: no text once its tags and
     * entities are gone. U+00A0 explicitly — `&nbsp;` is what an editor
     * leaves in a paragraph somebody emptied, and `trim()` removes neither
     * the entity nor the character it decodes to.
     */
    public static function isBlank(string $html): bool
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/[\s\x{00A0}]+/u', '', $text) === '';
    }

    /**
     * The unit's own body, or null when none is stored or it renders as
     * nothing.
     */
    private function customBody(string $key): ?string
    {
        $stored = $this->editableContentService->get($key);

        return $stored === null || self::isBlank($stored) ? null : $stored;
    }

    /**
     * Whether two bodies say the same thing, whatever the sanitiser and the
     * editor did to their spelling: both go through the sanitiser, a
     * `{{ variable }}` reads the same encoded, spaced or not, and
     * whitespace is ignored. Erring towards « different » is the harmless
     * direction — at worst the page offers to restore a text identical to
     * the default.
     */
    private static function isSameText(string $a, string $b): bool
    {
        $sanitizer = new HtmlSanitizer();
        $engine = self::engine();
        $normalise = static function (string $html) use ($sanitizer, $engine): string {
            $html = $engine->decodeEncodedTokens($sanitizer->sanitize($html));
            $html = (string) preg_replace('/\{\{\s*([a-z0-9_]+)\s*\}\}/', '{{$1}}', $html);

            return (string) preg_replace('/[\s\x{00A0}]+/u', '', $html);
        };

        return $normalise($a) === $normalise($b);
    }

    private static function engine(): TokenEngine
    {
        return new TokenEngine(TokenSyntax::identifiers());
    }

    /**
     * @return list<RequestEmailTemplate>
     */
    private static function buildCatalogue(): array
    {
        $familyVariables = TokenCatalogue::of([
            'prenom_enfant' => 'Prénom de l\'enfant',
            'annee_scoute' => 'Année scoute demandée',
            'lien_suivi' => 'Lien de suivi de la demande, propre à la famille',
            'nom_unite' => 'Nom de l\'unité',
        ]);

        return [
            new RequestEmailTemplate(
                key: self::KEY_RECEIPT,
                slug: 'accuse-de-reception',
                name: 'Accusé de réception de la demande',
                description: 'Envoyé à la famille dès le dépôt.',
                defaultBody: '<p>Bonjour,</p>'
                    . '<p>Nous avons bien reçu votre demande d\'inscription pour {{ prenom_enfant }} pour l\'année '
                    . 'scoute {{ annee_scoute }}.</p>'
                    . '<p><strong>Ceci n\'est pas encore une acceptation.</strong> Votre demande sera examinée par '
                    . 'l\'unité.</p>'
                    . '<p>Vous pouvez suivre l\'état de votre demande à tout moment via ce lien : <a '
                    . 'href="{{ lien_suivi }}">{{ lien_suivi }}</a></p>'
                    . '<p>À bientôt,<br>{{ nom_unite }}</p>',
                variables: $familyVariables
            ),
            new RequestEmailTemplate(
                key: self::KEY_UNIT_ALERT,
                slug: 'alerte-unite',
                name: 'Alerte à l\'unité',
                description: 'Envoyé à l\'unité à chaque nouvelle demande.',
                defaultBody: '<p>Nouvelle demande d\'inscription reçue pour {{ prenom_enfant }} — créneau : '
                    . '{{ creneau }}, année {{ annee_scoute }}, reçue le {{ date_reception }}.</p>'
                    . '<p><a href="{{ lien_fiche }}">Voir la demande</a></p>',
                variables: TokenCatalogue::of([
                    'prenom_enfant' => 'Prénom de l\'enfant — jamais les coordonnées de la famille',
                    'annee_scoute' => 'Année scoute demandée',
                    'creneau' => 'Branche et année de parcours de l\'enfant',
                    'date_reception' => 'Date de réception de la demande',
                    'lien_fiche' => 'Lien vers la fiche de la demande, réservée au Staff d\'U',
                ])
            ),
            new RequestEmailTemplate(
                key: self::KEY_ACCEPTED,
                slug: 'acceptation',
                name: 'Acceptation de la demande',
                description: 'Envoyé à la famille quand la demande est acceptée.',
                defaultBody: null,
                variables: $familyVariables
            ),
            new RequestEmailTemplate(
                key: self::KEY_REFUSED,
                slug: 'refus',
                name: 'Refus de la demande',
                description: 'Envoyé à la famille quand la demande est refusée.',
                defaultBody: null,
                variables: $familyVariables
            ),
        ];
    }
}
