<?php

declare(strict_types=1);

namespace Tests\Modules\News\Finance;

use Core\Security\Role;
use Modules\Finance\Api\ReceivableViewer;
use Modules\News\Finance\NewsReceivableDescriber;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\NewsForm;
use PHPUnit\Framework\TestCase;

/**
 * A paid form's receivables lead to the form's answers — for whoever may
 * read them (issue #836).
 */
class NewsReceivableDescriberTest extends TestCase
{
    private function describer(bool $formExists = true, bool $articleExists = true, string $responseRoleMin = 'chief'): NewsReceivableDescriber
    {
        $forms = $this->createStub(FormRepository::class);
        $forms->method('findById')->willReturn($formExists ? new NewsForm(
            12, 5, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, $responseRoleMin, null, false,
            null, null, null, null, '2026-01-01 09:00:00'
        ) : null);
        $articles = $this->createStub(ArticleRepository::class);
        $articles->method('findById')->willReturn($articleExists
            ? new Article(5, 'Week-end Louveteaux', 'public', true, true, null, null, null, 1, '2026-01-01', '2026-01-01')
            : null);

        return new NewsReceivableDescriber($forms, $articles);
    }

    public function testItLeadsToTheFormsAnswers(): void
    {
        $destination = $this->describer()->destinationFor(12, new ReceivableViewer('c@example.org', Role::CHIEF));

        $this->assertNotNull($destination);
        $this->assertSame('/news/5/form/responses', $destination->url);
        $this->assertSame('Ouvrir les réponses du formulaire', $destination->label);
    }

    public function testNoLinkForAViewerBelowTheFormsOwnFloor(): void
    {
        // The form keeps its answers for chiefs; an intendant sees the
        // receivable on the finance account, and would get a refusal there.
        $this->assertNull($this->describer()->destinationFor(12, new ReceivableViewer('i@example.org', Role::INTENDANT)));
    }

    public function testNoLinkForAViewerBelowTheRoutesFloorEvenIfTheFormIsOpenToThem(): void
    {
        $describer = $this->describer(responseRoleMin: 'identified');

        $this->assertNull($describer->destinationFor(12, new ReceivableViewer('m@example.org', Role::IDENTIFIED)));
        $this->assertNotNull($describer->destinationFor(12, new ReceivableViewer('i@example.org', Role::INTENDANT)));
    }

    public function testAFormOrArticleThatIsGoneHasNoLink(): void
    {
        $viewer = new ReceivableViewer('a@example.org', Role::ADMIN);

        $this->assertNull($this->describer(formExists: false)->destinationFor(12, $viewer));
        $this->assertNull($this->describer(articleExists: false)->destinationFor(12, $viewer));
    }
}
