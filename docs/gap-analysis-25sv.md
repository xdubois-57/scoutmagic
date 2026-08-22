# Gap analysis — ScoutMagic vs 25sv.be

**Objet** : identifier les fonctionnalités de l'ancien site 25sv.be qui manquent (totalement ou partiellement) dans ScoutMagic, afin de pouvoir le remplacer complètement.

**Méthode** : chaque exigence du document de besoins fonctionnels 25SV a été vérifiée dans le code réel du dépôt (routes de `public/index.php` et des `module.json`, schémas SQL, services, contrôleurs, templates Twig). `ARCHITECTURE.md` et `specifications.md` n'ont servi qu'à localiser le code. Aucun changement de code n'a été effectué.

**Légende** : ✅ présent · 🟡 partiel · ❌ absent

---

## 1. Synthèse générale

Sur les 28 domaines fonctionnels du document 25SV :

| Couverture | Domaines |
|---|---|
| **Complète ou quasi complète** | Authentification/identité/rôles, Sections, Passages, Projections d'effectifs, SOS Staff d'U, Contenu éditable en ligne, Planificateur de tâches, Sauvegardes, Bascule d'année scoute, Email en masse (cœur), Galerie (approche différente), Documents de section |
| **Partielle avec écarts notables** | Import Desk (pas de diff), Inscriptions (pas de liste d'attente, pas d'export), Finances (mono-compte, pas de trésorier par section), Actualités (pas de tags/épinglage/archivage), Calendrier (pas de vue annuelle, pas de cloisonnement par section), Tableaux de bord staff, Notifications internes, Statistiques d'effectifs |
| **Absente ou embryonnaire** | **Cotisations**, **Suivi de paiements (campagnes)**, **Ticketing/billetterie**, **Attestations (découpage PDF)**, **Formations BACV**, **Lieux de camp**, **Outils divers (QR manuel, lookup communication, extraction GSM, scanner)**, **Fréquentation du site**, **FAQ dynamiques** |

À l'inverse, ScoutMagic dépasse le legacy sur plusieurs points hors périmètre 25SV : locations d'infrastructures, groupes de discussion privés, PWA installable + notifications push, passkeys/mot de passe, courrier entrant IMAP, rétrospectives, connecteur IA, mises à jour GitHub avec rollback, RGPD/cookies, chiffrement au repos généralisé.

---

## 2. Domaines entièrement (ou presque) manquants

Ce sont les chantiers à créer de zéro ou presque pour remplacer 25sv.be.

### 2.1 Cotisations (§7 du besoin) — ❌ quasi absent

Choix architectural assumé dans ScoutMagic : les cotisations vivent dans Desk, hors du site (cf. étape 6 du workflow « Année scoute » : « Cette étape se passe hors du site »).

- ❌ **Aucun montant** : `fee_categories` ne contient que `desk_code` + `label`, aucune colonne de montant (ni part fédération, ni part unité).
- ❌ Calcul des cotisations dues par famille avec tarifs dégressifs (les montants).
- ❌ Vérification de la facture fédération (reconstitution ligne à ligne).
- ❌ Validation du contenu Desk (liste triée par adresse, détection d'incohérences de tarifs, recommandations).
- ❌ Résumé synthétique (totaux, familles, anomalies).
- ❌ Export tableur de suivi des paiements de cotisations.
- ❌ Cas particuliers (Iama exonérés, tarifs réduits) : « Iama » n'existe que comme branche pour le calcul d'âge.
- 🟡 Briques existantes réutilisables : regroupement par adresse (`Core\Member\AddressNormalizer` + blind index), catégorie de foyer suggérée (`HouseholdFeeCategory` : NORMAL/COUPLE/FAMILY = logique 1er/2e/3e enfant), import du code tarif Desk (`member_years.fee_category_id`).

### 2.2 Suivi de paiements par campagnes (§9) — ❌ concept absent

Le mot « campagne » n'apparaît nulle part dans le code. Ce qui existe est un registre passif de créances (`finance_expected_receivables`) alimenté uniquement par les modules news (formulaires payants) et rental — jamais saisi à la main.

- ❌ Création de campagnes (nom, catégorie, période, import tableur de personnes avec montants individuels).
- ❌ Édition manuelle du statut d'une ligne (paiement en liquide) : le statut est calculé, jamais stocké, et il n'existe aucune UI de création de mouvement manuel.
- ❌ Affiche/poster imprimable par campagne avec QR de paiement (le générateur d'affiche `PosterPdfService` existe mais ne sert que les actualités, avec un QR vers l'article, pas un QR SEPA).
- ❌ Vue « Factures » côté parents : aucune page ne liste, pour une famille, ses paiements attendus et leurs statuts.
- ✅ Acquis : la **réconciliation automatique** via l'import bancaire fonctionne (`ExpectedReceivableService::sumMatchingCredits()`, statuts payé/partiel/impayé calculés en direct, paiements en plusieurs fois gérés) et une vue de suivi existe (`/finance/receivables`), mais groupée par module source, pas par campagne nommée.
- 🟡 La communication structurée est un **jeton aléatoire d'unicité**, pas un identifiant porteur de sens : impossible de retrouver membre + catégorie depuis le numéro (pas d'encodage bidirectionnel, pas de `findByCommunication()`).

### 2.3 Ticketing / billetterie (§13) — ❌ totalement absent

Aucune trace dans le dépôt : ni module, ni table, ni route, ni vue.

- ❌ Génération de billets par réponse de formulaire (code de réservation + QR code).
- ❌ Page de validation le jour J (scan QR par caméra ou saisie du code) — aucune API caméra (`getUserMedia`) n'est utilisée nulle part ; le site **produit** des QR (SEPA, affiches) mais n'en **lit** aucun.
- ❌ Affichage du billet scanné, marquage « utilisé », édition de statut, recherche parmi les billets.
- Le seul identifiant unique par réponse de formulaire est la communication structurée bancaire — ni imprimée sur un billet, ni scannable.

### 2.4 Attestations fiscales et de présence (§17) — ❌ chaîne d'alimentation absente

Le stockage et la confidentialité sont prêts et corrects (`member_documents` + `FileAccessGuard`, accès strictement self-only, chiffrement au repos, bloc « Documents privés » sur la page de l'animé), mais le schéma dit explicitement : *« Storage + listing only in this iteration; no generation or admin upload UI yet »*.

- ❌ Import du PDF fédéral unique et **découpage automatique** en attestations individuelles par reconnaissance du nom (les briques `PdfTextExtractor`/smalot existent, mais aucune librairie de split PDF n'est présente et aucun appariement nom→membre n'est écrit).
- ❌ Génération du tableur famille → lien sécurisé (pour l'emailing « votre attestation est disponible »).
- ❌ Gestion des lots importés (liste, suppression d'un lot complet).
- ❌ Aucun écran ne permet même de déposer manuellement un document privé par membre : le bloc restera vide en production.

### 2.5 Formations des animateurs / BACV (§18) — ❌ en trompe-l'œil

Seule existe la colonne Desk `Niveau formation`, importée comme **champ texte plat** (`member_years.formation_level`) et réaffichée à trois endroits (page membre, page Staffs, colonne d'export).

- ❌ Aucune modélisation du parcours BACV (formations suivies, camps de formation, brevet, dates), aucun complément saisi sur le site.
- ❌ Pas de vue « Mon parcours » (juste une ligne d'info sur la page membre).
- ❌ Pas de vue d'ensemble admin de l'unité (la page Staffs est par section ; aucune synthèse, aucun compteur).

### 2.6 Lieux de camp (§22) — ❌ totalement absent

Aucune table, route, service ou vue ; aucun champ GPS dans tout le code ; pas même mentionné dans `specifications.md`. Attention au faux ami : le module `rental` gère la **location des bâtiments de l'unité à des tiers** — l'inverse du besoin (annuaire des endroits où l'unité part en camp).

### 2.7 Outils divers (§24) — ❌ les quatre absents

- ❌ Générateur manuel de QR de paiement (le `SepaQrCodeService` EPC existe mais n'est jamais appelable depuis une saisie libre).
- ❌ Vérificateur de communication structurée (aucune validation mod 97 d'une communication saisie, aucune recherche inverse en base).
- ❌ Extraction en masse de numéros de GSM depuis une audience (les GSM sont en base et dans les exports, mais aucune fonction dédiée type « liste collable dans WhatsApp »).
- ❌ Scanner de QR code autonome.

### 2.8 Fréquentation du site (§25.2) — ❌ absent

Aucun comptage de visites (pas de table `page_views`, pas de hook dans le routeur, aucun outil type Matomo/Plausible). Ne pas confondre avec `Core\Statistics` (télémétrie d'installation vers le support ScoutMagic) ni avec la catégorie de cookies « analytics » déclarée dans le bandeau RGPD, qu'aucun code n'utilise.

### 2.9 FAQ dynamiques (§2.2, §5.4, §27) — ❌ absent

Aucune FAQ nulle part (ni connexion, ni inscriptions, ni page publique). Le composant générique `list_editor.html.twig` est explicitement commenté comme prévu « pour une future FAQ », mais rien n'est bâti dessus.

---

## 3. Manques ponctuels dans des domaines par ailleurs couverts

### 3.1 Authentification (§2)

- ❌ Anti-spam par **réutilisation du lien magique récent** : le code fait l'inverse (nouveau token à chaque demande, rejet au-delà de 5/heure).
- ❌ FAQ dédiée à la connexion.
- ✅ Tout le reste : lien magique multi-appareils avec détection automatique (polling), expiration 15 min, emails secondaires, multi-membres par email, hiérarchie de rôles à 6 niveaux avec `role_min` obligatoire par route, captcha (honeypot + délai HMAC + rate-limit) levé pour les identifiés. En bonus : mot de passe et passkeys.

### 3.2 Import Desk et membres (§3)

- ❌ **Différentiel d'import** : aucun affichage des ajouts/suppressions/modifications (le résultat d'import ne montre que des compteurs) ; pas de mode aperçu. Un classificateur de mouvements existe (`MemberMovementClassifierService`) mais n'est utilisé que par le roster et l'export, jamais par l'import.
- ❌ **Date de désactivation** : les membres disparus sont bien marqués inactifs, mais sans horodatage (`member_years` n'a pas de `deactivated_at`).
- ❌ **Normalisation de fonction à l'import** (routiers/JER's) : correspondance 1:1 brute, aucune règle de réécriture.
- ❌ **Rappel automatique programmé** quelques semaines après l'import (ex. intendants) : aucune tâche de ce type.
- ❌ **Notes libres par membre** : aucune table/colonne (seul le commentaire de départ, chiffré et à finalité unique, existe).
- 🟡 Désinscription → simple **email** au Staff d'U (template dédié), pas de notification interne dans le centre de notifications.
- 🟡 Section cible pour l'année suivante : existe, mais dans le module `registration` (indisponible si le module est désactivé) et en `role_min: admin` (pas accessible aux staffs de section).

### 3.3 Sections (§4)

- ❌ **Logo par section** : seuls existent le logo par branche (avec défauts livrés Baladins→Route ✅) et la photo de groupe par section/année.
- ❌ **Ordre d'affichage configurable** des sections (ordre figé : branche puis code Desk ; seul l'ordre des branches est modifiable).
- 🟡 IBAN de section : via le module finance (`finance_accounts`), pas une propriété de la section.

### 3.4 Inscriptions (§5)

- ❌ **Liste d'attente** : n'existe pas comme état d'une demande (ENUM : pending/accepted/refused/withdrawn/encoded), ni comme file gérée par année de naissance (rang, promotion). Seul un **indicateur** de tension par année de naissance (Disponible/Limitée/Complet) est calculé.
- ❌ **Export tableur des inscriptions** (aucun export dans le module, alors que l'infrastructure d'export générique existe).
- ❌ **Commentaire externe visible par la famille** (seul le commentaire interne staff existe, chiffré).
- ❌ FAQ liée aux inscriptions.
- 🟡 Compteurs par statut sur la vue d'ensemble : un seul compteur (demandes non clôturées).
- 🟡 Texte d'avertissement quand les inscriptions sont fermées : **codé en dur**, non configurable (seule l'intro de page est éditable).
- 🟡 Places par année de naissance : niveau de disponibilité affiché publiquement, mais **jamais les chiffres exacts** (choix assumé et documenté — les chiffres sont réservés au staff).
- 🟡 Encart d'administration : réalisé autrement (page staff séparée `/config/inscriptions/demandes/{id}` au lieu d'un encart sur la page de suivi).
- ✅ Le reste est solide et dépasse le legacy : programmation annuelle d'ouverture/fermeture, suivi par token haché, statut masqué tant que l'email de décision n'est pas parti, rapprochement Desk automatique + liaison manuelle, rétentions configurables.

### 3.5 Passages (§6)

- ❌ Listes **nominatives** de « qui reste » et « qui arrive en fin de parcours » (uniquement des agrégats ; la page Passage ne montre que les changements de branche).
- ✅ Le reste : calcul par année de naissance avec dérogation `scout_year_offset` (−1/0/+1) intégrée partout, section cible manuelle stockée hors données Desk, alimentation complète des projections.

### 3.6 Finances (§8)

- ❌ **Vue par journée** (mouvements + justificatifs + note libre de la journée) : n'existe pas ; il y a une note par mouvement.
- ❌ **Statistiques financières globales de l'unité** (tous comptes) et leur export : tout est cadré « un compte à la fois ».
- ❌ **Comparaison entre exercices** dans les graphiques (le sélecteur d'exercice remplace les données, il ne superpose pas).
- ❌ **Trésorier désigné par section** : l'accès est purement hiérarchique par rôle global (`role_min_view` par compte) ; le badge « Trésorier » existe mais n'est jamais consulté par le module finance. Tout intendant voit les comptes de toutes les sections accessibles à son rôle.
- ❌ **Sous-catégories** (liste de catégories volontairement plate).
- 🟡 Association justificatif↔mouvement : par modale de recherche/suggestion IA, pas de glisser-déposer sur un mouvement (le drag&drop n'existe que pour l'upload).
- 🟡 Repérage des mouvements sans justificatif : encart « en attente d'action » limité à 10 + compteur par ligne, mais pas de filtre exhaustif.
- 🟡 **Un seul format bancaire** supporté à l'import : BNP (le legacy visait le CSV « de la banque » de l'unité).
- ✅ Le cœur (comptes par section, import relevés avec checkpoints de solde, catégorisation par règles + IA, justificatifs chiffrés avec extraction IA, exports mouvements) est très soigné.

### 3.7 Paiements transversaux (§10)

- ✅ Génération de communication structurée belge (mod 97, format `+++xxx/xxxx/xxxxx+++`) et **QR EPC/GiroCode complet** (consommé par formulaires payants et locations).
- ❌ **Validation** d'une communication saisie (pas de `isValid()` ; la réconciliation compare des chiffres sans vérifier le checksum).
- ❌ Encodage **bidirectionnel** (communication → membre + catégorie) : impossible par construction (numéro aléatoire).
- ❌ **Formatage lisible des IBAN** : les IBAN sont affichés d'un bloc (`BE71096123456769`) ; aucune fonction/filtre de groupement par 4.
- 🟡 Page de paiement publique : existe comme page de confirmation d'un formulaire payant (montant, IBAN, communication, QR), mais pas comme page autonome accessible par lien avec textes personnalisables.

### 3.8 Actualités (§11)

- ❌ **Tags** de catégorisation et filtre par tags (seuls des mots-clés SEO non filtrables existent).
- ❌ **Épinglage** et remontée automatique des articles à formulaire ouvert (tous les tris sont `created_at DESC`).
- ❌ **Archivage** (seule la suppression définitive existe ; succédané : visibilité « lien direct »).
- 🟡 Visibilité : 4 niveaux existent (`public`/`direct_link`/`chief`/`admin`) mais le niveau « **restreint = membres identifiés** » n'existe pas — un article visible par les parents connectés mais pas par le public est impossible.
- ✅ Partage social (Open Graph complet + lien court automatique + affiche QR) et mise en avant sur l'accueil.

### 3.9 Formulaires dynamiques (§12)

- ✅ Tous les types de champs demandés (+ radio, cases, confirmation, options alimentées par les membres), ouverture/fermeture indépendante, réponses horodatées rattachées au membre, édition admin, export XLSX, montant à payer + capacité verrouillée serveur.
- 🟡 Approche différente : constructeur visuel drag&drop au lieu d'une syntaxe inline dans le contenu (couverture fonctionnelle supérieure).
- ❌ **Création directe d'un brouillon d'email aux répondants** : `createDraft()` du module mass_mail existe mais n'est exposé à aucun autre module. Contournement en 4 étapes manuelles : export XLSX → réimport comme audience de publipostage.
- ❌ Génération de billets (voir §2.3).
- 🟡 La date de soumission n'est ni affichée ni exportée.

### 3.10 Email en masse (§14)

- ❌ **Copie à l'expéditeur** optionnelle.
- ❌ **Protection anti « tout majuscules »** du sujet.
- ❌ **Brouillons pré-adressés générés par d'autres fonctionnalités** (formulaires, paiements, attestations).
- 🟡 **Variables de personnalisation** : moteur complet (`{{Colonne}}`, aperçu par destinataire, détection des variables inconnues) mais **uniquement en mode publipostage Excel** — aucune variable membre native ({{prenom}}, {{nom}}) sur les listes classiques.
- 🟡 Audiences : par section, chefs seuls, membres actifs, listes personnalisées fonctions×sections ✅ ; **par branche** ❌.
- 🟡 Suivi de l'envoi : page de suivi destinataire par destinataire avec relance des échecs ✅, mais **pas de rafraîchissement automatique** (pas de temps réel).
- ✅ Le reste : cycle brouillon→test→envoi avec liste gelée, lots planifiés, expéditeur = section de l'auteur, pièces jointes, désinscription un clic RFC 8058 avec liste de suppression, historique, visualisation en ligne d'un email reçu par les parents.

### 3.11 Calendrier (§15)

- ❌ **Vue chronologique de l'année scoute** (septembre → fin) : seule la grille mensuelle + 10 prochains évènements existent.
- 🟡 **Cloisonnement d'édition par section absent** : tout chef édite les évènements de toutes les sections (choix documenté dans le code), et le calendrier « Animateurs » est éditable par tout chef, pas seulement par les admins (réglable manuellement en visibilité `admin`).
- 🟡 ICS : flux « unité complète » + personnel + calendriers supplémentaires ✅, mais **pas de lien ICS individuel par calendrier de section** (refus explicite dans le code).
- ✅ Calendriers par section auto-créés, calendrier Animateurs masqué aux parents, vue combinée/filtrée, évènements générés par d'autres modules (gardes SOS, locations, rétros).

### 3.12 Tableaux de bord (§16)

- ❌ **Notes libres par animé** (voir 3.2).
- 🟡 **Recherche d'un animé** : réservée `admin` (`/admin/members`) — un chef de section n'y a pas accès, et le roster `/chefs/membres` n'a pas de champ de recherche.
- 🟡 **Tableau staff par section** : totem, fonction, emails, téléphones ✅ à l'écran, mais **adresses, dates de naissance et tarifs absents de la table** (adresses/naissances uniquement dans l'export ; le tarif n'est nulle part, même pas dans l'export).
- 🟡 **Badges édités en ligne dans le tableau** : réalisé pour les **staffs** (`/chefs/staffs`, AJAX optimiste) mais pas dans le tableau des **animés**.
- ✅ Page par animé pour les parents complète (section, responsable, Desk, documents privés — lecture, badges, galeries, emails reçus), section cible pour l'année suivante (via module registration), indication visuelle des mouvements (badges NEW/SECTION_CHANGE/BRANCH_CHANGE… + export).

### 3.13 Trombinoscope (§19)

- ❌ **Version imprimable sans photos** (aucune route d'impression/PDF dans le module).
- 🟡 Photo de groupe et mur individuel **sur deux pages différentes** (`/chefs/staffs` vs `/trombinoscope`) ; pas d'identification des personnes sur la photo de groupe.
- 🟡 Responsables : un seul « lead » mis en avant par section (le premier trouvé), pas de responsables multiples.
- ✅ Photos remplaçables par upload (versionnées par année), responsable désigné par flag de fonction, réutilisé sur la page membre et par SOS.

### 3.14 Documents (§20)

- ❌ **Bibliothèque publique** de documents (aucune).
- ❌ **Bibliothèque restreinte staffs** transversale (les documents staff n'existent que rattachés à une section).
- ❌ Upload des documents **privés par membre** (lecture seule aujourd'hui — voir attestations §2.4).
- ⚠️ **Écart d'autorisation** : tout chef peut déposer/supprimer des documents dans **n'importe quelle** section (`can_edit_section` = « est chef », sans vérification de la section animée) — contrairement à l'exigence « par staffs pour les documents de leur section ». Le motif correct existe déjà dans le module galerie (`GalleryAccessService::canManageAlbum()`) et dans `SectionStaffAuthorizationService`.
- ✅ Contrôle d'accès aux fichiers exemplaire (`FileAccessGuard`, fail-safe, chiffrement au repos).

### 3.15 Galerie (§21)

- ✅ Albums externes par lien avec récupération automatique du titre et de la miniature (scraping Open Graph, copie locale de l'image) ; gestion cloisonnée par section.
- ❌ **Déduction automatique de l'année** (date saisie à la main, préremplie à aujourd'hui).
- ❌ **Regroupement par année** : grille plate triée par date, sans en-têtes d'année, et surtout **horizon limité à l'année courante + la précédente** — les albums plus anciens ne sont plus navigables (le legacy est une archive pluriannuelle).

### 3.16 SOS Staff d'U (§23)

Domaine le plus fidèlement porté — tout est présent (état réel chez l'opérateur OVH, numéro par défaut, grille mensuelle 3 états à sauvegarde immédiate, activités des sections en regard, bascules automatiques à heure fixe avec vérification préalable et confirmation, emails de passation + alerte d'échec, publication dans le calendrier Animateurs avec fusion des jours consécutifs), **sauf** :

- 🟡 La **purge > 1 an n'est pas planifiée** : elle ne s'exécute qu'à la sauvegarde de la grille par un admin (si personne ne touche au planning pendant des mois, les données subsistent).
- 🟡 Numéro par défaut : toujours un membre réel (relu depuis Desk), pas de saisie libre — choix de conception plutôt qu'un manque.

### 3.17 Notifications internes (§26)

- ❌ **Ciblage par section** d'une notification (les destinataires sont « tous les comptes » ou « membres d'un groupe » ; le ciblage section n'existe que pour l'emailing).
- ❌ **Texte d'accompagnement personnalisable** sur la page du centre de notifications (aucun bloc `editable()`).
- 🟡 **Criticité** : le mécanisme de canal verrouillé existe mais un seul type l'utilise (`core.security_alert`) ; pas de notion transversale de notification critique toujours affichée.
- 🟡 **Déduplication** : garantie au niveau du planificateur (référence idempotente) pour les notifications planifiées, mais rien n'empêche un doublon en dispatch direct.
- 🟡 **Désinscriptions Desk** : ne passent pas par le centre (simple email au Staff d'U).
- ✅ Le centre lui-même dépasse le legacy (push Web, préférences par type/canal, heures silencieuses, chiffrement).

### 3.18 Contenu éditable et pages publiques (§27)

- ❌ Pages **Partenaires**, **ASBL**, **Année typique (timeline)** : aucune trace. Pages publiques présentes : accueil, contact, sections, RGPD (+ calendrier, actualités, inscriptions, locations).
- ❌ **Vidéo** sur l'accueil (`editable_contents` ne connaît que `rich_text` et `image`).
- ❌ **Pages internes staffs** (aide/wiki d'utilisation, ressources, guide de mise en forme) : aucune route.
- ❌ FAQ (voir §2.9).
- 🟡 Pied de page : mentions RGPD/cookies ✅, mais pas de lien contact ni de mentions légales distinctes.
- ✅ L'édition en ligne (mode configuration, clic-pour-éditer riche, journalisée, sans back-office) est complète — c'est le socle sur lequel bâtir les pages manquantes.

### 3.19 Transversal (§28)

- ❌ **Redirections traquées** : aucun comptage de clics (ni sur `short_urls`, ni dans mass_mail).
- 🟡 **Liens courts** : moteur générique complet (`/s/{code}`, cible chiffrée) mais **aucune interface de raccourcissement libre** — seuls 3 appelants internes figés (affiches news, édition de réponse, rétros).
- 🟡 **Liens uniques sécurisés** : motif homogène et solide (tokens hachés) sur inscriptions, locations, ICS, rétros, désinscription — mais pas d'attestations (inexistantes).
- 🟡 **Journal** : complet (recherche par catégorie/niveau/texte/date/IP/email) mais seulement **deux niveaux (`info`/`security`)** — pas de niveaux erreur/avertissement distincts (un échec SOS est journalisé en `info`).
- ✅ Planificateur (exécution en fin de requête + cron, annulation/remplacement, écran d'admin), sauvegardes (à la demande + automatiques, chiffrées, téléchargeables, restauration), bascule d'année scoute (workflow 14 étapes, années publique/staff/aperçu, veto inscriptions).
- 🟡 **Exports** : membres, finances, réponses de formulaires ✅ (avec en-têtes réutilisables comme audience de publipostage — règle établie) ; **inscriptions, cotisations, attestations ❌**.

---

## 4. Lecture priorisée pour remplacer 25sv.be

**Bloquants probables** (fonctionnalités opérationnelles du legacy sans équivalent) :

1. **Cotisations** (§2.1) — modèle de tarifs avec montants, calcul par famille, vérification de la facture fédération, validation Desk, exports.
2. **Campagnes de paiement** (§2.2) — création par tableur, statut manuel (liquide), poster QR, vue Factures parents. La réconciliation automatique, la plus grosse brique, est déjà là.
3. **Attestations** (§2.4) — découpage du PDF fédéral, appariement nom→membre, tableur de liens, gestion de lots. Stockage et confidentialité déjà prêts.
4. **Billetterie** (§2.3) — module complet à créer, y compris le scan caméra.
5. **Liste d'attente des inscriptions** (§3.4) — état de demande + gestion par année de naissance.

**Importants mais contournables temporairement** :

6. Diff d'import Desk + rappels post-import + normalisation de fonctions (§3.2).
7. Lieux de camp (§2.6) — module CRUD simple, sans dépendance.
8. Outils divers (§2.7) — quatre petits outils, dont deux (QR manuel, lookup) débloqués par un `formatIban()`, un `validate()` mod 97 et un `findByCommunication()`.
9. Chaînage email inter-modules (répondants d'un formulaire → brouillon, §3.9/3.10) — `createDraft()` existe, il n'est juste exposé à personne : meilleur rapport valeur/effort du rapport.
10. Organisation éditoriale des actualités (tags, épinglage, archivage, visibilité « membres identifiés », §3.8).
11. Tableau de bord staff : colonnes manquantes (adresses, naissances, tarifs), badges en ligne sur les animés, recherche pour les chefs (§3.12).
12. Formations BACV (§2.5), fréquentation du site (§2.8), FAQ (§2.9), pages publiques manquantes (§3.18).

**Écarts d'autorisation à corriger indépendamment du legacy** :

- Documents de section : tout chef écrit dans toutes les sections (§3.14).
- Calendrier : tout chef édite tous les calendriers, y compris « Animateurs » (§3.11).
- Finances : pas de notion de trésorier par section (§3.6).

**Choix de conception assumés par ScoutMagic** (à arbitrer, pas à « corriger ») :

- Les chiffres exacts de places d'inscription ne sont jamais publics (seul un niveau de disponibilité l'est).
- Le formulaire d'article est un constructeur visuel, pas une syntaxe inline.
- La communication structurée est aléatoire (pas d'encodage campagne/membre) — la réconciliation passe par la base, pas par le numéro.
- La transition d'année est exclusivement manuelle (veto inscriptions).
- Le numéro SOS par défaut est toujours un membre réel, jamais une saisie libre.
