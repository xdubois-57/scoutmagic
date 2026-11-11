# ScoutMagic — Spécification fonctionnelle du module « Migration de données »

**Statut :** projet de spécification fonctionnelle destiné à une analyse technique ultérieure  
**Périmètre :** nouveau module optionnel, sept sous-pages d'importation  
**Nature du document :** exigences fonctionnelles, règles d'import, formats d'échange et critères d'acceptation — **pas une roadmap**, ni un plan de développement.

## 1. Objectif et principes directeurs

Le module **Migration de données** sert à transférer, lors de l'adoption de ScoutMagic, des données produites sur un **ancien site**, potentiellement lui-même connecté à Desk. Il est prévu pour une utilisation ponctuelle lors d'une migration, et non pour une synchronisation permanente.

Les sept importations concernent :

1. des événements de calendrier issus d'un flux ICS externe ;
2. un historique de courriels sortants ;
3. des actualités et les définitions de leurs formulaires, **sans leurs réponses** ;
4. des photos associées aux membres et à leurs années scoutes ;
5. des albums photo externes référencés par URL ;
6. des endroits de camp ;
7. des demandes d'inscription pour l'année scoute suivante, qui sont **des dossiers actifs**.

**Principe fondamental :** le module ne doit pas être le prétexte à enrichir ou complexifier les fonctionnalités métier de ScoutMagic. Il doit remplir les structures existantes avec les données importées. Les modifications hors du nouveau module seront réduites au strict nécessaire, sauf refactoring justifié pour déplacer une fonctionnalité réellement générique vers le cœur.

Les données historiques ne sont pas toutes traitées comme les demandes en cours :

- Les **courriels** et **actualités** importés portent une indication « Importé » et sont **en lecture seule**.
- Les **formulaires** attachés à ces actualités sont également en lecture seule et **toujours fermés après importation**.
- Les **photos de membres** s'intègrent à l'historique déjà géré par ScoutMagic, sans nouvelle vue d'historique.
- Les **albums externes**, **endroits de camp** et **événements ICS** utilisent les fonctionnalités existantes, sans écran métier supplémentaire.
- Les **demandes d'inscription** importées sont opérationnelles et **gérables de la même façon** que les demandes enregistrées directement dans ScoutMagic : aucun verrouillage en lecture seule.

## 2. Intégration et accès

### 2.1. Module optionnel

Le nouveau module, désigné provisoirement par l'identifiant technique `data_migration`, doit être activable et désactivable comme les autres modules ScoutMagic. Il ne dépend pas obligatoirement d'un module métier unique.

Il propose une entrée **« Migration de données » dans « Espace chefs d'U »**, accessible au niveau **administrateur / chef d'unité** (`admin`), et **non** dans la Configuration réservée au superadministrateur.

La page d'accueil du module affiche uniquement les importateurs disponibles. Chaque sous-page dépend de l'activation du module correspondant :

| Sous-page | Module requis |
|---|---|
| Calendrier ICS | `calendar` |
| Historique des courriels | `mass_mail` |
| Actualités et formulaires | `news` |
| Photos des membres | Aucun module métier supplémentaire : fonctionnalité du cœur |
| Galeries photo | `gallery` |
| Endroits de camp | `camps` |
| Inscriptions de l'année suivante | `registration` |

La condition d'activation s'applique **à l'affichage, à l'accès direct à la sous-page, aux points d'entrée AJAX et à l'exécution de l'import**. Une page masquée ne doit pas pouvoir être appelée directement si son module métier est désactivé.

Désactiver « Migration de données » après une migration ne doit ni supprimer les données importées, ni retirer les protections permanentes des actualités et courriels historiques.

### 2.2. Limites de périmètre

Le module n'offre **aucun** mécanisme d'export de ScoutMagic, de migration bidirectionnelle, de synchronisation périodique, de restauration de sauvegarde, de comparaison continue entre deux sites ou de gestion métier parallèle. Il n'ajoute ni répertoire de photos historiques, ni visualiseur de formulaires archivés distinct, ni nouveau suivi des inscriptions.

## 3. Parcours utilisateur commun aux importations

Chaque sous-page doit respecter un parcours cohérent :

1. **Sélection de la source :** URL ICS pour le calendrier, fichier ZIP pour les six autres importateurs.
2. **Analyse et validation :** vérifier le format, les références, les fichiers, les modules requis, les données manquantes et les doublons détectables.
3. **Aperçu avant importation :** présenter les volumes prévus, les éléments importables, les doublons et les exclusions ; expliciter les effets de l'import.
4. **Confirmation explicite :** aucune modification persistante avant validation par l'administrateur.
5. **Exécution progressive :** afficher la progression via des appels asynchrones, **sans rechargement de page**, même pour un lot volumineux ; prévoir reprise idempotente des traitements.
6. **Bilan final :** distinguer importés, déjà présents, ignorés pour données manquantes ou non reconnues, et erreurs techniques ; fournir des explications compréhensibles sans exposer des données personnelles.

Les fichiers invalides ou éléments non reconnus ne doivent **pas bloquer tout le lot** lorsque le traitement des autres éléments reste possible. Inversement, un défaut de sécurité ou un manifeste structurellement inutilisable justifie le rejet du lot.

Une nouvelle exécution du même import ne doit pas dupliquer les données. **Aucune importation ne doit écraser silencieusement des données existantes.** Les ambiguïtés sont signalées et ignorées, sans fusion automatique.

### 3.1. Informations de provenance

L'importateur garde, là où nécessaire, une trace permettant d'identifier l'installation source et l'identifiant source de l'objet importé. Le couple **(installation source, identifiant source)** constitue la référence primaire de dédoublonnage lorsque les données disposent d'un identifiant historique fiable. Les heuristiques complémentaires sont propres à chaque type de données.

Cette provenance doit permettre les réimportations idempotentes, sans imposer un nouvel affichage permanent dans les modules existants. Seules les actualités et les courriels doivent présenter explicitement le badge « Importé ».

### 3.2. Effets de bord interdits pendant l'import

L'opération d'import elle-même ne doit pas :

- envoyer de courriels (confirmation, réception, décision, relance, notification, digest) ;
- diffuser des notifications de publication d'actualités, d'albums ou d'autres contenus ;
- déclencher l'envoi d'une ancienne campagne de courriels ;
- rouvrir un formulaire importé ;
- créer une créance ou un mouvement financier à partir d'un ancien formulaire ;
- créer automatiquement un membre Desk ou un compte de connexion à partir d'une simple référence introuvable ;
- mettre à jour les données d'un objet déjà présent sans demande explicite ;
- réactiver une liste de diffusion ou un destinataire désinscrit.

**Après l'import**, les inscriptions actives doivent suivre les règles et actions habituelles de ScoutMagic ; cette interdiction concerne les effets de bord de *l'import*, pas le fonctionnement métier normal ultérieur.

### 3.3. Qualité, sécurité et contraintes d'hébergement

- Vérification des autorisations côté serveur, de l'authenticité des requêtes et des permissions de chaque sous-page.
- Archives ZIP : validation des chemins, refus de la traversée de répertoires, des liens symboliques dangereux, des tailles décompressées excessives, de la compression abusive et des fichiers non autorisés ; limites de taille, nombre et dimensions documentées.
- Intégrité des médias et contenus : contrôle du type réel, de la taille et des empreintes annoncées.
- URL externes : appliquer les protections SSRF déjà prévues dans ScoutMagic ; éviter la récupération de ressources internes ou privées.
- Les contenus HTML sont nettoyés selon les règles de ScoutMagic.
- Les données personnelles importées sont soumises aux protections existantes (droits d'accès, chiffrement au repos, non-divulgation dans les journaux). Les ZIP temporaires contenant des données personnelles sont supprimés après usage, dans une durée strictement bornée.
- L'import est découpé en petites unités de travail adaptées à un hébergement PHP contraint (référence projet : **128 MiB de mémoire**, exécutions HTTP typiquement limitées à **30 secondes**).
- L'échec ou l'interruption d'un lot ne doit pas laisser des objets partiellement incohérents. Une relance doit pouvoir ignorer les éléments correctement importés.

## 4. Formats documentés pour les agents exporteurs

Le format de chaque import ZIP doit faire l'objet d'un **document Markdown autonome** suffisamment précis pour qu'un agent, sur l'ancien site, puisse générer l'archive **sans consulter le code source de ScoutMagic**.

Chaque spécification de format doit comporter : nom et version, structure exacte du ZIP, encodage, champs obligatoires/facultatifs, types et valeurs admises, dates et fuseaux horaires, exemples valides, références et identifiants stables, liens entre fichiers, règles de compatibilité, contrôles d'intégrité, dédoublonnage, limites et cas invalides.

### 4.1. Conventions communes des ZIP

Les ZIP peuvent utiliser des fichiers JSON et **JSON Lines** (`.jsonl`, un objet JSON par ligne) pour traiter progressivement des volumes importants. Les propositions ci-dessous sont des **contrats fonctionnels à préciser lors de l'analyse technique**, pas des schémas techniques définitivement figés.

Chaque ZIP contient `manifest.json`, indiquant au minimum :

- `format` : identifiant de format, propre à la sous-page ;
- `format_version` : version majeure.mineure.correctif ;
- `source.installation_id` : identifiant stable de l'ancien site ;
- `export_id` : identifiant unique de l'opération d'export ;
- `exported_at` : date de génération au format ISO 8601 ;
- un inventaire des fichiers et de leurs empreintes SHA-256, pour les archives contenant plusieurs fichiers.

Les identifiants source sont des **chaînes de caractères**, même lorsqu'ils ressemblent à des nombres (préservation des zéros initiaux). Un identifiant de membre Desk doit être transmis dans un champ explicite `desk_id`, jamais comme un identifiant SQL local.

L'importateur ignore les **champs supplémentaires inconnus** si les données reconnues restent cohérentes. Une version majeure non reconnue ou un manifeste contradictoire doit être refusé explicitement plutôt qu'interprété au hasard.

## 5. Sous-page 1 — Calendrier ICS

**Disponibilité :** si `calendar` est activé.  
**Source :** URL d'un fichier ou flux ICS externe.  
**Destination :** calendrier existant choisi par l'administrateur.

### 5.1. Interface

L'administrateur renseigne l'URL ICS, sélectionne le calendrier de destination et peut choisir une **date minimale des événements**. La valeur proposée par défaut est **la date du jour moins deux années**. L'utilisateur peut la modifier.

La page indique clairement qu'il s'agit d'une **copie unique des événements**, pas d'un abonnement, et qu'aucune mise à jour ultérieure de l'URL ne sera synchronisée.

### 5.2. Importation

- Analyser les événements simples dont la date satisfait le filtre.
- Conserver les données reconnues : intitulé, description, début et fin, lieu, horaire et caractère « toute la journée » lorsque disponibles, en préservant correctement dates et fuseaux horaires.
- **Ignorer tous les événements récurrents**, y compris leurs occurrences particulières, exceptions et modifications individuelles. Ce choix doit être affiché clairement dans l'interface.
- Ignorer les doublons, notamment sur la base de l'UID ICS et de l'identité de la source, selon la capacité du modèle existant.
- Ignorer individuellement les événements illisibles ou incomplets sans interrompre les autres.
- Respecter les règles normales d'accès au calendrier de destination, sans publier des événements dans un autre calendrier.

Le bilan distingue **événements créés, doublons, événements antérieurs à la date minimale, événements récurrents exclus et événements invalides**.

## 6. Sous-page 2 — Historique des courriels

**Disponibilité :** si `mass_mail` est activé.  
**Source :** ZIP versionné, format proposé `scoutmagic.email-history` / `1.0.0`.  
**Usage :** importer une archive de **courriels sortants historiques**, sans recréer de campagnes à envoyer.

### 6.1. Priorité absolue : conserver le message

| Donnée | Traitement |
|---|---|
| Sujet | Indispensable |
| Contenu du message | Indispensable, HTML et/ou texte ; garder la version effectivement envoyée si disponible |
| Date historique | Indispensable |
| Expéditeur | Indispensable : adresse ; nom si connu |
| Destinataires | Facultatifs : conserver les adresses connues, sans les inventer |
| Liste de diffusion / audience | Facultative, mais conserver au moins son **nom** lorsque connu |
| Liens avec des membres | Facultatifs, sur identifiants Desk (`desk_id`) |
| Statut / tentatives / erreurs de livraison | Facultatifs, valeur « Inconnu » si indéterminables |
| Fichiers joints / images intégrées | Importer s'ils sont disponibles et valides |

Un ancien courriel **sans destinataire individuel identifié doit rester importable**. Le nom de sa liste de diffusion suffit à renseigner son audience de manière informative. Il ne faut pas recalculer les destinataires historiques d'une liste à partir de l'annuaire actuel.

Pour un publipostage, conserver si possible le **sujet et corps final rendus pour chaque destinataire**, plutôt que de dépendre de données personnalisables actuelles. La structure plus riche (destinataires, cibles Desk, statuts par adresse, pièces jointes) est **optionnelle**, et non une raison de rejeter un message exploitable.

La mention « Accepté par le serveur d'envoi » ne doit pas être transformée en « Remis au destinataire » sans preuve de distribution.

### 6.2. Structure fonctionnelle du ZIP

```text
historique-emails.zip
├── manifest.json
├── communications/
│   └── <id-source>.json
├── content/
│   └── <sha256>.html ou <sha256>.txt
└── attachments/
    └── <sha256>
```

Un fichier `communications` décrit un courriel : identifiant source, date, sujet, expéditeur, références des corps et éventuelle liste de diffusion. Il peut comporter des ensembles facultatifs de destinataires, livraisons, identifiants Desk, statuts et métadonnées de pièces jointes. Les octets des pièces jointes sont référencés par chemin et empreinte. Les champs absents restent absents, sans valeurs fabriquées.

### 6.3. Résultat dans ScoutMagic

- Les messages sont visibles, dans le respect des droits actuels, dans les surfaces pertinentes de l'historique d'envoi et, si un rattachement fiable existe, dans l'historique du membre correspondant.
- Badge **« Importé »** dans les listes et détails, accompagné de la mention « Élément historique en lecture seule ».
- **Aucune modification, suppression fonctionnelle, reprise ou renvoi** depuis les actions ordinaires de l'application ; protection appliquée aussi côté serveur. Les obligations légales de suppression de données personnelles restent applicables.
- Pas de génération d'un nouveau message, de destinataires ou de statuts de livraison inventés.

Un message manquant de sujet, de contenu exploitable, de date historique ou d'expéditeur est ignoré individuellement. Une pièce jointe invalide est ignorée **sans empêcher d'importer le courriel**.

## 7. Sous-page 3 — Actualités et formulaires

**Disponibilité :** si `news` est activé.  
**Source :** ZIP versionné, format proposé `scoutmagic-news-history/1.0`.

### 7.1. Actualités

Importer les champs suivants :

| Champ | Règle |
|---|---|
| Identifiant source | Obligatoire pour rattachement et dédoublonnage |
| Titre | Obligatoire |
| Sous-titre / résumé | Importer s'il existe ; ne pas en inventer |
| Corps | Conserver le HTML nettoyé ou le texte |
| Date historique de publication | Conserver, sans la remplacer par la date d'import |
| Visibilité | Conserver la valeur reconnue : publique, identifiée, réservée, lien direct, etc. |
| Image principale | Importer si elle existe, avec les images intégrées au corps lorsqu'elles sont disponibles |
| État de publication | Conserver s'il est reconnu et compatible |

**Il n'est pas demandé d'importer** les réglages SEO, les auteurs comme identités actives, les options avancées et les autres paramètres de configuration sans pertinence pour la consultation historique.

En cas de visibilité inconnue, ne **jamais** rendre l'article public par défaut : choisir un niveau protecteur compatible avec le site cible et signaler le choix dans le bilan. Les contenus importés ne déclenchent pas d'annonce de publication.

### 7.2. Formulaires associés

Les définitions des formulaires sont liées par **identifiant source de l'actualité**. Importer, si disponibles, les questions dans l'ordre, leurs types, libellés et options utiles à la consultation du formulaire historique.

Règles impératives :

1. **Aucune réponse historique n'est importée.** Le ZIP ne contient pas de fichier de réponses ; ignorer un tel fichier s'il est présent.
2. **Tous les formulaires importés sont fermés**, quel que soit leur statut passé. Le statut d'origine peut être conservé comme métadonnée informative, sans effet métier.
3. Aucune nouvelle réponse ne peut être soumise, y compris par une requête directe.
4. Les formulaires importés sont **en lecture seule**, sans réouverture, édition ou nouvelles inscriptions.
5. Aucun recalcul de places, paiement, créance, billet ou notification n'est déclenché.
6. Un formulaire dont l'actualité parente ne peut pas être importée ni identifiée de façon sûre est ignoré sans bloquer les autres.

### 7.3. Structure fonctionnelle du ZIP

```text
actualites-et-formulaires.zip
├── manifest.json
├── articles/
│   └── <id-source>.json
├── forms/
│   └── <id-source>.json
└── media/
    └── <sha256>
```

**Aucun répertoire de réponses.** Chaque fichier de formulaire référence l'identifiant source de l'actualité et décrit seulement le formulaire et ses champs.

### 7.4. Résultat dans ScoutMagic

Les actualités affichent le badge **« Importé »** dans les listes et pages de détail. Les actualités et leurs formulaires sont consultables selon les droits définis, mais **non modifiables** par les interfaces et points d'entrée habituels. Les restrictions survivent à la désactivation du module Migration.

Les exigences actuelles de création des actualités (notamment résumé et image) ne doivent pas amener l'import à inventer des informations historiques. La solution de compatibilité minimale relève de l'analyse technique.

## 8. Sous-page 4 — Photos des membres

**Disponibilité :** fonctionnalité du cœur, dès que Migration est activé.  
**Source :** ZIP versionné, format proposé `scoutmagic-member-photos/1.0`.

### 8.1. Périmètre

Importer **les photos rattachées à un membre Desk**, et non les photographies des comptes de connexion. Le trombinoscope constitue le cas d'usage principal, mais toutes les pages consommant la photo d'un membre bénéficieront des données existantes.

Une photo historique est identifiée par :

- `desk_id` : identifiant stable du **Tiers Desk**, comme chaîne ;
- `scout_year` : libellé d'année scoute, par exemple `2022-2023` ;
- `path` : chemin de l'image dans l'archive ;
- `sha256` : empreinte complète des octets ;
- facultativement, nom de fichier d'origine et date de prise de vue.

Le rattachement doit tenir compte des **alias Desk** connus après fusion de membres. L'absence du membre ou de l'année cible conduit à ignorer l'élément, sans créer artificiellement une identité.

### 8.2. Structure du ZIP

```text
photos-membres.zip
├── manifest.json
├── photos.jsonl
└── images/
    └── <sha256>.<extension>
```

L'import passe par le stockage et les traitements de photo existants, notamment contrôle du format, adaptations éventuelles et miniatures. Le modèle actuel prend en charge **une photo de référence par membre et par année scoute**.

### 8.3. Doublons et affichage

- Ne pas écraser une photo existante pour le même membre et la même année, même si le fichier est différent.
- Si plusieurs photos de l'archive visent ce même couple, prendre la première image valide uniquement lorsque l'emplacement est libre ; ignorer les suivantes.
- Conserver les photos des années précédentes dans la structure existante ; le mécanisme actuel peut sélectionner une photo d'une année antérieure lorsqu'aucune photo plus récente n'existe.
- **Ne créer aucune page d'historique des photos** et ne pas modifier les écrans du trombinoscope ou des fiches membres.
- Exclure les photos de comptes utilisateurs, de sections et les albums collectifs.

## 9. Sous-page 5 — Galeries photo externes

**Disponibilité :** si `gallery` est activé.  
**Source :** ZIP versionné, format proposé `scoutmagic-gallery-links/1.0`.

### 9.1. Périmètre

Pour cette première version, importer **uniquement les albums externes accessibles par URL**. Aucun fichier photo, vidéo, album local ou contenu de galerie hébergé n'est transféré.

Un album comporte :

- `id` : identifiant stable sur le site d'origine ;
- `url` : URL complète de l'album (**obligatoire**) ;
- `album_date` : date historique de l'album, nécessaire à son classement ;
- `scout_year` : année scoute, si elle peut être déterminée côté source ;
- `title`, `subtitle` : facultatifs lorsque les informations OG peuvent les compléter ;
- une référence de section stable, si l'album est limité à une section.

### 9.2. Format ZIP

```text
galeries.zip
├── manifest.json
└── albums.jsonl
```

### 9.3. Résultat

- Créer de **véritables albums externes** du module Galerie, intégrés dans ses listes et filtres par année/section.
- Réutiliser la **récupération Open Graph existante** de ScoutMagic pour le titre, la description et la couverture ; aucune métadonnée OG ne doit être exigée du ZIP.
- Lorsque la récupération OG échoue, privilégier les données fournies. Si aucun titre ne peut être déterminé, ignorer l'album sans interrompre l'import.
- Détecter et ignorer les URL déjà enregistrées, après normalisation sûre.
- Si la section est inconnue, ne **pas élargir silencieusement la visibilité** à toute l'unité ; ignorer ou signaler l'album si un rattachement sûr est impossible.
- Ne déclencher aucune notification de publication d'album pendant l'import.

Les albums importés utilisent **l'interface normale de Galerie**, sans badge ni verrouillage particulier exigé.

## 10. Sous-page 6 — Endroits de camp

**Disponibilité :** si `camps` est activé.  
**Source :** ZIP versionné, format proposé `scoutmagic-camp-places/1.0`.

### 10.1. Périmètre

Importer uniquement les **lieux de camp** eux-mêmes, avec :

| Champ | Règle |
|---|---|
| `id` | Identifiant source stable pour éviter les doubles imports |
| `name` | Nom du lieu, **obligatoire** |
| `address` | Adresse facultative |
| `postal_code`, `city`, `country` | Facultatifs |
| `latitude`, `longitude` | Coordonnées facultatives, contrôlées ensemble |
| `website_url` | Facultatif |
| `is_archived` | État archivé ou actif, lorsqu'il est connu |

Les coordonnées provenant explicitement de l'ancien site ne doivent pas être écrasées par un géocodage automatique ultérieur.

**Hors périmètre :** anciens séjours, sections ayant campé sur place, contacts, avis, prix, contrats, documents et photos des séjours. Aucun lien vers une année scoute n'est requis pour identifier un lieu.

### 10.2. Format ZIP

```text
endroits-de-camp.zip
├── manifest.json
└── places.jsonl
```

### 10.3. Doublons

Utiliser prioritairement la provenance et, pour les lieux déjà présents dans ScoutMagic, les capacités existantes de recherche de doublons (nom, localité, adresse et autres indices) :

- Correspondance certaine : ignorer le lieu existant sans le modifier.
- Correspondance probable ou ambiguë : ne pas fusionner et ne pas créer automatiquement un doublon ; signaler l'ambiguïté.
- Lieu absent : créer le lieu avec les informations reconnues.
- Vérifier également les **lieux archivés** pour éviter de les recréer.

Les lieux importés restent **modifiables normalement** dans le module Camps. Aucun écran supplémentaire n'est requis.

## 11. Sous-page 7 — Inscriptions de l'année scoute suivante

**Disponibilité :** si `registration` est activé.  
**Source :** ZIP versionné, format proposé `scoutmagic-next-year-registrations/1.0`.  
**Nature :** **migration de demandes de nouvelles inscriptions en cours de traitement**, et non archivage figé.

### 11.1. Résultat attendu

Une demande importée doit apparaître dans **les mêmes listes, filtres, fiches, compteurs et processus** qu'une demande soumise directement via le formulaire public de ScoutMagic. Elle doit être **éditable et traitable selon les mêmes possibilités** que toute autre demande : décision, section prévue, catégorie de cotisation, notes, rapprochement Desk et autres opérations natives accessibles aux rôles autorisés.

**Aucun badge « Importé » ou mode lecture seule n'est imposé** pour ces dossiers. Une éventuelle trace de provenance peut rester interne à l'importateur.

### 11.2. Données attendues

Chaque demande doit fournir un identifiant source stable et l'**année scoute cible explicite**. Il ne faut pas inférer l'année à partir de la date actuelle.

Le format doit permettre de conserver :

| Groupe | Données |
|---|---|
| Enfant | Prénom, nom, date de naissance, genre |
| Responsable | Nom du parent / responsable, adresse postale, adresse électronique principale, téléphone principal ; autre téléphone si connu |
| Demande | Identifiant source, date de réception historique, année scoute demandée, remarques |
| Suivi | État de traitement, dans les états reconnus par ScoutMagic ; éventuel motif de refus |
| Affectation | Section souhaitée et section prévue si les correspondances sont fiables |
| Informations additionnelles | Ancienne unité scoute si déclarée, catégorie de cotisation, notes internes si existantes |
| Famille | Frères et sœurs déjà membres, référencés par `desk_id` si disponibles |
| Contacts | Adresses secondaires et état de validation, uniquement si ces données peuvent être représentées correctement |
| Desk | Membre associé, uniquement si un rapprochement fiable existe et que le statut le permet |

Les **champs que ScoutMagic exige pour qu'une demande active soit valide** doivent être fournis. Contrairement à un courriel historique, une inscription incomplète ne peut pas être insérée dans un état incohérent pour « sauver ce qui peut l'être » : elle est ignorée et clairement signalée.

Le statut devra correspondre à un état réellement pris en charge : `pending` (en attente), `accepted` (acceptée), `refused` (refusée), `withdrawn` (retirée) ou, lorsque l'identité Desk le permet, `encoded` (encodée dans Desk). **Ne pas convertir silencieusement un statut historique non reconnu** en un autre ; exclure ou signaler l'élément si la correspondance est incertaine.

### 11.3. Format ZIP

```text
inscriptions-annee-suivante.zip
├── manifest.json
└── requests.jsonl
```

Chaque ligne de `requests.jsonl` décrit une demande et ses éventuelles références à des membres Desk et aux sections. Le ZIP ne contient **aucun export de la base de données** ni des identifiants SQL locaux interprétés comme des clés de ScoutMagic.

### 11.4. Doublons et fonctionnement courant

- Ignorer les demandes déjà importées selon leur identifiant source stable.
- Comparer aux demandes éventuellement **déjà présentes** pour la même année : utiliser les mécanismes existants de signalement de doublons (identité de l'enfant, date de naissance, courriel), **sans fusion automatique** ni suppression.
- Préserver la date de réception, les informations opérationnelles et le statut lorsque le modèle cible peut les représenter de façon sûre.
- Ne pas envoyer de reçu, d'alerte à l'unité, de décision ou de lien de suivi **du seul fait de l'importation**.
- Ne jamais réutiliser un jeton de suivi de l'ancien site comme authentifiant sur ScoutMagic. Si la structure cible nécessite un jeton, celui-ci est créé suivant les règles natives, sans envoi automatique.
- Les parents déjà identifiés par leur adresse électronique doivent retrouver leur demande via les mécanismes normaux de ScoutMagic, si les conditions habituelles d'accès sont remplies.
- Dès l'import terminé, les équipes poursuivent le traitement des dossiers **sans différence de parcours** par rapport à une inscription native.

**Point technique prioritaire à analyser :** les états accepté/refusé et l'affichage aux familles sont liés, dans le module actuel, aux courriels de décision. Il faut préserver la cohérence de ce suivi sans générer de faux historiques d'envoi ni envoyer automatiquement de décisions. L'import doit soit prendre en charge cette correspondance proprement, soit identifier explicitement les cas non importables.

**Frontière fonctionnelle retenue à ce stade :** demandes de **nouvelles inscriptions** de l'année suivante. Les campagnes de **réinscription de membres existants**, les passages de branche et les autres outils de planification sont des fonctions voisines, mais ne sont **pas explicitement inclus** dans cette sous-page ; cette frontière est à confirmer avant toute implémentation.

## 12. Règles de dédoublonnage et de traitement des erreurs

| Situation | Attente commune |
|---|---|
| Même objet source réimporté | Ne pas créer de nouvelle copie |
| Objet déjà présent dans ScoutMagic | Ne pas le remplacer silencieusement |
| Champ facultatif inconnu | Ignorer ce champ |
| Fichier facultatif invalide | Ignorer le fichier, conserver le parent si cohérent |
| Référence à un membre, une section ou une année introuvable | Ignorer ce lien ; si le lien est indispensable, ignorer l'objet |
| Format ou état qui ne peut être représenté sans déformation | Ignorer l'élément et fournir le motif |
| Archive malveillante ou structure majeure incompatible | Rejeter l'import avant écriture |
| Lot interrompu | Permettre la reprise sans doublons |

Le récapitulatif sépare **doublons**, **données non reconnues** et **erreurs techniques** : un élément ignoré volontairement n'est pas nécessairement une erreur logicielle.

## 13. Contraintes d'architecture et de maintenance

1. **Isolation maximale dans `data_migration`.** Les parseurs de ZIP, contrôles des contrats, écrans d'import et orchestration appartiennent au nouveau module.
2. **Réutilisation des services métiers existants** afin que les objets importés aient les mêmes règles de stockage, de chiffrement, d'autorisation, de consultation et d'édition que les autres.
3. **Modifications minimales hors module.** Les adaptations strictement nécessaires (notamment indication « Importé » et lecture seule pour les courriels/actualités, ou points d'entrée d'insertion sans effets de bord) doivent être localisées, justifiées et maintenables.
4. **Refactoring du cœur uniquement lorsqu'il est réellement générique.** Ne pas déplacer une fonction métier vers le cœur uniquement pour faciliter un import ponctuel.
5. **Pas de couplage direct fragile entre modules.** Respecter les contrats d'API intermodules et les règles documentées du projet ; l'agent technique décidera où de très petits contrats supplémentaires sont nécessaires.
6. **Documentation à jour.** Décrire l'utilisation du module, les droits, les limites et **un fichier Markdown autonome par format**. Inclure des archives exemples et des cas de test d'import.
7. **Pas de dégradation hors migration.** Après désactivation de `data_migration`, les données migrées restent utilisables et, lorsque requis, toujours verrouillées.

## 14. Critères d'acceptation fonctionnels

Le module satisfait les exigences lorsque, au minimum :

- Un administrateur active/désactive Migration et ne voit que les sous-pages dont le module métier est activé ; un accès direct non autorisé ou à un import désactivé est refusé.
- Un ZIP valide peut être analysé, prévisualisé, confirmé, traité progressivement puis réimporté **sans duplication** ; les erreurs isolées ne font pas perdre le reste du lot.
- Un flux ICS est importé **une seule fois**, dans un calendrier choisi, avec date minimale personnalisable (défaut : deux ans en arrière) et exclusion visible de **toutes** les récurrences.
- Un courriel historique avec sujet, corps, date et expéditeur, mais **sans destinataire identifié**, est importable ; les pièces jointes invalides ne bloquent pas le message ; le badge « Importé » et la lecture seule fonctionnent aussi côté serveur.
- Une actualité conserve son titre, contenu, date, visibilité reconnue et images disponibles ; son formulaire est **toujours fermé**, aucune réponse n'est importée, et article et formulaire sont en lecture seule.
- Un ZIP de photos peut alimenter plusieurs années scoutes d'un même membre à partir de son **Desk Tiers ID**, sans remplacer une photo présente, sans toucher aux photos de compte et **sans créer d'écran d'historique**.
- Un ZIP de galeries ne crée que des **albums externes**, laisse ScoutMagic récupérer leurs métadonnées OG, n'importe aucune image locale et ne notifie pas les membres.
- Un ZIP d'endroits de camp crée les lieux manquants, conserve les coordonnées et ignore les doublons et ambiguïtés sans fusion automatique ; les lieux restent utilisables normalement.
- Une demande d'inscription importée pour la bonne année cible apparaît dans le **workflow natif** des inscriptions, peut être gérée comme une demande directe et n'a déclenché aucun courrier ni notification pendant son import.
- Désactiver Migration ne supprime aucun contenu et ne rend pas modifiables les actualités et courriels historiques.
- Les formats ZIP sont assez documentés pour qu'un exporteur indépendant puisse les produire sur l'ancien site.

## 15. Points à examiner lors de l'analyse technique

Le présent document fixe l'objectif fonctionnel ; il ne présuppose pas que les API actuelles couvrent déjà chaque cas. L'agent d'analyse technique devra vérifier et signaler clairement, sans modifier silencieusement le périmètre :

1. **Courriels et actualités importés en lecture seule :** où conserver durablement la provenance et faire appliquer la protection, y compris quand Migration est désactivé ?
2. **Actualités anciennes :** comment conserver un article dont le résumé ou l'image obligatoire à la création native manque, tout en gardant une modification minimale du module `news` ?
3. **Courriels :** comment rendre visibles des messages historiques sans relancer le processus d'envoi, et comment restituer les messages personnalisés si l'audience d'origine est partielle ?
4. **Calendrier :** quels identifiants ICS et quelles règles existantes permettent un dédoublonnage solide, y compris pour les événements récurrents rejetés ?
5. **Photos :** comment résoudre les alias Desk et les années scoutes et réutiliser le pipeline existant sans écraser des photos ?
6. **Galerie :** comment appeler la récupération OG existante **sans** notification de nouvel album ? Que faire lorsque la section source n'a pas d'équivalent sans élargir les droits ?
7. **Camps :** comment détecter les lieux déjà présents, notamment archivés, sans déclencher fusion ou modification ?
8. **Inscriptions :** comment insérer des demandes **valides et actives** avec leur statut, leurs jetons et leurs relations, tout en évitant les courriels d'import et les incohérences avec l'historique des décisions ?
9. **Inscriptions :** confirmer que « inscriptions de l'année suivante » vise les **nouvelles demandes d'inscription**, et non les réponses aux campagnes de réinscription des membres déjà présents.
10. **Hébergement :** quelle orchestration minimale permet de traiter des archives volumineuses et de reprendre un import interrompu dans les limites mémoire/temps de ScoutMagic ?
11. **Formats :** finaliser les schémas et versions, avec exemples d'archives réellement valides et gestion explicite de l'incompatibilité de versions.

L'agent devra **relire cette spécification de manière critique**, confronter chaque exigence au code et aux conventions du dépôt, signaler les contradictions ou les coûts disproportionnés et **poser des questions de clarification** avant de proposer des changements de périmètre. Il devra également veiller à la mise à jour des documentations techniques et utilisateur.

---

*Fin de la spécification fonctionnelle. Ce document n'est pas une roadmap, ne prescrit ni découpage en tickets ni ordre d'implémentation, et n'autorise aucune modification de code.*
