# Chantier — Module « Migration de données » (`data_migration`)

> **Sources.**
> - La spécification fonctionnelle : `docs/chantiers/SPEC-migration-donnees.md`.
> - La maquette : `docs/chantiers/maquettes/migration-donnees.html`. Elle fait foi pour la hiérarchie des écrans, les libellés et les états.
> - Ce document : le découpage, l'ordre et les décisions du mainteneur.
>
> En cas de contradiction, **ce document l'emporte sur la spécification** : les décisions ci-dessous ont été prises après elle, contre le code.
>
> **Ticket GitHub :** #1142 (le numéro est inscrit ici à la création du ticket).

## 0. Comment travailler

- **Lire d'abord** `AGENTS.md`, `ARCHITECTURE.md` (en particulier §7.4 à §7.6 sur les modules et les `Api\`, et §8.1 sur l'import Desk), `SECURITY.md`, `CONTRIBUTING.md`, `docs/module-development.md`, `docs/exigences-non-fonctionnelles.md` §2 (hébergement) et `design.md`.
- **Enchaîner les lots dans l'ordre des vagues, sans poser de question.** On ne s'arrête que pour un changement de design ou un manque de clarté fonctionnelle. Une décision technique ordinaire se prend et se note dans la description de la PR.
- **Une PR par lot**, en français, dont la description dit `Lot X du chantier #1142 (le ticket reste ouvert jusqu'au lot I).` Jamais un mot de fermeture (corrige, close, fix, resolve…) suivi du numéro, ni dans la PR ni dans les commits : `issue-fixed-comment.yml` fermerait le ticket. Le lot I le ferme à la main, avec son commentaire de synthèse (§3).
  - **Fusion automatique dès que tout est vert**, par l'auto-merge de la PR, sans demander de confirmation.
  - Pas de poussée directe sur `main`.
- **Paralléliser la vague 2.** Ses sept lots partent en même temps, chacun dans sa propre branche ou son worktree. Le §4 dit quels fichiers ils partagent et comment résoudre les conflits.
- **Avant chaque commit :**
  - `vendor/bin/phpstan analyse` dès qu'il touche du PHP, et `npm run typecheck` dès qu'il touche `public/assets/js/`. Ne jamais ajouter son propre constat à une baseline ;
  - les tests écrits avec le code ; un nouveau répertoire de tests hors de `tests/Modules/` est déclaré dans `phpunit.xml` dans le même changement ;
  - un test RBAC pour chaque route : accordée au `role_min`, refusée un cran en dessous.
- **Code en anglais, interface en français.**

## 1. Décisions verrouillées

Elles complètent ou corrigent la spécification.

| # | Sujet | Décision |
|---|---|---|
| D1 | Public visé | **Module générique**, pour toute unité qui adopte ScoutMagic. Les formats ZIP sont publics et documentés. L'exporteur propre à 25sv vit dans un dépôt séparé (`scoutmagic-25sv-migration`) et produit ces ZIP. **Rien de propre à 25sv dans ce dépôt.** |
| D2 | Inscriptions décidées | Une demande `accepted` ou `refused` importée reçoit, dans l'historique des décisions, une ligne dans un **nouvel état « Communiquée par l'ancien site »** (par exemple `registration_decision_emails.state = 'communicated_elsewhere'`). `RequestStatusService::visibleStatus()` le traite comme `sent` : **la famille voit la décision tout de suite**. Aucun courriel n'est envoyé et aucun faux « envoyé » n'est écrit. |
| D3 | Publipostages historiques | **Un seul message, avec son texte modèle.** Les versions personnalisées par destinataire ne sont pas reprises. Ceci remplace le §6.1 de la spécification (« sujet et corps final rendus pour chaque destinataire »). |
| D4 | Actualités sans résumé ou sans image | **Autorisées pour les seuls articles importés.** L'obligation reste entière pour la création et la modification normales. Les listes affichent une vignette neutre (« Pas d'image ») et pas de résumé. Rien n'est inventé. |
| D5 | Années scoutes manquantes (photos, galeries) | L'aperçu **liste les années passées manquantes** et l'administrateur **coche celles à créer**. Jamais l'année en cours ni une année future. Les éléments des années non cochées sont ignorés avec ce motif. |
| D6 | Section d'un courriel importé | **D'après l'adresse d'expédition** : si elle est l'adresse d'une section ScoutMagic, le courriel lui est rattaché, **sinon au Staff d'U**. L'adresse et le nom d'expéditeur d'origine sont conservés et affichés tels quels. |
| D7 | Périmètre des inscriptions | **Nouvelles demandes seulement.** Les réponses aux campagnes de réinscription ne sont pas importées. |
| D8 | Bilan | Il ne montre **jamais de nom de personne**, seulement la référence de l'ancien site et le motif. Les noms de lieux de camp, qui ne sont pas des données personnelles, peuvent apparaître. |
| D9 | Abandon d'un import interrompu | **Garde ce qui est déjà importé**, sans rien défaire, et efface le fichier déposé. |
| D10 | Référence d'une section dans les ZIP | Le **code Desk de la section** (`sections.desk_code`), jamais un identifiant SQL. Sans correspondance, le lien est ignoré. S'il est indispensable (album limité à une section), l'élément est ignoré. Jamais d'élargissement silencieux de la visibilité. |
| D11 | Protection permanente | Le verrou « Importé » des actualités et des courriels est **porté par les tables de leur module** (colonnes `imported_at` et `import_source`), jamais par `data_migration`. Il survit à la désactivation du module. Le serveur refuse les actions interdites, pas seulement l'interface. |
| D12 | Provenance et dédoublonnage | Une **table de correspondance propre à `data_migration`** : (installation source, format, identifiant source) → (type cible, identifiant cible). C'est la référence de dédoublonnage. Aucune colonne `legacy_*` n'est ajoutée aux modules, sauf le verrou de D11. |

## 2. Ce que le code impose

Constats établis le 11/11/2026 sur `main`. À revérifier au début de chaque lot.

- **Aucun module cible n'expose d'interface d'écriture adaptée.** Chaque lot de la vague 2 ajoute **une petite interface d'écriture « sans effet de bord »** dans le module cible (`Modules\X\Api\…`). `data_migration` n'appelle que cette interface. `ModuleBoundariesTest` l'exige : aucune référence au-delà de `Api\` et aucune clé étrangère entre modules.
- **Il n'existe pas d'interrupteur global** pour couper les notifications ou les courriels. Chaque interface d'écriture crée l'objet **sans appeler** les méthodes de service qui notifient. Elle passe par le dépôt, ou par une variante de service sans notification, puis applique les règles métier (chiffrement, droits sur les fichiers, journal).
  - Effets de bord connus :
    - `CalendarEventService::createEvent()` → `dispatchEventPublished()` ;
    - `AlbumService::create()` → `dispatchAlbumPublished()` ;
    - `RegistrationService::submit()` → accusé de réception et alerte à l'unité ;
    - `SecondaryEmailService::addEmail()` → courriel de confirmation ;
    - `FormService::willStartIssuingTickets` → billets ;
    - `DigestService` → un `last_digest_sent_at` à NULL compte comme 1970.
  - Une interface d'écriture qui crée une ligne `files` passe par `UploadHandler` ou `PhotoIngestionService`, avec `DiskBudget` (`DiskBudgetWiringTest`).
- **Réutiliser plutôt que réécrire :**

  | Besoin | Ce qui existe |
  |---|---|
  | Dépôt de gros fichiers, avec reprise | `core/File/ChunkedUploadStore` + `public/assets/js/chunked-upload.js` |
  | Lecture de ZIP non fiables | `core/Mail/Feedback/Dmarc/BoundedArchive` (le seul lecteur pensé pour ça), à généraliser |
  | Traitement par tranches piloté par la page | `MaintenanceController::advanceMigration()` (tranche d'environ 5 s par appel), `window.ScoutMagicApi.poll()` / `pollSlot()` |
  | Membre par identifiant Desk, alias de fusion compris | `core/Import/MemberRepository::findByDeskId()` |
  | Années scoutes | `ScoutYearService::findByLabel()`. `ensureYear()` crée sans garde-fou : ne l'appeler qu'au travers de l'aide de D5 |
  | Sécurité des URL sortantes | `Core\Security\SsrfUrlValidator`, `Core\Http\UrlMetadataFetcher` (redirections revalidées, IP épinglée) |
  | Nettoyage HTML | `Core\Security\HtmlSanitizer` |
  | Photos | `core/Photo/PhotoIngestionService::ingest()` (utilisable hors requête web) |
  | Doublons de lieux de camp | `Modules\Camps\Service\DuplicatePlaceDetector` |
  | Doublons d'inscription | blind index `name_dob_blind_index` et `RecommendationService` |

- **Hébergement** : 128 Mio, 30 s par requête, et aucune tâche ne lance un processus en ligne de commande. Chaque tranche d'import tient en environ 5 s et reprend depuis un curseur enregistré.

## 3. Les lots

### Vague 1 — socle (seul)

#### Lot A — Module, parcours commun, sécurité des archives

1. **Le module** `modules/data_migration/` :
   - `module.json` : catégorie `technique`, désactivé par défaut. Un `requires` vers d'autres modules est **interdit**, puisque chaque sous-page dépend d'un module différent.
   - Entrée « Migration de données » dans `espace_admin` (`role_min: admin`), colonne `services`, avec `menu_order` explicite.
   - Accueil conforme à la maquette : la liste des imports disponibles, leur dernier état et le bandeau « Reprendre ».
2. **Disponibilité de chaque sous-page.** Un import n'est proposé que si son module cible est actif. Le même contrôle s'applique à l'affichage, à l'accès direct par l'adresse, aux points d'entrée AJAX et à l'exécution d'une tranche : module inactif = 404.
   - Les entrées de sous-page passent par un `MenuEntryProvider` (ARCHITECTURE §7.4), pas par un `label` statique.
3. **Lecteur d'archives sûr au cœur.** `core/Archive/BoundedZipReader` (nom libre), extrait de `BoundedArchive` et généralisé. Il refuse :
   - les chemins absolus, avec lettre de lecteur ou `..` ;
   - les liens symboliques ;
   - le dépassement des plafonds de nombre d'entrées, de taille par entrée et de taille totale, décompressée **mesurée** et non déclarée ;
   - les extensions hors de la liste autorisée de chaque format.

   `BoundedArchive` (DMARC) l'utilise ensuite, sans changement de comportement, et ses tests restent verts.
4. **Manifeste commun** (spécification §4.1) :
   - `format`, `format_version` en semver, `source.installation_id`, `export_id`, `exported_at`, et l'inventaire SHA-256 de chaque fichier ;
   - une version majeure inconnue, un manifeste contradictoire ou une empreinte fausse rejettent tout le lot **avant toute écriture** ;
   - les champs inconnus sont ignorés ;
   - les identifiants source sont des chaînes.
5. **Les tables du module** :
   - `data_migration_runs` : l'import, le fichier déposé, l'état (`uploaded`, `analysed`, `confirmed`, `running`, `interrupted`, `done`, `abandoned`), le curseur, les compteurs, le bilan en JSON **sans données personnelles** (D8), l'auteur et les dates ;
   - `data_migration_items` : la correspondance de D12, unique sur (installation source, format, identifiant source).
6. **Le parcours commun** (maquette « Un import ZIP ») :
   1. dépôt par morceaux, avec reprise ;
   2. analyse ;
   3. aperçu (« Ce que l'import fera ») ;
   4. dialogue de confirmation ;
   5. exécution par tranches, avec interrompu et reprise ;
   6. bilan en quatre catégories : importés, déjà présents, ignorés pour données manquantes ou non reconnues, erreurs techniques.

   Un composant Twig et un script partagés servent aux sept imports. Chaque import ne fournit que son **analyseur**, son **aperçu** et son **écrivain d'élément**, via une interface `Importer` interne au module.
7. **Idempotence et cohérence :**
   - chaque élément s'écrit dans **sa propre transaction**, avec sa ligne `data_migration_items` ;
   - une relance saute les éléments déjà correspondants ;
   - une erreur technique sur un élément est comptée, puis l'import continue.
8. **Années manquantes (D5)** : une aide partagée liste les années passées absentes d'un fichier et crée **seulement** celles cochées.
9. **Section par code Desk (D10)** : une aide partagée.
10. **Purge** : une tâche planifiée efface les fichiers déposés 24 h après la fin, l'abandon ou le dernier mouvement d'un import interrompu. Le contenu personnel des ZIP n'est jamais journalisé.
11. **Documentation** :
    - `docs/formats/data-migration/conventions.md`, avec le manifeste, les règles communes, les versions et les empreintes ;
    - l'aide du module (`help/`) ;
    - la page RGPD : données traitées le temps de l'import, durée de conservation du fichier.
12. **Tests** :
    - archives malveillantes : traversée, lien symbolique, bombe de compression, empreinte fausse, version majeure inconnue ;
    - RBAC ;
    - sous-page d'un module inactif refusée par l'adresse et par AJAX ;
    - reprise après interruption sans doublon.

### Vague 2 — les sept imports (en parallèle, après la fusion du lot A)

Chaque lot livre :
- l'interface d'écriture sans effet de bord dans le module cible ;
- l'importeur dans `data_migration` ;
- `docs/formats/data-migration/<format>.md`, autonome, assez précis pour qu'un exporteur le suive sans lire le code ;
- une **archive exemple valide** et des cas invalides sous `tests/fixtures/data-migration/<format>/` ;
- les tests, l'aide et la mise à jour de `specifications.md` pour sa partie.

#### Lot B — Calendrier ICS (module `calendar`)

- **Lecteur ICS.** Ajouter `sabre/vobject` à `composer.json` (licence BSD, compatible). Ne pas écrire un lecteur maison.
- **Téléchargement sûr.** Ajouter à `UrlMetadataFetcher` une méthode publique qui accepte `text/calendar`, avec un plafond propre (5 Mo) et la même chaîne de redirections revalidées et d'IP épinglée. SECURITY.md §17 interdit un second outil de récupération.
- **Écran.** L'adresse, le calendrier de destination (parmi ceux que l'utilisateur peut modifier, `getEditableCalendars()`) et la date minimale (aujourd'hui moins deux ans, modifiable). La mention « copie unique, pas un abonnement » et l'exclusion visible des récurrences.
- **Récurrences exclues** : tout événement porteur de `RRULE`, `RDATE`, `EXDATE` ou `RECURRENCE-ID`.
- **Heures.** Elles sont converties à Europe/Brussels, puisque le calendrier stocke des heures locales sans fuseau. Un événement sans heure devient « toute la journée » (`start_time` NULL).
- **Dédoublonnage** sur (empreinte de l'adresse source, UID).
- **Interface `Api\` d'écriture** : crée l'événement **sans** `dispatchEventPublished()`, sans rappel pour un événement passé, sans rétrospective automatique.
- **Pas de ZIP** pour ce lot : l'étape « Source » est le formulaire. Analyse, aperçu, confirmation, exécution et bilan restent communs.

#### Lot C — Historique des courriels (module `mass_mail`, format `scoutmagic.email-history` 1.0.0)

- **Schéma.** Sur `mass_mail_emails` : `imported_at`, `import_source`, l'adresse et le nom d'expéditeur d'origine, et le nom d'audience d'origine (texte). Le statut de livraison accepte une valeur « inconnue » si nécessaire.
- **Rattachement à une section** selon D6.
- **Destinataires facultatifs.** Un courriel sans destinataire est importable. Les adresses connues deviennent des `mass_mail_deliveries` avec leur statut d'origine (« Accepté par le serveur d'envoi » ou « Inconnu », jamais « Remis »). Le lien vers un animé passe par `desk_id` (`mass_mail_targets` exige `member_id`), sinon il n'y a pas de lien.
- **Publipostage selon D3**, avec son texte modèle.
- **Pièces jointes** via `mass_mail_attachments`. Une pièce jointe invalide est ignorée, et le courriel est importé quand même.
- **Verrou côté serveur (D11).** Pour un courriel importé, refuser `updateDraft`, `deleteUnsent`, l'ajout ou le retrait de pièces jointes et **`resendDelivery`**, qui renverrait réellement le message. Masquer « Renvoyer » dans `tracking.html.twig`. Ne jamais créer de livraison `pending`, que `SendBatchHandler` enverrait.
- **Affichage.** Le badge « Importé » et le bandeau « Élément historique en lecture seule » dans la liste, le suivi et le détail. Sur la fiche d'un animé, via `MassMailQueryInterface`, dont le DTO porte l'indicateur.

#### Lot D — Actualités et formulaires (module `news`, format `scoutmagic-news-history` 1.0)

- **Schéma.** Sur `news_articles` : `imported_at`, `import_source`.
- **Interface d'écriture.** Elle crée l'article publié à sa date historique (`setPublication`).
  - **Visibilité.** Elle est reprise telle quelle si elle est reconnue. Inconnue, elle devient `identified`, avec un motif au bilan ; jamais `public` par défaut.
  - **Contenu.** Le corps est rangé là où le module le lit aujourd'hui, c'est-à-dire le premier champ `text` du formulaire. L'image principale et les images intégrées passent par `files`, avec les mêmes droits que les images natives. Le HTML passe par `HtmlSanitizer`.
  - **Résumé et image facultatifs**, selon D4.
- **Formulaires :**
  - questions, types, libellés et options seulement ;
  - **toujours fermés** (`is_force_closed = 1`) ;
  - `digest_email` NULL, `issues_ticket` faux, pas de compte ni de catégorie financière ;
  - aucune réponse : un fichier de réponses présent dans le ZIP est ignoré ;
  - un formulaire sans actualité parente reconnue est ignoré.
- **Verrou côté serveur (D11).** Pour un article importé, refuser la modification, la publication, la programmation, le retour en brouillon, la suppression et l'enregistrement du formulaire. Pour son formulaire, refuser toute réponse et toute modification de réponse, en plus de la fermeture.
- **Affichage.** Le badge « Importé » dans la liste et le détail, et le bandeau de lecture seule, selon la maquette.

#### Lot E — Photos des membres (cœur, format `scoutmagic-member-photos` 1.0)

- **Pas d'interface `Api\`** : la fonctionnalité est au cœur. L'importeur appelle `PhotoIngestionService::ingest()` par une petite façade du cœur.
- **Rattachement** par `desk_id` via `findByDeskId()`, alias compris. Un membre introuvable fait ignorer la photo, sans jamais créer d'identité.
- **Années selon D5.**
- **Jamais d'écrasement.** Le couple (membre, année) déjà pourvu fait ignorer la photo du fichier. Si le fichier contient plusieurs photos pour un même couple, seule la première valide est prise, quand l'emplacement est libre.
- **Contrôle** du type réel, de la taille et de l'empreinte SHA-256 du manifeste.
- Pas d'écran d'historique, et aucun changement au trombinoscope ni à la fiche.

#### Lot F — Galeries photo externes (module `gallery`, format `scoutmagic-gallery-links` 1.0)

- **Interface d'écriture.** Elle crée un album `external`, **sans** `dispatchAlbumPublished()`, avec `created_by` = l'administrateur qui importe.
- **Dédoublonnage.** Ajouter une normalisation sûre des URL (schéma et hôte en minuscules, sans fragment) et une recherche par URL normalisée. Une URL déjà enregistrée est ignorée.
- **Informations Open Graph.** Elles sont relues par le `LinkPreviewFetcher` existant, **une par tranche** : c'est lent, et l'aperçu le dit. En cas d'échec, les données du fichier sont prises. Sans aucun titre, l'album est ignoré.
- **Section par code Desk (D10)** : une section inconnue fait ignorer l'album.
- **Années selon D5.** Un album d'une année non cochée est ignoré, jamais rangé dans l'année en cours.

#### Lot G — Endroits de camp (module `camps`, format `scoutmagic-camp-places` 1.0)

- **Interface d'écriture.** Elle crée le lieu avec ses coordonnées marquées manuelles (`coordinates_are_manual`), que le géocodage ne remplace jamais, et son état archivé.
- **Doublons.** `DuplicatePlaceDetector` gagne une option pour **inclure les lieux archivés**. Le résultat :
  - correspondance `certain` : ignoré ;
  - correspondance `possible` : ambigu, ni créé ni fusionné, et listé au bilan avec le nom du lieu existant ;
  - aucune correspondance : créé.
- **Pas d'appel au modèle de langage** pour les doublons pendant l'import.

#### Lot H — Inscriptions de l'année suivante (module `registration`, format `scoutmagic-next-year-registrations` 1.0)

- **Validation.** Extraire les contrôles de `PublicRegistrationController::validate()` dans un validateur partagé, utilisé par le formulaire public **et** par l'import. Une demande incomplète est ignorée et signalée.
- **Année cible** explicite dans le fichier, existante dans ScoutMagic, sans inférence. Elle s'affiche dans l'aperçu.
- **Interface d'écriture.** Elle passe par `RegistrationRequestRepository::create()` avec la date de réception historique, puis applique :
  - le statut, en un seul passage par `updateStatus()` et **jamais** par `RequestDecisionService::apply()`, qui planifie un courriel ;
  - le motif de refus ;
  - la section prévue et la catégorie de cotisation ;
  - les notes internes ;
  - l'ancienne unité ;
  - les frères et sœurs, reçus par `desk_id` et convertis en `members.id` ;
  - le membre Desk lié, seulement si `encoded` est fiable.
- **Décisions selon D2**, avec le nouvel état « Communiquée par l'ancien site », affiché dans l'historique de la demande selon la maquette.
- **Adresses secondaires.** Elles sont enregistrées dans leur état d'origine (`valid` ou `pending`) **sans** courriel de confirmation.
- **Aucun envoi.** Pas d'accusé de réception, pas d'alerte à l'unité, pas de lien de suivi envoyé. Un jeton natif est créé mais pas envoyé, et le jeton de l'ancien site n'est jamais repris.
- **Doublons.** Les mécanismes existants signalent « doublon possible », sans fusion.
- **Après l'import**, les demandes vivent le parcours normal, y compris l'acceptation automatique et le rapprochement Desk. L'aide le dit.

### Vague 3 — clôture (seul, après toute la vague 2)

#### Lot I — Parcours de bout en bout et documentation

- **Un parcours e2e** : activer le module, déposer l'archive exemple des photos, interrompre, reprendre, puis vérifier le bilan et la relance sans doublon. Ajouter la vérification d'une actualité importée en lecture seule.
- **`docs/formats/data-migration/README.md`** : l'index des sept formats, avec version et module requis.
- **`ARCHITECTURE.md`** : le motif « interface d'écriture d'import sans effet de bord », le verrou de D11 et la table de correspondance de D12.
- **`specifications.md`** : la section du module, en cohérence avec les sections des modules touchés.
- Vérifier que **désactiver `data_migration`** ne supprime rien et ne déverrouille rien. Écrire un test pour chacun des deux modules verrouillés.
- **Ajouter `migration-donnees.html` au tableau de `docs/chantiers/maquettes/README.md`**, s'il n'y figure pas déjà.
- **Fermer le ticket #1142** avec un commentaire qui résume ce qui a été livré, lot par lot.

## 4. Ordre et parallélisme

```
Vague 1 :  A
Vague 2 :  B  C  D  E  F  G  H    (en parallèle, après la fusion de A)
Vague 3 :  I                      (après la fusion de B à H)
```

**Fichiers partagés en vague 2.** Les conflits y sont attendus et triviaux : on rebase, on garde les deux ajouts.
- L'enregistrement des importeurs dans `data_migration` : une ligne par import.
- Le câblage dans `public/index.php`.
- `composer.json` (lot B seulement).

**Aucun lot de la vague 2 ne modifie le composant commun du lot A.** Si un lot a besoin d'y changer quelque chose, il l'écrit dans sa PR et le change de façon rétrocompatible.

## 5. Hors périmètre

Rien de ce qui suit n'est livré par ce chantier :
- l'export depuis ScoutMagic, la synchronisation, la migration bidirectionnelle ;
- les réponses aux formulaires, les finances, les séjours de camp ;
- les albums locaux, les réinscriptions ;
- un écran d'historique des photos ;
- un exporteur pour un site particulier (voir D1).
