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
            'J.-P. Dupont',
            'St.Gilles',
            'Jean.Louis',
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
            // A bare domain, with or without a path (CodeRabbit on #1179).
            'Jeanne — consultez exemple.com',
            'voir evil.be/login',
            'EXEMPLE.COM',
            'jean@exemple.be',
            'bit.ly/abc',
        ] as $name) {
            $this->assertSame(PublicFormName::MESSAGE_WEB_ADDRESS, PublicFormName::violation($name), $name);
        }
    }

    /**
     * An invisible format character would split `www.` or `https://` for
     * the check while the recipient's mail client still shows the address
     * whole (review of #1179) — refused, not stripped.
     */
    public function testAnInvisibleFormatCharacterIsRefused(): void
    {
        foreach (["www\u{200B}.evil.test", "https\u{200B}://evil.test", "Jeanne\u{FEFF}", "Jeanne\u{2060}", "Jeanne\u{202E}"] as $name) {
            $this->assertSame(PublicFormName::MESSAGE_INVISIBLE, PublicFormName::violation($name), json_encode($name));
        }
    }

    public function testATextThatIsNotUtf8IsRefused(): void
    {
        $this->assertSame(PublicFormName::MESSAGE_LINE_BREAK, PublicFormName::violation("Jeanne \xC3\x28"));
    }
}
