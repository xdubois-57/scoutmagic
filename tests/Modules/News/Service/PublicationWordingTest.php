<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Modules\News\Repository\Article;
use Modules\News\Service\PublicationWording;
use PHPUnit\Framework\TestCase;

/**
 * The French sentences of an article's publication (issue #845), each one
 * checked on its own.
 */
final class PublicationWordingTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-11 10:00:00');
    }

    public function testTheAudienceFollowsTheEditorsVisibilityLabels(): void
    {
        $this->assertSame('tout le monde', PublicationWording::audience(Article::VISIBILITY_PUBLIC));
        $this->assertSame('les membres connectés', PublicationWording::audience(Article::VISIBILITY_IDENTIFIED));
        $this->assertSame('les animateurs', PublicationWording::audience(Article::VISIBILITY_CHIEF));
        $this->assertSame('les chefs d\'unité', PublicationWording::audience(Article::VISIBILITY_ADMIN));
        $this->assertSame('qui a le lien', PublicationWording::audience(Article::VISIBILITY_DIRECT_LINK));
    }

    public function testTheLongDateNamesTheDayAndGivesTheYearOnlyWhenItIsAnother(): void
    {
        $this->assertSame('mardi 20 octobre à 8 h 00', PublicationWording::longDateTime('2026-10-20 08:00:00', $this->now));
        $this->assertSame('vendredi 1 janvier 2027 à 18 h 30', PublicationWording::longDateTime('2027-01-01 18:30:00', $this->now));
        $this->assertSame('', PublicationWording::longDateTime('', $this->now));
        $this->assertSame('20 octobre 2026', PublicationWording::dayMonthYear('2026-10-20 08:00:00'));
    }

    /**
     * The scheduling badge of the list (#1359): the same date, without the
     * day of the week, so the badge fits a narrow column. The long format
     * every other page and message uses is untouched.
     */
    public function testTheBadgeDateDropsTheWeekdayAndKeepsTheRestOfTheLongFormat(): void
    {
        $n = "\u{00A0}";
        $this->assertSame("22{$n}novembre à 8{$n}h{$n}00", PublicationWording::dateTimeWithoutWeekday('2026-11-22 08:00:00', $this->now));
        $this->assertSame("1{$n}janvier{$n}2027 à 18{$n}h{$n}30", PublicationWording::dateTimeWithoutWeekday('2027-01-01 18:30:00', $this->now), 'the year when it is another');
        $this->assertSame("5{$n}mars à 0{$n}h{$n}05", PublicationWording::dateTimeWithoutWeekday('2026-03-05 00:05:00', $this->now), 'hours without padding, minutes with');
        $this->assertSame('', PublicationWording::dateTimeWithoutWeekday('', $this->now));
        $this->assertSame('', PublicationWording::dateTimeWithoutWeekday('pas une date', $this->now));
    }

    /** A wrapping badge breaks between the day and the time, never inside them. */
    public function testTheBadgeDateOnlyBreaksBetweenItsDayAndItsTime(): void
    {
        $text = PublicationWording::dateTimeWithoutWeekday('2026-11-22 08:00:00', $this->now);

        $this->assertSame(['22' . "\u{00A0}" . 'novembre', 'à', '8' . "\u{00A0}" . 'h' . "\u{00A0}" . '00'], explode(' ', $text));
    }

    public function testNoDayOfTheWeekIsEverNamedInTheBadgeDate(): void
    {
        foreach (range(1, 7) as $day) {
            $text = PublicationWording::dateTimeWithoutWeekday('2026-11-' . str_pad((string) (15 + $day), 2, '0', STR_PAD_LEFT) . ' 09:15:00', $this->now);
            $this->assertDoesNotMatchRegularExpression('/lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche/u', $text);
        }
        // …while the long format keeps naming it, for the pages that rely on it.
        $this->assertSame('dimanche 22 novembre à 8 h 00', PublicationWording::longDateTime('2026-11-22 08:00:00', $this->now));
    }

    /**
     * One question per visibility the editor offers, naming whom it is for
     * — the page picks the one matching the radio, and fills the title in.
     */
    public function testPublishAsksForWhomForEveryVisibility(): void
    {
        $questions = PublicationWording::publishQuestions(null, $this->now);

        $this->assertSame(Article::VISIBILITIES, array_keys($questions));
        $this->assertSame('Publier pour tout le monde ?', $questions[Article::VISIBILITY_PUBLIC]['title']);
        $this->assertSame('Publier pour les animateurs ?', $questions[Article::VISIBILITY_CHIEF]['title']);
        foreach ($questions as $question) {
            $this->assertStringContainsString('« {titre} »', $question['message']);
            $this->assertStringContainsString('Son formulaire s\'ouvrira aux réponses.', $question['message']);
        }
        $this->assertStringContainsString('aucune liste', $questions[Article::VISIBILITY_DIRECT_LINK]['message']);
    }

    public function testAFirstPublicationIsDatedToday(): void
    {
        $message = PublicationWording::publishQuestions(null, $this->now)[Article::VISIBILITY_PUBLIC]['message'];

        $this->assertStringEndsWith("\nSa date de publication sera celle d'aujourd'hui, et ne changera plus.", $message);
    }

    public function testARepublicationKeepsItsDate(): void
    {
        $message = PublicationWording::publishQuestions('2026-09-01 08:00:00', $this->now)[Article::VISIBILITY_PUBLIC]['message'];

        $this->assertStringEndsWith("\nElle garde sa date de publication du 1 septembre 2026.", $message);
    }

    public function testUnpublishSaysWhatDisappearsAndWhatIsKept(): void
    {
        $question = PublicationWording::unpublishQuestion('Souper', 3, '2026-09-01 08:00:00', $this->now);

        $this->assertSame(
            "« Souper » disparaîtra des pages du site jusqu'à sa republication.\n"
            . 'Son formulaire a reçu 3 réponses. Elles sont conservées, avec leurs paiements et leurs billets, '
            . "mais le formulaire sera fermé jusqu'à la republication.\n"
            . 'Republiée, elle gardera sa date de publication du 1 septembre.',
            $question
        );
    }

    public function testUnpublishWithOneResponseAndNoPastDate(): void
    {
        $question = PublicationWording::unpublishQuestion('Souper', 1, '2999-01-01 08:00:00', $this->now);

        $this->assertStringContainsString('a reçu 1 réponse. Elle est conservée', $question);
        $this->assertStringNotContainsString('Republiée', $question);
        $this->assertSame(
            "« Souper » disparaîtra des pages du site jusqu'à sa republication.",
            PublicationWording::unpublishQuestion('Souper', 0, null, $this->now)
        );
    }

    public function testTheScheduleDialogSaysForWhomAndThatTheAuthorWillBeTold(): void
    {
        $messages = PublicationWording::scheduleMessages();

        $this->assertSame(Article::VISIBILITIES, array_keys($messages));
        $this->assertSame(
            'À l\'heure choisie, « {titre} » apparaîtra pour les membres connectés, et son formulaire '
            . 's\'ouvrira aux réponses. Vous serez prévenu.',
            $messages[Article::VISIBILITY_IDENTIFIED]
        );
    }
}
