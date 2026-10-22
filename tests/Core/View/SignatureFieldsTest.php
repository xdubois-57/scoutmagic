<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Http\Request;
use Core\Signature\PersonalSignatureService;
use Core\Signature\SignatureSubmission;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;
use Twig\Environment;

/**
 * The fields of one signer (issue #989, lot D,
 * `partials/signature_fields.html.twig`): the body of the signature
 * dialog, and what a page signing for two people in one form of its own
 * includes once per signer, each under its own prefix.
 */
final class SignatureFieldsTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = TestTwig::withTemplates([
            // A page of the test's own: one form, two signers.
            'two_signers.html.twig' => <<<'TWIG'
                <form method="post" action="/sign" data-signature-form data-signature-inline-form>
                {% include 'partials/signature_fields.html.twig' with {
                    id: 'renter', name_prefix: 'renter_', witness: true,
                } only %}
                {% include 'partials/signature_fields.html.twig' with {
                    id: 'manager', has_stored_signature: stored,
                } only %}
                </form>
                TWIG,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function fields(array $options = []): string
    {
        return $this->twig->render('partials/signature_fields.html.twig', $options + ['id' => 'sign']);
    }

    /** @return list<string> the name of every field of the HTML, in order */
    private static function names(string $html): array
    {
        preg_match_all('#\sname="([^"]+)"#', $html, $m);

        return $m[1];
    }

    public function testOneSignerIsOneContainerWithOnePadAndOneHiddenField(): void
    {
        $html = $this->fields();

        $this->assertSame(1, substr_count($html, 'data-signature-field'));
        $this->assertStringContainsString('<div data-signature-field data-signature-mode="new">', $html);
        $this->assertSame(1, substr_count($html, 'data-signature-pad'));
        $this->assertSame(1, substr_count($html, 'name="signature_data"'));
        $this->assertSame(
            ['signature_source', 'signature_data', 'signature_file', 'remember_signature'],
            self::names($html)
        );
        $this->assertStringNotContainsString('<form', $html, 'the form is the page’s, or the dialog’s');
    }

    /** Every field takes the prefix: one signer's drawing never lands in the other's field. */
    public function testThePrefixGoesInFrontOfEveryFieldName(): void
    {
        $this->assertSame(
            ['renter_signature_source', 'renter_signature_data', 'renter_signature_file', 'renter_remember_signature'],
            self::names($this->fields(['name_prefix' => 'renter_']))
        );
        $this->assertSame(
            ['renter_signature_source', 'renter_' . PersonalSignatureService::WITNESS_NAME_FIELD, 'renter_signature_data'],
            self::names($this->fields(['name_prefix' => 'renter_', 'witness' => true]))
        );
        $this->assertSame(['manager_signature_source'], self::names($this->fields([
            'name_prefix' => 'manager_',
            'has_stored_signature' => true,
        ])));
    }

    public function testTheModesAreTheDialogs(): void
    {
        $stored = $this->fields(['has_stored_signature' => true, 'manage_return' => '/retour']);
        $this->assertStringContainsString('data-signature-mode="stored"', $stored);
        $this->assertStringContainsString('data-signature-preview', $stored);
        $this->assertStringContainsString('href="/account/signature?retour=%2Fretour">Gérer ma signature</a>', $stored);
        $this->assertStringNotContainsString('<img', $stored);
        $this->assertStringNotContainsString('/account/signature/image', $stored);

        $witness = $this->fields(['witness' => true, 'has_stored_signature' => true]);
        $this->assertStringContainsString('data-signature-mode="witness"', $witness);
        $this->assertStringNotContainsString('data-signature-preview', $witness);
        $this->assertStringNotContainsString('signature_file', $witness);
        $this->assertStringNotContainsString('remember_signature', $witness);
        $this->assertStringContainsString("elle n'est gardée nulle part ailleurs", html_entity_decode($witness, ENT_QUOTES));
    }

    /**
     * Two signers in one form: two containers, two pads, two hidden
     * fields, and the element ids of one never repeat the other's.
     */
    public function testTwoSignersInOneFormKeepApart(): void
    {
        $html = $this->twig->render('two_signers.html.twig', ['stored' => false]);

        $this->assertSame(2, substr_count($html, 'data-signature-field'));
        $this->assertSame(2, substr_count($html, 'data-signature-pad'));
        $this->assertSame(1, substr_count($html, 'name="renter_signature_data"'));
        $this->assertSame(1, substr_count($html, 'name="signature_data"'));
        $names = self::names($html);
        $this->assertSame($names, array_values(array_unique($names)), 'a field name posted twice');
        preg_match_all('#\sid="([^"]+)"#', $html, $ids);
        $this->assertSame($ids[1], array_values(array_unique($ids[1])), 'an element id rendered twice');
    }

    /**
     * What the page renders is what the server reads back: each signer's
     * fields, under the same prefix, and never the other's.
     */
    public function testTheNamesRenderedAreTheNamesTheServerReads(): void
    {
        $html = $this->twig->render('two_signers.html.twig', ['stored' => true]);
        $body = [];
        foreach (self::names($html) as $name) {
            $body[$name] = match (true) {
                str_ends_with($name, 'signature_source') => str_starts_with($name, 'renter_') ? 'new' : 'stored',
                str_ends_with($name, 'signature_data') => 'data:image/png;base64,AAAA',
                default => 'Jeanne Martin',
            };
        }
        $request = new Request('POST', '/sign', [], $body, [], []);

        $this->assertTrue(SignatureSubmission::fromRequest($request)->useStored);
        $renter = SignatureSubmission::fromRequest($request, true, false, 'renter_');
        $this->assertFalse($renter->useStored);
        $this->assertSame('data:image/png;base64,AAAA', $renter->drawing);
        $this->assertSame('Jeanne Martin', $body['renter_' . PersonalSignatureService::WITNESS_NAME_FIELD]);
    }
}
