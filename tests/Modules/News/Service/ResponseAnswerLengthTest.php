<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Modules\News\Repository\FormField;
use Modules\News\Service\FieldAnswerException;
use Modules\News\Service\ResponseService;
use PHPUnit\Framework\TestCase;

/**
 * The length rule of a form answer (issue #1486), with no database: what
 * is counted, where the limit falls, and the sentence the respondent reads.
 * ResponseServiceAnswerLengthOnMysqlTest replays it on the engine whose
 * column the limit exists for.
 */
final class ResponseAnswerLengthTest extends TestCase
{
    private static function remarks(): FormField
    {
        return new FormField(7, 1, 0, FormField::TYPE_LONG_TEXT, 'Remarques', false, null, null, null, null, null);
    }

    /**
     * The premise of the cap, as arithmetic: the widest answer it admits,
     * encrypted, fits the 65 535-byte BLOB — with room to spare.
     */
    public function testTheWidestAnswerTheCapAdmitsFitsTheColumnOnceEncrypted(): void
    {
        $nonceAndTag = 12 + 16;

        $this->assertLessThanOrEqual(65535, 4 * FormField::MAX_ANSWER_LENGTH + $nonceAndTag);
    }

    public function testAnAnswerAtTheCapIsAccepted(): void
    {
        ResponseService::assertAnswerLength(self::remarks(), str_repeat('é', FormField::MAX_ANSWER_LENGTH));

        $this->addToAssertionCount(1);
    }

    public function testTheRefusalNamesTheFieldTheLimitInCharactersAndTheLengthTyped(): void
    {
        try {
            ResponseService::assertAnswerLength(self::remarks(), str_repeat('a', 12345));
            $this->fail('an answer of 12 345 characters was accepted');
        } catch (FieldAnswerException $e) {
            $this->assertSame(7, $e->fieldId, 'the page shows the sentence next to this field');
            $this->assertSame(
                'Le champ "Remarques" ne peut pas dépasser 10 000 caractères (votre réponse en compte 12 345) : '
                    . 'raccourcissez-la.',
                $e->getMessage()
            );
        }
    }

    /** Characters, not bytes: an accent is one, like for the person typing it. */
    public function testCharactersAreCountedNotBytes(): void
    {
        $this->assertSame(3, ResponseService::answerLength('été'));
        $this->assertSame(1, ResponseService::answerLength("\u{1F600}"));
    }

    /** A line break counts once, as the textarea's `maxlength` counted it before the browser sent CRLF. */
    public function testACrlfCountsAsOneCharacter(): void
    {
        $this->assertSame(3, ResponseService::answerLength("a\r\nb"));
        $this->assertSame(3, ResponseService::answerLength("a\nb"));
    }

    /**
     * Bytes that are not UTF-8 count one each, so no counted character is
     * ever wider than four bytes — the premise the cap's arithmetic rests on.
     */
    public function testInvalidBytesCountOneEach(): void
    {
        $this->assertSame(4, ResponseService::answerLength("\xF0\xF0\xF0\xF0"));
    }
}
