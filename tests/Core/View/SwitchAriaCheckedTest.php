<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\TwigFactory;
use Modules\News\Repository\FormField;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\TwigFunction;

/**
 * Regression coverage for SonarCloud Web:S6807 ("The aria-checked state
 * should match its checkbox"): 9 role="switch" checkboxes across 9
 * templates rendered their initial checked state server-side but never
 * carried the matching aria-checked, so a screen reader announced every
 * switch as unchecked regardless of its real state. Twig now renders
 * aria-checked from the exact same condition as the checked attribute;
 * public/assets/js/nav.js (tests/js/nav.test.js) keeps it in sync
 * afterward.
 */
class SwitchAriaCheckedTest extends TestCase
{
    private function createTwig(array $moduleNamespaces = []): Environment
    {
        $coreTemplateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $twig = TwigFactory::create($coreTemplateDir, true, $moduleNamespaces);
        $twig->addFunction(new TwigFunction('param', fn (string $key): string => 'Test Unité'));

        return $twig;
    }

    private function assertSwitchAriaChecked(string $html, string $idAttribute, bool $expectedChecked): void
    {
        $this->assertMatchesRegularExpression(
            '/<input\b[^>]*' . preg_quote($idAttribute, '/') . '[^>]*>/',
            $html
        );
        preg_match('/<input\b[^>]*' . preg_quote($idAttribute, '/') . '[^>]*>/', $html, $match);
        $tag = $match[0];

        $this->assertStringContainsString('aria-checked="' . ($expectedChecked ? 'true' : 'false') . '"', $tag);
        // The plain `checked` HTML attribute — matched as a standalone word so
        // it doesn't also match inside "aria-checked".
        $hasCheckedAttribute = preg_match('/(?<![a-z-])checked(?![a-z="\'])/', $tag) === 1;
        $this->assertSame($expectedChecked, $hasCheckedAttribute);
    }

    public function testAccountPushToggleDefaultsAriaCheckedFalse(): void
    {
        $twig = $this->createTwig();
        $html = $twig->render('account/index.html.twig', [
            'is_authenticated' => true,
            'config_mode' => false,
            'cookie_consent_given' => true,
            'vapid_public_key' => 'test',
        ]);

        $this->assertStringContainsString('id="push-toggle" aria-checked="false"', $html);
    }

    public function testBadgesActiveSwitchAriaCheckedTracksIsActive(): void
    {
        $twig = $this->createTwig();
        $badges = [
            (object) ['id' => 1, 'name' => 'Actif', 'isDefault' => false, 'isActive' => true, 'referentSectionId' => null],
            (object) ['id' => 2, 'name' => 'Inactif', 'isDefault' => false, 'isActive' => false, 'referentSectionId' => null],
        ];
        $html = $twig->render('config/badges.html.twig', [
            'is_authenticated' => true,
            'config_mode' => false,
            'cookie_consent_given' => true,
            'badges' => $badges,
            'assigned_badge_ids' => [],
        ]);

        $this->assertSwitchAriaChecked($html, 'id="badge-active-1"', true);
        $this->assertSwitchAriaChecked($html, 'id="badge-active-2"', false);
    }

    public function testFunctionsSectionVisibleSwitchAriaCheckedTracksIsVisible(): void
    {
        $twig = $this->createTwig();
        $sections = [
            'branch_name' => 'Louveteaux',
            'sections' => [
                (object) ['id' => 10, 'desk_code' => 'LOU', 'name' => 'Louveteaux', 'email' => '', 'effective_color' => '#000', 'color' => null, 'is_visible' => true],
                (object) ['id' => 11, 'desk_code' => 'ECL', 'name' => 'Éclaireurs', 'email' => '', 'effective_color' => '#000', 'color' => null, 'is_visible' => false],
            ],
        ];
        $html = $twig->render('config/functions.html.twig', [
            'is_authenticated' => true,
            'config_mode' => false,
            'cookie_consent_given' => true,
            'unconfirmed' => [],
            'confirmed_by_role' => [],
            'section_groups' => [(object) $sections],
        ]);

        $this->assertSwitchAriaChecked($html, 'id="section-visible-10"', true);
        $this->assertSwitchAriaChecked($html, 'id="section-visible-11"', false);
    }

    public function testNotificationPreferencesChannelAndDiscretionSwitchesAriaCheckedTracksValue(): void
    {
        $twig = $this->createTwig();
        $items = [
            (object) [
                'type' => (object) ['id' => 1, 'label' => 'Test', 'description' => ''],
                'channels' => [
                    'in_app' => (object) ['value' => true, 'locked' => false],
                    'push' => (object) ['value' => false, 'locked' => false],
                    'email' => (object) ['value' => false, 'locked' => true],
                ],
            ],
        ];
        $html = $twig->render('notifications/preferences.html.twig', [
            'is_authenticated' => true,
            'config_mode' => false,
            'cookie_consent_given' => true,
            'groups' => ['Test' => $items],
            'notification_discretion' => true,
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);

        $this->assertStringContainsString('aria-checked="true"', $html);
        $this->assertStringContainsString('aria-checked="false"', $html);
        $this->assertSwitchAriaChecked($html, 'id="notification-discretion"', true);
    }

    public function testCalendarConfigNotifyEnabledSwitchAriaCheckedTracksSetting(): void
    {
        $twig = $this->createTwig(['calendar' => dirname(__DIR__, 3) . '/modules/calendar/views']);
        $html = $twig->render('@calendar/config.html.twig', [
            'notify_multiday_events_enabled' => true,
            'notify_multiday_events_days_before' => 3,
        ]);

        $this->assertSwitchAriaChecked($html, 'id="notify-enabled"', true);
    }

    public function testNewsEditorIsIndexedSwitchAriaCheckedTracksValue(): void
    {
        $twig = $this->createTwig(['news' => dirname(__DIR__, 3) . '/modules/news/views']);
        $html = $twig->render('@news/editor.html.twig', [
            'article' => null,
            'is_indexed_value' => false,
            'csrf_token' => 'test',
            'preview' => false,
            'form' => null,
            'fields' => [],
            'finance_available' => false,
            'finance_accounts' => [],
        ]);

        $this->assertSwitchAriaChecked($html, 'id="is_indexed"', false);
    }

    public function testNewsFieldSwitchTypeAriaCheckedTracksValue(): void
    {
        $twig = $this->createTwig(['news' => dirname(__DIR__, 3) . '/modules/news/views']);
        $field = new FormField(
            id: 1, formId: 1, sortOrder: 0, fieldType: FormField::TYPE_SWITCH, label: 'Accepte',
            isRequired: false, optionsSource: null, optionsManual: null, capacityMax: null,
            pricePerUnit: null, confirmationText: null
        );

        $htmlChecked = $twig->render('@news/partials/_field.html.twig', ['field' => $field, 'value' => '1']);
        $this->assertSwitchAriaChecked($htmlChecked, 'id="field_1"', true);

        $htmlUnchecked = $twig->render('@news/partials/_field.html.twig', ['field' => $field, 'value' => '0']);
        $this->assertSwitchAriaChecked($htmlUnchecked, 'id="field_1"', false);
    }

    public function testNewsFormSettingsDailyDigestSwitchAriaCheckedTracksValue(): void
    {
        $twig = $this->createTwig(['news' => dirname(__DIR__, 3) . '/modules/news/views']);

        $htmlEnabled = $twig->render('@news/partials/_form_settings.html.twig', [
            'form' => (object) ['dailyDigestEnabled' => true],
        ]);
        $this->assertSwitchAriaChecked($htmlEnabled, 'id="form_daily_digest_enabled"', true);

        $htmlDisabled = $twig->render('@news/partials/_form_settings.html.twig', [
            'form' => null,
        ]);
        $this->assertSwitchAriaChecked($htmlDisabled, 'id="form_daily_digest_enabled"', false);
    }
}
