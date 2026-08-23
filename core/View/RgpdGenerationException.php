<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

/**
 * A refusal to publish AI-generated RGPD content, addressed to the admin
 * who pressed « Générer » — the AI service being unavailable, the produced
 * text not designating the deploying unit as data controller, or a response
 * still truncated after every continuation attempt.
 *
 * Marked {@see \Core\Exception\UserFacingException} because these three
 * messages are the whole point of the catch block in
 * Core\Http\Controller\RgpdConfigController::generate(): they tell the
 * admin exactly what to change in their custom instructions and retry.
 *
 * Deliberately NOT used for the regex failures in sanitizeHtmlOutput()
 * (« Erreur regex lors du nettoyage du HTML généré (code fence début) »):
 * those name an internal cleanup step, mean nothing to an admin, and stay
 * plain \RuntimeException so the display site substitutes its fallback.
 */
class RgpdGenerationException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
