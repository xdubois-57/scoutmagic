# Chantier — Partage vers Facebook et Instagram

Roadmap d'exécution en **5 itérations**, pour un nouveau module. IT-01 et IT-02 ne se touchent
pas : elles peuvent être menées en parallèle. IT-03 dépend des deux. IT-04 et IT-05 dépendent
d'IT-03 et sont indépendantes l'une de l'autre : elles aussi peuvent être menées en parallèle.

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `specifications.md`, `design.md` et
`docs/module-development.md`. Ils priment sur ce fichier sur toute règle générale ; si tu
découvres qu'ils décrivent une réalité que le code contredit, **mets-les à jour dans la même PR**.

- Une itération = une branche, une PR. Rebase sur `main` avant de merger.
- **Merge et push sur `main` dès que la CI complète est verte** (auto-merge armé :
  `gh pr merge <n> --squash --auto`). Un test rouge arrête le chantier : tu corriges, tu ne
  contournes pas, tu ne désactives rien, tu n'ajoutes rien à une baseline.
- Tests obligatoires : PHPUnit, PHPStan, et `npm run typecheck` + Vitest dès que tu touches
  `public/assets/js/`. Couverture RBAC explicite sur chaque frontière de rôle touchée.
- **Toute édition du `schema.sql` du module impose de bumper `version` dans son `module.json`,
  dans le même changement.** Sans le bump, la table n'est créée que sur une activation neuve.
- Code, commentaires, identifiants et noms de colonnes en anglais ; interface en français.
- Chaque écran ajouté ship son sujet d'aide dans la même PR. `tests/Core/Help/` échoue sinon.
- **Aucun secret, aucun jeton, aucune donnée personnelle dans le journal, les messages d'erreur ou
  les traces.**
- Tu avances seul. Tu ne poses une question que sur une **ambiguïté fonctionnelle réelle** ou un
  **changement de conception** non tranché ici.
- Un problème réel que tu décides de ne pas corriger devient une issue GitHub, jamais un
  commentaire de PR.

---

## Le besoin

Un bouton de partage, en un clic, depuis trois endroits — un album de la galerie, une actualité,
une communication libre — vers trois sortes de destinations : la **Page Facebook** de l'unité, son
**compte Instagram**, et l'un des **groupes de discussion du site** lui-même.

Les deux premières sont publiques et hors du site ; la troisième est interne et privée. Presque
toutes les contraintes de ce chantier viennent des deux premières.

---

## Ce que l'API de Meta permet, et ce qu'elle interdit — **établi, ne pas ré-explorer**

**Les groupes Facebook sont hors de portée.** Meta a annoncé la dépréciation de l'API Groupes le
23 janvier 2024 avec Graph v19.0 et l'a retirée de **toutes** les versions le 22 avril 2024, avec
les permissions `publish_to_groups` et `groups_access_member_info`. À la v26.0 (juillet 2026), rien
n'a été rétabli. Aucun outil ne publie plus dans un groupe — ni Buffer, ni Hootsuite, ni Zapier, ni
le Business Suite de Meta. **Ne cherche pas de contournement.** Toute solution passant par une
session de navigateur ou une extension est hors sujet et hors charte.

**Instagram ne publie pas de lien.** Une URL en légende s'affiche en texte brut ; seul le lien de
la bio est cliquable, et il n'est ni publiable ni modifiable par l'API. Les autocollants de lien
en story sont explicitement exclus de l'API, comme tous les éléments interactifs. C'est pourquoi
**l'adresse du site est incrustée sur l'image**.

**Instagram ne reçoit pas de fichier.** Meta va chercher le média lui-même : il doit être hébergé
sur un serveur publiquement accessible au moment de la tentative. D'où la route éphémère d'IT-02.

**Compte professionnel obligatoire** côté Instagram (Business ou Creator) : un compte personnel n'a
aucun accès à l'API de publication.

**Pas de revue Meta** tant que seuls des comptes ayant un rôle sur l'application s'y connectent.
Une unité crée sa propre application Meta, en mode développement, et s'y ajoute : aucune revue,
aucune vérification d'entreprise, aucune attente.

---

## Les décisions verrouillées

### Un module, pas le cœur — **Décidé**

Service externe, identifiants, publication vers l'extérieur, écrans dédiés : rien de tout ça n'est
du cœur. Une unité qui ne veut rien publier sur Meta désactive le module. Et le jour où Meta ferme
une API de plus, la casse est circonscrite.

Le module publie son interface sous son propre espace `Api`. **La galerie et les actualités
déclarent une dépendance facultative vers cette interface** et dégradent proprement quand elle est
`null` : pas de bouton « Partager », et rien d'autre ne change (§7.5 d'`ARCHITECTURE.md`, même
patron que `llm_connector`, déjà éprouvé deux fois).

Le générateur de carte reste **dans le module** pour cette fois, bien qu'il soit au fond un
utilitaire d'image générique de la même famille que les processeurs GD du cœur : il n'a qu'un seul
consommateur. Si un second apparaît, il remonte.

### L'image est toujours obligatoire — **Décidé**

Aucune publication sans image, sur aucune des deux destinations.

### Le flou des images de galerie — **Décidé**

**Toute image venant de la galerie est floutée avant publication, sans exception et sans
interrupteur.** Flou de l'image entière, pas des visages : la détection de visages demanderait une
bibliothèque de vision que la politique de dépendances n'absorberait pas, et un algorithme qui rate
un visage est pire que pas d'algorithme du tout.

- Le flou est appliqué **côté serveur**, à la fabrication de la carte. Aucun réglage par
  publication, aucune case pour le désactiver.
- Un **seul niveau**, le même partout, **calibré pendant IT-02** en générant des cartes à partir de
  vraies photos à trois ou quatre intensités. Pas de valeur choisie au jugé.
- Ce niveau est un `SettingService` **en lecture seule** (`editable = false`, comme
  `pwa_icon_version`), vivant sur la page Paramètres avec les autres, **pas** sur l'écran de
  configuration du module.
- Il s'exprime **en proportion du petit côté de l'image**, jamais en pixels : un rayon absolu laisse
  une photo de 4000 px à peine voilée et rend illisible une de 800 px.
- Les vignettes du sélecteur sont **nettes** : le chef a le droit de voir ces photos, c'est même à
  ça qu'il les choisit. Le flou est un traitement de publication, pas un filtre d'affichage.
- **Le flou ne s'applique qu'aux destinations publiques.** Vers un groupe de discussion, la photo
  part nette : le groupe est privé, ses membres ont déjà accès à la galerie, et flouter une image
  pour des gens qui peuvent la voir en clair à deux clics de là n'est pas une protection, c'est une
  gêne. La règle reste sans exception là où elle a un sens — tout ce qui sort du site.

### La carte publiée — **Décidé**

Une image composée : l'image de fond (floutée si elle vient de la galerie, nette si elle a été
téléversée), un titre incrusté, et l'adresse du site en pied.

- Pour un album : la photo de couverture et le nom de l'album.
- Pour une communication libre : l'image choisie et le titre saisi.
- **L'adresse sur l'image est la seule chose qui survit à l'interdiction des liens Instagram**, et
  elle reste attachée à l'image si quelqu'un la republie ailleurs.
- Incruster du texte demande à GD une police TrueType, et **le dépôt n'en embarque aucune** — pas un
  seul `.ttf`, aucun appel à `imagettftext`. Il faut en livrer une avec le module, sous licence
  permissive, la licence versionnée à côté.

### La route publique éphémère — **Décidé**

Meta doit pouvoir télécharger la carte. Une photo de galerie est servie par `/files/{id}` derrière
`FileAccessGuard` : ses serveurs ne peuvent pas l'atteindre.

Donc : une route publique servant **la carte générée**, jamais les octets d'un original, avec un
jeton non devinable, une durée de vie courte (de l'ordre de l'heure), et chaque accès journalisé.

C'est une **seconde exception documentée** à la règle « tout téléchargement passe par `/files/{id}`
sous `FileAccessGuard` » — la première étant `GET /api/offline/photo/{member_id}` (`SECURITY.md`
§6). **Écris-la dans `SECURITY.md`** : son périmètre, pourquoi elle est inévitable, et pourquoi elle
est étroite. Une exception non écrite devient un précédent.

### Les identifiants et les jetons — **Décidé**

- Application Meta **par installation**, en mode développement, créée par l'unité. Identifiants
  saisis dans l'écran de configuration du module, rangés dans `secrets.enc` — jamais dans
  `settings`, qu'un superadmin lit sur la page Paramètres.
- **Jeton de Page Facebook** : obtenu depuis un jeton utilisateur longue durée, il ne porte pas
  d'expiration ; stocké tel quel.
- **Jeton Instagram** : longue durée, 60 jours, renouvelable par appel serveur dès qu'il a plus de
  24 h et n'a pas expiré ; il repart alors pour 60 jours. Une **tâche planifiée** le renouvelle bien
  avant l'échéance, jamais le jour même.
- Un jeton non rafraîchi pendant 60 jours expire définitivement : reconnexion manuelle. Le cron
  étant obligatoire et surveillé depuis l'installation, le risque se limite à un site réellement à
  l'arrêt deux mois, ou à une révocation côté Facebook.
- L'écran de configuration ne montre que ce qui se manipule : les deux connexions, leur état, et les
  boutons **Tester la connexion** et **Reconnecter**.

### Une publication, une seule fois — **Décidé**

**Un contenu ne part qu'une fois vers une destination donnée.** La clé est la paire
*contenu + destination*, jamais le contenu seul — sinon relancer l'Instagram tombé republierait sur
Facebook et créerait le doublon qu'on veut éviter. **Chaque groupe de discussion est une
destination à part entière** : le même album peut donc partir dans deux groupes différents, mais
une seule fois dans chacun.

- Une destination déjà publiée apparaît **cochée-grisée avec sa date** dans le dialogue de partage.
- Seule une destination **en échec** peut être relancée, et le réessai **demande confirmation** :
  celle-ci dit ce qui repart, rappelle que la destination déjà publiée n'est pas touchée, et
  rappelle la raison de l'échec précédent.
- Conséquence assumée : **un album qui reçoit de nouvelles photos ne peut plus être repartagé.**
  C'est voulu.

### La confirmation avant publication — **Décidé**

Elle montre **la carte générée**, flou compris, titre compris, adresse comprise — pas la photo
source. Faire valider une chose et en publier une autre est la seule faute vraiment grave possible
sur cet écran. Elle dit aussi, en toutes lettres, que c'est public, hors du site, et que personne ne
pourra le reprendre depuis ScoutMagic.

### Les icônes et la marque — **Décidé**

Icône de partage universelle sur les boutons d'action ; un glyphe par plateforme à côté de chaque
destination, repris dans l'historique. **Embarque les ressources de marque officielles de Meta**, en
respectant ses règles d'usage — pas des logos redessinés, juridiquement bancals et visuellement
faux.

---

## IT-01 — Le connecteur

Rien ne publie encore. Livrable et testable seule.

- Le squelette du module : `module.json`, `schema.sql`, l'écran de configuration.
- La connexion à la Page Facebook et au compte Instagram professionnel, les jetons chiffrés dans
  `secrets.enc`, la tâche planifiée de renouvellement, les boutons Tester et Reconnecter.
- Le client HTTP sortant : même approche `file_get_contents()`/`stream_context_create()` que
  `Modules\LlmConnector\Provider\AnthropicProvider`, le seul précédent d'appel sortant du dépôt.
  **Aucune dépendance Composer nouvelle.**
- L'interface publique sous `Api`, encore sans consommateur.
- La journalisation : connexion, reconnexion, renouvellement, échec d'authentification.

**La page RGPD** : `AGENTS.md` impose de reprendre `RgpdContentService::getDefaultContent()` quand
une intégration externe apparaît. Meta devient un sous-traitant dès cette itération.

---

## IT-02 — Le générateur de carte et la route éphémère

Indépendante d'IT-01 : elles peuvent tourner en parallèle.

- Le processeur GD : flou plancher proportionnel, incrustation du titre, adresse en pied, cadrage
  aux proportions acceptées par Instagram. Même précédent que `PwaIconProcessor`,
  `SectionPhotoProcessor` et `StaffThumbnailProcessor`.
- La police livrée, sa licence versionnée à côté.
- **Le calibrage du flou**, comme décrit plus haut, et la valeur retenue devient la valeur par
  défaut du réglage en lecture seule.
- La route publique éphémère à jeton, et sa section dans `SECURITY.md`.
- Les tests : une carte générée depuis une image de galerie est floutée quoi qu'il arrive ; le jeton
  expire ; une carte n'est jamais servie après expiration.

---

## IT-03 — Le partage depuis les actualités et les albums

Dépend d'IT-01 et d'IT-02.

- Le bouton « Partager » sur un album et sur une actualité, via la dépendance facultative.
- Le dialogue de confirmation montrant la carte réelle, les destinations avec leur état, et la
  mention d'irréversibilité. Une destination déjà publiée y apparaît cochée-grisée avec sa date.
- **Le bouton de publication garde son mot**, pas seulement son icône : l'action est publique et
  irréversible, et une icône muette ne se devine pas. Les actions secondaires, elles, sont des
  boutons-icônes de 44 px avec libellé accessible.
- La publication elle-même : **côté Facebook, une actualité part en publication de lien**, avec sa
  prévisualisation Open Graph — le module Actualités émet déjà ses balises et son `og_image_url`
  pointe sur un `/files/{id}` public par construction. Un album et une communication libre partent
  en publication d'image. **Côté Instagram, toujours une image**, jamais un lien.
- L'enregistrement par destination, et la règle « une seule fois » appliquée côté serveur, pas
  seulement dans l'interface.

---

## IT-04 — L'écran Communications

Dépend d'IT-03. Indépendante d'IT-05.

### La page « Nouvelle communication »

Une **vraie page**, pas un dialogue, dans l'espace chefs, dans cet ordre :

1. **L'image en grand**, telle qu'elle sera publiée — c'est elle qu'on juge.
2. **Deux boutons sur une seule ligne**, à parts égales : « Galerie » et « Téléverser », avec sous
   eux la phrase qui dit ce qui change entre les deux, flou compris.
3. **Le titre incrusté** — juste après l'image, parce qu'il modifie l'image, et non groupé avec le
   texte, ce qui laisserait croire que ce sont deux champs du même message.
4. **Le texte de la publication.**
5. **Les destinations**, puis le bouton de publication.

### Le sélecteur de photo

- **Trente photos au maximum, sans navigation** : les photos de couverture des **15 albums les plus
  récents**, quelle que soit leur année, complétées par les **photos les plus récentes hors
  couverture** jusqu'à trente, le tout **trié par date décroissante**.
- **Albums locaux seulement** : un album `external` n'héberge aucun média, il n'y a rien à y choisir.
- **Photos seulement** : une vidéo est un reel, avec ses propres contraintes, et flouter une vidéo
  est un autre travail.
- **Sélection unique** : une carte porte une image. Le carrousel est une autre fonctionnalité.
- **La visibilité n'est pas recalculée ici** : le sélecteur réutilise la résolution du module
  Galerie telle quelle, albums délégués compris. Une seconde implémentation de « ce que ce chef peut
  voir » finirait par diverger, et c'est toujours celle-là qui laisse passer ce qu'elle ne devrait
  pas.
- Vignettes en vrais `<button>` avec libellé accessible : une grille non tabulable est inutilisable
  sans souris.
- Pas de champ de recherche : des photos sans titre ni description ne se cherchent pas.

### L'historique « Ce qui est parti »

- **Un statut par destination**, pas un statut global : Facebook peut être passé et Instagram non.
  Chaque ligne porte son état, son heure, et une icône vers la publication.
- Trois états : publié, échec (avec sa raison, conservée), et **non demandé** — la destination n'a
  jamais été cochée, ce n'est ni un succès ni un échec.
- **« Voir » et « Réessayer » sont des icônes**, à la même place en bout de ligne, alignées en
  colonne ; la ligne « non demandé » garde une case vide de même largeur pour que rien ne se décale.
- Un échec reste visible. Une publication ratée qu'on ne voit nulle part est une publication qu'on
  croit faite.

---

## IT-05 — Le groupe de discussion comme destination

Dépend d'IT-03, indépendante d'IT-04 : elles peuvent être menées en parallèle.

### Ce que cette destination change — **Décidé**

Elle est **interne**. Aucune des contraintes de Meta ne s'applique : pas d'URL publique à exposer,
pas de jeton à renouveler, pas d'interdiction de lien.

- **Pas de carte générée.** Le module `groups` porte nativement des médias et des liens sur ses
  publications (`discussion_group_post_media`, `discussion_group_post_links`) : la photo part telle
  quelle, en média de publication.
- **Un vrai lien cliquable** vers l'album ou l'actualité, ce qui vaut mieux qu'une adresse écrite
  sur une image.
- **La photo n'est pas floutée**, pour la raison donnée plus haut.

### Ce qu'il faut construire

- **`groups` n'a pas encore d'espace `Api`.** Il lui en faut un, exposant de quoi créer une
  publication avec média et lien, et de quoi lister les groupes dans lesquels le demandeur a le
  droit de publier. Le module social le consomme en dépendance **facultative** : module
  `groups` absent ou désactivé, la destination disparaît simplement du dialogue de partage.
- **Plusieurs groupes à la fois.** Chacun reçoit **sa propre publication** et compte pour une
  destination à part entière : sa propre ligne d'historique, son propre statut, et la règle « une
  seule fois » appliquée groupe par groupe.
- **Le choix passe par un dialogue**, jamais par une liste posée sur l'écran de partage : cases à
  cocher, nom du groupe et nombre de membres — c'est ce qui distingue « Staff Lutins » de « Staff
  Pionniers » sans deviner. Le dialogue dit avant le clic que chaque groupe reçoit sa propre
  publication, avec ses propres règles de modération.
- **Sur l'écran de partage, deux lignes seulement** : la case « Groupe de discussion », puis les
  groupes retenus énumérés en clair avec un **bouton-icône** (crayon, 44 px, libellé accessible)
  qui rouvre le dialogue. Le même motif sur le partage d'album et sur la communication libre.
- Seuls les groupes où l'auteur peut publier apparaissent. **La résolution vient de `groups`,
  jamais recalculée ici** — même principe que pour la visibilité des photos.
- L'enregistrement dans l'historique, au même titre que Facebook et Instagram : un statut, une
  heure, un lien vers la publication, **une ligne par groupe**.

### Ce qui ne change pas

La publication reste soumise aux règles propres du module `groups` — limitation de débit,
signalement, modération. Le partage crée une publication ordinaire, il ne se glisse pas sous ces
règles.

---

## Écarté, explicitement

- **Publier dans un groupe Facebook**, par quelque moyen que ce soit.
- **Toute approche par session de navigateur ou extension.**
- **Flouter les visages** plutôt que l'image entière.
- **Un interrupteur pour désactiver le flou**, ou un réglage par publication.
- **Un réglage de flou modifiable** depuis l'interface.
- **Partager une vidéo**, un carrousel, ou plusieurs photos à la fois.
- **Repartager un contenu déjà publié** sur une destination où il est passé.
- **Naviguer dans les albums** depuis le sélecteur de photo.
- **La revue Meta et la vérification d'entreprise** : le mode développement les évite.
- **Flouter une photo partagée dans un groupe de discussion.**
- **Contourner les règles propres du module `groups`** — débit, signalement, modération — au
  prétexte que la publication vient d'un partage.
