# Chantier — Actualités : brouillon, publication et programmation

Répond à l'issue **#845**. Module `modules/news`.

**Prérequis : l'issue #706 doit être fermée avant d'écrire la moindre ligne de code.** Le chantier
Médias sociaux qu'elle porte modifie le même bouton de partage d'une actualité (`ArticleShareAction`)
et l'éditeur ; les mener en même temps garantirait un conflit. L'issue #845 peut être réclamée avant
(branche `claude/issue-845`) ; l'implémentation part de `main` mis à jour, une fois #706 fermée.

**Un seul lot, une seule PR**, en deux parties, un commit chacune : la partie 1 pose le statut et
toutes ses conséquences, la partie 2 ajoute la programmation. Elles touchent les mêmes fichiers ;
les séparer en deux PR ferait attendre la CI deux fois pour rien.

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `specifications.md`, `design.md` et
`docs/module-development.md`. Ils priment sur ce fichier sur toute règle générale ; si tu
découvres qu'ils décrivent une réalité que le code contredit, **mets-les à jour dans la même PR**.

- **Le travail suit `AGENTS.md` § « Fix the backlog », pour #845 seulement** : branche
  `claude/issue-845` créée par l'API, une PR, un commit par partie.
- **Lance PHPUnit, PHPStan, et `npm run typecheck` + Vitest si tu touches `public/assets/js/`,
  localement avant chaque poussée.** La CI doit confirmer, pas découvrir.
- **Arme l'auto-merge dès que la PR est prête**, comme le décrit `AGENTS.md` § « Merging a pull
  request », sans demander de confirmation : la PR se fusionne sur `main` dès que la CI est verte. Un
  test rouge arrête le lot : tu corriges, tu ne contournes pas, tu ne désactives rien, tu n'ajoutes
  rien à une baseline.
- Code, commentaires et identifiants en anglais ; interface en français.
- Chaque écran modifié ship son sujet d'aide dans la même PR ; `tests/Core/Help/` échoue sinon.
- Couverture RBAC explicite sur toute route nouvelle ou modifiée.
- Aucune donnée personnelle dans le journal ni dans un message d'erreur.
- Tu avances seul. Tu ne poses une question que sur une **ambiguïté fonctionnelle réelle** ou un
  **changement de conception** non tranché ici.
- Le site est en test : **aucune reprise de données** (voir « Le modèle »), et aucun texte
  d'interface ne mentionne un état antérieur.
- **La PR porte « Corrige #845 »** dans sa description — pas un mot-clé de fermeture GitHub, voir
  `AGENTS.md`. Après la fusion, **vérifie que #845 est fermée** ; si le workflow ne l'a pas fait,
  ferme-la toi-même avec un commentaire qui renvoie à la PR.

---

## La maquette, et ce qu'il ne faut pas toucher

La maquette jointe (`docs/chantiers/maquettes/actualites-brouillon.html`) est **partielle**. Chaque zone
porte une étiquette :

- **NOUVEAU** — à créer ;
- **MODIFIÉ** — seul ce que dit l'étiquette change ; le reste de la zone ne bouge pas ;
- **INCHANGÉ** — ne pas toucher, même quand la zone est abrégée ou seulement nommée dans un encadré en
  pointillé.

**Tout ce qui n'est pas dessiné reste exactement comme aujourd'hui** : dans l'éditeur, les blocs
« Informations générales », « Visibilité », « Article » avec ses onglets et le constructeur de
formulaire, et « Paramètres du formulaire » ; la page publique de l'actualité ; la liste, en dehors
du badge d'état et de la date de chaque carte. La maquette fait autorité sur la hiérarchie, les
libellés et les états, pas sur le style : ses styles sont en ligne pour la rendre autonome, le produit
suit Bootstrap 5 et `design.md`.

---

## Ce qui existe aujourd'hui

- Une actualité (`news_articles`) **n'a aucun statut** : enregistrée, elle est en ligne pour son
  public. Le seul levier est la **visibilité** (`public`, `identified`, `chief`, `admin`,
  `direct_link`). Le contournement actuel : visibilité « chefs » et un préfixe « Brouillon » dans le
  titre.
- Les listes et l'affichage s'appuient sur **`created_at`** : `ArticleRepository::findByVisibilities*`,
  `findLatestByVisibilities` (colonne « Actualités » de la page d'accueil, via
  `Core\Module\HomeNewsProvider`), `findForManager` ; la date affichée sur la liste et sur la page de
  l'article.
- Une actualité est exposée par : `/news`, la page d'accueil, `/news/{id}` (`ArticleService::canView`),
  son **formulaire** (réponses possiblement publiques, `FormController::submit`), l'**écran de scan**
  des billets (`FormRepository`, `issues_ticket = 1`), le bouton **« Partager »**
  (`modules/social`, `ArticleShareAction`, `ArticleShareSourceService`), l'**affiche PDF**
  (`/news/{id}/poster`), le **lien court**, et l'**indexation** (`is_indexed`).
- **Le piège de la couverture** : pour qu'un aperçu de lien se construise, la couverture d'un article
  public, « lien direct » ou « membres connectés » est rendue lisible par tous
  (`ArticleService::coverImageRoleMin()` → `files.role_min = public`), réappliqué à chaque création et
  modification.

---

## Le modèle — **Décidé**

- Deux colonnes sur `news_articles` :
  - **`status ENUM('draft', 'published') NOT NULL DEFAULT 'published'`** ;
  - **`published_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP`**.
- **Les défauts tiennent lieu de reprise de données** : les actualités existantes deviennent
  « Publiée », datées du jour où le schéma est appliqué. Site en test, c'est voulu ; aucune tâche de
  reprise.
- **Une actualité créée par l'éditeur naît en brouillon** : le service écrit `draft` et
  `published_at = NULL`, quel que soit le défaut de la colonne.
- **Trois états lus**, jamais stockés comme tels :
  - **Brouillon** — `status = draft` ;
  - **Programmée** — `status = published` et `published_at` dans le futur ;
  - **Publiée** — `status = published` et `published_at` passé ou présent.
  Une seule méthode du service répond « est-elle en ligne ? » (`isLive()` ou équivalent), utilisée
  partout ; aucune requête ni gabarit ne refait le calcul à sa façon.
- **La date de publication n'est pas modifiable à la main.** Elle est fixée à la **première**
  publication — l'instant du clic, ou l'heure programmée — et ne change plus ensuite, même après un
  passage en brouillon et une republication. Seule une actualité **programmée et pas encore en ligne**
  peut voir son heure changée.
- **La date affichée et le tri** utilisent `published_at` partout où l'on utilisait `created_at` pour
  une actualité publiée : `/news`, la page d'accueil, la page de l'article, la liste de gestion
  (où un brouillon, sans date de publication, se trie par sa création).

---

## Partie 1 — Brouillon et publication

### L'éditeur

Le bouton unique « Enregistrer » (`views/editor.html.twig`) est remplacé selon l'état :

- **Brouillon** : **« Enregistrer le brouillon »** et **« Publier »**. « Publier » enregistre et met en
  ligne, avec une confirmation qui dit **pour qui elle devient visible**, d'après la visibilité choisie :
  « Publier pour tout le monde ? », « … pour les membres connectés ? », « … pour les animateurs ? »,
  « … pour les chefs d'unité ? », « … pour qui a le lien ? » — avec les libellés de visibilité de
  l'éditeur. C'est le moment où la visibilité compte.
  « Programmer… » (partie 2) dit la même chose dans son dialogue.
- **Publiée** : **« Enregistrer »** et **« Repasser en brouillon »**, avec une confirmation : « Elle
  disparaîtra des pages du site jusqu'à sa republication. »
- **Repasser en brouillon une actualité dont le formulaire a déjà reçu des réponses est autorisé.** La
  confirmation l'annonce : « Son formulaire a reçu 12 réponses. Elles sont conservées, mais le
  formulaire sera fermé jusqu'à la republication. » Rien n'est effacé : réponses, créances et billets
  restent tels quels.
- Un **badge d'état** près du titre de la page, le même que dans la liste.

### La suppression

- **Seul un brouillon se supprime.** Une actualité publiée — ou programmée, donc `status = published` —
  doit d'abord repasser en brouillon.
- Le bouton « Supprimer » de l'éditeur et son dialogue (`#news-delete-modal`) n'apparaissent qu'en
  brouillon. Dans les autres états, une ligne discrète à leur place dit : « Pour supprimer cette
  actualité, repassez-la d'abord en brouillon. »
- **La règle est vérifiée côté serveur**, dans `NewsController::delete()` (route `DELETE /news/{id}`) ou
  le service qu'il appelle : une actualité qui n'est pas en brouillon est refusée avec une réponse
  explicite, quel que soit le rôle.
- **Les contrôles de validité existants s'appliquent à tous les boutons**, brouillon compris : un
  brouillon exige déjà son titre, son résumé et son image de couverture, comme aujourd'hui.

### Ce qu'une actualité qui n'est pas en ligne ne fait pas

Pour un brouillon, et pour une actualité programmée pas encore en ligne :

- **Absente** de `/news`, de la colonne de la page d'accueil, du compte total paginé.
- **Sa page `/news/{id}`** ne s'ouvre qu'à **ceux qui gèrent les actualités** (rôle `chief`, celui des
  routes d'édition), **telle qu'elle sera publiée**, avec un bandeau « Brouillon — non publiée » ou
  « Programmée pour le … » et un lien vers l'éditeur : c'est là qu'on la relit avant de la publier.
- **Tout autre visiteur voit un message « pas encore en ligne »**, **visibilité « lien direct »
  comprise** — détenir le lien ne suffit pas avant la publication. Ni titre, ni résumé, ni image, ni
  contenu : seulement « Cette actualité n'est pas encore en ligne. », et, pour une actualité
  programmée, « Elle le sera le 20 octobre à 8 h. » C'est ce qu'on voit en scannant le QR code d'une
  affiche imprimée à l'avance. Statut HTTP de la réponse : 404 avec ce corps, pour que rien ne
  l'indexe.
- **Pas d'aperçu de lien** : aucune balise `og:`/`twitter:`, et `noindex` quoi que dise `is_indexed`.
- **Son formulaire refuse les réponses, pour tout le monde**, gestionnaires compris — y compris le
  chemin de modification d'une réponse. Pas de réponse de test avant la publication : l'aperçu de
  l'éditeur montre déjà le formulaire, et une réponse de test créerait une créance ou un billet pour
  rien. Le refus est vérifié côté serveur, par un contrôle explicite
  de publication dans `FormController`, pas seulement par `canView`.
- **Son événement n'apparaît pas** dans l'écran de scan des billets ni dans le raccourci du menu
  (`NewsMenuHookService`).
- **Pas de bouton « Partager »** (`ArticleShareAction`), et la source de partage
  (`ArticleShareSourceService::describe`) refuse une actualité qui n'est pas en ligne.
- **L'affiche et le QR code restent disponibles, dans tous les états** : le bloc « Affiche & QR code »
  de l'éditeur ne change pas, le lien court est créé à l'enregistrement comme aujourd'hui, et
  `/news/{id}/poster` fonctionne pour un brouillon et une actualité programmée. Préparer l'affiche
  d'une actualité programmée à l'avance est précisément l'usage visé. Le lien court mène à la page de
  l'actualité, qui applique la règle ci-dessus.
- **Sa couverture n'est pas publique, et l'état se décide à la demande de l'image, jamais par une
  tâche** : sinon, entre la mise en ligne d'une actualité programmée et le passage d'une tâche, les
  vignettes de `/news` et l'image de sa page seraient cassées (403) pour son public.
  - `files.role_min` de la couverture suit la seule **visibilité**, comme aujourd'hui
    (`coverImageRoleMin()`), quel que soit l'état ; il n'est plus réécrit à la publication.
  - La couverture porte un propriétaire (`owner_type` / `owner_id` = l'actualité), et le module
    enregistre un `FileOwnershipCheckerInterface` que `FileAccessGuard` consulte à chaque demande :
    il refuse tant que l'actualité n'est pas en ligne (`isLive()`), sauf à ceux qui gèrent les
    actualités, et accepte sinon.
  - Elle s'ouvre donc **à la minute exacte** de la mise en ligne, sans tâche, et se referme d'elle-même
    au retour en brouillon.
  - Les couvertures existantes reçoivent leur propriétaire par le même circuit que le schéma (pas de
    tâche de reprise) ; vérifie que l'indexation (`FileIndexingPolicyInterface`) et l'aperçu de lien
    d'une actualité en ligne restent inchangés.

### Le bloc « Affiche & QR code »

- **Chaque bouton porte une icône et un texte.** Aujourd'hui le bouton de partage, ajouté par le module
  `social` (`ArticleShareAction`), est une icône seule (`iconOnly`). Dans l'éditeur d'une actualité, il
  devient **« Partager »**, avec son icône. L'album de la galerie, qui a sa propre action, n'est pas
  concerné.
- **Tous les boutons du bloc sont dans un seul conteneur** — « Télécharger le PDF » et les actions
  ajoutées par les autres modules — en `d-flex flex-wrap gap-2` : **sur une seule ligne sur ordinateur**,
  et qui passent à la ligne sur téléphone si la place manque. Aujourd'hui, le bouton du PDF et les
  actions sont dans deux blocs, donc toujours sur deux lignes.
- « Partager » reste masqué tant que l'actualité n'est pas en ligne ; l'affiche et le QR code restent
  disponibles dans tous les états.

### Le bloc « Référencement »

- **L'indexation n'est possible que pour une actualité de visibilité « Public ».** Pour toute autre
  visibilité, l'interrupteur « Indexer cette page dans les moteurs de recherche », les mots-clés et la
  date d'arrêt sont **désactivés et grisés**, avec une phrase : « Seule une actualité publique peut être
  indexée. Choisissez « Public » dans la visibilité pour l'activer. » Ils se réactivent dès qu'on
  choisit « Public », sans recharger la page.
- **Une actualité publique en brouillon ou programmée peut avoir l'indexation activée** : le réglage est
  enregistré, et une phrase dit que les moteurs de recherche ne voient la page qu'une fois en ligne
  (la page reste `noindex` jusque-là, voir plus haut).
- **La règle est appliquée côté serveur** dans `ArticleService::enforceSeoRules()`, qui ne refuse
  aujourd'hui que « lien direct » et « membres connectés » : elle n'accepte plus que `public`, et force
  « non indexée », sans mots-clés ni date, pour toute autre visibilité — « animateurs » et « chefs
  d'unité » compris. Son docblock est réécrit en conséquence.

### La liste de gestion

- Les brouillons y sont visibles de **tous ceux qui gèrent les actualités**.
- **Chaque carte porte un badge d'état**, publiée comprise : **« Brouillon »**, **« Programmée le 20
  octobre à 8 h »** ou **« Publiée »**, placé en premier, avant les badges existants de visibilité et de
  formulaire.
- **Ces badges sont pleins et contrastés**, pour se distinguer d'un coup d'œil des badges de visibilité,
  qui restent clairs : texte blanc en gras sur un fond foncé — gris pour Brouillon, bleu pour
  Programmée, vert pour Publiée —, chacun avec un contraste d'au moins 4,5:1. Les trois teintes diffèrent
  aussi en luminosité, pas seulement en couleur. Le badge d'état en tête de l'éditeur utilise les mêmes.
  Réutilise `partials/status_badge.html.twig` si ses variantes le permettent ; sinon ajoute-lui ces
  trois variantes de façon générique.

---

## Partie 2 — La programmation

### L'éditeur

- **Brouillon** : un troisième bouton, **« Programmer… »**, qui ouvre un dialogue — date et heure,
  dans le futur, **à la minute près** — puis enregistre. L'heure se saisit avec le champ natif
  `<input type="time">`, sans pas imposé, comme l'heure des mises à jour automatiques
  (`config/maintenance/mise_a_jour.html.twig`) : roue native sur téléphone, saisie au clavier sur
  ordinateur. **Pas de liste déroulante d'heures.** Le serveur refuse seulement une heure passée.
- **Programmée** : un bandeau « Programmée pour le 20 octobre à 8 h », avec **« Modifier l'heure »**,
  **« Publier maintenant »** et **« Repasser en brouillon »**.

### La mise en ligne

- **L'apparition dans les listes ne dépend d'aucune tâche** : les requêtes filtrent sur
  `published_at <= maintenant`. À l'heure dite, l'actualité est en ligne.
- **Une tâche planifiée** (`publish_scheduled_articles`, déclarée dans `module.json`) fait, pour chaque
  actualité passée en ligne depuis son dernier passage, la seule chose qui ne peut pas se déduire
  d'une requête : **envoyer la notification** à l'auteur. L'accès à la couverture ne dépend pas d'elle
  (voir « Ce qu'une actualité qui n'est pas en ligne ne fait pas »).
  Elle tourne avec le cron du site, chaque minute. Elle ne traite chaque actualité qu'une fois : marque ce qui a été fait (une colonne ou une table,
  selon ce qui est le plus simple), pour qu'un passage manqué ou répété ne doublonne rien. Le léger
  décalage entre l'heure de mise en ligne et le passage de la tâche est acceptable : seule la
  notification en dépend.
- Une actualité qu'on **publie** ou qu'on **publie maintenant** à la main n'attend rien : elle est en
  ligne, couverture comprise, dès l'enregistrement. Elle ne notifie pas : la notification n'a de sens
  que pour une mise en ligne programmée.

### La notification

- **« Actualité en ligne »** (`news.article_published`), nouvelle notification du module : « Votre
  actualité *titre* est en ligne », avec le lien vers sa page. Destinataire : **son auteur**
  (`created_by`). Application et push activés par défaut, e-mail désactivé, réglable comme les
  autres.

---

## Tests

États lus (brouillon, programmée, publiée) et méthode unique ; création en brouillon ; défauts des
colonnes ; mêmes contrôles de validité pour « Enregistrer le brouillon », « Publier » et
« Programmer » ; date fixée à
la première publication et conservée après un retour en brouillon ; heure programmée à la minute près,
modifiable avant la mise en ligne seulement ; absence des listes, de la page d'accueil et du total ; page refusée hors
gestionnaires, lien direct compris ; aucune balise d'aperçu et `noindex` ; réponses de formulaire
refusées pour tous ; événement absent du scan et du menu ; partage refusé ; affiche et lien court
disponibles dans tous les états ; message « pas encore en ligne » pour un visiteur, avec la date pour
une actualité programmée et sans aucun contenu de l'actualité ;
couverture refusée en brouillon et avant l'heure programmée, servie dès la minute de mise en ligne
sans aucune tâche, refusée de nouveau au retour en brouillon, toujours servie aux gestionnaires ;
apparition à l'heure dite sans tâche ; tâche qui notifie une seule fois ;
publication manuelle immédiate sans notification ; tri et date affichée par `published_at` ;
suppression d'un brouillon acceptée, d'une actualité publiée ou programmée refusée côté serveur et
absente de l'éditeur ; bouton « Partager » avec son texte, et boutons du bloc affiche dans un seul
conteneur ; référencement désactivé et forcé côté serveur hors visibilité publique, actif pour une
actualité publique en brouillon ; confirmation de publication selon chaque visibilité ; retour en brouillon
avec réponses existantes, annoncé et sans perte ; droits d'accès des routes nouvelles.

---

## Écarté, explicitement

- **Modifier à la main la date de publication** d'une actualité déjà publiée.
- **Une reprise des actualités existantes** au-delà des défauts de colonne.
- **Ouvrir un brouillon à qui que ce soit d'autre que ceux qui gèrent les actualités**, lien direct
  compris.
- **Accepter des réponses de formulaire** sur une actualité qui n'est pas en ligne.
- **Notifier d'autres personnes que l'auteur**, ou notifier une publication manuelle.
- **Dépublier automatiquement** à une date de fin : non demandé.
- **Supprimer une actualité publiée ou programmée** sans la repasser d'abord en brouillon.
