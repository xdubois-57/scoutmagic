<?php

declare(strict_types=1);

namespace Modules\News\Service;

use Core\Mail\MailService;
use Core\Security\UserAccountRepository;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Twig\Environment;

/**
 * Task\SendResponseDigestHandler's business logic: for every form with
 * daily_digest_enabled, mail the article's author a summary of responses
 * submitted since the last digest — no email at all when there are none
 * (module spec §7).
 */
class DigestService
{
    public function __construct(
        private FormRepository $formRepository,
        private FormResponseRepository $responseRepository,
        private ArticleRepository $articleRepository,
        private UserAccountRepository $userAccountRepository,
        private MailService $mailService,
        private Environment $twig,
        private string $siteName,
        private string $baseUrl
    ) {
    }

    public function sendPendingDigests(): void
    {
        foreach ($this->formRepository->findAllWithDigestEnabled() as $form) {
            // '1970-01-01' (not $form->createdAt) as the first-run
            // baseline: a response submitted the same second the form was
            // created would tie with createdAt under a strict ">"
            // comparison and be silently skipped by the very first digest.
            $since = $form->lastDigestSentAt ?? '1970-01-01 00:00:00';
            $newResponses = $this->responseRepository->findByFormIdSince($form->id, $since);

            $now = date('Y-m-d H:i:s');
            if ($newResponses === []) {
                $this->formRepository->markDigestSent($form->id, $now);
                continue;
            }

            $article = $this->articleRepository->findById($form->newsArticleId);
            $author = $article !== null ? $this->userAccountRepository->findById($article->createdBy) : null;

            if ($article !== null && $author !== null) {
                $this->sendDigestEmail($author->email, $article->title, $article->id, count($newResponses));
            }

            $this->formRepository->markDigestSent($form->id, $now);
        }
    }

    private function sendDigestEmail(string $to, string $articleTitle, int $articleId, int $count): void
    {
        $context = [
            'site_name' => $this->siteName,
            'article_title' => $articleTitle,
            'count' => $count,
            'responses_url' => rtrim($this->baseUrl, '/') . '/news/' . $articleId . '/form/responses',
        ];

        $bodyHtml = $this->twig->render('@news/email/digest.html.twig', $context);
        $bodyText = $this->twig->render('@news/email/digest.text.twig', $context);

        $this->mailService->send(
            to: $to,
            subject: 'Nouvelles réponses — ' . $articleTitle,
            bodyHtml: $bodyHtml,
            bodyText: $bodyText
        );
    }
}
