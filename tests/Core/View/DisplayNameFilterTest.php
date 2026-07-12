<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Member\MemberProfile;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class DisplayNameFilterTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = new Environment(new ArrayLoader());
        $this->twig->addFilter(new \Twig\TwigFilter('display_name', function ($member) {
            if ($member instanceof \Core\Member\MemberProfile) {
                return $member->getDisplayName();
            }
            // Also handle arrays (from menu builder)
            if (is_array($member)) {
                return $member['totem'] ?? $member['first_name'] ?? '?';
            }
            return (string) $member;
        }));
    }

    public function testFilterWithMemberProfileThatHasTotemReturnsTotem(): void
    {
        $member = new MemberProfile(
            memberYearId: 1,
            memberId: 1,
            deskId: 'T001',
            firstName: 'John',
            lastName: 'Doe',
            totem: 'Baloo',
            quali: 'Joyeux',
            gender: null,
            birthDate: null,
            phone: null,
            mobile: null,
            email: null,
            patrol: null,
            formationLevel: null,
            federationMailConsent: false,
            unitMailConsent: false,
            addresses: [],
            functions: [],
            scoutYearLabel: '2025-2026'
        );

        $template = $this->twig->createTemplate('{{ member|display_name }}');
        $result = $template->render(['member' => $member]);

        $this->assertSame('Baloo', $result);
    }

    public function testFilterWithMemberProfileWithoutTotemReturnsFirstName(): void
    {
        $member = new MemberProfile(
            memberYearId: 1,
            memberId: 1,
            deskId: 'T001',
            firstName: 'John',
            lastName: 'Doe',
            totem: null,
            quali: null,
            gender: null,
            birthDate: null,
            phone: null,
            mobile: null,
            email: null,
            patrol: null,
            formationLevel: null,
            federationMailConsent: false,
            unitMailConsent: false,
            addresses: [],
            functions: [],
            scoutYearLabel: '2025-2026'
        );

        $template = $this->twig->createTemplate('{{ member|display_name }}');
        $result = $template->render(['member' => $member]);

        $this->assertSame('John', $result);
    }

    public function testFilterWithArrayContainingTotemReturnsTotem(): void
    {
        $member = ['totem' => 'Mowgli', 'first_name' => 'John'];

        $template = $this->twig->createTemplate('{{ member|display_name }}');
        $result = $template->render(['member' => $member]);

        $this->assertSame('Mowgli', $result);
    }

    public function testFilterWithArrayWithoutTotemReturnsFirstName(): void
    {
        $member = ['first_name' => 'Jane', 'last_name' => 'Doe'];

        $template = $this->twig->createTemplate('{{ member|display_name }}');
        $result = $template->render(['member' => $member]);

        $this->assertSame('Jane', $result);
    }

    public function testFilterWithArrayWithoutTotemOrFirstNameReturnsQuestionMark(): void
    {
        $member = ['last_name' => 'Doe'];

        $template = $this->twig->createTemplate('{{ member|display_name }}');
        $result = $template->render(['member' => $member]);

        $this->assertSame('?', $result);
    }

    public function testFilterWithStringReturnsString(): void
    {
        $template = $this->twig->createTemplate('{{ member|display_name }}');
        $result = $template->render(['member' => 'TestString']);

        $this->assertSame('TestString', $result);
    }
}
