<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Task;

use Core\Mail\Template\EmailTemplateOverrideRepository;
use Core\Mail\Template\EmailTemplateRegistry;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Module\ModuleManifest;
use Core\Scheduler\TaskContext;
use Core\View\TwigFactory;

/**
 * The core e-mail renderer as this module's task handlers need it: core's
 * templates plus this module's own. A handler runs outside the composition
 * root, so nothing has aggregated the manifests for it (ARCHITECTURE.md
 * §8.7bis). A customisation is honoured all the same: that lives in the
 * database, not in the registry.
 *
 * One builder for every handler that sends — the re-enrolment campaign and
 * the decision e-mails (issue #905) — so both read the same declarations.
 */
final class TaskEmailRenderer
{
    public static function create(TaskContext $context): EmailTemplateRenderer
    {
        $registry = new EmailTemplateRegistry();
        $registry->registerModuleManifest(ModuleManifest::fromFile(dirname(__DIR__, 2) . '/module.json'));

        return new EmailTemplateRenderer(
            TwigFactory::create(
                dirname(__DIR__, 4) . '/core/View/templates',
                false,
                ['registration' => dirname(__DIR__, 2) . '/views']
            ),
            $registry,
            new EmailTemplateOverrideRepository($context->connection->getPdo()),
            $context->journal
        );
    }
}
