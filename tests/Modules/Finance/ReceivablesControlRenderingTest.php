<?php

declare(strict_types=1);

namespace Tests\Modules\Finance;

use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;

/**
 * Full-page render of « Contrôle des créances » (issue #836): a
 * cross-module check, after the screens where the work is done, that says
 * no action is expected on it and leads to the module that manages each
 * receivable.
 */
class ReceivablesControlRenderingTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $overview
     */
    private function render(array $overview): string
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 3) . '/modules/finance/views';
        $twig = TwigFactory::create($templateDir, true, ['finance' => $moduleViews]);
        $twig->addGlobal('site_name', 'Test Unité');
        $twig->addGlobal('menus', null);
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'test@example.com');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('current_path', '/finance/receivables');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('csp_nonce', 'n');
        $twig->addGlobal('effective_scout_year_id', 1);

        return $twig->render('@finance/receivables.html.twig', [
            'accounts' => [],
            'overview' => $overview,
            'focus_source' => '',
            'focus_id' => 0,
        ]);
    }

    /**
     * @param array{label: string, url: string}|null $destination
     * @return array<string, mixed>
     */
    private static function row(int $id, int $reference, ?array $destination): array
    {
        return [
            'id' => $id,
            'source_reference_id' => $reference,
            'label' => 'Jean Dupont',
            'communication' => '+++100/0000/00011+++',
            'amount_due' => 6500,
            'amount_received' => 0,
            'status' => 'unpaid',
            'destination' => $destination,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $instances
     * @return array<string, mixed>
     */
    private static function source(string $module, string $label, bool $grouped, array $instances): array
    {
        $rows = array_merge(...array_column($instances, 'receivables'));

        return [
            'source_module' => $module,
            'source_label' => $label,
            'amount_due' => 0,
            'amount_received' => 0,
            'groups_instances' => $grouped,
            'instances' => $instances,
            'receivables' => $rows,
        ];
    }

    public function testThePageIsNamedForWhatItIsAndSaysNothingIsExpectedOnIt(): void
    {
        $html = $this->render([]);

        $this->assertStringContainsString('<title>Contrôle des créances — Test Unité</title>', $html);
        $this->assertStringContainsString('Aucune action n\'est attendue ici', $html);
        $this->assertStringNotContainsString('Paiements attendus', $html);
    }

    public function testItsTabComesAfterReconciliationAndBeforeTools(): void
    {
        $html = $this->render([]);
        $picker = (int) strpos($html, 'finance-page-picker');
        $this->assertGreaterThan(0, $picker);
        $html = substr($html, $picker);

        $order = [];
        foreach (['Tableau de bord', 'Paiements à traiter', 'Mouvements', 'Reçus', 'Importer', 'Campagnes', 'Contrôle des créances', 'Outils'] as $label) {
            $position = strpos($html, '>' . $label . '<');
            $this->assertNotFalse($position, $label . ' is missing from the page picker');
            $order[$label] = $position;
        }
        $sorted = $order;
        asort($sorted);

        $this->assertSame(array_keys($order), array_keys($sorted));
    }

    public function testAGroupLeadsToTheScreenWhereItIsManaged(): void
    {
        $destination = ['label' => 'Ouvrir les réponses du formulaire', 'url' => '/news/5/form/responses'];
        $html = $this->render([self::source('news', 'Formulaires', true, [[
            'source_reference_id' => 12,
            'instance_label' => 'Week-end Louveteaux',
            'destination' => $destination,
            'amount_due' => 0,
            'amount_received' => 0,
            'receivables' => [self::row(1, 12, $destination), self::row(2, 12, $destination)],
        ]])]);

        $this->assertSame(1, substr_count($html, 'href="/news/5/form/responses"'), 'once, on the group — not on every row under it');
    }

    public function testAFlatListLeadsEachRowToItsOwnScreen(): void
    {
        $html = $this->render([self::source('finance', 'Campagnes', false, [
            [
                'source_reference_id' => 11, 'instance_label' => 'Cotisations', 'amount_due' => 0, 'amount_received' => 0,
                'destination' => ['label' => 'Ouvrir la campagne', 'url' => '/finance/campaigns/3'],
                'receivables' => [self::row(1, 11, ['label' => 'Ouvrir la campagne', 'url' => '/finance/campaigns/3'])],
            ],
            [
                'source_reference_id' => 14, 'instance_label' => 'Cotisations', 'amount_due' => 0, 'amount_received' => 0,
                'destination' => null,
                'receivables' => [self::row(2, 14, null)],
            ],
        ])]);

        $this->assertStringContainsString('Campagnes', $html);
        // Table and phone cards both carry the one row that has a link.
        $this->assertSame(2, substr_count($html, 'href="/finance/campaigns/3"'));
        $this->assertSame(2, substr_count($html, 'Jean Dupont') / 2, 'the row without a link is still listed');
    }

    public function testNothingOnThePageChangesAReceivable(): void
    {
        $html = $this->render([self::source('finance', 'Campagnes', false, [[
            'source_reference_id' => 11, 'instance_label' => 'Cotisations', 'amount_due' => 0, 'amount_received' => 0,
            'destination' => null,
            'receivables' => [self::row(1, 11, null)],
        ]])]);

        $main = substr($html, (int) strpos($html, '<main'));
        $this->assertStringNotContainsString('<form', $main);
        $this->assertStringNotContainsString('method="post"', $main);
    }
}
