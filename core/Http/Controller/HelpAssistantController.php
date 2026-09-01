<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Exception\UserFacingMessage;
use Core\Help\Assistant\AssistantAnswer;
use Core\Help\Assistant\AssistantException;
use Core\Help\Assistant\AssistantService;
use Core\Help\Assistant\AssistantSession;
use Core\Help\HelpService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\Role;
use Core\View\MarkdownRenderer;
use Modules\LlmConnector\Api\LlmException;
use Twig\Environment;

/**
 * The assistant's two surfaces (ARCHITECTURE.md §8.87): the page at
 * /aide/assistant, and the endpoint both it and the help panel post to.
 *
 * **The route order matters.** Core\Http\Router::resolve() keeps the
 * FIRST route that matches, in registration order, so /aide/assistant is
 * registered BEFORE /aide/{topic} in public/index.php — otherwise the
 * topic route swallows it and the assistant becomes a 404 for a topic
 * called "assistant". Core\Help\HelpFrontMatterParser reserves that id
 * for the same reason, from the other side.
 *
 * **The answer is never rendered raw.** It comes from a language model
 * and is therefore untrusted content, exactly like anything else a third
 * party wrote: it goes through Core\View\MarkdownRenderer with
 * HelpController::RENDER_OPTIONS, which escapes the HTML. The panel and
 * the page both receive it already rendered.
 *
 * **Every gate is server-side.** The role floor is the route's
 * (`role_min: chief`, enforced by the router before this class is
 * instantiated), the role used to build the catalogue and to re-check
 * every topic id is the CURRENT session's, and the quota is charged to
 * the signed-in account — none of it travels in the request.
 */
class HelpAssistantController extends AbstractController
{
    public function __construct(
        Environment $twig,
        private readonly AssistantService $assistant,
        private readonly HelpService $helpService,
    ) {
        parent::__construct($twig);
    }

    /**
     * GET /aide/assistant — the full page, for a conversation that wants
     * more room than the panel gives it.
     *
     * The page renders whether or not the connector is there: with none,
     * it says so and points at the search, which is the whole point of
     * the search existing first (locked decision D2).
     *
     * @param array<string, string> $params
     */
    public function page(Request $request, array $params): Response
    {
        return $this->render('help/assistant.html.twig', [
            'assistant_available' => $this->assistant->isAvailable(),
            'breadcrumb_current' => "Assistant",
        ]);
    }

    /**
     * POST /api/aide/assistant — one question, one answer.
     *
     * Answers cleanly whatever the state: with the connector absent it is
     * a 503 with a French sentence, never a 404. A route that vanishes
     * when a module is disabled would make the frontend guess.
     *
     * @param array<string, string> $params
     */
    public function ask(Request $request, array $params): Response
    {
        // The payload is JSON (public/assets/js/api.js's postJson), so the
        // token is read out of it rather than out of $_POST — the same
        // shape as every other JSON endpoint here.
        $body = json_decode((string) $request->getRawBody(), true);
        $body = is_array($body) ? $body : [];

        if (($guard = $this->guardCsrfJson($request, isset($body['_csrf_token']) && is_string($body['_csrf_token']) ? $body['_csrf_token'] : null)) !== null) {
            return $guard;
        }

        $role = Role::fromString(AuthSession::getRole());
        $userAccountId = AuthSession::getUserAccountId();
        if ($userAccountId === null) {
            // The route is role_min: chief, so the guard has already run
            // — this is the belt to its braces, and the one case where
            // there is nobody to charge the quota to.
            return $this->json([
                'success' => false,
                'error' => self::SESSION_EXPIRED_MESSAGE,
            ], 403);
        }

        if (!$this->assistant->isAvailable()) {
            return $this->json([
                'success' => false,
                'error' => "L'assistant n'est pas disponible sur ce site. "
                    . "La recherche dans l'aide, elle, fonctionne toujours.",
            ], 503);
        }

        $question = isset($body['question']) && is_string($body['question']) ? trim($body['question']) : '';
        $currentPath = isset($body['path']) && is_string($body['path']) ? trim($body['path']) : '';

        try {
            $answer = $this->assistant->ask(
                $question,
                $role,
                $userAccountId,
                AssistantSession::history(),
                $currentPath !== '' ? $currentPath : null,
                $this->topicIdsFor($currentPath, $role)
            );
        } catch (AssistantException $e) {
            // Its message is written for the reader (it implements
            // UserFacingException); the quota case is the one worth its
            // own status, so the frontend can tell "come back later" from
            // "that question will never work".
            $spentQuota = str_contains($e->getMessage(), 'Réessayez');

            return $this->json(['success' => false, 'error' => $e->getMessage()], $spentQuota ? 429 : 400);
        } catch (LlmException $e) {
            // Already user-facing and already French — shown as it comes,
            // never re-wrapped (AGENTS.md § Exception messages).
            return $this->json(['success' => false, 'error' => $e->getMessage()], 502);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'error' => UserFacingMessage::from($e, "L'assistant n'a pas pu répondre. Réessayez dans un instant."),
            ], 500);
        }

        if (!$answer->foundNothing) {
            AssistantSession::remember($question, $answer->text);
        }

        return $this->json([
            'success' => true,
            'found_nothing' => $answer->foundNothing,
            // Rendered here, escaped there: a model's output is untrusted
            // content and never reaches a template as raw HTML.
            'answer_html' => $answer->foundNothing
                ? ''
                : MarkdownRenderer::toHtml($answer->text, HelpController::RENDER_OPTIONS),
            'topics' => array_map(
                static fn ($topic): array => ['id' => $topic->id, 'title' => $topic->title],
                $answer->topics
            ),
        ]);
    }

    /**
     * The topics covering the page the question was asked from — the
     * anchor that makes « comment je fais ça ? » answerable.
     *
     * Resolved HERE from the path, never taken from the request: a client
     * that could name the topics could name ones it may not see.
     *
     * @return string[]
     */
    private function topicIdsFor(string $path, Role $role): array
    {
        if ($path === '' || !str_starts_with($path, '/')) {
            return [];
        }

        return array_map(
            static fn ($topic): string => $topic->id,
            $this->helpService->findForPath($path, $role)
        );
    }
}
