<?php

declare(strict_types=1);

namespace Core\View;

use Core\Module\ModuleManager;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Repository\ProviderRepository;

class RgpdContentService
{
    public function __construct(
        private ModuleManager $moduleManager,
        private ?LlmConnectorInterface $llmConnector = null,
        private ?ProviderRepository $llmProviderRepo = null
    ) {
    }

    /**
     * Get the default RGPD content from docs/rgpd.md
     */
    public function getDefaultContent(): string
    {
        $rgpdPath = __DIR__ . '/../../docs/rgpd.md';
        if (!file_exists($rgpdPath)) {
            return '<h2>Politique de confidentialité</h2><p>Document RGPD non disponible.</p>';
        }

        $markdown = file_get_contents($rgpdPath);
        if ($markdown === false) {
            return '<h2>Politique de confidentialité</h2><p>Document RGPD non disponible.</p>';
        }

        return $this->convertMarkdownToHtml($markdown);
    }

    /**
     * Generate RGPD content via AI based on active modules and user prompt
     */
    public function generateWithAi(string $userPrompt): string
    {
        if ($this->llmConnector === null || !$this->llmConnector->isAvailable()) {
            throw new \RuntimeException('Service IA non disponible.');
        }

        $baseContent = $this->getDefaultContent();
        $activeModules = $this->moduleManager->getEnabledModuleIds();
        $providerInfo = $this->getActiveProviderInfo();

        $systemPrompt = $this->buildSystemPrompt($baseContent, $activeModules, $providerInfo, $userPrompt);

        $request = new LlmRequest(
            prompt: "Génère le contenu RGPD complet en HTML.",
            tier: LlmTier::CAPABLE,
            systemPrompt: $systemPrompt
        );

        $response = $this->llmConnector->complete($request);

        return $this->sanitizeHtmlOutput($response->content);
    }

    /**
     * Build the system prompt for AI generation
     *
     * @param array<int, string> $activeModules
     */
    private function buildSystemPrompt(string $baseContent, array $activeModules, string $providerInfo, string $userPrompt): string
    {
        $modulesText = implode(', ', $activeModules);

        return <<<PROMPT
Tu es un assistant spécialisé en conformité RGPD pour des sites web d'unités scoutes belges.

Contexte:
- Le site utilise actuellement les modules suivants (actifs): {$modulesText}
- Fournisseur IA configuré: {$providerInfo}

Contenu RGPD de référence (docs/rgpd.md):
{$baseContent}

Instructions utilisateur:
{$userPrompt}

Tâche:
Génère un document RGPD complet, conforme au règlement européen, en HTML bien formaté.

Règles strictes:
1. Inclure TOUTES les informations du document de référence pour les modules actifs.
2. Exclure les sections des modules non actifs (exemple: si "sos_staff" n'est pas dans la liste, ne pas mentionner le module SOS Staff d'U).
3. Inclure la section sur le fournisseur IA avec les bonnes informations de transfert de données (Anthropic = USA, Mistral/Scaleway = UE).
4. Appliquer les instructions utilisateur (ton, ajouts, précisions) SANS jamais retirer d'informations obligatoires RGPD.
5. Utiliser des balises HTML sémantiques: <h2>, <h3>, <p>, <ul>, <li>, <table>, <strong>.
6. Ne JAMAIS inventer de données personnelles non collectées.
7. Conserver la structure: Responsable du traitement, Données collectées, Base légale, Durée de conservation, Cookies (dynamiques), Sous-traitants, Vos droits, Modules actifs.
8. Le HTML doit être prêt à l'insertion directe (pas de ```html, pas de balise <html> ou <body>).
9. Respecter le format des tableaux markdown pour les données collectées.
10. La liste des cookies est générée dynamiquement par le code — ne pas la dupliquer dans le contenu généré, juste mentionner qu'elle est affichée plus bas.

Réponds UNIQUEMENT avec le HTML généré, rien d'autre.
PROMPT;
    }

    /**
     * Get info about the active AI provider for RGPD disclosure
     */
    private function getActiveProviderInfo(): string
    {
        if ($this->llmProviderRepo === null) {
            return 'Non configuré';
        }

        $provider = $this->llmProviderRepo->findFirstActive();
        if ($provider === null) {
            return 'Non configuré';
        }

        $driver = $provider['driver'];
        return match ($driver) {
            'anthropic' => 'Anthropic (États-Unis, hors UE)',
            'mistral' => 'Mistral AI (France, UE)',
            'scaleway' => 'Scaleway (France/Pays-Bas, UE)',
            default => $provider['name'],
        };
    }

    /**
     * Convert markdown to HTML (basic implementation)
     */
    private function convertMarkdownToHtml(string $markdown): string
    {
        // Headers
        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $markdown);
        $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);

        // Bold
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);

        // Lists
        $html = preg_replace_callback('/^- (.+)$/m', function ($matches) {
            static $inList = false;
            $item = '<li>' . $matches[1] . '</li>';
            if (!$inList) {
                $inList = true;
                return '<ul>' . $item;
            }
            return $item;
        }, $html);

        // Close unclosed lists
        $html = preg_replace('/<\/li>\n(?!<li|<\/ul)/', "</li>\n</ul>\n", $html);

        // Tables (basic)
        $html = preg_replace_callback('/(\|.+\|)\n(\|[-:\s|]+\|)\n((?:\|.+\|\n?)+)/s', function ($matches) {
            $header = $matches[1];
            $rows = $matches[3];

            $headerCells = array_map('trim', explode('|', trim($header, '|')));
            $th = '<thead><tr>' . implode('', array_map(fn ($cell) => '<th>' . htmlspecialchars($cell) . '</th>', $headerCells)) . '</tr></thead>';

            $bodyRows = array_filter(explode("\n", trim($rows)));
            $tbody = '<tbody>';
            foreach ($bodyRows as $row) {
                $cells = array_map('trim', explode('|', trim($row, '|')));
                $tbody .= '<tr>' . implode('', array_map(fn ($cell) => '<td>' . htmlspecialchars($cell) . '</td>', $cells)) . '</tr>';
            }
            $tbody .= '</tbody>';

            return '<table class="table table-sm">' . $th . $tbody . '</table>';
        }, $html);

        // Links
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $html);

        // Paragraphs
        $lines = explode("\n", $html);
        $result = [];
        $inParagraph = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($inParagraph) {
                    $result[] = '</p>';
                    $inParagraph = false;
                }
                continue;
            }

            if (preg_match('/^<(h[1-6]|ul|li|\/ul|table|thead|tbody|tr|th|td|\/table|strong)/', $trimmed)) {
                if ($inParagraph) {
                    $result[] = '</p>';
                    $inParagraph = false;
                }
                $result[] = $trimmed;
            } else {
                if (!$inParagraph) {
                    $result[] = '<p>';
                    $inParagraph = true;
                }
                $result[] = $trimmed;
            }
        }

        if ($inParagraph) {
            $result[] = '</p>';
        }

        return implode("\n", $result);
    }

    /**
     * Sanitize HTML output from AI
     */
    private function sanitizeHtmlOutput(string $html): string
    {
        // Remove markdown code fences if present
        $html = preg_replace('/^```html\s*\n/', '', $html);
        $html = preg_replace('/\n```\s*$/', '', $html);

        // Remove full document wrappers if present
        $html = preg_replace('/<\?xml[^>]*>\s*/', '', $html);
        $html = preg_replace('/<!DOCTYPE[^>]*>\s*/', '', $html);
        $html = preg_replace('/<html[^>]*>/', '', $html);
        $html = preg_replace('/<\/html>/', '', $html);
        $html = preg_replace('/<head>.*?<\/head>/s', '', $html);
        $html = preg_replace('/<body[^>]*>/', '', $html);
        $html = preg_replace('/<\/body>/', '', $html);

        return trim($html);
    }
}
