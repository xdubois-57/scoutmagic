# Chantier — Documents officiels (autorisation parentale, fiche santé)

Roadmap d'exécution en **5 itérations séquentielles**. Traite-les une par une, dans l'ordre.

Répond aux issues **#363** (générer l'autorisation parentale) et **#362** (gestion des fiches
médicales). Les deux sont à fermer à la fin du chantier, et une issue neuve est à ouvrir pour ce qui
reste de #362 — voir « Clôture » en fin de fichier.

La maquette `Documents officiels — maquette` (toile Design) fait autorité sur **la structure des
écrans, les libellés français et l'ordre des champs** — et sur rien d'autre.

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `specifications.md`, `design.md` et
`docs/module-development.md`. Ils priment sur ce fichier sur toute règle générale ; si tu découvres
qu'ils décrivent une réalité que le code contredit, **mets-les à jour dans la même PR**.

- Une itération = une branche, une PR. Rebase sur `main` avant de merger.
- **Merge et push sur `main` dès que tous les tests et la CI complète sont verts.** Un test rouge
  arrête le chantier : tu corriges, tu ne contournes pas, tu ne désactives rien.
- Tests obligatoires : PHPUnit, PHPStan, Vitest et `npm run typecheck` si tu touches du JS.
  Couverture RBAC sur chaque route : autorisée au rôle déclaré, refusée un cran en dessous, **et
  refusée à un membre identifié qui n'est pas le bon membre**.
- **Toute édition de `modules/official_documents/schema.sql` impose de bumper `version` dans son
  `module.json`**, dans le même changement.
- Code, commentaires, identifiants, tables, colonnes en anglais ; interface en français. Aucune
  donnée personnelle dans le journal, les messages d'erreur ou les traces.
- **Aucun jargon interne dans l'interface de ces écrans** : ni « Desk », ni « member_year », ni
  « blind index ». Ils s'adressent à des parents. Le vocabulaire de l'Espace chefs d'U n'est pas le
  leur.
- Ne pose pas de question, sauf si une décision de design manque ou si une règle fonctionnelle
  ci-dessous est ambiguë. Tout le reste est tranché.

---

## L'objectif

Un parent ouvre la page de son enfant, télécharge le formulaire officiel de la fédération
**déjà rempli avec ce que le site sait**, l'imprime, le signe, et le remet à l'animateur.

C'est tout. Il n'y a pas de signature électronique, pas de renvoi du document signé au site, pas de
tableau de bord pour le staff.

### Le principe qui gouverne tout le chantier — **Décidé**

**La seule version qui a une valeur légale est celle signée par les parents, sur papier.**

Ce que le site enregistre n'est qu'un brouillon de pré-remplissage. Il s'ensuit, et ce sont des
interdits, pas des préférences :

- **Aucune vue staff sur ces données, jamais.** Ni maintenant, ni par extension future. Un
  animateur qui lirait la version du site lirait une version non signée, donc sans valeur — et
  croirait savoir. Les vues « allergies » et « médicaments » demandées par #362 sont explicitement
  hors de ce chantier, et le resteront tant que ce principe tient.
- **Auto-service strict**, au sens de `ARCHITECTURE.md` §8.27 : chaque action revérifie que le
  compte demandeur est lié au membre, quel que soit son rôle. Aucun contournement chef ou
  administrateur.
- L'avertissement « un document non signé n'a aucune valeur » s'affiche **sur la page web**, jamais
  sur le PDF : on n'écrit rien sur un document officiel qui n'en fasse pas partie.

### Le périmètre documentaire — **Décidé**

Deux fichiers du pack « Pack admin membre » de la fédération, et deux seulement :

| Fichier | Pages | Champs AcroForm | Version PDF | xref compressé |
|---|---|---|---|---|
| `4.Les_Scouts_Autoristation_parentale.pdf` | 1 | 0 | 1.7 | non |
| `2.Les_Scouts_Fiche_sante_formulaire.pdf` | 2 | 60 | 1.7 | **oui** |

Sont hors périmètre : la fiche d'inscription, la prescription médicale et les deux autorisations de
publication de photos.

**Il n'existe pas deux autorisations parentales.** Un seul formulaire couvre n'importe quelle
période, via « du …/…/… au …/…/… ». La distinction résidentiel / non résidentiel ne se traduit donc
que par la plage de dates choisie.

---

## Les décisions d'architecture

### Un module optionnel, non activé par défaut — **Décidé**

`modules/official_documents/`, nom affiché « Documents officiels ». **Pas de
`enabled_by_default`** : une donnée de santé ne doit exister sur une installation que si quelqu'un
l'a décidé. Un module jamais activé n'a jamais créé sa table.

Le bloc sur la page du membre passe par un hook cœur (`ARCHITECTURE.md` §7.4/§7.5), exactement
comme les six dépendances optionnelles que `Core\Member\MemberPageService` porte déjà : dépendance
nullable, bloc non affiché quand elle est nulle.

Le prix, assumé : module désactivé = plus d'autorisation parentale non plus, et la désactivation
laisse les données en place (§7.3). C'est le bouton « Tout effacer » d'IT-03 qui donne la sortie
propre.

### FPDI par-dessus le formulaire officiel — **Décidé**

Le PDF officiel est importé comme fond et le texte est écrit par-dessus, à des coordonnées fixes en
millimètres. Deux dépendances nouvelles à ajouter à la table de `ARCHITECTURE.md` §1, avec leur
justification :

| Dépendance | Justification |
|---|---|
| `setasign/fpdi` | Importe les pages d'un PDF existant comme fond. Le formulaire de la fédération fait foi ; le reproduire en HTML serait une contrefaçon de mise en page à re-vérifier à chaque version. |
| `setasign/tfpdf` | Le moteur de rendu sous FPDI. **tFPDF et non FPDF** : FPDF n'écrit qu'en cp1252 via ses polices de base, et un nom porteur d'un caractère hors de ce jeu (`ł`, `ș`, `ğ`) sortirait mutilé ou ferait échouer la génération. tFPDF écrit en UTF-8 avec une police TrueType embarquée — DejaVu Sans, déjà la police par défaut de `dompdf` dans ce dépôt. |

`dompdf` reste en place pour tout le reste (contrats, factures, étiquettes, feuille d'appel) : les
deux moteurs coexistent, chacun pour ce qu'il sait faire. FPDI ne produit rien tout seul, et dompdf
ne sait pas importer une page existante.

**L'add-on commercial FPDI PDF-Parser n'est pas nécessaire**, et ne doit pas l'être : sa licence
propriétaire n'a pas sa place dans un dépôt AGPL-3.0. Voir la conversion ci-dessous.

### Les gabarits sont committés, convertis une fois — **Décidé**

FPDI libre ne lit que les tables de références croisées classiques, soit les PDF jusqu'à la version
1.4. La fiche santé est en 1.7 avec flux d'objets : elle est donc **convertie une fois par un
mainteneur**, hors du serveur, et c'est le résultat qui est committé :

```bash
qpdf --object-streams=disable --force-version=1.4 \
     2.Les_Scouts_Fiche_sante_formulaire.pdf \
     modules/official_documents/templates/fiche-sante.pdf
```

Vérifié : 2 pages, 60 champs et 896 Ko conservés contre 905 à l'origine. L'autorisation parentale
n'a besoin d'aucune conversion mais suit le même chemin, pour que les deux gabarits se traitent
pareil.

**Aucun code serveur ne convertit quoi que ce soit.** `qpdf` n'est pas une dépendance du projet :
c'est un geste de mainteneur.

### Où la commande doit figurer — **Décidé**

À deux endroits, et aux deux : une procédure qui ne vit que dans un fichier que personne n'ouvre est
une procédure perdue.

**1. Dans les commentaires du code.** Le docblock de la classe qui charge les gabarits porte la
commande complète, et le test qui épingle les SHA-256 porte, dans son message d'échec, la phrase qui
dit quoi faire. C'est ce test qui casse le jour où quelqu'un remplace un gabarit : c'est donc là que
la marche à suivre doit se trouver, pas trois dossiers plus loin.

**2. Dans `modules/official_documents/templates/README.md`**, fourni avec ce chantier et à
committer tel quel. Il porte la procédure complète en quatre étapes — convertir, laisser le test
échouer, recaler les coordonnées, mettre à jour la constante — et les trois interdits qui vont avec.

### Les gabarits sont fournis, déjà convertis — **Décidé**

Les deux fichiers accompagnent ce chantier, déjà passés par `qpdf`. Committe-les tels quels, sans
les régénérer :

| Fichier | Pages | Champs | SHA-256 |
|---|---|---|---|
| `autorisation-parentale.pdf` | 1 | 0 | `7bdc1f93c7ef3f659522a19955928dd34e0fe429a21999214e13acc84a9ad928` |
| `fiche-sante.pdf` | 2 | 60 | `26b573fa86f2658aaff238d329634dbae701a07c53a88fc1a031e4569559a73f` |

Ce sont ces deux empreintes que le test d'IT-02 épingle. Ne les recalcule pas depuis un fichier que
tu aurais reconverti toi-même : une conversion refaite produit des octets différents, et la
constante ne vaudrait plus rien.

---

## IT-01 — Prénom et nom obligatoires

Cœur, et prérequis de tout le reste : le nom du signataire proposé par défaut vient du compte
identifié, qui peut aujourd'hui n'en avoir aucun.

### À faire

`user_accounts.first_name` et `last_name` deviennent obligatoires.

- Un **écran intercalaire après identification** quand l'un des deux manque. Il bloque la
  navigation tant qu'ils ne sont pas fournis.
- Dans « Mon compte », les deux champs deviennent obligatoires à l'enregistrement : on ne peut plus
  les vider.
- Aucune migration de données : les comptes existants dont les champs sont vides rencontrent
  l'écran à leur prochaine connexion.

### Décidé

**La déconnexion reste toujours accessible pendant le blocage.** C'est la seule sortie, et elle
n'est pas négociable : une validation qui se braque enfermerait quelqu'un dehors de son propre
site, superadmin compris — l'assistant d'installation ne collecte qu'une adresse e-mail, donc le
premier compte de chaque installation est concerné.

Le blocage vaut pour **tout compte identifié**, quel que soit son rôle. Pas de cas particulier.

### Pièges

- La connexion par lien magique peut être confirmée depuis un autre appareil : l'écran apparaît sur
  celui qui **porte la session**, pas sur celui qui a cliqué sur le lien.
- Les pages publiques ne sont pas concernées : le blocage s'applique à une session identifiée, pas
  à un visiteur.
- Ne casse pas les routes que le blocage doit laisser passer : déconnexion, et les ressources
  statiques de la page elle-même.
- Effet de bord à vérifier plutôt qu'à subir : `person_avatar()` et les initiales du menu compte
  cessent d'avoir un cas « sans nom ». Ne supprime pas leur repli pour autant — un compte peut être
  lu dans la même requête que celle qui présente l'écran.

---

## IT-02 — Le socle du module et l'autorisation parentale

L'itération qui porte le risque technique : FPDI, tFPDF, le calage des coordonnées.

### À faire

Créer `modules/official_documents/` avec sa checklist complète (`AGENTS.md` § « Module creation
checklist »), le gabarit converti, le moteur de superposition, l'écran de génération de
l'autorisation parentale, et le bloc sur la page du membre.

### Les routes

Sur le modèle exact de `Modules\MassMail\Controller\MemberEmailController`, qui déclare déjà une
sous-page de la page du membre : `menu: "espace_animes"`, `label: ""` pour n'apparaître dans aucun
menu, et un `breadcrumb` avec `parents: ["Espace membres"]`.

| Route | Rôle | Gardée aussi par |
|---|---|---|
| `GET /members/{id}/autorisation-parentale` | `identified` | lien compte ↔ membre |
| `POST /members/{id}/autorisation-parentale` | `identified` | lien compte ↔ membre |

Le `role_min` n'est **jamais** la protection réelle ici : c'est la vérification du lien entre le
compte et le membre, faite dans le contrôleur à chaque action, comme
`MemberEmailService::isOwnMember()`.

### L'écran de génération

Un formulaire court :

- **Nom du signataire**, pré-rempli avec le prénom et le nom du compte identifié (IT-01), modifiable.
- **Qualité** : père / mère / tuteur / répondant. Une seule.
- **Dates de début et de fin**, librement saisissables.
- **Un sélecteur d'événements** qui ne fait que remplir les deux champs de date côté navigateur.
- **Lieu** (« Fait à »), pré-rempli avec la ville du membre.
- Le cas échéant, **camp à l'étranger** : le formulaire porte une note (1) indiquant que la dernière
  phrase est à biffer pour les activités en Belgique. Par défaut, elle est biffée.

### Le sélecteur d'événements — **Décidé**

Il propose les événements **en cours ou à venir** de la section du membre qui **couvrent au moins
une nuit** (`endDate > startDate`).

C'est `Modules\Calendar\Api\CalendarEventLookupInterface::findEventsInWindow($début, $fin,
$sectionId, $rôleDuVisiteur)` qu'il faut, **pas** `SectionEventLookupInterface`. Son propre docblock
le dit : elle replie les calendriers supplémentaires à côté de celui de la section, « ce qui est
juste pour un sélecteur ». Un camp d'unité porté par un calendrier non sectionnel serait proposé
avec l'une et invisible avec l'autre.

Dépendance optionnelle au sens §7.5 : `calendar` désactivé, le sélecteur disparaît, les deux champs
de date restent.

**Le serveur ne reçoit jamais qu'une date de début et une date de fin**, jamais un identifiant
d'événement. Le sélecteur est un confort d'interface, rien de plus — donc rien à re-résoudre côté
serveur, et aucune confiance accordée au client sur un identifiant.

Le module `camps` n'est **pas** consommé : c'est un référentiel de lieux et d'historique, pas la
source des dates d'une activité à venir. N'y crée aucune interface `Api`.

### Ce que le PDF reçoit

| Emplacement du formulaire | Source |
|---|---|
| Animateur responsable : prénom, nom | responsable de section, via `Core\Module\SectionResponsableProvider` |
| Animateur responsable : adresse complète | `MemberService::findProfileByMemberAndYear()` — `hydrateMemberProfile()` ne charge pas les adresses, voir §8.22 |
| Je soussigné(e) | saisie |
| père / mère / tuteur / répondant | les trois non retenues **barrées d'un trait** |
| autorise (prénom, nom) | le membre |
| Baladins / Louveteaux / Éclaireurs / Pionniers | les non concernées **barrées d'un trait** |
| de l'unité | code et nom d'unité, depuis `SettingService` |
| du … au … | saisie |
| Fait à … le … | lieu saisi, date du jour |

Les deux lignes « Signature représentant·e légal·e 1 » et « 2 » restent **vides** : le formulaire
prévoit deux signatures pour un seul « Je soussigné(e) ».

### Le calage des coordonnées

Écris `scripts/pdf-template-grid.php`, un utilitaire de développement qui produit le gabarit avec
une grille millimétrée en surimpression, pour placer les champs. Les coordonnées vivent dans **un
seul fichier de carte par document**, en PHP, jamais dispersées dans le service.

Le `README.md` des gabarits (voir plus haut) renvoie à ce script par ce nom exact : s'il change de
nom, ce README change dans la même PR.

Un test vérifie que chaque champ déclaré dans la carte tient dans les limites de la page et
qu'aucun nom de champ attendu ne manque.

### Pièges

- **tFPDF a besoin de sa police TrueType embarquée.** Ne retombe pas sur les polices de base de
  FPDF : un accent y passerait, un caractère hors cp1252 non.
- **Une branche peut n'être dans aucune des quatre listées** — Staff d'U, Iama, une branche
  inconnue. Dans ce cas, ne barre rien plutôt que de barrer les quatre.
- Le texte du membre est contrôlé par le membre : chaque valeur substituée passe par
  l'échappement avant d'atteindre le PDF, exactement comme `DocumentPdfService` l'exige de ses
  appelants.
- La longueur déborde : un nom composé, une adresse longue. Réduis le corps sur la ligne plutôt que
  de laisser le texte sortir de la zone.
- **Aucun fichier temporaire sur disque.** Le PDF sort en mémoire.

---

## IT-03 — La fiche santé : saisie et stockage

### À faire

Une page dédiée, une table, un enregistrement par membre.

| Route | Rôle | Gardée aussi par |
|---|---|---|
| `GET /members/{id}/fiche-sante` | `identified` | lien compte ↔ membre |
| `POST /members/{id}/fiche-sante` | `identified` | lien compte ↔ membre |
| `POST /members/{id}/fiche-sante/effacer` | `identified` | lien compte ↔ membre |

### La table — **Décidé**

`official_documents_health_sheets` : `member_id` (unique, FK `members`, `ON DELETE CASCADE`),
`content_encrypted` BLOB, `last_used_at`, `created_at`, `updated_at`.

**Un seul BLOB chiffré portant un document JSON**, et non une colonne par champ. Ces données ne
sont jamais cherchées, jamais filtrées, jamais triées : elles sont lues pour un membre à la fois.
Même raisonnement que `entity_changes`, qui chiffre ses valeurs sans condition, et que
`mail_deferred_messages.payload_encrypted`. Conséquence utile : ajouter un champ au formulaire ne
touche pas le schéma, donc n'impose pas un bump de version du module à chaque ajustement.

Chiffrement et déchiffrement **dans le Repository uniquement**.

**Pas de `scout_year_id`**, et c'est l'exception que `AGENTS.md` § Database autorise explicitement :
la fiche décrit une personne aujourd'hui, elle est remplacée sur place, et sa fraîcheur est portée
par `last_used_at`. Même raisonnement que `member_notes`, clé sur `members.id`.

### Le formulaire

Les rubriques suivent le formulaire officiel, dans son ordre :

- **Identité** : affichée en lecture seule, non stockée par le module, non modifiable ici. Elle
  vient de `member_years` — mais **le mot « Desk » n'apparaît nulle part sur ces écrans** : un
  parent ne sait pas ce que c'est. La maquette dit la formulation retenue — un libellé « Non
  modifiable ici » et la phrase « Ces informations viennent de votre inscription à la fédération.
  Pour les corriger, prévenez votre animateur. », qui donne en plus le chemin de correction.
- **Deux personnes à contacter en cas d'urgence** : nom et prénom, lien de parenté, téléphone,
  e-mail, remarque.
- **Médecin traitant** : nom, prénom, téléphone.
- **Santé** : taille, poids, participation aux activités et précisions, niveau de natation (cinq
  niveaux), les douze cases d'affections, fréquence / gravité / mesures, maladies et opérations,
  informations utiles, tétanos et date du dernier rappel, allergies et conséquences, régime
  alimentaire, traitement en cours et autonomie de prise.

Tout est facultatif. Une fiche vide est un état valide : le parent peut n'utiliser que
l'autorisation parentale.

### Le bouton « Tout effacer » — **Décidé**

Vide l'enregistrement, en confirmation simple par boîte de dialogue. **Pas** le mot-clé à retaper de
la page Maintenance : celui-ci protège une installation entière, pas les données de quelqu'un sur
lui-même.

Journalisé avec l'identifiant du membre seul, jamais un champ.

### Le bloc sur la page du membre

Court : deux liens, l'avertissement « un document non signé n'a aucune valeur », et rien d'autre.
La page du membre porte déjà une douzaine de blocs (§8.22) ; celui-ci n'en devient pas un
treizième pesant.

### Pièges

- **Aucune donnée de santé dans le journal.** Ni dans un message d'erreur, ni dans une exception,
  ni dans un paquet de support. Une validation qui échoue ne renvoie jamais la valeur refusée.
- Le formulaire est long : découpe-le en sections repliables plutôt qu'en plusieurs pages, pour
  qu'un enregistrement partiel ne perde rien.
- `last_used_at` est touché par l'enregistrement **et** par la génération d'un document (IT-04).

---

## IT-04 — La fiche santé : génération du PDF

### À faire

Le même moteur qu'IT-02, sur deux pages et une soixantaine d'emplacements.

`GET /members/{id}/fiche-sante/pdf`, `identified`, gardée par le lien compte ↔ membre.

### Ce que FPDI fait des 60 champs — **Décidé**

FPDI transforme le contenu d'une page en « form XObject », et les champs de formulaire comme les
annotations n'y sont pas repris : le gabarit devient un fond aplati.

**Ce n'est pas une perte ici.** La sortie est faite pour être imprimée et complétée à la main : les
emplacements que le site ne sait pas remplir redeviennent des lignes pointillées et des cases vides,
ce qui est exactement le geste visé. Les cases cochées sont dessinées par-dessus.

### Ce qui n'est pas ajouté — **Décidé**

Le pied de la page 2 porte déjà les mentions RGPD de la fédération, y compris la durée de
conservation et la destruction après le séjour. **N'ajoute rien** : la mention demandée par #362 est
déjà imprimée, et on n'écrit pas sur un document officiel ce qui n'en fait pas partie.

### Pièges

- Deux pages : `importPage(1)` puis `importPage(2)`. Ne suppose pas qu'un document est monopage.
- Les zones de texte libre (allergies, traitements, informations utiles) débordent vite. Découpe sur
  les lignes disponibles, et si le texte ne tient pas, écris ce qui tient **sans le tronquer en
  silence** : la page web doit dire au parent que sa saisie ne tiendra pas.
- Même règle qu'en IT-02 : rien sur disque, tout en mémoire.

---

## IT-05 — La purge et la documentation

### À faire

**La purge** : une tâche quotidienne auto-replanifiée, sur le patron de
`Core\Notification\Task\PurgeNotificationsHandler`. Elle supprime toute fiche dont `last_used_at`
remonte à plus de N mois.

- `N` est un réglage `SettingService` du module, **défaut 18 mois**, donc réinitialisable par
  « Paramètres par défaut » comme le reste.
- Le compteur repart sur **mise à jour ou génération d'un document** — générer n'est pas un geste
  passif : le parent a ouvert la page, vu les valeurs, et décidé de les imprimer.
- **Purge silencieuse.** Aucune notification, aucun avertissement préalable.

**La page RGPD** : `Core\View\RgpdContentService::getDefaultContent()` — nouvelle donnée personnelle
et nouveau traitement, détaillés rubrique par rubrique — et
`RgpdContentService::buildSystemPrompt()`, qui doit décrire le traitement du module. `AGENTS.md`
est explicite : une PR qui ajoute un traitement de données personnelles sans cela est incomplète.

**L'aide contextuelle** : `modules/official_documents/help/*.md`, déclarée dans `module.json`, sur
le modèle des autres modules. Au minimum : à quoi servent ces documents, pourquoi seul le papier
signé compte, et que la fiche s'efface toute seule au bout de 18 mois.

### Un point à mentionner dans l'aide — **Décidé**

Le formulaire officiel dit lui-même qu'il est à compléter « au début de chaque année scoute ». Nos
18 mois sont donc plus permissifs que ce que la fédération attend : une fiche de dix-sept mois sera
pré-remplie sans que rien ne signale qu'elle a sauté une année. Le parent voit les valeurs avant
d'imprimer — mais l'aide doit le dire.

### Pièges

- La tâche se replanifie **à la fin de chaque exécution** : `Core\Scheduler` n'a pas de notion de
  tâche récurrente de premier rang.
- La purge journalise l'identifiant du membre, jamais autre chose.
- Teste la purge sur les deux moteurs, comme le reste de la suite.

---

## Récapitulatif

| # | Itération | Schéma | Bump version | Risque |
|---|---|---|---|---|
| IT-01 | Prénom et nom obligatoires | — (cœur) | — | moyen : bloque tout le monde |
| IT-02 | Socle du module + autorisation parentale | création | **création** | **FPDI, tFPDF, coordonnées** |
| IT-03 | Fiche santé : saisie et stockage | nouvelle table | **oui** | faible |
| IT-04 | Fiche santé : génération du PDF | — | — | moyen : 60 emplacements, 2 pages |
| IT-05 | Purge et documentation | `settings` | **oui** | faible |

IT-02 porte le risque technique : deux dépendances nouvelles, un moteur PDF qui n'est pas celui du
reste du dépôt, et un calage au millimètre. IT-04 réutilise tout ce qu'elle aura mis en place.

IT-01 porte un risque d'une autre nature : elle touche le parcours de connexion de **tous** les
comptes de **toutes** les installations. Elle est petite et doit le rester.

---

## Clôture

À la fin d'IT-05, et pas avant :

- Fermer **#363** — l'autorisation parentale est livrée.
- Fermer **#362** — la fiche santé est livrée dans le périmètre décidé.
- **Ouvrir une issue neuve** reprenant ce que #362 demandait et que ce chantier écarte
  délibérément : vue « allergies » et vue « médicaments » exportables pour le staff, rapport de mise
  à jour pour le staff de section, et demande de signature aux parents. L'issue doit **citer le
  principe du §« L'objectif »** — seule la version signée fait foi — comme la raison pour laquelle
  ces trois points ne sont pas de simples itérations suivantes, mais demandent d'abord de trancher
  si le site peut présenter à un animateur des données qu'aucun parent n'a signées.
