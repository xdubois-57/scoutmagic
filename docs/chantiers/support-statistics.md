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
