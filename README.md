# ScoutMagic

Site web open source pour les unités scoutes belges de la fédération « Les Scouts ».

## Auteur

ScoutMagic est développé et maintenu par Xavier Dubois.

Voir [NOTICE](NOTICE) pour la liste des contributeurs et
[LICENSE](LICENSE) pour les conditions de licence, y compris les conditions
additionnelles relatives à l'usage du nom « ScoutMagic ».

## Avertissement

ScoutMagic est un logiciel libre fourni sans garantie d'aucune sorte,
conformément à la licence AGPL-3.0 (voir LICENSE, sections 15-16).

Toute unité qui déploie ScoutMagic agit en tant que responsable de
traitement au sens du RGPD pour les données qu'elle y héberge : c'est à
elle qu'incombent l'évaluation de la conformité RGPD, la sécurisation de
son hébergement, la tenue du registre de traitement et la notification en
cas de violation de données. L'auteur et les contributeurs du projet ne
sont ni responsables de traitement ni sous-traitants pour les instances
déployées par des tiers, et n'ont aucun accès aux données qui y sont
hébergées.

Une faille de sécurité découverte dans le code doit être signalée via
[SECURITY.md](SECURITY.md). Les corrections sont apportées sur une base
volontaire, sans garantie de délai.

## Fonctionnalités

- Gestion des membres depuis l'import CSV Desk
- Contrôle d'accès basé sur les rôles (6 niveaux)
- Authentification sans mot de passe (lien magique, mot de passe, passkey)
- Design responsive mobile-first, installable comme application sur l'écran d'accueil (PWA) avec accès hors ligne aux pages publiques, au calendrier, au centre de notifications, au trombinoscope, aux staffs, aux statistiques des membres, aux prévisions, et à la page et au compte propres de chaque membre — chaque image pré-téléchargée, extensible par des modules, sans qu'aucune page de configuration ni contenu privé/financier ne soit jamais mis en cache
- Mode configuration pour l'édition de contenu en ligne
- Architecture modulaire pour l'extensibilité
- Données personnelles chiffrées au repos
- Emails transactionnels signés DKIM
- Gestion du consentement aux cookies (bannière et préférences alignées sur les exigences ePrivacy)
- Migration de schéma automatisée
- Planificateur de tâches (cron + pseudo-cron)
- Journal d'audit
- Centre de notifications avec Web Push, préférences par type (in-app/push/email), plages horaires de silence, et un mode discret
- Sauvegardes chiffrées à la demande et automatiques, mise à jour en un clic depuis les releases GitHub, réinitialisation/restauration
- Page Support : envoi optionnel (activable/désactivable) de statistiques d'utilisation agrégées vers ScoutMagic, avec aperçu exact de ce qui est transmis et sans aucune donnée de membre ; génération à la demande d'une archive de diagnostic (chiffrée, réservée au superadmin, jamais transmise automatiquement)
- Modules optionnels : gestion financière (import de relevés bancaires, reçus, créances), articles actualités/événements avec formulaires d'inscription et paiement, calendrier d'activités avec flux ICS, galerie photo/vidéo (stockage local ou S3), groupes de discussion privés par section, location des biens de l'unité (locaux, terrains, tentes, remorques, matériel), tableaux de rétrospective post-activité, trombinoscope du staff, envoi de mails groupés, bannières de la page d'accueil, statistiques des membres, transfert d'appel du numéro d'urgence de l'unité
- Intégration IA optionnelle (extraction de données de reçus, génération de texte RGPD, modération des rétrospectives, résumés d'articles)

## Prérequis

- PHP >= 8.4
- MySQL >= 8.0
- Composer (pour le développement/build uniquement — non nécessaire sur le serveur)
- Node.js >= 22 et npm (pour l'outillage de développement uniquement — analyse statique JavaScript, tests unitaires JavaScript et tests de bout en bout ; non nécessaire sur le serveur, ni pour exécuter ScoutMagic)
- Accès FTP au serveur d'hébergement

## Installation

1. Cloner le dépôt.
2. Exécuter `composer install`.
3. Pointer la racine de votre serveur web vers `public/`.
4. Accéder au site — l'assistant de configuration vous guidera.

## Développement

```bash
composer install
composer serve                     # serveur de dev local (localhost:8000)
vendor/bin/phpunit                 # exécuter les tests PHP (suite complète)
vendor/bin/phpstan analyse core/   # analyse statique

npm ci                             # dépendances Node (outillage de dev uniquement — voir Prérequis)
npm run typecheck                  # analyse statique JavaScript (TypeScript checkJs — voir ci-dessous)
npm test                           # exécuter les tests unitaires JavaScript (Vitest + jsdom)
npm run test:coverage              # idem, avec couverture LCOV (coverage/js/lcov.info)

npm run e2e:install                # une seule fois : installer le navigateur Chromium (Playwright)
npm run e2e                        # exécuter les tests de bout en bout (voir ci-dessous)
```

`vendor/bin/phpunit` exécute **toute** la suite, groupe `database` compris : aucun test n'est exclu par défaut, ni en local, ni en CI, ni dans `scripts/release.sh`. La majorité de ce groupe tourne sur une base SQLite en mémoire (`Tests\DatabaseTestHelper`) et ne demande rien de particulier ; seuls les six fichiers qui lisent `TEST_DB_*` ont besoin d'une vraie instance MySQL jetable. Renseignez alors `TEST_DB_HOST` / `TEST_DB_PORT` / `TEST_DB_NAME` / `TEST_DB_USER` / `TEST_DB_PASSWORD` (la CI utilise `127.0.0.1`, `3306`, `test_db`, `root`, `test_password`). **`TEST_DB_PASSWORD` ne doit pas être vide** : les tests du formulaire d'installation (`Tests\Core\Http\Controller\SetupControllerTest`) rejouent la vraie validation du formulaire, qui rend le mot de passe de base de données obligatoire.

`composer serve` encapsule `php -S` avec des valeurs `upload_max_filesize`/`post_max_size` relevées (`public/.user.ini`, utilisé en production, n'est pas pris en compte par le serveur intégré) — si vous lancez `php -S` directement à la place, les uploads de plus de 8 Mo échoueront avec une erreur 413. Augmentez les valeurs dans `scripts.serve` de `composer.json` si vous devez tester des uploads plus volumineux en local (ex. vidéos de galerie).

Les tests JavaScript (`tests/js/`, Vitest + jsdom) sont isolés du reste de la pile : aucun serveur PHP, aucune base MySQL, aucun réseau réel (`fetch`/Service Worker/WebAuthn sont simulés dans les tests qui en ont besoin). Ils exercent directement le vrai code de production sous `public/assets/js/`, jamais une réimplémentation. Node/npm ne servent qu'à ça — ScoutMagic lui-même reste du JavaScript navigateur simple, sans étape de build, et ni Node ni npm ne sont nécessaires pour l'exécuter ou le déployer (voir `AGENTS.md` § CSS / frontend).

### Analyse statique JavaScript

`npm run typecheck` est l'équivalent JavaScript de `vendor/bin/phpstan analyse` pour PHP : un contrôle statique, déterministe, qui détecte avant toute exécution une classe d'erreurs que ni Vitest (comportement à l'exécution) ni SonarQube Cloud (qualité de code générale, doublons, complexité, sécurité) ne couvrent — identifiants non résolus, appels de fonction avec un nombre d'arguments incorrect, signatures qui divergent d'un site d'appel après un refactoring, accès à une propriété statiquement invalide. C'est exactement la classe de bug documentée dans `AGENTS.md` § Static analysis (un paramètre de constructeur PHP supprimé, un site d'appel non mis à jour, jamais détecté avant la production) — appliquée ici au JavaScript.

Concrètement, c'est le compilateur TypeScript utilisé uniquement comme vérificateur de développement (`allowJs`/`checkJs`/`noEmit` — voir `tsconfig.json`) sur le JavaScript existant de `public/assets/js/`, qui reste par ailleurs strictement inchangé : pas de transpilation, pas de build, aucun fichier généré, rien de nouveau à servir au navigateur ou à déployer (voir `AGENTS.md` § CSS / frontend). Le typage vient d'annotations [JSDoc](https://jsdoc.app/) (`@param`, `@returns`) ajoutées aux fonctions à plusieurs paramètres réutilisées à plusieurs endroits — là où une erreur de signature serait sinon invisible pour l'outil ; les scripts strictement DOM (un écouteur d'événement à un seul paramètre évident) n'en ont pas besoin.

`scripts/js-typecheck.mjs` (invoqué par `npm run typecheck`) encapsule `tsc` avec un mécanisme de baseline identique dans son principe à `phpstan-baseline.neon` : `js-typecheck-baseline.json` recense les signalements préexistants acceptés, et une exécution propre signifie *aucun nouveau signalement*, pas zéro signalement. Au moment de l'introduction de ce contrôle, l'essentiel des signalements initiaux (le typage générique de `document.getElementById()`/`querySelector()` ne correspondant pas au type d'élément réellement utilisé) a été corrigé par un cast JSDoc plutôt qu'accepté comme dette — la baseline est donc actuellement vide (`{}`) — mais ce mécanisme reste en place pour absorber une dette légitime future sans bloquer chaque changement. Ne régénérez la baseline (`node scripts/js-typecheck.mjs --generate-baseline`) que pour accepter délibérément une dette préexistante que vous ne corrigez pas maintenant — jamais pour masquer un signalement introduit par votre propre changement.

Exécutez `npm run typecheck` avant tout commit qui touche `public/assets/js/` — comme `vendor/bin/phpstan analyse` pour le PHP (`AGENTS.md` § Static analysis). CI (`javascript-tests`, avant les tests unitaires) et `scripts/release.sh` (verrou « Tests ») exécutent la même commande canonique et bloquent en cas d'échec.

### Tests de bout en bout (E2E)

Un petit nombre de scénarios à haute valeur, jamais une couverture exhaustive : **ScoutMagic démarre-t-il vraiment et affiche-t-il une page dans un vrai navigateur ?** PHPUnit instancie les contrôleurs un par un et n'exécute jamais `public/index.php` ; Vitest exécute du JavaScript dans un DOM simulé, sans PHP ni base de données. Une erreur de câblage dans la racine de composition de `public/index.php` (un constructeur dont la signature change, un service manquant) ne se voit donc qu'en production — c'est déjà arrivé (voir `AGENTS.md` § Static analysis). Les tests E2E ferment ce trou.

```bash
npm ci                 # une fois : dépendances Node
npm run e2e:install    # une fois : télécharge le navigateur Chromium utilisé par Playwright
npm run e2e            # exécuter le scénario complet
```

`npm run e2e` (c'est-à-dire `scripts/e2e.sh`) fait tout, en une commande, sans aucune étape manuelle :

1. crée une installation ScoutMagic jetable dans un répertoire temporaire (son propre `storage/`, sa propre `config/app.php`, ses propres secrets générés) — **votre installation locale n'est jamais lue ni modifiée** ;
2. crée et vide une base de données dédiée (`scoutmagic_e2e` par défaut, jamais `test_db` ni une base de développement), puis y applique `schema/core.sql` via le vrai `Core\Database\MigrationRunner` ;
3. démarre `php -S` sur un port libre, avec pour racine le `public/` de cette installation jetable — le vrai `public/index.php`, pas une application de test ;
4. attend (par sondage, jamais par `sleep`) que le serveur réponde ;
5. lance Playwright/Chromium en mode *headless* ;
6. rend le code de sortie de Playwright tel quel ;
7. arrête le serveur, supprime la base et le répertoire temporaire — après un succès, après un échec, et sur Ctrl-C.

Scénarios actuels (`tests/e2e/specs/`), chacun bootant l'application réelle de bout en bout :

- **`public-home-page.spec.js`** — `GET /` (page d'accueil publique, `role_min: public`) : réponse HTTP 200, titre de page issu du `site_name` lu en base, titre `<h1>` du contenu éditable, repères d'accessibilité `main`/`navigation`/`contentinfo`, lien « Protection des données » du pied de page.
- **`login-page.spec.js`** — `GET /login` (`Core\Http\Controller\AuthController::login()`) : formulaire de connexion (onglet lien magique par défaut) avec ses champs étiquetés et son lien vers `/rgpd`, exerçant une racine de composition différente (CsrfGuard, LastLoginMethodCookie, HumanCheck) de celle de la page d'accueil.
- **`rbac-anonymous-redirect.spec.js`** — un visiteur anonyme demandant `/account` (`role_min: identified`) est réellement redirigé, dans un vrai navigateur, vers une page `/login` qui s'affiche correctement — pas seulement le couple statut/en-tête `302`/`Location` qu'un test unitaire de `RbacGuard` vérifie déjà isolément.
- **`cookie-consent-reject.spec.js`** — la bannière de consentement s'affiche sans y être invitée sur une instance jamais visitée ; cliquer sur « Tout refuser » la fait disparaître et ne laisse dans le navigateur que le cookie nécessaire `cookie_consent`, jamais le cookie fonctionnel `last_login_method` (AGENTS.md § Cookie consent).
- **`scout-year-transition.spec.js`** — le passage complet à l'année scoute suivante, connecté en super-administrateur, en suivant les quatre étapes de la page « Année scoute » (`/admin/scout-year`) : prévisualisation limitée à la session, import Desk des nouvelles inscriptions, bascule des chefs et intendants sur l'année cible, puis bascule publique. Le test vérifie aussi ce que chaque étape ne doit *pas* faire (chaque bouton reste désactivé tant que l'étape précédente n'est pas faite, le public ne voit jamais l'année du staff) et lit les libellés sur la page plutôt que de les recopier, pour rester valable d'une année à l'autre. **La page `/admin/scout-year` est la spécification de ce test** : toute modification du déroulé doit être répercutée dans le test, dans le même changement (rappel présent dans `core/View/templates/admin/scout_year.html.twig` et dans `ScoutYearController::buildTransitionSteps()`).

Toutes les assertions utilisent les rôles ARIA et les textes visibles (`getByRole`, `getByLabel`), jamais des sélecteurs CSS structurels — et échouent sur toute réponse 5xx ou erreur JavaScript non capturée.

**Base de données.** Le harnais réutilise le serveur MySQL déjà joignable (`E2E_DB_HOST`/`E2E_DB_PORT`/`E2E_DB_USER`/`E2E_DB_PASSWORD`, avec repli sur les variables `TEST_DB_*` déjà utilisées par les tests PHPUnit du groupe `database`, puis sur `127.0.0.1:3306` / `root` / mot de passe vide). S'il n'y en a aucun et que Docker est disponible, il démarre un conteneur `mysql:8.0` jetable et le supprime à la fin. Sinon il échoue avec un message explicite — jamais silencieusement.

**Artefacts.** Trace, capture d'écran et vidéo ne sont produites qu'en cas d'échec, sous `tests/e2e/test-results/`, avec un rapport HTML dans `tests/e2e/playwright-report/`. Les deux sont ignorés par git.

**Où ça tourne.** Le navigateur est *headless* : aucune fenêtre, aucune interaction souris/clavier, aucun service à démarrer à la main, aucun chemin absolu propre à une machine. macOS et Linux fonctionnent à l'identique. Depuis Claude Code, ce qui compte est l'hôte d'exécution, pas l'appareil client : une session lancée depuis un iPhone s'exécute dans un environnement de développement distant, et c'est cet hôte (Linux) qui doit pouvoir installer Chromium et joindre un MySQL — iOS lui-même n'exécute jamais Playwright. Si l'hôte distant ne peut pas héberger de binaire navigateur, `npm run e2e` n'y est pas exécutable ; la même commande reste la référence en local et dans GitHub Actions.

**Couverture de code.** `E2E_COVERAGE=1 npm run e2e` produit en plus `coverage-e2e.xml` (Clover), fusionné par SonarQube Cloud avec la couverture PHPUnit (`sonar.php.coverage.reportPaths` liste les deux rapports : une ligne couverte par l'un *ou* l'autre compte comme couverte). La collecte se fait avec l'extension **pcov** dans le processus `php -S` lui-même, via `auto_prepend_file` (`scripts/e2e-coverage-prepend.php`) : chaque requête écrit un fragment, et `scripts/e2e-support.php merge-coverage` les fusionne en fin de run. Sans pcov (ni xdebug), la commande échoue explicitement au lieu de produire un rapport vide. C'est la seule couverture qui existe pour `public/index.php` — la racine de composition, que PHPUnit n'exécute jamais et que le navigateur traverse à chaque requête. Le harnais lui-même (`scripts/e2e-support.php`, `scripts/e2e-coverage-prepend.php`) est exclu du calcul de couverture (`sonar.coverage.exclusions`) : c'est de l'infrastructure de test, que rien ne peut couvrir puisqu'elle exécute la suite au lieu d'être exécutée par elle. La collecte n'a **aucune** influence sur le verdict des tests : une fusion ratée est signalée, elle ne fait jamais échouer un run vert. Elle est désactivée par défaut en local (le run est plus rapide) et activée dans CI.

**Playwright et la règle « pas d'outil de build frontend ».** Playwright est de l'outillage de test, au même titre que Vitest : il n'introduit ni bundler, ni compilateur Sass, ni transpileur, ni la moindre étape de build pour `public/assets/js/`, qui reste du JavaScript navigateur simple chargé par une balise `<script src="...">`. Ni Node ni Playwright ne sont nécessaires pour exécuter ou déployer ScoutMagic, et rien de tout cela n'entre dans l'artefact de release. Voir `AGENTS.md` § CSS / frontend.

## Intégration continue

Chaque push sur `main` et chaque Pull Request déclenchent `.github/workflows/ci.yml` :

- **`test`** : vérification de syntaxe PHP, `vendor/bin/phpstan analyse`, puis `vendor/bin/phpunit` — **la suite complète, groupe `database` compris** — avec couverture PCOV et un service MySQL. Génère `coverage.xml` (Clover) et `phpunit-report.xml` (JUnit), publiés comme artefacts pour le job `sonarqube`.
- **`javascript-tests`** : `npm ci`, puis analyse statique JavaScript (`npm run typecheck` — voir § Analyse statique JavaScript), puis tests unitaires JavaScript (`npm run test:coverage` — Vitest + jsdom, `tests/js/`), isolés (sans serveur PHP ni base de données) — génère `coverage/js/lcov.info`, publié comme artefact pour le job `sonarqube`. Un échec de l'une ou l'autre étape fait échouer ce check GitHub indépendamment du job `test`.
- **`e2e-tests`** (« End-to-end (browser) ») : les scénarios de bout en bout (`npm run e2e` — la commande canonique, identique en local et dans `scripts/release.sh`), avec un service MySQL et Chromium installé via `npm run e2e:install`. Sans serveur graphique, borné à 20 minutes. Exécuté avec `E2E_COVERAGE=1` et pcov : génère `coverage-e2e.xml` (Clover), publié comme artefact pour le job `sonarqube`. Un échec fait échouer ce check GitHub ; les diagnostics Playwright (trace, capture, vidéo, rapport HTML) sont publiés comme artefact **uniquement en cas d'échec**.
- **`security`** : `composer audit`.
- **`sonarqube`** : analyse [SonarQube Cloud](https://sonarcloud.io/project/overview?id=xdubois-57_scoutmagic) (voir `sonar-project.properties`), à partir de la couverture/du rapport PHP produits par le job `test`, de la couverture PHP de bout en bout produite par le job `e2e-tests` (les deux rapports Clover sont fusionnés par SonarQube) et de la couverture JavaScript (LCOV) produite par le job `javascript-tests` — ni PHPUnit, ni Vitest, ni Playwright ne sont relancés une seconde fois. Le Quality Gate SonarQube fait échouer ce check GitHub s'il n'est pas OK (`-Dsonar.qualitygate.wait=true`).
- **CodeQL** : analyse de code activée au niveau du dépôt (GitHub Advanced Security), indépendante de ce workflow.

SonarQube Cloud est complémentaire à PHPStan, PHPUnit, l'analyse statique JavaScript (`npm run typecheck`), Vitest, `composer audit` et CodeQL — il ne les remplace pas. Sur une Pull Request, SonarQube Cloud décore automatiquement la PR (résumé du Quality Gate et annotations sur les lignes concernées) via son intégration GitHub officielle, à condition que le secret `SONAR_TOKEN` soit configuré (voir « Configuration manuelle requise » ci-dessous).

### Configuration manuelle requise

Le job `sonarqube` nécessite un secret de dépôt **`SONAR_TOKEN`** (Settings > Secrets and variables > Actions), généré depuis SonarQube Cloud. Sans ce secret, le job échoue pour toute PR/push interne au dépôt (les PR venant d'un fork sont ignorées, GitHub n'exposant pas les secrets aux forks). Voir `AGENTS.md` § Releases pour le rôle de ce même token dans le Release Gate.

## Déploiement

### Créer une release (mainteneurs)

```bash
./scripts/release.sh              # créer une nouvelle release (patch par défaut)
./scripts/release.sh --minor      # incrément de version mineure
./scripts/release.sh --major      # incrément de version majeure
```

Publie une release GitHub avec l'artefact d'installation et `bootstrap.php` en tant qu'assets. Nécessite le CLI GitHub (`gh`).

Avant de créer un commit, un tag ou une release, le script exécute six verrous, dans cet ordre — chacun bloque la release en cas d'échec :

1. **Déploiement** : `www.scoutmagic.be` doit déjà avoir installé la release précédente (comparé via `GET /api/version`, exposé publiquement par `Core\Http\Controller\VersionController`) et répondre normalement (code 200, pas d'erreur visible sur la page d'accueil).
2. **Sécurité** : aucun signalement CodeQL ni alerte Dependabot ouvert dans le dépôt (`gh api repos/{owner}/{repo}/code-scanning/alerts` et `.../dependabot/alerts`, filtrés sur `state == "open"`).
3. **Tests** : `vendor/bin/phpstan analyse`, `vendor/bin/phpunit`, l'analyse statique JavaScript (`npm run typecheck` — voir § Analyse statique JavaScript) et les tests unitaires JavaScript (`npm run test:coverage`) doivent tous passer. `--skip-tests-gate` contourne les quatre à la fois — voir `AGENTS.md` § Releases.
4. **Tests de bout en bout** : `npm run e2e` — la page d'accueil publique doit démarrer via le vrai `public/index.php` et s'afficher dans un Chromium *headless* (voir § Tests de bout en bout). C'est le seul verrou qui prouve que la racine de composition démarre réellement. Verrou distinct de « Tests » à dessein : c'est le seul à exiger un serveur MySQL et un binaire navigateur, et un mainteneur qui n'a ni l'un ni l'autre ne doit pas avoir à renoncer aussi à PHPStan/PHPUnit/Vitest pour publier. `--skip-e2e-gate` le contourne — voir `AGENTS.md` § Releases.
5. **Fraîcheur des dépendances** : aucune dépendance Composer directe (`composer outdated --direct`) ni aucune bibliothèque front-end vendorisée (`public/assets/vendor/` — Bootstrap, Bootstrap Icons, Chart.js) ne doit être en retard sur sa dernière version publiée.
6. **SonarQube Cloud** (`scripts/check-sonar-release.sh`) : aucun signalement de sécurité actif (issue d'impact `SECURITY` non résolue, ou Security Hotspot non trié), aucun signalement de sévérité `HIGH` ou supérieure (`BLOCKER`) actif, et le Quality Gate du projet doit être `OK` — pour l'analyse confirmée correspondre exactement au commit en cours de release. **Ce verrou est fail-closed** : un `SONAR_TOKEN` absent, SonarQube Cloud injoignable, une authentification invalide, une réponse invalide, ou l'impossibilité de confirmer qu'une analyse existe pour le commit exact bloquent la release.

   Ce verrou lit `SONAR_TOKEN` depuis l'environnement, ou à défaut depuis `.sonar-token` à la racine du dépôt (une ligne, jamais commité — voir `.gitignore`). S'il est absent des deux et qu'un terminal est attaché, le script le demande de manière interactive (saisie masquée) et propose de l'enregistrer dans `.sonar-token` — mais seulement après avoir vérifié via `git check-ignore` que ce fichier est bien ignoré par git ; sinon il refuse d'écrire le token et bloque la release. Sans terminal (CI, exécution automatisée), l'absence de token bloque directement, comme avant.

Corrigez le problème signalé (ou justifiez son rejet) avant de publier — voir `AGENTS.md` § Releases. Chaque verrou peut être contourné individuellement en cas d'urgence (`--skip-deployment-check`, `--skip-security-gate`, `--skip-tests-gate`, `--skip-e2e-gate`, `--skip-dependency-check`, `--skip-sonar-gate` ; un avertissement est affiché) — voir l'en-tête de `scripts/release.sh` pour le détail de chaque option. Ces options sont réservées à une décision explicite et informée de l'utilisateur, jamais à contourner une découverte réelle.

### Installation sur hébergement mutualisé (administrateurs d'unité)

Aucun SSH, Git ou Composer nécessaire sur le serveur — seulement le FTP, et une seule fois.

1. Téléchargez `bootstrap.php` depuis la [dernière release](https://github.com/xdubois-57/scoutmagic/releases/latest).
2. Envoyez-le par FTP dans le dossier web vide que votre hébergeur sert comme racine du document.
3. Ouvrez-le dans un navigateur (ex. `https://votre-domaine.be/bootstrap.php`). Il télécharge la dernière release, l'installe, et exécute une vérification de sécurité complète avant de vous montrer quoi que ce soit d'autre — l'écran de confirmation explique lequel des deux types d'installation il a choisi pour votre hébergeur et pourquoi.
4. Cliquez sur **Installer**. Il rapporte la progression étape par étape, puis un tableau succès/échec pour chaque vérification de sécurité effectuée (y compris des vérifications que votre propre navigateur a effectuées en récupérant des URLs directement). Tout échec annule proprement l'installation et explique quoi corriger — rien n'est laissé à moitié installé.
5. Une fois toutes les vérifications passées, il écrit un fichier `token.php` à côté de lui-même (ou vous indique de le créer manuellement par FTP s'il n'a pas pu), se supprime lui-même, et vous redirige vers l'assistant de configuration.
6. L'assistant de configuration demande la valeur du fichier `token.php` avant de montrer quoi que ce soit d'autre — copiez-la depuis le fichier par FTP si vous ne l'avez pas notée. Il est supprimé automatiquement une fois l'assistant terminé.
7. Complétez l'assistant : identifiants de base de données, paramètres de l'unité, configuration email, et votre compte administrateur.

**Activer les mises à jour automatiques** : une fois installé, allez dans *Configuration > Maintenance* et générez un secret de webhook GitHub. Dans les *Settings > Webhooks* de votre dépôt GitHub, ajoutez un webhook avec :
- **Payload URL** : `https://votre-domaine.be/api/webhook/github`
- **Content type** : `application/json`
- **Secret** : la valeur générée sur la page Maintenance
- **Events** : sélectionnez uniquement *Releases*

Sans cela, le site n'apprend jamais qu'une nouvelle release existe — voir ARCHITECTURE.md §8.17 pour le fonctionnement de l'installation des mises à jour une fois notifié.

## Architecture

Voir [ARCHITECTURE.md](ARCHITECTURE.md) pour la référence architecturale complète.

## Sécurité

Voir [SECURITY.md](SECURITY.md) pour les exigences de sécurité.

## Contribuer

Voir [CONTRIBUTING.md](CONTRIBUTING.md) pour les recommandations de contribution.

## Développement de modules

Voir [docs/module-development.md](docs/module-development.md) pour savoir comment créer un module.

## Licence

[AGPL-3.0](LICENSE)

Ce projet est mis à disposition des unités scoutes et de la communauté, avec l'attente
que tout usage reste open source.
