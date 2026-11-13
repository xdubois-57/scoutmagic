<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Mail\PublicFormName;
use PHPUnit\Framework\TestCase;

/**
 * A name typed into a public form is a name (issue #1114): one line, no web
 * address — and every real name still passes.
 */
class PublicFormNameTest extends TestCase
{
    public function testRealNamesPass(): void
    {
        foreach ([
            'Jeanne Martin',
            "Anne-Sophie d'Ursel",
            'Ève Ñúñez-Øster',
            'J. R. R. Tolkien',
            'Les Scouts de Nulle Part — SV025',
            'Marie (Mimi) Dupont',
        ] as $name) {
            $this->assertNull(PublicFormName::violation($name), $name);
        }
    }

    public function testEveryKindOfLineBreakIsRefused(): void
    {
        foreach (["a\nb", "a\r\nb", "a\rb", "a\tb", "a\u{2028}b", "a\u{2029}b", "a\u{0085}b", "a\0b"] as $name) {
            $this->assertSame(PublicFormName::MESSAGE_LINE_BREAK, PublicFormName::violation($name), json_encode($name));
        }
    }

    public function testAWebAddressIsRefusedWhateverItsScheme(): void
    {
        foreach ([
            'voir https://exemple.test',
            'HTTP://EXEMPLE.TEST',
            'hxxp://exemple.test',
            'ftp://exemple.test',
            'www.exemple.test',
            'Jeanne WWW.exemple.test',
        ] as $name) {
            $this->assertSame(PublicFormName::MESSAGE_WEB_ADDRESS, PublicFormName::violation($name), $name);
        }
    }

    public function testATextThatIsNotUtf8IsRefused(): void
    {
        $this->assertSame(PublicFormName::MESSAGE_LINE_BREAK, PublicFormName::violation("Jeanne \xC3\x28"));
    }
}
