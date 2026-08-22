# Chantier — Squelette de site partagé

Document de spécification du futur composant partagé (« le squelette ») :
un package extrait de ScoutMagic, hébergé dans son propre repository
GitHub, dont ScoutMagic deviendra le premier consommateur. Ce document
consolide la discussion de cadrage ; il est destiné à être relu et amendé
avant l'ouverture du chantier. Aucune ligne de code n'a encore été
modifiée.

---

## 1. Vision et objectifs

Extraire de ScoutMagic un squelette de site web réutilisable, pour
construire plusieurs sites sur la même base technique sans dupliquer ni
forker le socle. ScoutMagic est déjà structuré comme si ce squelette
existait : le dossier `core/` sépare nettement la mécanique générique
(HTTP, sécurité, modules, vues) du métier scout (membres, années scoutes,
badges, import Desk). Le chantier est donc un travail d'**extraction et de
découplage**, pas de réécriture.

Objectifs non négociables :

- **Jamais de fork.** Un site consommateur ne modifie jamais un fichier du
  squelette. Toute personnalisation passe par un point d'extension ; si un
  besoin ne peut pas être couvert sans toucher au squelette, la réponse
  est un nouveau point d'extension en amont, pas une copie locale.
- **Mise à jour facile.** Publier une version du squelette doit propager
  la mise à jour vers les sites consommateurs avec un effort minimal
  (idéalement : une PR de bump automatique à merger quand la CI est
  verte).
- **La philosophie ScoutMagic est conservée** : PHP 8.4, hébergement
  mutualisé (FTP + MySQL), aucun outil de build front en production,
  dépendances externes rares et justifiées, Bootstrap 5 en fichiers
  compilés, `vendor/` construit par la CI et livré dans l'artefact de
  release.

## 2. Forme du composant

- **Repository GitHub séparé**, distinct de `xdubois-57/scoutmagic`.
- **Package Composer** versionné en semver, consommé via Packagist ou
  dépôt VCS : ScoutMagic déclare par exemple
  `"<vendor>/web-skeleton": "^1.0"`. Le squelette arrive dans `vendor/`
  comme Twig ou PHPMailer aujourd'hui — compatible tel quel avec le
  déploiement FTP et l'auto-update existant.
- **Développement local** : dépôt Composer de type `path` avec symlink
  pour itérer sur les deux projets simultanément.
- **Assets publics** du squelette (JS de la nav, `sw.js`, CSS, logo par
  défaut) : copiés vers `public/` du site par un script de
  synchronisation (cohérent avec la règle « pas de build front »).

### À trancher avant le premier commit

| Décision | Options |
|---|---|
| Nom du package et du repo | par ex. `<vendor>/web-skeleton` |
| Namespace PHP | par ex. `Skeleton\` (remplace `Core\`) |
| Licence | AGPL-3.0 (tout site consommateur reste open source, cohérent avec l'intention actuelle) ou LGPL/MIT pour le seul squelette |

## 3. Périmètre du squelette

### 3.1 Noyau HTTP / MVC — extraction quasi telle quelle

`Core\Http` en entier : `Request`, `Response`, `Router`, `FrontController`,
`ResolvedRoute`, `ErrorHandler`, `SafeRedirect`, `FlashMessage`,
`AbstractController`. L'invariant architectural clé voyage avec le
squelette et devient une garantie du framework : **le RBAC est vérifié par
le routeur avant d'instancier le contrôleur, jamais appelé manuellement**
(un contrôleur peut re-vérifier une permission fine, jamais comme
protection primaire).

### 3.2 Sécurité et authentification

- Sessions : `SessionManager`, `SessionStore`, `SessionRevalidator`,
  `AuthSession` (verrou de session relâché avant le travail lourd,
  ré-ouverture explicite pour écrire).
- Les trois méthodes d'authentification, toutes résolvant un email :
  lien magique (défaut, `MagicLink*`), mot de passe (`PasswordAuthMethod`,
  `PasswordPolicy`, `PasswordReset*`), passkey (`WebAuthnService`,
  `CborDecoder`).
- Le modèle de compte « on se connecte avec un email, pas avec un compte
  métier » (`user_accounts`) devient le modèle du squelette.
- Défenses transverses : `CsrfGuard`, `LoginThrottler`, `HumanCheck`
  (anti-bot des formulaires publics), `HtmlSanitizer`, `SsrfUrlValidator`,
  `EncryptionService` (+ blind index), `SecretManager`, en-têtes de
  sécurité et nonce CSP, messages d'erreur identiques contre
  l'énumération de comptes, régénération d'ID de session au login.

### 3.3 Permissions (RBAC) — mécanique générique, vocabulaire déclaratif

La mécanique part dans le squelette : `RbacGuard`, `role_min` obligatoire
sur chaque route (rejet au chargement d'une valeur inconnue), hiérarchie
cumulative par niveaux.

**Découplage requis** : l'enum `Core\Security\Role` code en dur
`public/identified/intendant/chief/admin/superadmin` — vocabulaire scout.
Le squelette accepte une **déclaration de rôles fournie par
l'application** (liste ordonnée : nom → niveau → libellé UI), consommée
partout où la liste est aujourd'hui dupliquée (`Role`,
`Router::VALID_ROLES`, `ModuleManifest::VALID_ROLES`). ScoutMagic déclare
ses six rôles actuels, un autre site déclare les siens.

### 3.4 Menus responsifs et couche vue

- `TwigFactory`, `MenuBuilder` (filtrage cumulatif par rôle, entrées
  dynamiques, tri par groupes dynamic/core/module), `DynamicMenuRegistrar`,
  `MenuEntryProvider`.
- `base.html.twig`, `partials/nav.html.twig` (navigation Bootstrap
  responsive), les partials génériques (breadcrumb, chip_picker,
  list_editor, rich_text_*, cookie_banner, notification_dropdown,
  password_complexity_checklist, section « avatar » du compte), les pages
  d'erreur 403/404, la page 413.
- **Découplage requis** : `MenuBuilder::MENUS` code en dur « Notre unité /
  Espace chefs / … ». Même traitement que les rôles : le squelette fournit
  la mécanique, l'application **déclare ses menus** (id, libellé, icône,
  `role_min`).
- **Surcharge de templates** : chaîne de loaders Twig — les templates du
  site sont cherchés d'abord, ceux du squelette servent de fallback. C'est
  le mécanisme central du « jamais de fork » côté présentation.

### 3.5 Système de modules

`ModuleManager`, `ModuleManifest`, `ModuleRegistryRepository` et le format
`module.json` (routes + `role_min` + menu + breadcrumb, `schema.sql`,
`settings`, `cookies`, `scheduled_tasks`, `notifications`, `offline`,
`storage`) partent dans le squelette — le format est déjà entièrement
piloté par les données.

**Évolution requise** : `ModuleManager` scanne aujourd'hui un seul dossier
`modules/`. Il devra accepter **plusieurs sources** : les modules embarqués
dans le package (`vendor/<vendor>/web-skeleton/modules/`) et ceux du site
(`modules/`). Chaque module reste activable/désactivable par site.

### 3.6 Migration automatique de la base de données

Toute la chaîne existante part dans le squelette, telle quelle :

- **Mémoire** : `MigrationRunner::isPending()` compare le hash SHA-256 du
  contenu des fichiers schéma au hash mémorisé en base — tant que rien n'a
  changé, chaque requête court-circuite en une comparaison.
- **Exécution par tranches** : diff schéma déclaré / base réelle
  (`SchemaIntrospector`, `SchemaComparator`, `SqlParser`), DDL exécuté par
  tranches courtes avec progression persistée (`MigrationProgress`) —
  reprise automatique si la requête est interrompue ; le hash n'est sauvé
  qu'une fois tout le DDL passé.
- **Page d'attente** : page de maintenance autonome (aucune dépendance au
  reste de l'appli, CSP nonce) avec barre de progression pilotée par
  `/api/system/migration-step` (protégé par en-tête custom anti-CSRF).
- `MaintenanceGate` pour l'autre fenêtre de maintenance (installation
  d'une mise à jour des fichiers).

Le Kernel (§4.5) assemble la liste des fichiers schéma : `core.sql` **du
squelette** (user_accounts, settings, notifications, journal, scheduler,
modules…) + schéma **de l'application** + `schema.sql` des modules actifs.
`MigrationRunner` accepte déjà une liste — une mise à jour du squelette qui
modifie le schéma migre les sites automatiquement, avec la page d'attente.

### 3.7 Configuration

Les trois étages sont génériques et partent ensemble :

1. **`SettingService` / `SettingRepository`** — paramètres auto-déclarés au
   boot (défaut, type, libellé, description, regex, options, éditable,
   ordre). La déclaration n'écrase jamais une valeur existante : un
   nouveau paramètre livré par une mise à jour apparaît simplement.
2. **Page générique `Configuration > Paramètres`** (`SettingsController`),
   rendu groupé automatique + liste d'exclusion pour les paramètres à page
   dédiée.
3. **Paramètres de modules** déclarés en données dans `module.json`.

Plus l'étage fichier : `AppConfig` (`config/app.php`, jamais écrasé par
les mises à jour) et `SecretManager` (`secrets.enc` chiffré par
`master.key`).

Le squelette embarque ses propres paramètres (PWA, notifications,
heures calmes, auto-update, human check…) ; l'application déclare les
siens, qui apparaissent dans la même page.

### 3.8 Envoi d'e-mails

`Core\Mail` en entier — vérifié sans couplage métier :

- `MailService` (PHPMailer, SMTP/STARTTLS, mode de livraison, signature
  DKIM), `MailServiceFactory` (construction depuis settings/secrets :
  `mail_from_*`, préfixe de sujet `short_name`, `dkim_selector`).
- `DkimManager` (clés DKIM), `DnsVerifier` (contrôles SPF/DKIM/DMARC).
- Templates d'e-mail de base (`email/base.html.twig` + variantes texte),
  surchargeables par site comme le reste.
- Les e-mails système voyagent avec leurs fonctionnalités : lien magique,
  réinitialisation, confirmations d'adresse.

### 3.9 PWA installable

- `PwaController` (manifest, icônes, favicon) — tire déjà tout de
  `SettingService`, générique tel quel.
- `public/sw.js` (service worker unique : app shell + push + offline),
  script d'enregistrement de `base.html.twig`, versionnage du cache par
  `VERSION` + version d'icône.
- `OfflineController`, `OfflineWhitelist`, `OfflineManifestService`, page
  `/offline`, réglage de péremption du cache.
- **Découplage mineur** : `UnitLogoService`/`UnitLogoProcessor`
  (dérivation des icônes 192/512/maskable/favicons/ICO depuis le logo
  uploadé) — mécanique générique, seul le nom est scout. Renommage en
  `SiteLogoService`, logo par défaut fourni par l'application hôte.

### 3.10 Notifications (centre + Web Push)

`Core\Notification` en entier : registre de types déclarés par les modules,
préférences par compte et par canal, heures calmes globales + surcharge
par compte, abonnements push chiffrés, clés VAPID auto-réparées, tâches
d'envoi et de purge, dropdown de nav, pages de préférences.

**Un seul couplage**, couvert par le contrat d'identité (§4.2) :
`NotificationService::isRoleAllowed()` appelle aujourd'hui
`RoleResolver->resolve($email, $currentScoutYearId)`. Le squelette
demandera « rôle effectif de ce compte, maintenant ? » au résolveur ; la
notion d'année scoute reste cachée dans l'implémentation ScoutMagic.

### 3.11 Mon compte

`AccountController` — vérifié : aucune référence Member/Section/ScoutYear.
Profil, gestion du mot de passe, passkeys, préférences de
notifications/heures calmes ; tout est rattaché à `user_accounts`. Part
avec ses templates. Extension par l'application : surcharge Twig par
blocs + **registre de sections de page de compte** (même patron que
`MenuEntryProvider`).

### 3.12 Infrastructure restante

`Connection` (PDO, requêtes préparées uniquement), `Scheduler`
(pseudo-cron + `cron.php`), `Journal` (log d'événements),
consentement cookies (`Core\Cookie`, catégories extensibles par module),
`File`/`UploadHandler`/`FileAccessGuard`/`ChunkedUploadStore`,
`ErrorHandler`/`RequestTimeline` (`?debug=1`), `TextNormalizerService`,
`ShortUrl`.

### 3.13 Candidats optionnels (extraction en phase ultérieure)

Auto-update GitHub + backup/restore (`Core\Maintenance`, `bootstrap.php` —
déjà paramétrés par owner/repo), wizard `/setup`, `Pdf`/`Image`
(processeurs génériques), `Statistics` (destination configurable),
`Support` (collecteurs).

## 4. Points d'extension (les contrats)

Ce sont eux qui rendent le « jamais de fork » tenable. Un site se
personnalise exclusivement par :

1. **Déclaration des rôles** — liste ordonnée nom/niveau/libellé (§3.3).
2. **`IdentityResolverInterface`** — le contrat central : l'auth du
   squelette s'arrête à « cet email est vérifié, session ouverte » ; le
   résolveur répond « rôle effectif + entités liées de ce compte ».
   ScoutMagic l'implémente avec son `RoleResolver` actuel (fonctions →
   rôle le plus élevé, navigation entre « ses » membres, année scoute
   encapsulée).
3. **Déclaration des menus** — id/libellé/icône/`role_min` (§3.4).
4. **Surcharge de templates Twig** — chaîne de loaders (§3.4).
5. **Modules** — le point d'extension principal pour les fonctionnalités
   (§3.5), y compris `MenuEntryProvider` pour les entrées dynamiques.
6. **Sections « Mon compte »** — registre de fragments (§3.11).
7. **Déclaration de settings** et **fichiers schéma** propres (§3.6, §3.7).

### 4.5 Le Kernel

Chantier le plus structurant : transformer le câblage manuel de
`public/index.php` (~3 900 lignes) en une classe `Kernel`/`App` du
squelette, qui orchestre le cycle complet (config → secrets → session →
migration pendante → services → modules → routing → dispatch) et expose
les points d'accroche ci-dessus. Le `index.php` d'un site devient court :
config + déclarations + `$app->run()`. Ce refactoring améliore ScoutMagic
indépendamment même de l'extraction.

## 5. Modules génériques embarqués dans le squelette

Livrés dans le package, découverts par le scan multi-sources, activables
par site, documentation MD incluse :

| Module | État | Notes |
|---|---|---|
| `llm_connector` | Existant, déjà générique | API propre (`LlmConnectorInterface`, `LlmRequest/Response`, `LlmTier`), providers interchangeables (Anthropic, Mistral, Scaleway). Régularise une anomalie actuelle : le noyau dépend déjà de ce module (`RgpdContentService`, `CheckStableUpdateHandler`, `StreamStatisticsTransport`). |
| `inbound_mail` | Existant, déjà générique | Passerelle IMAP lecture seule + API de consommation (utilisée par rental). Sa dépendance `webklex/php-imap` déménage avec lui. |
| test d'e-mails | **En cours de développement** | Diagnostic de délivrabilité : envoi de test, contrôles DNS (réutiliser `DnsVerifier` ; des envois de test existent déjà dans `SetupController` et `NotificationConfigController` — à unifier). Option : test en boucle complète via `inbound_mail` (envoyer vers la boîte surveillée et vérifier l'arrivée réelle). Vigilance pendant son développement : aucune dépendance au modèle membre (destinataire = adresse libre ou compte connecté via `user_accounts`). Vit d'abord comme module ScoutMagic, migre à l'extraction. |

Candidats ultérieurs après audit de couplage : `banner`, `gallery`.
Restent côté ScoutMagic (ancrés dans le modèle membre) : `mass_mail`,
`calendar`, `registration`, et les autres modules métier.

## 6. Documentation du squelette (fichiers MD)

Le repo du squelette embarque sa documentation :

- `README.md` — présentation, philosophie (mutualisé, pas de build front,
  dépendances justifiées).
- `ARCHITECTURE.md` — le contrat du squelette. **Scission de
  l'ARCHITECTURE.md actuel** : les invariants génériques (couches MVC,
  RBAC par le routeur, PDO préparé, règles de dépendances) partent dans le
  squelette ; ScoutMagic garde ses sections métier.
- `SECURITY.md` — modèle de menaces, chemin de téléchargement unique,
  CSRF, CSP…
- `CHANGELOG.md` + `docs/upgrade.md` — notes de migration par version
  majeure.
- `docs/getting-started.md` — créer un site : `composer require`,
  déclarer rôles/menus/settings, implémenter le résolveur d'identité,
  écrire son `index.php`, copier le site exemple.
- `docs/module-development.md` — **existe déjà dans ScoutMagic**
  (`docs/module-development.md`), migre presque tel quel.
- Un guide par sous-système (auth, notifications, PWA, scheduler, mail —
  `docs/inbound-mail-setup.md` existe déjà et suit son module).

## 7. CI/CD et release partagés

Les technologies étant identiques (PHP 8.4, PHPStan, PHPUnit, Vitest,
Playwright, SonarQube Cloud), toute la chaîne de fabrication est
mutualisée.

### 7.1 Workflows GitHub réutilisables

Le repo du squelette héberge des workflows `workflow_call` paramétrés :
`ci-php` (syntax, PHPStan, PHPUnit + couverture), `ci-js` (typecheck,
Vitest), `ci-e2e` (Playwright/Chromium + MySQL), `ci-deps` (audit),
`ci-sonar`. Chaque site consommateur n'a qu'un appelant d'une dizaine de
lignes :

```yaml
jobs:
  ci:
    uses: <vendor>/web-skeleton/.github/workflows/ci.yml@v1
    with:
      sonar_project_key: xdubois-57_scoutmagic
      run_e2e: true
    secrets: inherit
```

Référencés par tag majeur mobile (`@v1`) : chaque amélioration du pipeline
profite immédiatement à tous les sites, sans PR. Canal indépendant de
Composer (GitHub tire les workflows directement du repo squelette).

### 7.2 Script de release avec gates

`scripts/release.sh` (657 lignes) se généralise : livré comme binaire
Composer (`vendor/bin/release`), configuré par site (fichier
`.release.conf` ou section `extra` du `composer.json`) : URL de production
(gate de déploiement, optionnelle), clé Sonar, gates actives. La logique
reste partagée : bump semver, notes générées depuis les commits, les six
gates (déploiement, sécurité CodeQL/Dependabot, tests PHP+JS, E2E,
fraîcheur des dépendances, Sonar) avec flags `--skip-*` d'urgence et
rapport « Vérifications effectuées » annexé aux notes, construction de
l'artefact zip avec `vendor/`. Les scripts compagnons
(`check-sonar-release.sh`, `e2e.sh`, `js-typecheck.mjs`, helpers E2E)
suivent.

### 7.3 Presets d'analyse

`phpstan-base.neon` inclus par chaque site
(`includes: [vendor/.../phpstan-base.neon]`), gabarits
`phpunit.xml`/`vitest.config.js`/`sonar-project.properties` documentés.
Le niveau d'exigence devient une décision du squelette, homogène partout.

### 7.4 Site exemple

Le repo du squelette embarque une application exemple minimale
(`example/` : `index.php` court, deux rôles, un menu, un module de
démonstration), qui sert à trois choses :

1. cible des tests E2E/intégration du squelette (le pipeline s'auto-teste) ;
2. gabarit vivant de `docs/getting-started.md` ;
3. démonstration exécutable de chaque point d'extension.

## 8. Canaux de mise à jour

| Contenu | Canal | Propagation |
|---|---|---|
| Code du squelette + modules embarqués + script de release + presets | Package Composer (`^1.x`) | PR de bump automatique (Dependabot/Renovate ou Action maison) dans chaque site → merge quand la CI est verte → release du site |
| Workflows CI | Reusable workflows `@v1` | Immédiate, tous les sites, sans action |
| Sites déployés chez les utilisateurs finaux | Auto-update GitHub existant (release avec `vendor/` construit par la CI) | Inchangé — les sites ne parlent jamais au repo du squelette |

Les migrations de schéma du squelette suivent le canal Composer puis le
mécanisme de migration automatique (§3.6). Discipline : semver strict —
patch/mineur mergés sans intervention, majeur accompagné de
`docs/upgrade.md`.

## 9. Ce qui reste dans ScoutMagic

Tout le domaine : `Core\Member`, `Core\Import` (Desk), `Core\ScoutYear`,
`Core\Badge`, `Section*`, `RoleResolver` (devient l'implémentation du
contrat d'identité), les modules métier, les pages publiques (home,
sections, contact), les settings métier, les processeurs photo
spécifiques (photos de section, y compris le logo scout par défaut), et la
partie métier de l'ARCHITECTURE.md.

## 10. Plan de migration par phases

Chaque phase laisse ScoutMagic vert (CI complète + release possible).

1. **Phase 1 — Découpler sur place** (dans ScoutMagic, sans nouveau repo) :
   rôles déclaratifs, menus déclaratifs, `IdentityResolverInterface`
   implémentée par `RoleResolver`. Zéro risque, valide le design des
   contrats. C'est ici que le squelette se conçoit réellement.
2. **Phase 2 — Créer le repo + extraire les feuilles** : docs de base,
   pipeline CI réutilisable et script de release (extractibles très tôt,
   indépendants du code PHP), puis `Http`, `Security` (hors
   `RoleResolver`), `Database` (Connection + migrations), `Config`
   (settings + secrets), `Journal`, `Scheduler`, `Cookie`, `Mail`,
   `Notification` (service).
3. **Phase 3 — Vue + modules + Kernel** : menus, templates de base +
   chaîne de loaders, `ModuleManager` multi-sources, Mon compte, PWA
   (avec renommage `SiteLogoService`), dropdown notifications, le Kernel
   remplaçant le `index.php` monolithique, le site exemple.
4. **Phase 4 — Modules embarqués et optionnels** : migration de
   `llm_connector`, `inbound_mail`, du module de test d'e-mails ;
   puis, selon besoin : maintenance/auto-update, setup, backup,
   statistics/support.

## 11. Points ouverts

- Nom du repo/package et namespace PHP (§2).
- Licence du squelette (§2).
- Textes français en dur dans les templates et messages du noyau (pages
  413/migration/erreurs…) : acceptable si tous les sites sont
  francophones ; sinon, centraliser derrière un mécanisme simple de
  surcharge au moment de l'extraction.
- Sort des tests : les tests du noyau migrent avec leur code ; ScoutMagic
  garde des tests d'intégration sur ses implémentations des contrats.
- Gouvernance : à partir de deux consommateurs, tout changement cassant du
  squelette coûte une majeure + notes de migration — à intégrer dans les
  habitudes de release.
