<?php

declare(strict_types=1);

namespace Tests\Modules\News\Controller;

use Modules\News\Repository\NewsForm;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;
use Twig\Environment;

/**
 * What a visitor reads when a form takes no answer (issue #925).
 *
 * The notice used to be one sentence for every reason, ending on « Il a été
 * fermé le <closing date> » — so a form closed by hand showed a closing date
 * still months away as if it had passed, and a form not open yet claimed to
 * be closed already. It now says which of the three it is, from the model's
 * own answer, and never states a date as past that is not.
 */
final class FormUnavailableNoticeTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = TestTwig::create(['news' => dirname(__DIR__, 4) . '/modules/news/views']);
    }

    private function noticeFor(bool $forceClosed, ?string $opensAt, ?string $closesAt, string $today = '2026-10-14'): string
    {
        $form = new NewsForm(
            1, 1, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, $opensAt, $closesAt,
            $forceClosed, 'chief', null, false, null, null, null, null, '2026-01-01 00:00:00'
        );

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($this->twig->render(
            '@news/partials/_form_unavailable.html.twig',
            ['form' => $form, 'form_availability' => $form->availability(new \DateTimeImmutable($today))]
        ))));
    }

    /** The screenshot of 14 October 2026: closed by hand, closing date 14 April 2027. */
    public function testAManualClosureNeverPresentsAFutureDateAsPast(): void
    {
        $notice = $this->noticeFor(true, '2026-10-14', '2027-04-14');

        $this->assertSame('Ce formulaire est fermé et n\'accepte plus de réponses.', $notice);
        $this->assertStringNotContainsString('2027', $notice);
        $this->assertStringNotContainsString('a été fermé', $notice);
    }

    public function testAFormNotYetOpenAnnouncesItsOpening(): void
    {
        $notice = $this->noticeFor(false, '2026-10-20', '2027-04-14');

        $this->assertSame('Ce formulaire n\'est pas encore ouvert. Il ouvrira le 20 octobre 2026.', $notice);
        $this->assertStringNotContainsString('fermé', $notice);
        $this->assertStringNotContainsString('2027', $notice);
    }

    public function testAnExpiredFormGivesItsClosingDateThatHasReallyPassed(): void
    {
        $notice = $this->noticeFor(false, null, '2026-04-14');

        $this->assertSame('Ce formulaire est fermé depuis le 14 avril 2026.', $notice);
    }

    public function testAManualClosureAfterTheClosingDateStillSaysItWasClosedByHand(): void
    {
        $notice = $this->noticeFor(true, null, '2026-04-14');

        $this->assertSame('Ce formulaire est fermé et n\'accepte plus de réponses.', $notice);
    }

    /** An open form has no notice to give. */
    public function testAnOpenFormRendersNothing(): void
    {
        $this->assertSame('', $this->noticeFor(false, '2026-10-01', '2026-12-01'));
    }

    /** The page must use it: nothing of the old, single sentence is left. */
    public function testTheArticlePageUsesThePartialAndNoLongerBuildsTheOldSentence(): void
    {
        $detail = (string) file_get_contents(dirname(__DIR__, 4) . '/modules/news/views/detail.html.twig');

        $this->assertStringContainsString('partials/_form_unavailable.html.twig', $detail);
        $this->assertStringNotContainsString('Il a été fermé le', $detail);
        $this->assertStringNotContainsString('not form_open', $detail);
    }
}
