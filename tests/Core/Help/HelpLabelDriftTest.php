<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help;

use Core\Help\HelpRegistry;
use Core\Help\HelpTopic;
use PHPUnit\Framework\TestCase;

/**
 * A help topic names a control by quoting it — « Enregistrer mes choix »,
 * « Tout marquer comme lu ». When that control is renamed, the topic goes
 * on quoting the old wording, and nothing says so: the text still reads
 * perfectly, it simply describes a screen that no longer exists. That is
 * the slowest and least visible way for documentation to become wrong,
 * and it is the one thing about a corpus a machine can check.
 *
 * So: every « … » citation in every shipped topic must appear somewhere in
 * the interface — a Twig template, a browser script, or a PHP source that
 * builds a label. Anything else fails, unless the topic is listed in
 * ALLOWLIST below with that exact citation.
 *
 * ---------------------------------------------------------------------
 * Only guillemets, not bold
 * ---------------------------------------------------------------------
 * The charter allows a topic to emphasise with `**bold**`, and this corpus
 * uses it overwhelmingly for prose emphasis — « **Un champ vide est
 * rempli.** » — rather than to name a control. Checking bold too would
 * produce dozens of findings that are not drift and an ALLOWLIST long
 * enough that nobody reads it, which is the failure mode of every
 * exception list. French quotes are this corpus's actual convention for
 * naming a control, and they are what this test holds.
 *
 * ---------------------------------------------------------------------
 * Why the interface is read TWICE
 * ---------------------------------------------------------------------
 * Four traps, each of which made an earlier version of this check accuse
 * the corpus of drift it did not have. They are why the reading below is
 * more careful than a grep:
 *
 * 1. **A tag splits a label.** The login page writes
 *    `J'accepte la <a …>politique de protection des données</a>`, so the
 *    citation only exists once the tags are removed.
 * 2. **A label lives in an attribute.** « Mois précédent » is an
 *    `aria-label` and nothing else — removing the tag removes it.
 *    (1) and (2) cannot both be satisfied by one reading, so the sources
 *    are flattened twice and a citation found in either is found.
 * 3. **A label lives inside a Twig tag.** This repository's dominant idiom
 *    for a button, an empty state or a list editor is
 *    `{% include … with { action_label: "Télécharger l'album (ZIP)" } %}`.
 *    Stripping Twig deletes exactly what the topics quote, so the
 *    attribute-preserving reading keeps Twig intact.
 * 4. **`glob()` does not recurse on `**`.** An earlier audit script missed
 *    every template three levels deep (`admin/members/search.html.twig`)
 *    and reported all of their labels as drift. The directory walk below
 *    is what stops that from being a whole class of false accusation.
 */
final class HelpLabelDriftTest extends TestCase
{
    /**
     * Citations that are legitimately not interface labels, per topic.
     *
     * A ratchet, the same one as `UxConventionsTest`'s allowlists: an
     * unlisted citation fails, and a listed citation that has stopped
     * appearing fails too, so the list can only shrink. It holds the exact
     * strings rather than a count, because "one exception was replaced by
     * another" is precisely the drift this test exists to catch.
     *
     * Six reasons, and nothing else belongs here:
     *
     * - **Reported speech** — the topic quotes what someone says or asks,
     *   not what a screen shows.
     * - **An example value** a unit would type in.
     * - **A label of the browser or the operating system**, which this
     *   project does not own.
     * - **A template with a placeholder** the interface fills in.
     * - **A shorthand naming two adjacent controls at once.**
     * - **A feature named in prose** rather than by its control.
     *
     * @var array<string, list<string>> topic id => citations that are not labels
     */
    private const ALLOWLIST = [
        // A template: the screen fills the number in.
        'actualites' => ['Il reste N places'],
        // An example value a unit would type.
        'attestations' => ['Attestation présence camp 2026'],
        'mon-compte' => ['Téléphone de Marie'],
        'camps-encoder' => ['On est allés là en 2012'],
        'config-envoi-mails' => [
            'les intendants de toutes les sections',
            'les animateurs des deux sections aînées',
        ],
        // Reported speech: what a banner, a reminder or a person says.
        'bannieres' => ['pensez à vos fiches médicales'],
        'config-calendrier' => ['votre activité a lieu demain'],
        'courrier-orienter' => ['ce message ne concerne pas ce module'],
        'departs' => ['ne se réinscrit pas', 'décocher'],
        'publier-une-actualite' => ['membres liés au compte'],
        'reponses-au-formulaire' => ['qui peut consulter les réponses'],
        'retrospectives' => ['lien seul'],
        'support-sondes-email' => ['jamais reçue'],
        // The browser's own menu, and a print dialog's option.
        'installer-application' => [
            "Ajouter à l'écran d'accueil",
            "Installer l'application",
            "Sur l'écran d'accueil",
        ],
        'etiquettes-paiement' => ['ajuster à la page'],
        // A question the page answers, a phrase a visitor reports, the
        // shorthand for the « Du » and « Au » fields side by side, and the
        // name of a feature rather than of its button.
        'journal' => [
            'qui a changé cela, et quand ?',
            "ça a affiché une erreur",
            'Du / Au',
            'agir au nom de',
        ],
    ];

    /** @return array<string, HelpTopic> the whole shipped corpus */
    private static function shippedTopics(): array
    {
        $root = dirname(__DIR__, 3);
        $registry = new HelpRegistry($root . '/docs/help');
        foreach (glob($root . '/modules/*/module.json') ?: [] as $manifestPath) {
            $moduleDir = dirname($manifestPath);
            $data = json_decode((string) file_get_contents($manifestPath), true);
            $dirName = is_array($data) && isset($data['help']['dir']) && is_string($data['help']['dir'])
                ? $data['help']['dir']
                : 'help';
            if (is_dir($moduleDir . '/' . $dirName)) {
                $registry->registerModuleTopics(basename($moduleDir), $moduleDir . '/' . $dirName);
            }
        }

        return $registry->all();
    }

    /**
     * The interface, in the two readings trap (1) and (2) above force.
     *
     * @return array{0: string, 1: string} text with tags removed, text with them kept
     */
    private static function interfaceSources(): array
    {
        $root = dirname(__DIR__, 3);
        $plain = '';
        $withAttributes = '';

        foreach ([$root . '/core', $root . '/modules', $root . '/public/assets/js'] as $dir) {
            $walker = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($walker as $file) {
                /** @var \SplFileInfo $file */
                if (!in_array(strtolower($file->getExtension()), ['twig', 'php', 'js'], true)) {
                    continue;
                }
                $path = $file->getPathname();
                // A module's own vendor tree is not this project's wording,
                // and the help corpus must not vouch for itself.
                if (str_contains($path, '/vendor/') || str_contains($path, '/help/')) {
                    continue;
                }
                $source = (string) file_get_contents($path);
                $plain .= "\n" . self::normalise(
                    (string) preg_replace('/<[^>]*>/s', '', self::stripTwig($source))
                );
                // Twig stays here on purpose — trap (3).
                $withAttributes .= "\n" . self::normalise(str_replace(['<', '>'], ' ', $source));
            }
        }

        return [$plain, $withAttributes];
    }

    private static function stripTwig(string $text): string
    {
        $text = (string) preg_replace('/\{#.*?#\}/s', '', $text);

        return (string) preg_replace('/\{\{.*?\}\}|\{%.*?%\}/s', ' ', $text);
    }

    private static function normalise(string $text): string
    {
        // A PHP source escapes the apostrophe French labels are full of.
        $text = str_replace("\\'", "'", $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * The titles and section headings of the whole corpus — a topic names
     * another one by quoting its title, which is a cross-reference, not a
     * claim about the interface.
     *
     * @param array<string, HelpTopic> $topics
     * @return array<string, true>
     */
    private static function crossReferences(array $topics): array
    {
        $known = [];
        foreach ($topics as $topic) {
            $known[mb_strtolower($topic->title)] = true;
            foreach (preg_split('/\R/', $topic->body()) ?: [] as $line) {
                if (preg_match('/^#{2,}\s+(.+?)\s*$/u', $line, $m) === 1) {
                    $known[mb_strtolower($m[1])] = true;
                }
            }
        }

        return $known;
    }

    public function testEveryQuotedLabelStillExistsInTheInterface(): void
    {
        $topics = self::shippedTopics();
        $this->assertNotEmpty($topics, 'The shipped corpus must not be empty.');

        [$plain, $withAttributes] = self::interfaceSources();
        $crossReferences = self::crossReferences($topics);

        $found = [];
        foreach ($topics as $topic) {
            $body = $topic->body();
            if (preg_match_all('/«\s*([^»]{2,70}?)\s*»/u', $body, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $raw) {
                // A body is hard-wrapped at ~72 columns, so a citation
                // regularly spans two lines — inside a `> ` callout too,
                // whose continuation marker is not part of the label. Bold
                // inside the quotes is emphasis, not spelling.
                $label = str_replace('**', '', $raw);
                $label = (string) preg_replace('/\R\s*>?\s*/u', ' ', $label);
                $label = trim((string) preg_replace('/\s+/u', ' ', $label));

                if ($label === '') {
                    continue;
                }
                // A citation carrying an ellipsis, or holding no letter at
                // all, is a shorthand by construction — « Référent … »,
                // « Nécessite : ... », the overflow glyph « ⋮ ».
                if (str_contains($label, '…') || str_contains($label, '...')) {
                    continue;
                }
                if (preg_match('/\p{L}/u', $label) !== 1) {
                    continue;
                }
                if (isset($crossReferences[mb_strtolower($label)])) {
                    continue;
                }
                if (str_contains($plain, $label) || str_contains($withAttributes, $label)) {
                    continue;
                }

                $found[$topic->id][$label] = true;
            }
        }

        $messages = [];
        foreach ($found as $id => $labels) {
            foreach (array_keys($labels) as $label) {
                if (!in_array($label, self::ALLOWLIST[$id] ?? [], true)) {
                    $messages[] = "Help topic '{$id}' quotes « {$label} », which appears nowhere in the interface"
                        . ' — the control was renamed, or the topic never said it right.';
                }
            }
        }
        // The ratchet: an exception that stopped being needed must leave
        // the list, or the list stops describing the corpus.
        foreach (self::ALLOWLIST as $id => $labels) {
            foreach ($labels as $label) {
                if (!isset($found[$id][$label])) {
                    $messages[] = "ALLOWLIST lists « {$label} » for help topic '{$id}', but it now resolves"
                        . ' — remove the entry to keep the list honest.';
                }
            }
        }

        sort($messages);
        $this->assertSame([], $messages, "Help topics must quote the interface as it is:\n  " . implode("\n  ", $messages));
    }
}
