<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class NavDynamicRenderingTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = new Environment(new ArrayLoader([
            'nav.html.twig' => '
{# Mobile offcanvas — dynamic entries with avatar and subtitle #}
{% for page in menu.pages %}
    {% if page.isSeparator %}
        <hr class="my-1 mx-3">
    {% elseif page.isDynamic %}
        <a href="{{ page.url }}" class="d-flex align-items-center gap-2 px-3 py-2 text-decoration-none">
            <div class="rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center"
                 style="width:32px;height:32px;min-width:32px;">
                <span class="small fw-semibold text-primary">{{ page.label[:2] }}</span>
            </div>
            <div class="lh-sm">
                <span class="d-block small">{{ page.label }}</span>
                {% if page.subtitle %}
                    <span class="d-block text-body-secondary" style="font-size:0.75rem;">{{ page.subtitle }}</span>
                {% endif %}
            </div>
        </a>
    {% else %}
        <a href="{{ page.url }}" class="d-block px-3 py-2 small text-body-secondary text-decoration-none">
            {{ page.label }}
        </a>
    {% endif %}
{% endfor %}

{# Desktop sub-menu bar — same logic #}
{% for page in menu.pages %}
    {% if page.isSeparator %}
        <span class="border-start mx-1" style="height:24px;"></span>
    {% elseif page.isDynamic %}
        <a href="{{ page.url }}" class="btn btn-sm d-flex align-items-center gap-1">
            <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary fw-bold"
                  style="width:20px;height:20px;font-size:.7rem;">
                {{ page.label[:2] }}
            </span>
            <span>{{ page.label }}</span>
        </a>
    {% else %}
        <a href="{{ page.url }}" class="btn btn-sm">
            {{ page.label }}
        </a>
    {% endif %}
{% endfor %}

{# User card #}
<div class="d-flex align-items-center gap-2">
    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-bold"
          style="width:32px;height:32px;font-size:.8rem;">
        {{ current_user_display_name[:2]|upper }}
    </span>
    <span class="small text-truncate">{{ current_user_display_name }}</span>
    {% if current_user_member_count > 0 %}
        <span class="text-muted" style="font-size:.75rem;">· {{ current_user_member_count }} membre{{ current_user_member_count > 1 ? \'s\' : \'\' }}</span>
    {% endif %}
</div>
'
        ]));
    }

    public function testNavigationRendersDynamicEntriesWithInitialsAvatar(): void
    {
        $menu = [
            'pages' => [
                [
                    'label' => 'Baloo',
                    'url' => '/members/1',
                    'isDynamic' => true,
                    'isSeparator' => false,
                    'subtitle' => 'Meute Akela'
                ],
                [
                    'label' => 'Configuration',
                    'url' => '/config',
                    'isDynamic' => false,
                    'isSeparator' => false,
                    'subtitle' => null
                ]
            ]
        ];

        $template = $this->twig->load('nav.html.twig');
        $output = $template->render(['menu' => $menu]);

        $this->assertStringContainsString('Ba', $output); // Initials from "Baloo"
        $this->assertStringContainsString('Baloo', $output);
        $this->assertStringContainsString('Meute Akela', $output);
        $this->assertStringContainsString('Configuration', $output);
    }

    public function testNavigationRendersSubtitleBelowMemberName(): void
    {
        $menu = [
            'pages' => [
                [
                    'label' => 'Mowgli',
                    'url' => '/members/2',
                    'isDynamic' => true,
                    'isSeparator' => false,
                    'subtitle' => 'Sizaine Loups'
                ]
            ]
        ];

        $template = $this->twig->load('nav.html.twig');
        $output = $template->render(['menu' => $menu]);

        $this->assertStringContainsString('Mowgli', $output);
        $this->assertStringContainsString('Sizaine Loups', $output);
        $this->assertStringContainsString('font-size:0.75rem', $output); // Subtitle styling
    }

    public function testSeparatorRendersAsHrInMobileOffcanvas(): void
    {
        $menu = [
            'pages' => [
                [
                    'label' => 'Baloo',
                    'url' => '/members/1',
                    'isDynamic' => true,
                    'isSeparator' => false,
                    'subtitle' => null
                ],
                [
                    'label' => '',
                    'url' => '',
                    'isDynamic' => false,
                    'isSeparator' => true,
                    'subtitle' => null
                ],
                [
                    'label' => 'Configuration',
                    'url' => '/config',
                    'isDynamic' => false,
                    'isSeparator' => false,
                    'subtitle' => null
                ]
            ]
        ];

        $template = $this->twig->load('nav.html.twig');
        $output = $template->render(['menu' => $menu]);

        $this->assertStringContainsString('<hr class="my-1 mx-3">', $output);
    }

    public function testUserCardShowsDisplayNameNotEmail(): void
    {
        $template = $this->twig->load('nav.html.twig');
        $output = $template->render([
            'current_user_display_name' => 'Baloo',
            'current_user_member_count' => 2
        ]);

        $this->assertStringContainsString('Baloo', $output);
        $this->assertStringNotContainsString('test@example.com', $output);
    }

    public function testUserCardShowsMemberCount(): void
    {
        $template = $this->twig->load('nav.html.twig');
        $output = $template->render([
            'current_user_display_name' => 'Baloo',
            'current_user_member_count' => 3
        ]);

        $this->assertStringContainsString('· 3 membres', $output);
    }

    public function testUserCardShowsSingularMemberWhenCountIsOne(): void
    {
        $template = $this->twig->load('nav.html.twig');
        $output = $template->render([
            'current_user_display_name' => 'Mowgli',
            'current_user_member_count' => 1
        ]);

        $this->assertStringContainsString('· 1 membre', $output);
        $this->assertStringNotContainsString('membres', $output);
    }

    public function testUserCardShowsInitialsInAvatar(): void
    {
        $template = $this->twig->load('nav.html.twig');
        $output = $template->render([
            'current_user_display_name' => 'Baloo',
            'current_user_member_count' => 0
        ]);

        $this->assertStringContainsString('BA', $output); // Uppercase first 2 chars
    }

    public function testDesktopDynamicEntriesRenderAsPillsWithInitials(): void
    {
        $menu = [
            'pages' => [
                [
                    'label' => 'Baloo',
                    'url' => '/members/1',
                    'isDynamic' => true,
                    'isSeparator' => false,
                    'subtitle' => null
                ]
            ]
        ];

        $template = $this->twig->load('nav.html.twig');
        $output = $template->render(['menu' => $menu]);

        $this->assertStringContainsString('btn btn-sm', $output);
        $this->assertStringContainsString('d-flex align-items-center gap-1', $output);
        $this->assertStringContainsString('rounded-circle bg-primary-subtle', $output);
        $this->assertStringContainsString('Ba', $output);
    }

    public function testDesktopSeparatorRendersAsVerticalDivider(): void
    {
        $menu = [
            'pages' => [
                [
                    'label' => 'Baloo',
                    'url' => '/members/1',
                    'isDynamic' => true,
                    'isSeparator' => false,
                    'subtitle' => null
                ],
                [
                    'label' => '',
                    'url' => '',
                    'isDynamic' => false,
                    'isSeparator' => true,
                    'subtitle' => null
                ]
            ]
        ];

        $template = $this->twig->load('nav.html.twig');
        $output = $template->render(['menu' => $menu]);

        $this->assertStringContainsString('<span class="border-start mx-1" style="height:24px;"></span>', $output);
    }
}
