# Chantier — Pages de texte

Journal d'implémentation du document de chantier « Pages de texte »
(itérations IT-01 et IT-02, issue #368). Une section par itération : ce
qui a été livré, les décisions prises en autonomie, les divergences
constatées entre le document de chantier et le dépôt réel, et ce qui a
été reporté. Même format que `docs/chantiers/courrier-sortant.md`.

La roadmap elle-même est déposée sans modification sous
`docs/chantiers/CHANTIER-pages-de-texte.md` (PR #396). Elle n'a pas été
réécrite en cours de route : les écarts entre ce qu'elle décrit et ce que
le dépôt contient réellement sont consignés ici.

---

## Écarté, explicitement — recopié du document de chantier

Pour que ça ne revienne pas par accident :

- **Les gabarits de page** — évoqués dans l'issue, écartés par le
  demandeur : « oublie les gabarits, gardons les choses simples ».
- **Un champ de niveau d'accès distinct de la section du menu.**
- **Un slug modifiable après création.**
- **Une page à la racine du site** (`/asbl` plutôt que `/pages/asbl`).
- **La mise en cache hors ligne des pages publiques** — les pages ne
  rejoignent pas `Core\Offline\OfflineWhitelist`.
- **Un avertissement expliquant qu'une page masquée renvoie 404, et un
  encart expliquant la structure de l'adresse.**

---

## IT-01 — Le modèle, la route, la page rendue

**Livré.**

- `schema/core.sql` : la table `text_pages` — identifiant, slug unique,
  nom de menu, titre, section, colonne (nullable), ordre, actif,
  horodatages. Pas de colonne de contenu et pas de colonne de rôle, pour
  les raisons écrites dans le commentaire de la table.
- `Core\Page\` : `TextPage` (objet immuable, qui porte `path()`,
  `contentKey()` et `roleMin()`), `TextPageRepository` (seule couche à
  toucher PDO), `TextPageService` (les règles), `TextPageException`,
  `TextPageRouteRegistrar` (l'enregistrement des routes au démarrage) et
  `TextPageMenuProvider` (les entrées de menu).
- `Core\Http\Controller\TextPageController` et
  `core/View/templates/pages/text_page.html.twig` : fil d'Ariane, titre,
  `editable()`. **Aucun contrôle de rôle dans le contrôleur**, et un test
  qui le vérifie en lisant le source.
- `Core\View\MenuBuilder::roleMinFor()` et `::menuIds()` — voir les
  décisions ci-dessous.
- Le câblage dans `public/index.php` : le service à côté des autres
  services cœur, l'enregistrement des routes à la fin du bloc de routes
  cœur, les entrées de menu juste après le calcul du surlignage, et le
  contrôleur pré-construit.
- `ARCHITECTURE.md` §8.115.
- 54 tests dans `tests/Core/Page/`, plus la table dans
  `tests/DatabaseTestHelper.php`.

### Décisions prises seul

**`MenuBuilder::roleMinFor()` a dû être créé.** Le chantier dit que le
niveau d'accès dérive de la section, et que les cinq menus portent déjà
leur plancher. C'est vrai — mais `MenuBuilder::MENUS` est `private`, et
aucun accesseur public ne rendait ce plancher. `labelFor()` rend le
libellé, `groupIdsFor()` rend les colonnes, rien ne rendait le rôle.
Recopier les cinq planchers à côté du service des pages aurait été
exactement la « deuxième vérité » que le chantier interdit, avec la
particularité que la copie qui dérive est celle qui garde une route. Un
accesseur a donc été ajouté, sur le modèle exact des deux autres.

Il répond `superadmin` — et non `public` — pour une section inconnue :
un appelant qui a perdu le fil doit se retrouver avec l'audience la plus
étroite, jamais la plus large. `labelFor()` répond `''` pour la même
entrée parce qu'un libellé manquant est un défaut cosmétique et qu'un
plancher manquant est une porte ouverte. C'est écrit dans son docblock.

**Le slug se lit dans le chemin, pas dans `$params`.** Les routes que ces
pages enregistrent sont concrètes — une par page, chacune avec son propre
`role_min` — donc elles ne portent aucun paramètre nommé et
`Router::matchPath()` rend un tableau vide. Le contrôleur relit donc le
slug derrière `/pages/`. C'est la conséquence directe de la décision
verrouillée, et elle est documentée à l'endroit où elle surprend.

**Une seule requête, et la liste circule.** Le chantier exige « une seule
requête, pas une par menu ni une par page ». `TextPageRouteRegistrar`
rend la liste qu'il a lue, et c'est cette liste — pas une seconde
requête — que `TextPageMenuProvider` reçoit. Effet de bord voulu : une
page ne peut pas apparaître dans un menu sans route derrière elle, ni
l'inverse.

**`MenuEntryProvider` plutôt qu'une boucle `addPage()`.** Le chantier
nomme le mécanisme ; le détail qui le justifie est que
`DynamicMenuRegistrar` résout déjà le rafraîchissement du surlignage de
la page active quand des entrées arrivent après la première construction
du menu. Une boucle directe aurait dû le refaire.

**L'ordre des pages dans leur menu** commence à 600, après les pages du
cœur : ce sont des ajouts à un menu que quelqu'un a composé, elles
suivent ce que le site a livré plutôt que de s'y intercaler.

**La colonne préremplie** suit le chantier (« Pages » pour Espace
membres, « Contenu du site » pour Espace chefs d'U) et retombe sur la
première colonne déclarée du menu si aucune des trois préférées n'existe
— pour qu'un renommage des colonnes n'ouvre jamais le formulaire sur un
choix invalide. Un test vérifie que chaque valeur proposée passe le vrai
validateur.

**Un titre qui ne donne aucun slug** — « ?!… », ou un alphabet que le
repli d'accents ne couvre pas — retombe sur `page`, suffixé comme les
autres. Sans ce repli la page serait à `/pages/`, ce qui n'est pas une
adresse.

### Écarts entre le document et le dépôt

**§8.114 était pris.** Le chantier demande de documenter les routes
enregistrées au démarrage dans `ARCHITECTURE.md` §8 ; la première section
libre est **§8.115**, §8.114 étant déjà `Core\Template`. Toutes les
références dans le code pointent §8.115.

**Le garde « pas de base, pas de route » est moins exposé que le chantier
ne le suppose, et il a été écrit quand même.** Dans `public/index.php`,
la branche « site non initialisé » sort vers `/setup` autour de la ligne
250, et l'auto-migration sort vers la page de progression autour de la
ligne 396 — les deux **avant** la déclaration des routes (ligne 3722 et
suivantes). L'enregistrement tourne donc normalement avec la table
présente. Ça ne retire rien au besoin : une connexion qui tombe après
l'installation — identifiants tournés, serveur injoignable, restauration
en cours — doit laisser le site répondre, y compris sur la page qui
explique la panne. Le garde ne s'appuie donc pas sur cet ordonnancement,
et deux tests le tiennent : base injoignable, et table absente.

Rien n'est journalisé dans ce `catch` : le journal vit dans la base qui
vient de ne pas répondre, et une seconde écriture qui échoue
transformerait une requête dégradée en requête cassée.

**`specifications.md` se contredit sur le rôle du menu Configuration** :
le titre §3.5 dit « Configuration (admin) » alors que §4.5 du même
fichier, `ARCHITECTURE.md` §3 et `MenuBuilder::MENUS` disent tous
`superadmin`. Constaté, pas corrigé ici : `specifications.md` est repris
en IT-02, qui est l'itération où cette section est de toute façon
réécrite.

**`docs/chantiers/README.md` n'existe pas.** Le journal de
`courrier-sortant.md` parle d'un « tableau du `README.md` de ce dossier » ;
le seul README est `docs/chantiers/maquettes/README.md`, et il ne recense
que les maquettes. Ce chantier n'en a pas, donc rien à y inscrire.

### Ce que les tests du dépôt ont trouvé

Trois garde-fous existants ont refusé la première version, et les trois
avaient raison.

**`Tests\Architecture\AccentFoldingTest`** a refusé
`iconv('ASCII//TRANSLIT')` dans la génération du slug. Ce n'est pas une
question de style : la sortie d'iconv dépend de la bibliothèque C de
l'hôte, donc « Bulle arrêtée » donne `bulle-arretee` sous glibc et
`bulle-arr-etee` sous la libiconv de musl ou de macOS. Une adresse est
gelée à la création et partagée aussitôt — un slug qui dépend de la
machine qui l'a créé est un lien qui casse le jour où l'unité change
d'hébergeur. Remplacé par `Core\Service\TextNormalizerService::fold()`,
qui applique une table explicite sur toutes les plateformes et fait déjà
la minuscule et la réduction des séparateurs.

**`Tests\Core\Exception\UserFacingExceptionInventoryTest`** a exigé que
`TextPageException` soit classée. Elle est marquée
`Core\Exception\UserFacingException` : ses huit messages sont des phrases
françaises entières qui ne nomment que ce que la personne voit à l'écran,
et aucun n'est construit à partir du `getMessage()` d'une autre exception
— le blanchiment contre lequel le docblock du marqueur met en garde.

**`Tests\Core\Database\SqlParserTest`** compte les tables de
`schema/core.sql` : 63 → 64, et la nouvelle est nommée explicitement dans
l'assertion.

### Ce que la relecture a trouvé, et qui n'était pas dans le chantier

Quatre constats, tous justes. Le premier est une **élévation de
privilège que cette itération introduisait**, et le chantier ne pouvait
pas l'anticiper : elle naît de la rencontre entre une décision
verrouillée (le niveau d'accès dérive de la section, Configuration
comprise) et un mécanisme qu'il fallait réutiliser tel quel
(`editable()`).

**Un `admin` pouvait écrire le corps d'une page que seul un `superadmin`
peut lire.** `POST /api/editable-content` est `role_min: admin`, sans
autorisation par clé. Une page classée dans le menu Configuration se lit
en `superadmin`, mais son texte s'écrit par ce même point d'entrée sous
la clé `page_content_{id}` — un identifiant petit, séquentiel et
devinable. Un chef d'unité correctement refusé sur `GET /pages/{slug}`
pouvait donc réécrire ce qu'un superadmin lit.

Le relecteur a mis le doigt sur ce qui rend ça invisible : **c'est le
premier contenu du site dont le plancher de lecture dépasse le plancher
d'écriture.** Tous les appels d'`editable()` antérieurs vivent sur une
page lisible à `admin` ou en dessous — accueil, contact, sections, vue
publique d'un module — donc « écrivable par un admin » et « lisible par
un admin » coïncidaient, et un seul plancher suffisait.

Corrigé par `Core\View\EditableContentAuthorizer`, la revérification par
clé que `SECURITY.md` §3 réclame en toutes lettres (« `role_min` is a
floor, never the whole answer »), et `Core\Page\
TextPageContentAuthorizer` qui répond le `roleMin()` de la page
elle-même. Un authorizer répond `null` pour les clés qu'il ne reconnaît
pas : il ne peut donc que restreindre, jamais élargir. Les deux points
d'entrée sont gardés, y compris `/api/rich-text-content` que les pages
n'utilisent pas — la clé est un espace de noms partagé.

Deux choix dans ce correctif méritent d'être dits. Une page **masquée**
garde le rôle de sa section : l'éteindre ne doit pas confier son texte à
un public plus large que la page. Et une clé de la bonne forme désignant
une page inexistante est **refusée** plutôt qu'ignorée — répondre `null`
la rendrait au plancher du point d'entrée et laisserait créer une ligne
de contenu que plus rien ne peut nommer.

**Le bloc de menu n'avait pas de garde, contrairement à son jumeau.**
`MenuBuilder::addPage()` lève une exception sur une colonne que son menu
ne déclare pas, et `MENU_GROUPS` est une constante PHP : la validité est
celle du moment de l'écriture. Renommer une colonne dans une version
ultérieure orpheline les lignes qui la nommaient — cas que le docblock
de `defaultGroupFor()` anticipe lui-même — et l'exception tomberait
pendant la construction du menu de **toutes** les pages du site, avant le
routage, à chaque requête.

`TextPageMenuProvider` filtre donc les placements devenus invalides.
Filtrer plutôt qu'entourer d'un `catch` est délibéré : un `catch` aurait
fait disparaître l'entrée de menu de **toutes** les pages parce qu'une
ligne a vieilli. Là, une ligne périmée coûte son entrée de menu ; la page
garde sa route et reste joignable par son adresse.

**Quatre tours de relecture sur une même faille, et ce qu'ils ont appris.**
Le premier constat nommait une porte ; les trois suivants ont montré que je
corrigeais la mauvaise chose. Les variantes, dans l'ordre :

| Tour | Orthographe qui passait | Pourquoi |
|---|---|---|
| 2 | `Page_Content_7`, `pagé_content_7` | motif sensible à la casse, collation `_ci`/`_ai` |
| 2 | `POST /upload` `context=editable_image` | seconde porte, non gardée |
| 3 | `page_content_７` (chiffre pleine chasse) | `FORM_D` canonique ≠ compatibilité → chiffre supprimé |
| 4 | `ｐage_content_7` (lettre dans le mot) | même cause, position que mes tests ne couvraient pas |

**La cause commune, et le vrai correctif.** Je dérivais une décision
d'autorisation en **analysant une chaîne contrôlée par l'appelant**, et mon
analyseur devait reproduire exactement ce que `utf8mb4_unicode_ci`
considère égal — casse, accents, espaces de fin (PAD SPACE), formes pleine
chasse, et tout caractère ignorable au premier niveau. Deux normaliseurs
différents finissent toujours par diverger. Quatre correctifs successifs
n'ont fait que déplacer l'endroit où.

La conception finale supprime l'analyse. **`editable_contents.text_page_id`
dit à quelle page appartient une ligne**, et
`EditableContentService::set()` — le point de passage unique — y lit le
rôle exigé. La question devient : « sur quelle ligne cette écriture
va-t-elle atterrir ? », à laquelle la base répond d'autorité, avec le même
`WHERE content_key = ?` que l'écriture elle-même utilisera.

Le test qui montre que c'est la bonne forme : un attaquant envoie
`ｐage_content_7`. Si la collation l'équivaut, `ownerPageIdForKey()` rend la
ligne de la page et le garde s'applique ; sinon il n'y a pas de ligne, et
l'écriture crée une orpheline qu'aucune page n'affiche. **Le garde est
correct sans que j'aie besoin de savoir laquelle des deux est vraie** —
c'est précisément ce qui manquait aux quatre tentatives.

Trois bénéfices tombent de la colonne plutôt que du code. La ligne de
contenu est **réclamée avec la page**, vide et possédée, donc il n'existe
jamais d'instant où la clé n'est réclamée par personne et où le premier à
écrire déciderait de ce que dit une page qu'il ne peut pas lire. La
suppression passe par `ON DELETE CASCADE` — une seconde suppression émise
par un service peut échouer seule et laisser du texte que plus rien ne
nomme ; une contrainte ne le peut pas. Et
`TextPageContentAuthorizer` retombe à une vingtaine de lignes : plus de
repli, plus de `FORM_KD`, plus de balayage caractère par caractère.

**Ce que la relecture a aussi corrigé côté portes.** `POST /upload` avec
`context=editable_image` écrit la même table sous une clé choisie par le
client, via `PhotoIngestionService`, sans passer par
`EditableContentController` ; son autorisation était
`ConfigurationMode::isActive()` seul, c'est-à-dire `admin`. Cette porte
n'avait aucune conséquence avant cette itération. Les deux portes
interrogent maintenant le service, et le service refuse de toute façon —
les contrôles aux portes produisent un bon refus, le point de passage
unique est la garantie.

**Une conséquence assumée.** Un `admin` qui écrit `page_content_{id}`
reçoit 403 si cette ligne appartient à une page qu'il ne peut pas lire, et
200 si elle n'appartient à rien. Il apprend donc qu'une page possède cette
ligne — ce que l'existence de la fonctionnalité lui dit déjà — et rien sur
laquelle : le refus ne nomme ni le titre, ni l'adresse, ni la section. Un
test le tient.

**Et le piège que cette ligne créée d'avance a tendu.** La relecture l'a
vu avant la mise en service : réclamer la clé avec une chaîne vide plutôt
qu'avec `NULL` rendait toute page neuve blanche. `EditableContentService::get()`
retombe sur le défaut de l'appelant avec `??`, qui répond à `NULL` et pas
à `''` — donc le « Cette page n'a pas encore de contenu » que le gabarit
passe ne pouvait plus jamais s'afficher, sur la seule page dont c'est
l'état garanti à la seconde où elle existe. Ce que la conception a changé,
c'est **ce qui fait apparaître ce défaut** : ce n'était plus l'absence de
ligne, c'était le `NULL` dans la ligne.

Aucun test ne l'a vu non plus, et pour une raison qui vaut d'être notée :
`TextPageControllerTest` construisait `TextPageService` **sans** son
dépôt de contenu. La page s'y affichait donc avec son texte par défaut
pour la bonne vieille raison — aucune ligne — et le câblage de production,
celui qui en crée une, n'était exercé nulle part sur le chemin de rendu.
Le test est recâblé comme `public/index.php` câble, et un second vérifie
directement que la ligne réclamée porte `NULL`.

**Et la clé plantée d'avance, trouvée par la relecture suivante.** Réclamer
la clé à la création fermait la fenêtre, mais par un `INSERT` — or
`content_key` est `UNIQUE`, `POST /api/editable-content` accepte n'importe
quelle clé au plancher `admin`, et `text_pages.id` est un auto-incrément
prévisible. Un admin pouvait donc écrire `page_content_{id suivant}` avant
que la page existe, ce que le garde autorise justement parce que personne
ne la possède. À la création, l'`INSERT` échouait sur la clé en double
**après** que la ligne de la page soit écrite : une page en ligne, routée,
dans un menu, dont le corps restait sans propriétaire — donc régi par le
plancher du point d'écriture et non par celui de sa section. L'escalade
que la colonne ferme, rouverte par le seul endroit qui écrit la colonne.

Deux corrections, pas une. `claimForPage()` **reprend** une ligne
existante au lieu de l'insérer aveuglément, et la vide au passage : un
texte écrit sous la clé d'une page avant que cette page existe ne peut être
son contenu par aucun chemin légitime, et le publier sous un titre choisi
par l'unité serait pire que le perdre. Et `TextPageService::create()`
**supprime la page qu'il vient de créer** si la réclamation ne peut pas
aboutir. Une page qui n'existe pas se recrée ; une page en ligne dont le
texte n'appartient à personne, non.

Le test qui le tient est vérifié par mutation : sans la suppression
compensatoire, il échoue.

**Et la flèche des couches, remise à l'endroit.** Le garde du service
levait son refus avec `AbstractController::FORBIDDEN_MESSAGE`, parce que
c'est là que vivait la phrase de refus standard du site. Rien n'échouait,
et c'était pourtant la seule classe de service de tout le dépôt à importer
du `Core\Http\Controller` : `AGENTS.md` écrit **Controller → Service →
Repository**, et cette flèche ne va que dans un sens. Un service qui
remonte dans la couche HTTP impose cette dépendance à tous ses appelants
suivants, y compris ceux qui ne parlent pas HTTP.

La phrase a déménagé dans `Core\Exception\UserFacingMessage::FORBIDDEN` —
la couche qui décide de ce qu'un visiteur peut lire — et
`AbstractController::FORBIDDEN_MESSAGE` **est** cette constante plutôt
qu'une copie : deux littéraux de la même phrase française divergent, et
celui qui diverge est celui que personne ne relit.

La règle est écrite comme test, `tests/Architecture/LayersPointOneWayTest.php`,
et pas seulement corrigée : rien hors de `core/Http/` ne peut appeler
l'API d'un contrôleur. Elle distingue **nommer** un contrôleur — le
`Foo::class` qu'une route donne au routeur, qui est de l'aiguillage — de
l'appeler. Vérifiée par mutation : en remettant l'ancien import, elle
échoue.

**Deux tests ne pouvaient pas échouer.** Le dépôt venait précisément de
livrer « Les tests qui ne peuvent pas échouer » (#389), et la relecture a
cité cette itération. `assertTrue($required->hasAccess($required))`
comparait un rôle à lui-même : il n'affirmait que la réflexivité de
`hasAccess()`. Et une assertion affirmait qu'une adresse avec barre
oblique finale résout la même page — faux dans l'application déployée :
`Router::matchPath()` ancre son motif avec `^…$`, les routes de ces pages
sont littérales et sans barre finale, donc `/pages/x/` fait 404 au
routeur et n'atteint jamais le contrôleur. Les deux sont supprimées.

### Reporté

Rien. IT-01 est livrée entière. L'écran de configuration, la
journalisation, le sujet d'aide et la reprise de `specifications.md` sont
le périmètre annoncé d'IT-02, pas un report.
