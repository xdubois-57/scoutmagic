<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateRenderer;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;

/**
 * Task\SendResponseDigestHandler's business logic: for every form carrying
 * a digest address, mail THAT address a summary of the responses submitted
 * since the last digest — no email at all when there are none (module
 * spec §7).
 *
 * The address used to be the article's author's, derived on every run from
 * `created_by` (issue #738). Two things were wrong with that: nobody could
 * change it without changing the article's author, and the registrations
 * of an event usually want a function's mailbox rather than whoever
 * happened to type the article. The form now carries the address, seeded
 * from the author's when it is created and its own from then on.
 *
 * One consequence worth naming: this class no longer reads the article's
 * author at all. The article is still fetched — the digest names it and
 * links to its responses — but an article whose author's account has
 * since been deleted now gets its digest sent, where before it was
 * silently skipped.
 */
class DigestService
{
    public function __construct(
        private FormRepository $formRepository,
        private FormResponseRepository $responseRepository,
        private ArticleRepository $articleRepository,
        private MailService $mailService,
        private EmailTemplateRenderer $emailTemplateRenderer,
        private string $siteName,
        private string $baseUrl
    ) {
    }

    public function sendPendingDigests(): void
    {
        foreach ($this->formRepository->findAllWithDigestEmail() as $form) {
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

            // The address is re-checked here rather than trusted from the
            // query: `findAllWithDigestEmail()` already excludes the empty
            // string, and this is what keeps a row edited by hand, or by a
            // future caller, from reaching MailService with nothing to
            // send to.
            $to = trim((string) $form->digestEmail);
            if ($article !== null && $to !== '') {
                $this->sendDigestEmail($to, $article->title, $article->id, count($newResponses));
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

        // Through the register (ARCHITECTURE.md §8.7bis): the declared
        // subject is « Nouvelles réponses — {{ article_title }} », so with
        // nothing customised this is the message it replaces, unchanged.
        $email = $this->emailTemplateRenderer->render('news.digest', $context);

        $this->mailService->send(
            to: $to,
            subject: $email->subject,
            bodyHtml: $email->bodyHtml,
            bodyText: $email->bodyText
        );
    }
}
