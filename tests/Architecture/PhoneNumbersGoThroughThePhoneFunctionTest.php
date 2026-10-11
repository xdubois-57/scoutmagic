<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A page shows a phone number through `phone()` (Core\View\PhoneExtension),
 * never through a `tel:` link written by hand.
 *
 * Eleven such links once sat in eight templates, each cleaning the number
 * its own way and none offering anything but a call
 * (docs/chantiers/CHANTIER-telephone.md, IT-03). A hand-written one is a
 * number that cannot be texted, copied or opened in WhatsApp or Signal, and
 * whose link may not even match what it shows.
 *
 * E-mail templates are left out: a message has no script and no sheet, so
 * a number in it stays plain (D13).
 */
final class PhoneNumbersGoThroughThePhoneFunctionTest extends TestCase
{
    public function testNoTemplateWritesATelLinkByHand(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            $this->twigFiles($root . '/core/View/templates'),
            ...array_map(fn(string $dir): array => $this->twigFiles($dir), glob($root . '/modules/*/views') ?: [])
        );
        $this->assertNotEmpty($files);

        $offenders = [];
        foreach ($files as $file) {
            if (str_contains($file, '/email/')) {
                continue;
            }
            $code = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($file));
            if (preg_match('/href\s*=\s*["\']\s*tel:/i', $code) === 1) {
                $offenders[] = substr($file, strlen($root) + 1);
            }
        }

        $this->assertSame([], $offenders, 'Render these numbers with {{ phone(number) }} instead of a tel: link.');
    }

    /**
     * Every screen the chantier lists, and the number each one shows: a
     * template that went back to printing the raw value would pass the
     * test above and still lose the actions.
     *
     * @return array<string, array{string, string}>
     */
    public static function screens(): array
    {
        return [
            'member card (admin), parent 1' => ['core/View/templates/admin/members/show.html.twig', 'phone(member.phone)'],
            'member card (admin), parent 2' => ['core/View/templates/admin/members/show.html.twig', 'phone(member.mobile)'],
            'member page, phone' => ['core/View/templates/members/show.html.twig', 'phone(member.phone)'],
            'member page, mobile' => ['core/View/templates/members/show.html.twig', 'phone(member.mobile)'],
            'staffs, mobile' => ['core/View/templates/chefs/staffs.html.twig', 'phone(member.mobile'],
            'staffs, phone' => ['core/View/templates/chefs/staffs.html.twig', 'phone(member.phone'],
            'section roster' => ['core/View/templates/chefs/section_roster.html.twig', 'phone(number.value)'],
            'camp contact' => ['modules/camps/views/camp.html.twig', 'phone(contact.phone)'],
            'camp contact before erasure' => ['modules/camps/views/contact_anonymise.html.twig', 'phone(contact.phone)'],
            'leadership lists' => ['modules/leadership/views/partials/_person_list.html.twig', 'phone(line.phone)'],
            'booking, renter' => ['modules/rental/views/management/booking.html.twig', 'phone(booking.renterPhone)'],
            'tracking, contacts' => ['modules/rental/views/public/tracking.html.twig', 'phone(contact.phone)'],
            'tracking, emergency' => ['modules/rental/views/public/tracking.html.twig', 'phone(emergency_phone)'],
            'asset, emergency' => ['modules/rental/views/config/asset.html.twig', 'phone(asset.emergencyPhone)'],
            'trombinoscope' => ['modules/trombinoscope/views/partials/contacts.html.twig', 'phone(contact_phone'],
            'carpool, driver on the card' => ['modules/covoiturage/views/_offer_card.html.twig', 'phone(_mine.driver_phone)'],
            'carpool, requester' => ['modules/covoiturage/views/_offer_card.html.twig', 'phone(request.phone)'],
            'carpool banner, driver' => ['modules/covoiturage/views/show.html.twig', 'phone(_banner.driver_phone)'],
            'carpool banner, passenger' => ['modules/covoiturage/views/show.html.twig', 'phone(passenger.phone)'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('screens')]
    public function testEveryScreenShowsItsNumberThroughThePhoneFunction(string $template, string $call): void
    {
        $this->assertStringContainsString($call, (string) file_get_contents(dirname(__DIR__, 2) . '/' . $template));
    }

    /**
     * @return list<string>
     */
    private function twigFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.twig')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
