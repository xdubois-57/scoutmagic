<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * The contact-synchronisation page tells a phone what to type, where the
 * typing happens.
 *
 * Read against the template SOURCE rather than a rendered page, because
 * what is asserted here is where a value is placed — and rendering
 * `account/devices.html.twig` means rendering `base.html.twig`, the help
 * panel and the whole application Twig environment with it. That the
 * value itself reaches the template is the controller's half, held by
 * `Tests\Core\Contact\Controller\DeviceCredentialControllerTest`.
 *
 * WHAT WENT WRONG (#483)
 *
 * A CardDAV connection from an iPhone to a real site, with correct
 * credentials, hung on « Vérification » indefinitely. Measured with
 * anonymous requests:
 *
 * ```
 * GET/PROPFIND /.well-known/carddav   403, an HTML page that is not ScoutMagic's
 * OPTIONS/PROPFIND /carddav/staff/    401 Basic realm="ScoutMagic", DAV: 1, 3, addressbook
 * ```
 *
 * The host answers `/.well-known/` before PHP is reached. iOS given the
 * domain alone goes there first, so the connection never starts — and the
 * page recommended the domain, offering the full address only as a
 * fallback sentence below.
 */
final class ContactSyncPageSaysWhatToTypeTest extends TestCase
{
    private const PAGE = 'core/View/templates/account/devices.html.twig';

    /**
     * The full address is the value of the « Serveur » field, not a footnote.
     */
    public function testTheServerFieldCarriesTheFullAddress(): void
    {
        $source = $this->page();

        $this->assertStringContainsString(
            '<dd class="col-sm-8"><code class="user-select-all">{{ site_url }}{{ carddav_collection_path }}</code></dd>',
            $source,
            'the « Serveur » field must give the full address, which is the one that connects'
        );
    }

    /**
     * The three values a phone asks for sit together.
     *
     * The secret is shown once and never again, so the panel showing it is
     * where somebody has their phone in hand. It gave the username and the
     * password and left the server address further up the page, which is
     * the one moment they cannot go looking for it.
     */
    public function testTheSecretPanelCarriesAllThreeValues(): void
    {
        $panel = $this->secretPanel();

        $this->assertStringContainsString('{{ carddav_collection_path }}', $panel, 'the server address');
        $this->assertStringContainsString('{{ account_email }}', $panel, 'the username');
        $this->assertStringContainsString('device-secret-value', $panel, 'the password');
    }

    /**
     * Why the full address, said on the page rather than only in the help.
     */
    public function testThePageSaysWhyTheDomainAloneCanFail(): void
    {
        $source = $this->page();

        $this->assertStringContainsString('/.well-known/carddav', $source);
        $this->assertMatchesRegularExpression(
            '/hébergements?\s+bloquent/u',
            $source,
            'a reader whose connection hangs must find the reason here, not only in the help'
        );
    }

    /**
     * iOS is named with the path through its settings.
     */
    public function testThePageNamesWhereToTypeItOnIOS(): void
    {
        $source = $this->page();

        $this->assertStringContainsString('Ajouter un compte CardDAV', $source);
        $this->assertStringContainsString('Serveur', $source);
    }

    /**
     * The device is no longer the subject of a page about contacts.
     *
     * « Appareils synchronisés » named the thing being registered; what
     * the visitor came for is their contacts in their phone. The name is
     * « Synchroniser mes contacts » and not « Synchronisation des
     * contacts », which Configuration already carries at
     * `role_min: superadmin` — a superadmin sees both, and two pages
     * under one name is the confusion this issue is about.
     */
    public function testThePageNoLongerPutsTheDeviceAtTheCentre(): void
    {
        $source = $this->page();

        $this->assertStringNotContainsString('Appareils synchronisés', $source);
        $this->assertStringNotContainsString('Mes appareils', $source);
        $this->assertStringContainsString('Synchroniser mes contacts', $source);
    }

    /**
     * And the Members page leads here.
     *
     * Somebody reading a list of members often wants those contacts in
     * their phone, and had no way of learning this page exists. Both pages
     * require `admin`, so the link needs no condition of its own.
     */
    public function testTheMembersPageLeadsToTheSynchronisationPage(): void
    {
        $members = (string) file_get_contents(
            dirname(__DIR__, 3) . '/core/View/templates/admin/members/search.html.twig'
        );

        // The href, not the address anywhere in the file: the comment
        // above the link names the same path, and a match on that would
        // survive the link itself being pointed elsewhere.
        $this->assertMatchesRegularExpression(
            '/<a\\s[^>]*href="\\/account\\/devices"/',
            $members,
            'the Members page must link to the synchronisation page'
        );
    }

    private function page(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . self::PAGE);
    }

    private function secretPanel(): string
    {
        $source = $this->page();
        $start = strpos($source, 'device-secret-panel');
        $this->assertNotFalse($start, 'the panel that hands out the password is gone from the page');

        // Up to the list it holds — far enough to carry the three values,
        // short enough that a match cannot come from the rest of the page.
        $end = strpos($source, '</dl>', $start);
        $this->assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }
}
