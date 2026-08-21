# Chantier — Statistiques d'usage, paquet de support, tableau de bord support

Journal d'implémentation du document `CHANTIER-support-statistiques.md`
(itérations IT-01 à IT-12). Une section par itération : ce qui a été fait,
les décisions prises en autonomie, les divergences constatées entre le
document de chantier et le dépôt réel, et ce qui a été reporté.

---

## Récapitulatif final

**Ce qui a été livré.** Les douze itérations, dans l'ordre, chacune sur sa
branche et mergée en `--no-ff` une fois la suite verte :

| # | Livré |
|---|---|
| IT-01 | Identité d'installation (id en `settings`, secret en `secrets.enc` uniquement), `DestinationMatcher`, cinq réglages, rattrapage d'`installed_at` |
| IT-02 | `StatisticsPayloadBuilder` — payload complet, collecteurs indépendants, toute valeur indisponible à `null` |
| IT-03 | Page `/config/support`, interrupteur, aperçu JSON, états d'envoi, case du setup, RGPD |
| IT-04 | `StatisticsSender` + tâche quotidienne, six gardes, HTTPS obligatoire, motifs expurgés |
| IT-05 | Pipeline du paquet de support : tâche de fond, ZIP chiffré, manifeste, README, purge |
| IT-06 | Collecteurs applicatifs : structure DB, paramètres (+ type `secret`), journal 48 h, tâches |
| IT-07 | Collecteurs système : `phpinfo` sans variables, filesystem, commandes, `.htaccess`, logs |
| IT-08 | Module receveur `support_dashboard`, `receiver_only`, endpoint d'intake, TOFU, débit |
| IT-09 | Tableau de bord : table responsive, filtres, recherche, tri, pagination, 5 cartes, 2 graphes, détail |
| IT-10 | Export XLSX, seuil d'activité et rétention réglables, purge, suppression manuelle |
| IT-11 | Historique mensuel : contributions, finalisation immuable, un graphe, sélecteur de période |
| IT-12 | Documentation, vérification transverse, vérification de bout en bout |

**Les décisions autonomes** sont listées itération par itération ci-dessous.
Les plus structurantes, celles qu'un relecteur voudra contester en premier :

1. **Le filtrage, le tri, la pagination et les agrégats du tableau de bord
   se font en PHP, sur l'ensemble conservé, jamais partagés avec SQL**
   (IT-09, décision 1). Motivé par le filtre « module activé » qui vit dans
   le JSON, et surtout par le fait que cartes et graphes doivent porter sur
   l'ensemble filtré. La sortie de secours est documentée en §8.50.
2. **Les valeurs de filtre qui circulent dans l'URL sont des clés
   techniques, jamais les libellés français affichés** (IT-09, décision 2).
3. **Un réglage de seuil nul, négatif ou vide n'est pas obéi** et retombe
   sur le défaut déclaré (IT-10, décision 1).
4. **L'indépendance de l'historique vis-à-vis des filtres est structurelle**
   — type distinct, méthode distincte, formulaire distinct — et non
   déclarative (IT-11, décision 3).
5. **Une tâche de purge des lignes de limitation de débit a été ajoutée**
   sans que le document la demande (IT-08, décision 4) : sans elle la table
   croît indéfiniment.

**Divergences constatées avec le document de chantier :**

- **Numérotation des sections d'`ARCHITECTURE.md`.** Le document réserve
  §8.29 et §8.30 ; ces numéros étaient pris. Les sections ont été placées à
  la suite des existantes, puis **renumérotées deux fois** au fil des
  fusions avec `main` (des chantiers parallèles réclamaient les mêmes
  numéros). État final : §8.47 à §8.51.
- **Références de classes.** Plusieurs noms cités par le document
  n'existaient pas tels quels dans le dépôt ; corrigés silencieusement et
  notés dans l'itération concernée.
- **`support_report_rate_limits`** n'a pas de colonne `form_key`,
  contrairement au calque `HumanCheckRateLimitRepository` : il n'y a qu'un
  seul formulaire ici (IT-08).
- **`support_monthly_aggregates`** a reçu une clé primaire technique `id`
  en plus de la contrainte d'unicité sur `month`, par cohérence avec le
  reste du dépôt (IT-11).

**Bug réel trouvé et corrigé en cours de route.** `PDOStatement::execute()`
lie toute valeur d'un tableau en chaîne, et PHP convertit `false` en `''` :
une colonne `BOOLEAN` recevait `''`, refusé par MySQL en mode strict. Un
`auto_update_enabled: false` rapporté devenait indiscernable d'un champ
absent — exactement la confusion que tout ce chantier interdit. Corrigé en
IT-09, détecté par un test d'IT-09 sur du code d'IT-08.

**Ce qui reste ouvert :**

- **9 tests en échec dans `tests/Modules/Rental/`**, introduits par un
  chantier parallèle sur `main` et **vérifiés comme préexistants** sur un
  `git worktree` d'`origin/main` sans aucune modification de ce chantier
  (chiffres identiques : `266 tests, 9 failures`). Hors périmètre ; signalé
  plutôt que corrigé.
- `SECURITY.md` ligne 332 renvoie à `§8.41` pour l'ajout temporaire de
  membre, alors que cette section est `§8.42`. Erreur d'un chantier
  parallèle, laissée telle quelle pour ne pas entrer en conflit avec lui.
- **Pistes ultérieures (Annexe B du document, non retenues ici)** : résumé
  des extensions PHP ; tests explicites d'inscriptibilité des répertoires ;
  état des migrations de schéma ; résumé des versions de dépendances ;
  résumé d'espace disque et de systèmes de fichiers ; résumé du statut de
  la dernière mise à jour.

Le détail par itération suit.

---

## Conventions de travail

- **Branche de destination.** Le document prévoit un cycle
  `main → feature/support-stats-itnn → merge sur main`. Cette exécution se
  déroule sur la branche de travail imposée par l'environnement,
  `claude/chantier-support-statistiques-xohlkz`, qui joue le rôle de `main`
  dans ce cycle. Chaque itération a bien sa propre branche
  `feature/support-stats-itnn`, mergée en `--no-ff` une fois la suite verte.
- **Numérotation des sections d'`ARCHITECTURE.md`.** Le document demande une
  section §8.29 (statistiques) et §8.30 (paquet de support). Ces deux numéros
  sont **déjà pris** dans le dépôt (§8.29 « Account identity vs.
  notifications », §8.30 « Chip picker »). Les nouvelles sections prennent
  donc les premiers numéros libres : **§8.47** (statistiques core), **§8.48**
  (paquet de support) et **§8.49** (module receveur).

---

## IT-01 — Fondations statistiques

### Implémenté

- `Core\Statistics\DestinationMatcher` — fonction pure `isSameHost()` et
  point d'entrée unique `isReceiver()`. Schéma et port ignorés, comparaison
  insensible à la casse, `www.` équivalent à son absence, tout autre
  sous-domaine distinct, URL vide ou invalide toujours fausse.
- `Core\Statistics\InstallationIdentityService` — identifiant d'installation
  (32 caractères hexadécimaux, `settings`, `editable = false`) et secret
  (64 caractères hexadécimaux, **uniquement** dans `secrets.enc`), tous deux
  générés paresseusement et de manière idempotente ; `regenerate()` journalisé
  `statistics_identity_regenerated` au niveau `security`.
- `Core\Statistics\InstallationDateService` — réglage `installed_at`, écrit en
  fin de premier paramétrage par `SetupController` et rattrapé une seule fois
  au démarrage depuis `MIN(event_log.logged_at)`, sinon l'instant courant.
- `SettingRepository::claimIfEmpty()` / `SettingService::claimIfEmpty()` —
  écriture conditionnelle atomique (un seul `UPDATE ... WHERE setting_value
  IS NULL OR setting_value = ''`), support de la génération paresseuse
  idempotente demandée par le document.
- Enregistrement des cinq réglages (`statistics_enabled`,
  `statistics_destination`, `statistics_installation_id`, `support_email`,
  `installed_at`) dans `public/index.php`, et exclusion des cinq du rendu
  générique de `Configuration > Paramètres`
  (`SettingsController::EXCLUDED_FROM_GENERIC_PAGE`).
- `ARCHITECTURE.md` §8.47.

### Décisions autonomes

1. **`installed_at` s'enregistre depuis son propre service.** Le document
   demande les cinq `register(...)` dans `public/index.php`. Quatre y sont ;
   le cinquième est déplacé dans
   `InstallationDateService::register(SettingService)`, appelé depuis
   `public/index.php`. Motif : `SetupController` doit écrire ce réglage à la
   fin du premier paramétrage, c'est-à-dire **avant** que la racine de
   composition n'ait jamais tourné, et la ligne `settings` n'existe pas
   encore à ce moment-là. Dupliquer le libellé et la description françaises
   dans deux fichiers aurait garanti une dérive.
2. **`InstallationIdentityService::getInstallationId()` lève si la ligne de
   réglage n'existe pas.** Une identité générée mais non persistée serait un
   bug silencieux (l'installation changerait d'identité à chaque requête) ;
   l'absence de la ligne est un défaut de câblage, signalé bruyamment via
   `Core\Statistics\StatisticsException`.
3. **`getSecret()` renvoie `null`** (plutôt que de lever) tant que
   `secrets.enc` n'existe pas — c'est le comportement « échec propre »
   demandé par les pièges de l'itération, et il permet à la page Support
   comme au parcours d'installation de l'appeler sans précaution.
4. **`DestinationMatcher` accepte un hôte sans schéma** (`scoutmagic.be`).
   `base_url` est saisi à la main par un administrateur et l'oubli du schéma
   est un cas courant ; le traiter comme « hôte inconnu » aurait fait échouer
   la détection du receveur sur une simple faute de frappe. Une chaîne
   contenant déjà `://` n'est jamais re-préfixée, sinon `https://` seul
   ressortirait comme l'hôte `https`.

### Divergences constatées avec le document

- §0.7 demande une section `ARCHITECTURE.md` §8.29/§8.30 : ces numéros sont
  occupés (voir « Conventions de travail »).
- Le document décrit `SettingService`/`SettingRepository` comme disposant
  déjà d'une « écriture conditionnelle ». Ce n'était pas le cas : elle a été
  ajoutée (`claimIfEmpty`).

### Reporté volontairement

- La case à cocher « Envoyer des statistiques d'utilisation » du formulaire
  d'installation appartient à IT-03 : `SetupController` n'écrit pour l'instant
  que `installed_at`.
- Les trois réglages d'état d'envoi (`statistics_last_success_at`,
  `statistics_last_failure_at`, `statistics_last_failure_reason`) sont
  déclarés en IT-03, avec la page qui les affiche.

---

## IT-02 — Construction du payload statistiques

### Implémenté

- `Core\Statistics\StatisticsPayloadBuilder` — `build(): array` et
  `buildJson(): string`, constante `STATISTICS_SCHEMA_VERSION = 1`, structure
  exacte demandée par le document en `snake_case` (D-17).
- Règle « indisponible ⇒ `null` » appliquée partout, y compris pour les
  compteurs (`active_members`/`active_sections` valent `null`, pas `0`, quand
  l'année scoute publique n'est pas définie).
- Chaque collecteur est isolé dans son propre `try/catch` ; `build()` ne lève
  jamais.
- `MailService::getDeliveryMode()` / `isDeliveryConfigured()` — deux
  accesseurs de diagnostic qui répondent « smtp/local » et « configuré ou
  non » sans jamais exposer hôte, port, identifiant, mot de passe ni adresse.
- `ARCHITECTURE.md` §8.47 complété (contenu du payload, propriétés,
  détermination de `installation.method` et de `scheduler.mode`).

### Décisions autonomes

1. **`usage.active_sections` est compté par jointure sur l'année publique**
   (`member_functions` × `member_years` × `sections`, `desk_code <> 'STAFFDU'`)
   plutôt que via `sections.is_active`. Motif : le document dit « pour l'année
   publique », or `sections.is_active` n'est pas scopé à une année — c'est un
   effet de bord du dernier import Desk. La jointure répond littéralement à la
   question posée et reste exacte même si l'année publique n'est pas celle du
   dernier import (période de transition, §8.26).
2. **`installation.method` privilégie `storage/config/install-report.json`.**
   `bootstrap.php` y persiste la valeur canonique (`layout` = `A` ou `B`), ce
   que le document autorise explicitement. La détection par système de
   fichiers (stub `index.php` racine + `public/index.php` ⇒ layout B, sinon
   layout A) n'est qu'un repli ; sans aucun indice, la valeur est `null`.
   Le document décrivait layout A comme « le répertoire du projet contient
   `index.php` et `assets/` », ce qui ne correspond pas au code réel de
   `bootstrap/bootstrap.php` (en layout A la racine projet est le *parent* du
   document root et ne contient ni l'un ni l'autre).
3. **`scheduler.mode` ne vaut jamais `null`.** Le document impose
   `real_cron` / `poor_mans_cron` sans troisième valeur : l'absence de
   `cron_last_run` *est* l'information « pas de vrai cron ».
4. **`ModuleManager` et `MailService` sont des dépendances facultatives** du
   constructeur. Le paquet de support (IT-05) et les tests doivent pouvoir
   construire le payload sans câbler toute la racine de composition ; leurs
   champs valent alors `null`, conformément à la règle générale.

### Divergences constatées avec le document

- L'exemple de payload du document montre `"modules": [ { "id": ..., "enabled":
  ..., "version": ... } ]` mais aussi `"email": { "mode": "smtp" }` sans dire
  d'où vient le mode ; la source réelle est `secrets.enc` (`mail_mode`), lue
  via `MailService`, jamais directement.

### Reporté volontairement

- Aucun câblage dans `public/index.php` : le payload n'est ni affiché ni
  envoyé à ce stade (IT-03 pour l'aperçu, IT-04 pour l'envoi).

---

## IT-03 — Page Support (core)

### Implémenté

- `Core\Http\Controller\SupportController` — `/config/support` (`role_min:
  superadmin`), entrée « Support » dans le menu Configuration, et
  `POST /config/support/statistics` (jeton CSRF obligatoire).
- Vue `config/support.html.twig` avec les quatre blocs demandés dans l'ordre :
  statistiques d'utilisation (interrupteur + destination + explication qui dit
  explicitement que le rapport **n'est pas anonyme**), état des envois, aperçu
  JSON du payload, paquet de support (adresse de support + avertissement
  `alert-warning` intégral).
- Trois réglages d'état d'envoi (`statistics_last_success_at`,
  `statistics_last_failure_at`, `statistics_last_failure_reason`), non
  éditables et exclus de la page Paramètres.
- Case « Envoyer des statistiques d'utilisation » dans le formulaire
  d'installation, cochée par défaut, avec dépliant « Voir ce qui sera envoyé »
  et mention de non-anonymat ; la valeur choisie alimente `statistics_enabled`
  en fin de parcours.
- RGPD : section 4.1 de `core/View/rgpd_default.html` (source de
  `RgpdContentService::getDefaultContent()`) enrichie de deux paragraphes
  (statistiques d'utilisation, archive de diagnostic), et règle 28 ajoutée au
  prompt système de `buildSystemPrompt()`.
- Classe utilitaire `.support-payload-preview` dans
  `public/assets/css/components.css` (hauteur maximale + défilement du bloc
  d'aperçu) plutôt qu'un `style=` en ligne.

### Décisions autonomes

1. **Le dépliant du setup affiche toujours la liste des catégories de
   données, jamais le payload.** À cette étape, `SetupController` n'a ni
   connexion à la base, ni `SettingService`, ni identité d'installation : le
   payload ne peut structurellement pas être construit. Le document prévoyait
   déjà ce repli ; la branche « afficher le payload » aurait été du code mort.
2. **L'interrupteur n'est journalisé que sur changement effectif.** Le
   document demande la journalisation de l'activation/désactivation ; ré-
   enregistrer la page sans rien changer n'est pas une décision de vie privée
   et produirait du bruit dans le journal.
3. **La destination est enregistrée par le même formulaire que
   l'interrupteur**, et une URL invalide rejette la soumission entière avec un
   message d'erreur français plutôt que d'enregistrer partiellement.
4. **Le bloc « État des envois » affiche l'horodatage brut à côté de la date
   en français.** Le filtre `french_date` n'affiche pas l'heure, or l'heure est
   précisément l'information utile pour diagnostiquer un envoi quotidien.
5. **`getDefaultContent()` lit un fichier statique** (`core/View/
   rgpd_default.html`) : c'est ce fichier qui a été modifié, pas la méthode.

### Divergences constatées avec le document

- Le document parle d'un « réglage » de destination « modifiable ici » sans
  préciser la route ; elle est portée par le même POST que l'interrupteur.

### Reporté volontairement

- Le bouton « Générer un paquet de support », l'indicateur de progression et
  le lien de téléchargement arrivent en IT-05 ; seul l'avertissement et
  l'adresse de support sont présents.
- Aucun envoi réel : les trois réglages d'état restent vides tant qu'IT-04
  n'est pas là.

---

## IT-04 — Envoi quotidien planifié

### Implémenté

- `Core\Statistics\StatisticsSender::send(): StatisticsSendResult` — trois
  issues (`sent` / `skipped(reason)` / `failed(reason)`), séquence de gardes
  dans l'ordre exact du document, HTTPS obligatoire sans repli.
- `StatisticsTransportInterface` + `StatisticsTransportResponse` +
  `StreamStatisticsTransport` (`file_get_contents` +
  `stream_context_create`, 10 s de connexion / 20 s au total, aucune
  nouvelle dépendance).
- `Core\Statistics\Task\SendStatisticsHandler` — clé `core`/`send_statistics`,
  référence `daily`, auto-replanification à +86 400 s dans un `finally`
  (donc dans les trois issues, et même si la composition du sender échoue),
  enregistré **et** amorcé dans `public/index.php`, enregistré dans
  `public/cron.php`.
- `Core\Statistics\StatisticsServiceFactory` — reconstruit toute la pile
  (identité, `ModuleManager` en lecture seule, payload, sender) depuis un
  `TaskContext`.
- `Core\Statistics\StatisticsStateSettings` — les trois clés d'état d'envoi.
- `Tests\Core\CronEntryPointTest` — nouveau test paramétré vérifiant que
  **chaque** handler core est enregistré dans les deux points d'entrée.
- `ARCHITECTURE.md` §8.47 complété (transport, gardes, redaction, non-reprise).

### Décisions autonomes

1. **Le réglage `dev_update_enabled` n'existe pas** dans le dépôt. Le mode
   développement est `auto_update_level === 'dev'` **et**
   `auto_update_enabled === '1'` (§8.17 d'`ARCHITECTURE.md`) : c'est cette
   condition qui est utilisée pour la garde `dev_mode`.
2. **Seuls les sauts « bloquants » alimentent l'état affiché.** `disabled` et
   `already_sent_today` sont l'état normal d'une installation en bonne santé ;
   les écrire dans `statistics_last_failure_*` afficherait en permanence un
   faux problème sur la page Support. Les autres sauts (`dev_mode`,
   `non_public_host`, `self_destination`, `maintenance_in_progress`) y sont
   bien reportés, conformément au bloc « Dernier échec ou saut ».
3. **Une base incapable de répondre à « une maintenance est-elle en cours ? »
   compte comme « oui »** : c'est la direction sûre, et c'est aussi le seul
   cas où le sender préfère ne rien envoyer plutôt que d'envoyer un instantané
   d'un état à moitié appliqué.
4. **Un hôte à un seul label** (`https://intranet`) est traité comme non
   public, au même titre qu'une IP littérale : le document ne le cite pas
   explicitement mais l'intention (D-16) est claire.
5. **Les trois clés d'état d'envoi sont sorties du contrôleur** vers
   `Core\Statistics\StatisticsStateSettings` : le sender est un service, il ne
   peut pas dépendre d'un contrôleur pour ses constantes (règle de couches
   d'`ARCHITECTURE.md` §2). `SupportController` expose toujours les mêmes
   constantes, déléguées.
6. **`StatisticsSenderFactoryInterface`** est un point d'injection de test
   pour le handler, exactement sur le modèle de
   `Core\Maintenance\BackupServiceInterface` sur `FullResetHandler` — un
   handler construit par `SchedulerRunner` avec un `new` nu n'a pas d'autre
   couture possible.

### Divergences constatées avec le document

- `dev_update_enabled` (voir décision 1).
- Le document dit « mise à jour de `statistics_last_success_at` ou du couple
  `statistics_last_failure_at` / `_reason` » après l'envoi, sans traiter le
  cas des sauts ; la page Support d'IT-03 promet pourtant « Dernier échec ou
  saut ». Arbitré par la décision 2.

### Reporté volontairement

- Aucun bouton « envoyer maintenant » : hors périmètre, l'envoi est
  exclusivement déclenché par le planificateur.

---

## IT-05 — Pipeline du paquet de support

### Implémenté

- Contrat de collecteur : `Core\Support\SupportCollectorInterface`,
  `SupportCollectorContext` (ajout par contenu ou par chemin source, accès
  `storage/`, connexion, réglages, `markUnavailable()`, `addNote()`), et
  `SupportCollectionOutcome` (`success` / `failed` / `unavailable`, motif,
  durée, notes).
- `Core\Support\SupportPackageService` — chaque collecteur dans son propre
  `try/catch`, ZIP toujours produit, `collection-status.json`, `README.txt`
  français complet (avertissement intégral inclus), `statistics.json`
  identique au payload d'envoi et généré même télémétrie désactivée.
- Tâche de fond `core`/`generate_support_package`
  (`Core\Support\Task\GenerateSupportPackageHandler`), planifiée à +0 s par le
  contrôleur avec `requested_by_user_account_id`, notification
  `core.support_package_ready` au demandeur, journal
  `support_package_generated`.
- Stockage : `storage/core/support/`, chiffré au repos
  (`EncryptedFileStorageService`), `FileRecord` en `role_min: superadmin`,
  servi via `/files/{id}` ; **un seul paquet conservé** (le précédent est
  supprimé, fichier et enregistrement) ; purge à 7 jours par
  `core`/`purge_support_packages` (quotidienne, auto-replanifiée).
- Interface : bouton « Générer un paquet de support » (POST + jeton CSRF,
  `superadmin`), indicateur de progression, `GET
  /api/support/package-status/{id}` interrogé par
  `public/assets/js/support-package.js`, puis lien de téléchargement.
- `ARCHITECTURE.md` §8.48, `SECURITY.md` §5/§6, `specifications.md` §4.5.

### Décisions autonomes

1. **Le paquet courant est décrit par deux réglages**
   (`support_package_file_id`, `support_package_generated_at`) plutôt que par
   une table : un seul paquet est conservé, une table aurait au mieux une
   ligne. L'horodatage de génération est stocké ici plutôt que lu dans
   `files.created_at` parce que la règle de rétention appartient à cette
   fonctionnalité, pas au magasin de fichiers.
2. **Les secrets à expurger sont injectés dans le service**
   (`SupportPackageFactory::secretsToRedact()` lit `secrets.enc` et n'en
   utilise les valeurs que comme aiguilles de remplacement). Un service de
   génération d'archive n'a aucune raison de savoir déchiffrer quoi que ce
   soit ; c'est aussi ce qui rend la règle testable.
3. **Les chemins d'archive sont normalisés** (pas de `/` initial, pas de
   `..`, pas d'antislash). Les collecteurs d'IT-07 composent des chemins à
   partir d'entrées de système de fichiers ; sans cette normalisation, un
   nom hostile pourrait écrire hors de l'arborescence de l'archive.
4. **`GET /api/support/package-status/{id}` refuse toute action planifiée
   qui n'est pas une génération de paquet**, plutôt que de renvoyer l'état
   brut de n'importe quelle ligne `scheduled_actions`.
5. **Le JavaScript est un fichier dédié** (`public/assets/js/
   support-package.js`) et non un script inline, conformément à la
   convention du dépôt pour toute logique de sondage (`maintenance.js`,
   `auth.js`). `npm run typecheck` passe.
6. **`storage/core/support/` n'a pas nécessité de modification de
   `.gitignore`** : la règle `storage/**` couvre déjà tout le contenu
   d'exécution (vérifié, pas supposé — SECURITY.md §12).

### Divergences constatées avec le document

- Le document numérote les sections `ARCHITECTURE.md` §8.30 pour le paquet de
  support ; ce numéro est occupé (voir « Conventions de travail »), la section
  est §8.48.

### Reporté volontairement

- Les collecteurs applicatifs (IT-06) et système (IT-07) ne sont pas encore
  branchés : seul `StatisticsCollector` figure dans
  `SupportPackageFactory::collectors()`, où l'ajout des suivants est une ligne.

---

## IT-06 — Collecteurs applicatifs

### Implémenté

- `DatabaseStructureCollector` → `database-structure.sql`, via un nouveau
  mode « structure seule » de `Core\Database\DatabaseDumper::dumpStructure()`.
  Aucun `INSERT`, vérifié par un test contre la vraie base MySQL de test.
- `ConfigurationParametersCollector` → `configuration-parameters.xlsx`
  (clé, module, libellé, type, valeur courante, valeur par défaut, « diffère
  du défaut », éditable). Redaction via le nouveau `setting_type = 'secret'`.
- `EventJournalCollector` → `event-journal.xlsx`, 48 dernières heures,
  une colonne par champ réel de `event_log`.
- `ScheduledTasksCollector` → `scheduled-tasks.xlsx`, une ligne par handler
  déclaré (core + modules), dernière instance exécutée et prochaine instance
  `pending`. Ni cadence, ni activé/désactivé, ni historique complet.
- `Core\Scheduler\CoreTaskHandlers` — déclaration unique des handlers core,
  utilisée par `public/index.php`, `public/cron.php` **et** le collecteur.
- `Core\Support\SupportSpreadsheet` — écriture XLSX, toutes les cellules en
  type chaîne explicite (SECURITY.md §23, injection de formule).
- `SupportCollectorContext::redact()` — expurgation partagée (secrets
  connus, caractères de contrôle, longueur bornée), utilisée par
  `collection-status.json` **et** par `last_error`.
- `Connection::dumpCredentials()` remplace la lecture par `ReflectionClass`
  des propriétés privées dans `BackupService::connectionCredentials()`.
- `AGENTS.md` (types de réglage, règle RGPD pour tout nouveau flux sortant),
  `docs/module-development.md` (type `secret`), `ARCHITECTURE.md` §8.48.

### Décisions autonomes

1. **L'énumération des handlers core est un registre déclaratif**
   (`CoreTaskHandlers`) plutôt qu'une méthode sur `SchedulerRunner`, comme le
   suggérait le document. Motif : le collecteur tourne dans un handler de
   tâche et n'a aucun accès à l'instance de `SchedulerRunner`. Le registre
   résout en plus la duplication entre les deux points d'entrée — précisément
   le bug documenté au §8.17 d'`ARCHITECTURE.md`.
2. **Un réglage de type `secret` est masqué aussi sur la page Paramètres.**
   Le document ne demande la redaction que dans le XLSX ; l'afficher en clair
   sur `/config/settings` viderait le filet de sécurité de son sens.
3. **`Connection::dumpCredentials()`** : `BackupService` lisait ces mêmes
   propriétés privées par réflexion. Un accesseur nommé est strictement
   meilleur (greppable, typé) et c'était le seul moyen propre de faire
   fonctionner le collecteur de structure.
4. **L'expurgation vit dans le contexte de collecte**, pas dans le service :
   `last_error` avait besoin de la même règle que les motifs d'échec, et deux
   implémentations auraient divergé.
5. **`last_error` est expurgé puis tronqué à 300 caractères** : un message
   PDO cite couramment les identifiants avec lesquels il a échoué.

### Divergences constatées avec le document

- `Core\Database\DatabaseDumper` n'exposait pas de mode « structure seule » :
  ajouté (`dumpStructure()`), comme le document l'autorisait.
- Le document évoque « une petite méthode sur `SchedulerRunner` » ; voir la
  décision 1.

### Reporté volontairement

- Les collecteurs système (phpinfo, système de fichiers, commandes, serveur
  web, journaux) arrivent en IT-07.

---

## IT-07 — Collecteurs système

### Implémenté

- `PhpInfoCollector` → `phpinfo.html`.
- `FilesystemCollector` → `filesystem.txt` (`storage/` en profondeur
  complète ; racine, `core/`, `modules/`, `public/`, `schema/`, `config/` en
  profondeur 2 ; `vendor/` non parcouru — présence et nombre d'entrées de
  premier niveau seulement). Pure PHP, liens listés mais jamais suivis.
- `CommandsCollector` → `commands.txt`.
- `WebServerCollector` → `webserver/` (tous les `.htaccess` de
  l'installation + liste courte de chemins candidats, sans découverte
  « platform-aware »).
- `LogsCollector` → `logs/` (48 h, plafond de 2 Mo par fichier, troncature
  signalée dans `collection-status.json`).
- `ARCHITECTURE.md` §8.48, `SECURITY.md` §6.

### Décisions autonomes — dont une correction de D-05

1. **`phpinfo()` est appelé avec `INFO_ALL & ~INFO_VARIABLES &
   ~INFO_ENVIRONMENT`, et non `INFO_ALL & ~INFO_VARIABLES` comme l'écrit
   D-05.** C'est une correction délibérée d'une **erreur factuelle du
   document**, pas un assouplissement : `~INFO_VARIABLES` masque la section
   « PHP Variables » (`$_SERVER`/`$_ENV`/`$_COOKIE`) mais **laisse
   intacte** la section « Environment », qui est un drapeau séparé
   (`INFO_ENVIRONMENT`) et qui imprime l'environnement du processus.
   Vérifié empiriquement sur cette machine : avec `~INFO_VARIABLES` seul,
   `phpinfo()` exposait encore des jetons d'API et des identifiants de proxy
   injectés par la plateforme d'hébergement. Or la justification écrite de
   D-05 cite explicitement « d'éventuels credentials d'environnement »
   parmi ce qui doit disparaître : appliquer la constante à la lettre aurait
   trahi l'intention littérale de la décision **et** violé `SECURITY.md`.
   Tout le reste de la sortie (modules, directives ini, extensions, chemins,
   limites) est conservé, exactement comme D-05 l'exige. Un test l'asserte
   contre une vraie sortie `phpinfo()`, pas contre le nom de la constante.
2. **La liste de `commands.txt` ne contient ni `mysqldump` ni `mysql`.**
   Vérification dans le code réel, comme le document le demande : le dump
   (`Core\Database\DatabaseDumper`, ifsnop/mysqldump-php) et la restauration
   (`Core\Database\DatabaseRestorer`) sont désormais **entièrement en PHP**,
   précisément parce que ces binaires étaient inutilisables sur l'hôte de
   production (§8.15.1). Les sonder rapporterait sur quelque chose que
   l'application n'appelle plus. En revanche `qpdf` et `pdftocairo` ont été
   **ajoutés** : ce sont les deux replis de `Core\Pdf\PdfCompressor` à côté
   de `gs`. Le docbloc de `Core\System\ExecutableLocator` mentionne encore
   un usage `mysql`/`timeout` qui n'existe plus dans le code.
3. **Une ligne de journal dont l'horodatage n'est pas analysable est
   conservée.** Les formats de journaux varient trop pour traiter « pas de
   date lisible ici » comme « ancien » ; supprimer les lignes de
   continuation d'une trace d'appel supprimerait exactement ce qui a de la
   valeur. Quatre formats sont reconnus (journal d'erreurs Apache, journal
   d'erreurs PHP, journal d'accès combiné, ISO 8601).
4. **Le plafond par fichier de journal est appliqué depuis la fin du
   fichier** (les lignes récentes), première ligne partielle supprimée.
5. **Les liens symboliques sont listés mais jamais suivis** dans le
   parcours de système de fichiers : un lien vers `/` transformerait le
   collecteur en balayage complet du disque.
6. **`vendor/` et `node_modules/` sont exclus de la recherche de
   `.htaccess`**, pour la même raison de volume que le collecteur de
   système de fichiers.

### Divergences constatées avec le document

- D-05 (voir décision 1) — **la seule divergence de fond de tout le
  chantier**, et elle va dans le sens de la sécurité.
- La liste de commandes du document est obsolète (voir décision 2).

### Reporté volontairement

- Rien. Le paquet de support est complet à l'issue de cette itération.

---

## IT-08 — Module receveur : réception

### Implémenté

- Champ de manifeste `receiver_only` (`ModuleManifest::$receiverOnly`,
  typage strict), drapeau `$isStatisticsReceiver` sur `ModuleManager`,
  filtrage en un seul point dans `discoverModules()`. Résolu depuis
  `DestinationMatcher::isReceiver(base_url, statistics_destination)` dans
  `public/index.php` **et** `public/cron.php`.
- Module `modules/support_dashboard/` (`version 1.0.0`,
  `enabled_by_default: false`, `receiver_only: true`) et son `schema.sql`
  (`support_installations`, `support_report_rate_limits`).
- `POST /api/statistics` (`role_min: public`, sans jeton CSRF — deuxième
  exception délibérée du dépôt) : HTTPS obligatoire, plafond de corps à
  64 Ko **avant** parsing, limitation à 10 requêtes/heure/IP (index aveugle,
  jamais l'adresse en clair), authentification par `password_hash()` /
  `password_verify()`, inscription à la première réception, champs inconnus
  acceptés + avertis + conservés, champs optionnels absents en `NULL`.
- Tâche `support_dashboard`/`purge_rate_limits` (quotidienne,
  auto-replanifiée).
- `ARCHITECTURE.md` §7.1 et §8.49, `SECURITY.md` §4 et §5,
  `docs/module-development.md`.

### Décisions autonomes

1. **La route `/api/statistics` déclare `menu: "notre_unite"` et
   `label: ""`.** `menu` est obligatoire dans le manifeste et doit être au
   moins aussi permissif que `role_min: public` ; un libellé vide
   n'enregistre aucune entrée de menu (`ModuleManager::loadModule()`).
2. **Réponse `204 No Content` en cas d'acceptation** : « 2xx minimal, sans
   écho du payload ». Un rejet renvoie `{"status":"rejected"}` et rien
   d'autre — une installation inconnue est indiscernable d'un mauvais secret.
3. **L'ordre des gardes est inversé par rapport au coût** : transport,
   puis taille (sur la chaîne brute), puis limitation de débit, puis
   parsing. Un corps de 1 Mo coûte un `strlen()` et n'incrémente même pas
   le compteur de débit.
4. **Une tâche de purge des lignes de limitation a été ajoutée** — non
   demandée par le document, mais sans elle la table croît indéfiniment
   (précédent : `PurgeHumanCheckRateLimitsHandler`, `PurgeRateLimitHandler`
   du module retro).
5. **Chaque rapport remplace toutes les colonnes dénormalisées, `NULL`
   compris.** Un émetteur qui cesse de pouvoir mesurer quelque chose doit
   cesser de le rapporter, pas figer sa dernière valeur connue.
6. **`receiver_only` est typé strictement.** `"false"` (chaîne, donc vraie)
   masquerait le module partout, avec un symptôme quasi indébogable.
7. **`payload` est de type `JSON`** en MySQL et `TEXT` dans le miroir
   SQLite des tests.

### Divergences constatées avec le document

- Le document décrit `support_report_rate_limits` comme « calque
  `HumanCheckRateLimitRepository` » : le calque a été suivi, mais la table
  n'a pas de colonne `form_key` (il n'y a qu'un seul formulaire ici).

### Reporté volontairement

- Aucune interface de consultation : la page `/support-dashboard`, les
  filtres, les indicateurs et les graphes arrivent en IT-09.
- Aucun dispositif de blocage ou de liste noire d'IP (hors périmètre,
  Annexe A).

---

## IT-09 — Tableau de bord : état courant

### Implémenté

- Page `/support-dashboard` (`role_min: superadmin`, menu `configuration`)
  et `GET /support-dashboard/installations/{id}` pour le corps de la boîte
  de dialogue de détail.
- `SupportDashboardFilters` : tout l'état de vue est construit depuis la
  seule chaîne de requête (aucun cookie, aucun stockage local, aucune
  session). `queryString()` reconstruit les liens de tri et de pagination
  sans jamais perdre un filtre en cours.
- `SupportDashboardService` : filtrage, recherche, tri, pagination,
  cinq cartes d'indicateurs et deux graphes, tous recalculés **sur
  l'ensemble filtré**.
- Filtres : statut actif/obsolète, version/build, méthode d'installation,
  mode de mise à jour automatique, module + état d'activation du module.
  Recherche libre sur URL, identifiant, version/build, année scoute.
- Tri sur dernière réception, membres actifs, version/build, date
  d'installation, URL. Pagination à 25.
- Table responsive par palier (mobile → xxl) conforme au document,
  URL cliquable en `target="_blank" rel="noopener noreferrer"`.
- Boîte de dialogue de détail : toutes les métriques plus le JSON brut
  exact du dernier rapport accepté, rendu **par Twig côté serveur** puis
  récupéré au clic.
- `ARCHITECTURE.md` §8.50.

### Décisions autonomes

1. **Filtrage, tri, pagination et agrégats se font en PHP, jamais en
   SQL.** Le filtre « ce module est-il activé » vit dans le JSON stocké :
   en SQL cela impose `JSON_CONTAINS`, propre à MySQL, donc intestable.
   Surtout, cartes et graphes doivent porter sur l'ensemble filtré —
   séparer le filtre (SQL) des agrégats (PHP) est précisément ainsi qu'une
   table et ses propres compteurs finissent par se contredire.
2. **Les valeurs de filtre qui circulent dans l'URL sont des clés
   techniques, jamais les libellés français affichés.** Le filtre de mise à
   jour automatique voyage en `disabled`/`patch`/`minor`/`major` ; le
   libellé est produit une seule fois, au rendu. Une URL n'est pas un
   artefact de traduction. (Corrigé au cours de l'itération : la première
   version comparait sur la chaîne `désactivées`.)
3. **Une valeur absente trie toujours en dernier, dans les deux sens.**
   L'enterrer sous un tri décroissant serait le même mensonge que de
   l'afficher `0`.
4. **Le corps de la boîte de dialogue est rendu par Twig et récupéré au
   clic**, plutôt qu'embarqué une fois par ligne : une page de vingt-cinq
   installations transporterait sinon vingt-cinq payloads JSON complets.
   Bénéfice secondaire : aucune valeur émanant d'une installation distante
   n'est assemblée en HTML côté client (SECURITY.md §28).
5. **Le seuil d'activité reste la constante
   `SupportDashboardService::ACTIVE_THRESHOLD_DAYS = 14`**, lue par une
   méthode `protected activeThresholdDays()` — le réglage
   `support_active_threshold_days` d'IT-10 n'aura qu'à la surcharger.
6. **Pas de test Vitest pour `support-dashboard.js`.** Le script est de la
   colle DOM : il transmet des séries calculées côté serveur à Chart.js et
   injecte un fragment HTML rendu par Twig. Aucune logique indépendante à
   isoler (AGENTS.md § Tests admet explicitement ce cas). `npm run
   typecheck` couvre les signatures.

### Divergence / correction hors périmètre apparent

- **Bug réel corrigé dans `SupportInstallationRepository` (IT-08).**
  `PDOStatement::execute()` lie toute valeur d'un tableau en chaîne, et PHP
  convertit `false` en `''` : une colonne `BOOLEAN` recevait donc `''`,
  refusé par MySQL en mode strict et stocké en SQLite comme une chaîne vide
  relue ensuite en « non renseigné ». Un `auto_update_enabled: false`
  rapporté devenait indiscernable d'un champ absent — exactement la
  confusion que tout ce chantier interdit. Les booléens sont désormais
  convertis en entiers au moment du bind. Détecté par le test de filtre
  d'IT-09, corrigé ici plutôt que reporté.

### Reporté volontairement

- Réglages `support_active_threshold_days` / `support_retention_months`,
  export XLSX, rétention automatique et suppression manuelle : IT-10.
- Section historique et son unique graphe : IT-11.

---

## IT-10 — Export XLSX, activité et rétention

### Implémenté

- Réglages de module `support_active_threshold_days` (défaut 14) et
  `support_retention_months` (défaut 6), tous deux éditables. Le seuil
  d'activité en dur d'IT-09 est remplacé par le premier.
- `SupportDashboardService::activeThresholdDays()` / `retentionMonths()` :
  point de lecture unique, pour que la page et la purge ne puissent jamais
  diverger sur le vocabulaire.
- Tâche `support_dashboard`/`purge_installations` (quotidienne,
  auto-replanifiée) : au-delà de la fenêtre de rétention sans rapport
  accepté, l'enregistrement est supprimé **en entier** — identifiant, URL,
  dernier payload, métadonnées de réception et empreinte du secret.
- Suppression manuelle par un superadmin : `POST
  /support-dashboard/installations/{id}/delete`, boîte de confirmation
  obligatoire, jeton CSRF, journalisée.
- Export XLSX : `GET /support-dashboard/export`, une ligne par installation
  de **l'ensemble filtré courant** (pas la page affichée), une colonne par
  métrique définie, statut actif/obsolète explicite et horodatage de
  dernière réception. Sans colonne JSON brut, sans email de contact, sans
  valeur dérivée « jours depuis le dernier rapport ».
- `ARCHITECTURE.md` §8.50 complété.

### Décisions autonomes

1. **Un réglage de seuil nul, négatif ou vide n'est pas obéi**, il retombe
   sur le défaut déclaré. Un seuil d'activité à zéro marquerait toute la
   flotte obsolète ; une rétention à zéro viderait la table à la prochaine
   purge. Un réglage éditable qui peut détruire les données en une frappe
   n'est pas un réglage, c'est un piège.
2. **L'export réutilise `Core\Support\SupportSpreadsheet`** (écrit en
   IT-06) plutôt que de repartir de PhpSpreadsheet : il applique déjà
   l'écriture en chaîne explicite exigée par SECURITY.md §23, et ces
   colonnes contiennent des valeurs venues d'installations distantes.
3. **`filteredRows()` est un point d'entrée distinct de `buildView()`.**
   Exporter la page à l'écran est précisément la manière dont un accident
   de pagination devient un livrable tronqué sans que personne ne le voie.
4. **Le lien d'export transporte la chaîne de requête courante, `page`
   retirée.** L'export est ainsi reproductible depuis sa propre URL et ne
   peut pas diverger de ce que la table montrait.
5. **L'entrée de journal de suppression porte l'identifiant de ligne du
   receveur, jamais l'identifiant d'installation ni l'URL.** Une
   suppression dont la trace conserve ce qu'elle prétend effacer n'efface
   rien.
6. **Une purge qui ne supprime rien n'écrit rien.** Sinon le journal gagne
   une ligne par jour et par installation-receveur pour dire qu'il ne s'est
   rien passé.
7. **Deux colonnes de modules dans l'export** (activés / désactivés) plutôt
   qu'une colonne par module : la liste des modules varie d'une
   installation à l'autre et d'une version à l'autre, une colonne par
   module rendrait l'entête instable d'un export au suivant.

### Dépendance couverte par anticipation

- « La rétention et la suppression manuelle laissent les agrégats finalisés
  intacts » : la table `support_monthly_aggregates` n'existe qu'en IT-11,
  mais le test
  `PurgeInstallationsHandlerTest::testThePurgeTouchesNoTableOtherThanSupportInstallations`
  crée la table et vérifie dès maintenant que la purge n'y touche pas.

### Reporté volontairement

- Agrégats mensuels, graphe historique et sélecteur de période : IT-11.

---

## IT-11 — Historique mensuel

### Implémenté

- `support_monthly_aggregates` (mois `YYYY-MM` unique, nombre
  d'installations, horodatage de finalisation) et
  `support_monthly_contributions` (table de travail, unicité
  `(month, installation_id)`). `module.json` passe en `1.1.0` — le schéma a
  changé (AGENTS.md § Database).
- `SupportMonthlyAggregateRepository` : enregistrement d'une contribution,
  recherche des mois révolus non finalisés, finalisation transactionnelle
  (écriture de l'agrégat **et** suppression des contributions du mois).
- Enregistrement d'une contribution à chaque rapport accepté, sur les deux
  chemins (première inscription et rapport ultérieur).
- Tâche `support_dashboard`/`finalize_monthly_aggregate` (quotidienne,
  auto-replanifiée) : finalise **tout** mois révolu non finalisé, ce qui
  rend le rattrapage de plusieurs mois gratuit.
- Section historique du tableau de bord : **un seul graphe** (ligne,
  installations ayant émis par mois), sélecteur de période 6 / 12 / 24 mois
  / tout l'historique, défaut 12, non persistant.
- `ARCHITECTURE.md` §8.51.

### Décisions autonomes

1. **L'unicité mois + installation est une contrainte de table, pas un
   test en PHP.** Un `SELECT` puis `INSERT` laisse deux rapports simultanés
   s'intercaler ; l'index unique ne le permet pas. `INSERT IGNORE` (MySQL)
   / `INSERT OR IGNORE` (SQLite) selon le pilote.
2. **La suppression des contributions à la finalisation est faite dans la
   même transaction que l'écriture de l'agrégat.** Sinon un incident entre
   les deux laisse soit un agrégat sans base, soit — bien pire — des
   identifiants individuels à côté d'un agrégat censé n'en contenir aucun.
3. **`SupportHistoryPeriod` est un type distinct de
   `SupportDashboardFilters`**, et `buildHistory()` une méthode distincte de
   `buildView()`. L'indépendance exigée par le document devient ainsi
   structurelle : les filtres d'état courant ne sont même pas un argument
   de la construction de l'historique. Un test le vérifie en appliquant
   tous les filtres à la fois et en constatant que la série ne bouge pas.
4. **L'enregistrement d'une contribution n'est jamais fatal.** Un receveur
   qui refuserait un rapport parce qu'une écriture de comptabilité a échoué
   échangerait ce qui compte contre ce qui ne compte pas.
5. **Le mois courant n'est jamais finalisé.** Il peut encore gagner des
   contributeurs, et un agrégat est immuable une fois écrit : le finaliser
   tôt figerait un demi-mois comme s'il était complet.
6. **Le graphe est une ligne, pas un histogramme, et son axe démarre à
   zéro** avec des graduations entières — un décompte d'installations est
   un entier, et démarrer ailleurs qu'à zéro exagère chaque oscillation.
7. **Le sélecteur de période soumet son propre formulaire**, qui ne
   transporte que `history`. Le partager avec le formulaire de filtres
   ferait perdre l'indépendance à la première soumission.

### Divergence constatée avec le document

- Le document décrit `support_monthly_aggregates` avec « mois, nombre
  d'installations ayant émis, horodatage de finalisation ». Une clé
  primaire technique `id` a été ajoutée à côté de la contrainte d'unicité
  sur `month`, par cohérence avec toutes les autres tables du dépôt.

### Piège rencontré

- `TwigFactory::create()` compile dans `storage/temp/twig_cache/{version}`
  avec `auto_reload` désactivé hors mode debug : une modification de
  gabarit reste invisible tant que ce cache n'est pas vidé. Sans incidence
  sur les tests (qui construisent leur propre environnement), mais à savoir
  pour toute vérification visuelle.

---

## IT-12 — Passe finale

### Documentation mise à jour

- `ARCHITECTURE.md` : §8.47 (statistiques core), §8.48 (paquet de support),
  §8.49 (module receveur), §8.50 (tableau de bord, état courant), §8.51
  (historique mensuel) ; champ `receiver_only` en §7.1 ; `Core\Statistics/`
  et `Core\Support/` dans l'arborescence du §12.
- `SECURITY.md` : §4 (deuxième exception CSRF), §5 (stockage du secret,
  chiffrement du ZIP), §6 (traitement du paquet : `superadmin`, un seul
  conservé, purge à 7 jours), justification de la décision `phpinfo`
  (D-05), justification du stockage en clair de l'URL d'instance côté
  receveur.
- `specifications.md` : ligne « Support » et ligne « Tableau de bord
  support » dans le tableau §4.5, et nouvelle section fonctionnelle §21
  décrivant les **trois responsabilités séparées**.
- `AGENTS.md` : `setting_type = 'secret'`, et la règle « tout nouveau flux
  sortant vers un tiers impose une mise à jour RGPD ».
- `design.md` : §2.5.1 (entités de statistiques d'usage) et deux lignes
  ajoutées à la table de stratégie de chiffrement §2.6.
- `README.md` : page Support et archive de diagnostic dans la liste des
  fonctionnalités.
- `docs/module-development.md` : champ `receiver_only`, type de réglage
  `secret`.
- `CONTRIBUTING.md` : inchangé — aucune règle de contribution ne change.

### Vérification transverse

- **Enregistrement des handlers.** Les handlers de tâches core du chantier
  sont enregistrés en un seul endroit, `Core\Scheduler\CoreTaskHandlers::
  registerAll()`, appelé à l'identique par `public/index.php` et
  `public/cron.php`. La parité est donc structurelle et non plus à
  maintenir à la main — c'est précisément l'oubli que §8.17 rapporte comme
  ayant déjà causé des échecs silencieux en production. L'amorçage de la
  première instance (`schedule(...)` au démarrage) n'existe que dans
  `index.php`, exactement comme le précédent `purge_notifications` : cron
  exécute les tâches, il ne les amorce pas.
- **Routes.** Les cinq routes du module et les quatre routes core ajoutées
  portent toutes un `role_min` explicite. Vérifié programmatiquement.
- **`.gitignore`.** `storage/**` couvre `storage/core/support/` — vérifié
  par `git check-ignore`, pas supposé (SECURITY.md §12).
- **Secrets.** Aucune valeur de secret en dur dans le code, les tests, les
  fixtures ou les commentaires. `statistics_secret` n'apparaît que comme
  nom de clé dans `secrets.enc`, jamais dans `settings`.
- **Langue.** Aucun identifiant français dans le code du chantier, aucune
  chaîne d'interface anglaise dans ses gabarits.

### Vérification de bout en bout

Scénario scripté et rejoué en fin de chantier — **25 contrôles, tous
passants** :

- résolution du receveur (équivalence `www.`, schéma et port ignorés,
  sous-domaine distinct non équivalent, URL vide) ;
- émission vers un receveur : première inscription, puis authentification
  du rapport suivant, puis rejet en 401 d'un mauvais secret ;
- champ inconnu accepté, signalé, et conservé verbatim dans le JSON brut ;
- le secret n'apparaît ni en base ni au journal, sous aucune forme ;
- requête en clair refusée, corps de 1 Mo refusé avant parsing ;
- tableau de bord : vue par défaut, cinq indicateurs, deux graphes, un
  filtre qui déplace simultanément table, compteurs et graphes ;
- export réellement au format XLSX (conteneur ZIP), sans colonne JSON ni
  email ;
- historique : trois rapports d'une même installation dans le mois ⇒ une
  contribution ; finalisation ; disparition des identifiants individuels ;
  seconde finalisation sans effet ; suppression d'une installation sans
  effet sur l'agrégat finalisé ; indépendance vis-à-vis des filtres.

Les scénarios restants du §3 sont couverts par des tests nommés plutôt que
par ce script, parce qu'ils y sont mieux à leur place :
`SetupControllerTest` (case cochée / décochée, installation aboutissant
dans les deux cas), `SupportPackageServiceTest::
testAThrowingCollectorStillProducesTheArchiveAndIsReportedAsFailed` et
`::testStatisticsJsonIsProducedEvenWhenReportingIsDisabled`.

### Vérification visuelle

Page `/support-dashboard` rendue et photographiée à **375 px** et
**1280 px** (AGENTS.md § Tests) : aucun débordement horizontal du document
aux deux largeurs (la table défile dans son propre conteneur
`.table-responsive`), les trois graphes se dessinent, et une métrique non
rapportée s'affiche bien « Non renseigné » et non `0`.

### État des tests à la clôture

- Suite du chantier : **325 tests, tous verts**.
- `vendor/bin/phpstan analyse` : **aucune erreur**.
- `npm run typecheck` : **aucune nouvelle anomalie**.
- Suite complète du dépôt : **9 échecs, tous dans `tests/Modules/Rental/`**,
  introduits par un chantier parallèle sur `main`. Vérifié sur un
  `git worktree` d'`origin/main` **sans aucune modification de ce
  chantier** : `266 tests, 9 failures`, chiffres identiques. Ces échecs
  préexistent à la fusion et sont hors du périmètre de ce chantier ; ils
  sont signalés au mainteneur plutôt que corrigés ici.
