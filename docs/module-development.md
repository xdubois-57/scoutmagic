# Developing a module

This guide explains how to create a module for ScoutMagic. Modules are self-contained features that integrate with the core system via a standardized manifest and lifecycle.

## Directory structure

```
modules/my_module/
  module.json          # Manifest (required)
  schema.sql           # Database tables (optional)
  src/
    Controller/
      MyModuleController.php
      ConfigController.php
    Service/
      MyModuleService.php
    Repository/
      MyModuleRepository.php
  views/
    index.html.twig
    config.html.twig
```

The directory name **must** match the `id` field in `module.json`.

## module.json — full annotated example

```json
{
  "id": "calendar",
  "name": "Calendrier des activités",
  "version": "1.2.0",
  "routes": [
    {
      "path": "/calendar",
      "method": "GET",
      "controller": "Modules\\Calendar\\Controller\\CalendarController",
      "action": "index",
      "menu": "espace_animes",
      "role_min": "identified",
      "label": "Calendrier"
    },
    {
      "path": "/calendar/event/{id}",
      "method": "GET",
      "controller": "Modules\\Calendar\\Controller\\CalendarController",
      "action": "show",
      "menu": "espace_animes",
      "role_min": "identified",
      "label": ""
    },
    {
      "path": "/config/calendar",
      "method": "GET",
      "controller": "Modules\\Calendar\\Controller\\ConfigController",
      "action": "index",
      "menu": "configuration",
      "role_min": "admin",
      "label": "Calendrier"
    }
  ],
  "settings": [
    {
      "key": "default_view",
      "default_value": "month",
      "type": "select",
      "label": "Vue par défaut",
      "description": "Type d'affichage par défaut du calendrier"
    },
    {
      "key": "show_past_events",
      "default_value": "1",
      "type": "boolean",
      "label": "Afficher les activités passées",
      "description": "Affiche les activités passées dans le calendrier"
    }
  ],
  "cookies": [
    {
      "name": "calendar_view",
      "category": "functional",
      "purpose": "Mémorise le type d'affichage choisi (mois/semaine)",
      "duration": "1 an"
    }
  ],
  "scheduled_tasks": [
    {
      "key": "send_reminders",
      "handler": "Modules\\Calendar\\Task\\SendRemindersHandler"
    }
  ],
  "storage": {
    "attachments": {
      "role_min": "identified"
    }
  }
}
```

## Manifest validation rules

- **id**: required, must match directory name.
- **name**: required, non-empty string (displayed in UI, in French).
- **version**: required, semver format (`x.y.z`).
- **routes**: each entry must have `path`, `controller`, `action`, `menu`, `role_min`.
  - `menu`: one of `notre_unite`, `espace_animes`, `espace_chefs`, `espace_admin`, `configuration`.
  - `role_min`: one of `public`, `identified`, `intendant`, `chief`, `admin`.
  - A route's `role_min` must not be more permissive than its menu's minimum role.
  - `method`: optional (defaults to `GET`).
  - `label`: if non-empty, the route is added to the menu with this label.
- **settings**: optional, each entry must have `key`, `type`, `label`, `description`.
- **cookies**: optional, each entry must have `name`, `category`, `purpose`, `duration`.
  - `category`: one of `necessary`, `functional`, `analytics`.
- **scheduled_tasks**: optional, each entry must have `key`, `handler` (FQCN).
- **storage**: optional, keys are subdirectory names, values have `role_min`.

## Controller conventions

- Extend `Core\Http\Controller\AbstractController`.
- Use `$this->render('@my_module/index.html.twig', [...])` for views (Twig namespace matches module id).
- Receive the Twig `Environment` via constructor.
- All controllers are instantiated by the FrontController using the registered Twig environment.

Example:

```php
<?php

declare(strict_types=1);

namespace Modules\Calendar\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Twig\Environment;

class CalendarController extends AbstractController
{
    public function __construct(protected Environment $twig)
    {
    }

    public function index(Request $request, array $params): Response
    {
        return $this->render('@calendar/index.html.twig', [
            'events' => [],
        ]);
    }
}
```

## Accessing core services

Module controllers that need core services must be registered with the FrontController manually, or use constructor injection via the standard pattern. For now, module controllers receive only `$twig` by default. If a module needs additional services, it should document this requirement.

## Database

- Create a `schema.sql` in the module root with complete table definitions (not incremental migrations).
- Table names should be prefixed with the module id to avoid collisions (e.g., `calendar_events`).
- All table/column names in English, snake_case.
- Personal data fields must use `BLOB` type and be encrypted/decrypted via `EncryptionService`.
- Include a `scout_year_id` foreign key on member-related data tables.

Example `schema.sql`:

```sql
CREATE TABLE IF NOT EXISTS calendar_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scout_year_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    event_date DATE NOT NULL,
    description TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (scout_year_id) REFERENCES scout_years(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Settings

- Declared in `module.json` under the `settings` section.
- Registered automatically when the module is activated.
- Appear in the Paramètres page, grouped by module.
- Access in code: `$settingService->get('default_view', 'calendar')`.
- Access in Twig: `{{ param('default_view', 'calendar') }}`.

## Cookies

- Declared in `module.json` under the `cookies` section.
- Automatically appear in the consent banner, preferences page, and RGPD page.
- Before setting a cookie: always check `$cookieConsentService->isAllowed('functional')`.

## Scheduled tasks

- Declared in `module.json` under the `scheduled_tasks` section.
- The `handler` field is a fully qualified class name implementing `Core\Scheduler\TaskHandlerInterface`.
- The handler receives `array $payload` and `Core\Scheduler\TaskContext $context`.
- `TaskContext` provides: `$context->connection`, `$context->encryption`, `$context->mailService`, `$context->journal`, `$context->settings`.

Example handler:

```php
<?php

declare(strict_types=1);

namespace Modules\Calendar\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

class SendRemindersHandler implements TaskHandlerInterface
{
    public function handle(array $payload, TaskContext $context): void
    {
        // Use $context->mailService to send reminders
        // Use $context->journal to log the action
    }
}
```

Schedule a task from a service:

```php
$schedulerService->schedule('calendar', 'send_reminders', $runAt, ['event_id' => 42]);
```

## Module lifecycle

1. **Discovery**: The `ModuleManager` scans `modules/` and reads `module.json` files.
2. **Activation**: Admin toggles the module on via Configuration générale. This runs `schema.sql`, registers default settings, and marks the module as enabled.
3. **Loading**: On every request, enabled modules have their routes, settings, cookies, menu pages, and task handlers registered.
4. **Deactivation**: Admin toggles the module off. Routes become 404, menu entries disappear. **Data and settings are never deleted.**

## Important rules

- Never duplicate core functionality (auth, session, encryption, journal, mail, scheduler, cookie consent).
- Never modify `schema/core.sql` for module-specific needs.
- Never write your own log table — use `JournalService`.
- Never access `$_SESSION` or `$_POST` directly in services.
- All SQL must use prepared statements (no concatenation).
- Every route must have `role_min`.
- All code and comments in English; all UI text in French.
- Automated tests are mandatory for every feature.
