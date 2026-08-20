# Chantier — Statistiques d'usage, paquet de support, tableau de bord support

Journal d'implémentation du document `CHANTIER-support-statistiques.md`
(itérations IT-01 à IT-12). Une section par itération : ce qui a été fait,
les décisions prises en autonomie, les divergences constatées entre le
document de chantier et le dépôt réel, et ce qui a été reporté.

Le récapitulatif final se trouve en fin de fichier (IT-12).

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
  donc les premiers numéros libres : **§8.41** (statistiques core), **§8.42**
  (paquet de support) et **§8.43** (module receveur).

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
- `ARCHITECTURE.md` §8.41.

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
- `ARCHITECTURE.md` §8.41 complété (contenu du payload, propriétés,
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
- `ARCHITECTURE.md` §8.41 complété (transport, gardes, redaction, non-reprise).

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
- `ARCHITECTURE.md` §8.42, `SECURITY.md` §5/§6, `specifications.md` §4.5.

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
  est §8.42.

### Reporté volontairement

- Les collecteurs applicatifs (IT-06) et système (IT-07) ne sont pas encore
  branchés : seul `StatisticsCollector` figure dans
  `SupportPackageFactory::collectors()`, où l'ajout des suivants est une ligne.
