# Chantier — Astuces de découverte (« Le saviez-vous ? »)

Journal d'implémentation du document de chantier « Astuces de découverte »
(itérations IT-01 à IT-03). Une section par itération : ce qui a été
livré, les décisions prises en autonomie, les divergences constatées entre
le document de chantier et le dépôt réel, et ce qui a été reporté. Même
format que `docs/chantiers/aide-contextuelle.md`.

Le document de chantier lui-même n'est pas dans le dépôt et ne s'y ajoute
pas : ce journal est ce qui en reste.

---

## IT-01 — Le socle : la clé `discovery` et l'état « vu »

**Livré.** Aucune interface, comme demandé — le parseur et le schéma sont
ce qui peut casser les ~120 fichiers du corpus, ils passent seuls.

- `Core\Help\DiscoveryPriority` — enum backed string (`High='1'`,
  `Normal='2'`, `Low='3'`, `Off='off'`) avec un `rank()` de tri.
- `HelpFrontMatterParser` : la clé `discovery` ajoutée à `OPTIONAL_KEYS`,
  lue par `tryFrom()`, **jetant une `HelpException` nommant le fichier**
  sur une valeur inconnue — et aussi sur une valeur vide (voir les
  décisions autonomes).
- `HelpTopic::$discovery`, champ en lecture seule, `Normal` par défaut.
- **Le piège du cache**, traité ici : `HelpRegistry::CACHE_FORMAT`, une
  marque de format intégrée à la clé du cache sérialisé. Sans elle, une
  installation déployée par artefact puis mise à jour sur place garde une
  version qui ne bouge qu'à la release — et désérialiserait indéfiniment
  un index écrit avant que `discovery` n'existe. `DiscoveryPriority` a
  aussi été ajouté à la liste des classes autorisées de
  `SerializedFileCache` : un enum n'est pas dégradable en
  `__PHP_Incomplete_Class`, une classe manquante y serait un fatal et non
  le « miss » que ce cache est écrit pour survivre.
- `schema/core.sql` : la table `help_topics_seen` et la colonne
  `user_accounts.help_discovery_snoozed_until`, avec leurs commentaires.
- `Core\Help\Discovery\SeenTopicRepository` — seule couche à toucher PDO :
  `findSeenIds`, `countSeen`, `markSeen`, `clear`, `snoozedUntil`,
  `snooze`.
- `Core\Help\Discovery\DiscoveryService` — `nextTopics()`, la graine
  reproductible, le tri éditorial, les quatre réglages.
- Les quatre réglages `core` enregistrés dans `public/index.php`
  (`help_discovery_enabled`, `_interval_hours`, `_snooze_days`,
  `_batch_size`, tris 297 à 300).
- Tests : parseur (défaut, chaque valeur, valeur inconnue, valeur vide),
  cache (clé sans marque de format = miss, `discovery` qui survit à
  l'aller-retour), repository sur SQLite **et sur MySQL**, service (17
  cas : commutateur, report en cours, report échu, rôle, module non
  enregistré, `off`, déjà vu, troncature, priorité contre graine,
  reproductibilité, changement d'état, deux comptes, délais, défauts).
- `ARCHITECTURE.md` §8.64 : la clé `discovery` documentée dans le
  paragraphe qui décrit le format du front matter.

**Décisions autonomes.**

1. **`markSeen()` s'écrit en deux dialectes, pas en un.** Le document
   demande « un `INSERT … ON DUPLICATE KEY UPDATE`, jamais une boucle de
   requêtes ». La contrainte réelle est la boucle, pas la syntaxe : la
   base de test par défaut est SQLite en mémoire, qui ne connaît pas cette
   clause. C'est l'appariement portable que ce dépôt utilise déjà
   (`Modules\Presences\Repository\PresenceRepository`,
   `Modules\UsageStats\Repository\PageViewRepository`) —
   `ON CONFLICT … DO NOTHING` sur SQLite, `ON DUPLICATE KEY UPDATE` sur
   MySQL/MariaDB — dans **une** requête, quelle que soit la taille du lot.
2. **Une seconde classe de test, sur le moteur réel.**
   `SeenTopicUpsertOnMysqlTest` rejoue l'upsert et l'aller-retour de la
   colonne de report contre MySQL/MariaDB, dans sa propre base jetable. Le
   test SQLite seul aurait laissé la clause MySQL non exercée — la forme
   exacte du défaut qui a valu son existence à
   `Tests\Core\Scheduler\LivePrefixGuardOnMysqlTest`.
3. **`snoozedUntil()` lit par `Core\Service\DateInput::fromStorage()`**,
   pas par `createFromFormat()`. `Tests\Security\DateParsingConvergenceTest`
   interdit le second partout sauf dans `DateInput`, et c'est de toute
   façon le bon outil : une colonne vide doit se lire « rien ne retient »
   et jamais « maintenant ».
4. **`discovery:` sans valeur est une erreur**, pas un défaut implicite.
   Le document ne tranche que la valeur inconnue ; une clé écrite puis
   laissée vide est la même faute de frappe avec le même symptôme
   indébogable.
5. **`eligibleTopics()` existe à côté de `nextTopics()`.** Le document ne
   nomme que le second, mais IT-02 a besoin de l'ensemble éligible complet
   à deux endroits : revalider les ids qu'un navigateur prétend avoir lus,
   et consommer tout le reliquat pour « Ne plus me proposer ». Il est
   délibérément **non** filtré par le commutateur ni par le report — ses
   deux appelants agissent sur un dialogue déjà légitimement affiché —
   tandis que `nextTopics()` applique les deux barrières puis tronque.
6. **`countSeen()` est conservé** bien que la graine puisse se contenter
   de `count(findSeenIds())` : c'est lui qui décidera, en IT-02, si
   « Revoir les astuces » a quelque chose à défaire sur `/account`. La
   graine, elle, réutilise le comptage déjà en main plutôt que de
   redemander une requête.
7. **Un réglage à zéro ou négatif retombe sur son défaut.** Un lot de
   taille 0 serait une fenêtre vide et un intervalle négatif une fenêtre
   qui ne part jamais ; ni l'un ni l'autre n'est ce qu'un administrateur
   veut dire en vidant un champ.

**Divergences avec le dépôt réel.**

- Le document décrit `HelpService::CATEGORY_ORDER` comme un départage à
  retirer. Il est **déjà** `private` et ne sert qu'au groupement de
  `/aide` : rien à retirer, rien à toucher — `DiscoveryService` n'y touche
  pas et trie sa propre liste à plat.
- `HelpService::listForRole()` rend un tableau **groupé par catégorie**
  (`array<string, HelpTopic[]>`), pas une liste plate ; le service
  l'aplatit.
- La numérotation des réglages `core` s'arrête à 296 dans
  `public/index.php` : les quatre nouveaux prennent 297 à 300.
- `tests/Core/Database/SqlParserTest` compte les tables de
  `schema/core.sql` en dur (48 → 49) ; il fallait le mettre à jour, ce que
  le document ne pouvait pas prévoir.
- Aucune entrée `<testsuite>` à ajouter : `tests/Core/Help/Discovery/` vit
  sous `tests/Core`, que `phpunit.xml` déclare déjà récursivement.

**Reporté.** Rien. Le câblage du service dans le root de composition part
avec IT-02, qui est ce qui le consomme.

---

## IT-02 — Le dialogue

**Livré.**

- **Le global Twig `help_discovery`**, posé dans `public/index.php` à côté
  de `help_search_index` — donc après le chargement de tous les modules,
  seul moment où « un sujet que ce compte n'a jamais vu » est une question
  à réponse complète. Il n'est **posé que s'il y a quelque chose à
  montrer** : `base.html.twig` inclut le partiel ou ne l'inclut pas, même
  forme que la bannière cookies.
- `DiscoveryService::dialogForRequest()` — toute la décision en un point :
  compte connecté, requête GET, page hors de `/aide`, `/api/`, `/login` et
  de la page hors connexion, puis les barrières d'IT-01.
- **`partials/help_discovery_dialog.html.twig`** — modale Bootstrap 5
  bâtie sur `partials/modal.html.twig`, une carte à la fois, la question
  avant le titre, « En savoir plus » vers `/aide/{id}`. Pied :
  « Suivant » → « Terminé » sur la dernière carte, « Voir d'autres
  astuces » si le reliquat n'est pas vide, puis « Pas avant une semaine »
  et « Ne plus me proposer ». **Rien n'est enveloppé dans un `<form>`** —
  la persistance est un `fetch`, donc le piège de rendu documenté
  (`.modal-dialog-scrollable` + `<form>` = pied hors écran sur mobile) ne
  se pose pas.
- **`public/assets/js/help-discovery.js`** — navigation en place,
  accumulation des ids réellement dépassés, **un seul appel réseau**, à la
  fermeture. Ajouté à l'app shell de `sw.js`.
- **`POST /api/aide/decouverte`** (`Core\Http\Controller\HelpDiscoveryController`,
  `role_min: identified`, jeton CSRF obligatoire) — `close`, `snooze`,
  `never` et `more`. Les ids reçus sont revalidés contre l'ensemble
  éligible courant du compte, jamais écrits tels quels.
- **« Revoir les astuces »** sur `/account` (`POST /account/discovery/reset`,
  CSRF, `data-confirm`), affiché seulement si le compte a déjà vu quelque
  chose.
- **Documentation** : `ARCHITECTURE.md` §8.95 (avec la règle de graine et
  sa raison, et le renvoi à §8.64 pour le corpus), `specifications.md`
  §4.6 et la ligne « Mon compte », `design.md` §7.11 pour la forme du
  dialogue, `core/View/rgpd_default.html` (données collectées et durée de
  conservation), et le sujet d'aide `docs/help/mon-compte.md`.
- **Tests** : contrôleur (les quatre actions, la revalidation des ids, un
  id de forme invalide, l'absence de jeton CSRF sur les deux routes),
  RBAC (`identified` et `chief` acceptés, `public` refusé sur les deux
  routes), le rendu du partiel (absent sans global, une carte par sujet,
  la question avant le titre, l'échappement), le service (aucun dialogue
  sur `/aide`, `/aide/{id}`, `/aide/assistant`, `/api/*`, `/login`,
  `/offline`, pour un visiteur sans compte ou sur une écriture), et
  Vitest (13 cas : la marche entre cartes, l'accumulation, l'appel unique,
  chaque action, et une page sans boîte à outils).

**Décisions autonomes.**

1. **Une quatrième action, `more`.** Le document en liste trois, mais
   « Voir d'autres astuces » n'en est aucune : `close` poserait le délai
   et il ne se passerait rien. `more` marque le lot vu et remet
   `snoozed_until` à `NULL`, après quoi le navigateur **recharge** la page
   et le serveur rend le lot suivant par le même gabarit. Recharger plutôt
   que rendre des cartes en JavaScript : pas de seconde implémentation de
   la carte, et aucun puits DOM alimenté par des chaînes venant du
   serveur — la forme exacte que CodeQL signale en `js/xss-through-dom`.
2. **`help_discovery` porte `{cards, more}`** et non la seule liste. Une
   carte ne peut pas dire s'il en reste d'autres après elle, et deux
   globals pour une décision valaient moins qu'un.
3. **L'indicateur « 2 / 5 » est en haut du corps, pas dans l'en-tête.**
   `partials/modal.html.twig` possède l'en-tête et l'échappe ; y injecter
   du balisage rouvrirait la dérive que ce partiel existe pour empêcher.
   La zone visuelle est la même.
4. **Une carte affichée est une carte vue.** « Réellement dépassés » se
   lit ainsi : qui a vu l'astuce l'a vue, qu'il ait ensuite appuyé sur
   « Suivant » ou fermé la fenêtre. Fermer d'emblée consomme donc la
   première carte, et une seule.
5. **`POST /account/discovery/reset` vit sur `HelpDiscoveryController`**,
   pas sur `AccountController` : la route est sous `/account`, le domaine
   non. `AccountController` reçoit seulement un `SeenTopicRepository`
   optionnel en dernier paramètre, pour savoir s'il y a quelque chose à
   défaire.
6. **Le bouton n'apparaît qu'au-delà de zéro astuce vue.** Un bouton qui
   propose de défaire ce qui n'existe pas se lit comme cassé.
7. **`Write` refusé au relecteur IA n'a pas été corrigé ici** — voir le
   commentaire de la PR IT-01 : toute modification de
   `.github/workflows/claude-review.yml` fait *refuser* la revue, qui sort
   alors en succès sans avoir rien relu. C'est le défaut #261 et il se
   corrige sur `main`.

**Divergences avec le dépôt réel.**

- Le squelette de modale partagé (`partials/modal.html.twig`) existe et
  s'impose ; le document décrit la modale comme si elle s'écrivait à la
  main.
- Le refus RBAC d'un visiteur non connecté est une **redirection vers
  `/login`**, pas un 403 : c'est ce que rend `FrontController` quelle que
  soit la méthode. Le test l'épingle sous cette forme, plus une seconde
  moitié avec une session dont le rôle est `public`.
- `Core\Http\Response` expose `getBody()` et non `getContent()`.
- La page hors connexion est `/offline` ; le document ne la nomme pas.
- `Core\View\rgpd_default.html` devait être mis à jour (AGENTS.md § RGPD),
  ce que le document ne mentionne pas : `help_topics_seen` est une donnée
  rattachée à un compte, même si c'est une préférence et non une trace de
  lecture.

**Reporté.** Rien.

---

## IT-03 — La passe éditoriale et la charte

**Livré.**

- **Le corpus entier étiqueté** — 135 sujets relus un par un : **24 en
  priorité 1**, 40 en `3`, 13 en `off`, et 58 laissés au défaut, c'est-à-dire
  sans clé du tout.
- **La charte, aux deux endroits où un auteur la cherchera** :
  `design.md` §7.11 (la règle des quatre valeurs, les exemples du corpus,
  le plafond d'une vingtaine de `1` et le plancher de trois par rôle) et
  la section Aide de `docs/module-development.md` (la puce `discovery`, à
  côté de `paths`, `question` et des citations de libellés).
- **`tests/Core/Help/HelpDiscoveryInvariantsTest`** — six invariants
  sur le corpus livré : toute valeur `discovery` déclarée est valide ;
  **aucun sujet n'écrit `discovery: 2`** en toutes lettres ; **chaque
  plancher de rôle porte au moins trois sujets en priorité 1** ; les
  huit sujets que le chantier nomme (`cookies`, `donnees-personnelles`,
  `se-connecter`, `mon-compte`, `reinitialisation`,
  `installation-serveur`, `sauvegardes`, `mises-a-jour`) sont bien en
  `off` ; **la priorité 1 reste un petit ensemble** (trente au plus —
  voir la décision 2) ; et **le corpus couvre exactement les six
  planchers de rôle pour lesquels il est écrit**. Ce dernier existe pour
  une cécité du précédent : le compte par plancher tire ses planchers du
  corpus lui-même, donc un plancher qui disparaît entièrement — le
  dernier sujet `intendant` supprimé, disons — cesse simplement d'être
  vérifié, et la suite reste verte sur un rôle dont l'aide a disparu.

**Les 24 sujets de priorité 1, par plancher de rôle** — quatre chacun,
ce qui est la rencontre entre les deux moitiés de la règle (voir la
décision 1) :

| Plancher | Sujets |
|---|---|
| `public` | `installer-application`, `un-email-plusieurs-animes`, `recherche-dans-l-aide`, `modifier-une-reponse` |
| `identified` | `mes-paiements`, `envoyer-une-photo`, `notifications-preferences`, `trombinoscope` |
| `intendant` | `etiquettes-paiement`, `importer-extraits`, `rappels`, `campagnes` |
| `chief` | `presences-feuille`, `publipostage`, `camps-encoder`, `envoi-de-mails` |
| `admin` | `points-attention`, `attestations-couverture`, `edition-du-site`, `justesse-des-tarifs` |
| `superadmin` | `config-emails`, `frequentation`, `connecteur-ia`, `config-desk` |

**Décisions autonomes.**

1. **Vingt-quatre plutôt que vingt.** Le document vise « une vingtaine,
   pas davantage » ET exige trois `1` par plancher de rôle. Le corpus
   utilise six planchers, donc le plancher seul impose dix-huit. À vingt,
   deux planchers vivraient au minimum exact, et le premier sujet retiré
   ou passé en `off` casserait le test. Quatre par plancher donne
   vingt-quatre, garde une marge d'un sujet partout, et reste « une
   vingtaine ». Le test porte les deux bornes.
2. **Un plafond, pas seulement un plancher.** Le document ne demande que
   l'invariant du bas ; sans plafond, « vise une vingtaine » n'est appliqué
   par rien et le corpus dérive vers cinquante prioritaires, ce qui est le
   défaut sous un autre nom. Le plafond est délibérément lâche (trente) :
   il refuse une dérive, pas un arbitrage sur un sujet.
3. **`discovery: 2` écrit en toutes lettres est une erreur de test.** La
   charte dit de ne pas l'écrire ; une règle que rien ne vérifie est une
   règle que la moitié du corpus finira par enfreindre.
4. **Cinq `off` de plus que le minimum du document** : `se-desinscrire`
   (se retirer d'une liste de diffusion — hygiène de compte),
   `comptes-superadmin` (idem, côté administration), `config-rgpd`
   (obligation légale), `support-github` et `support-sondes-email`
   (opérations techniques qu'on ouvre au moment où on en a besoin). Chacun
   tombe exactement dans une des trois catégories que le document énumère.
5. **Les variantes d'un sujet déjà en `1` passent en `3`, systématiquement** :
   `presences-anime` et `presences-registre` derrière `presences-feuille`,
   les neuf sujets `camps-*` derrière `camps-encoder`,
   `attestations-distribuer` et `attestations-verifier` derrière
   `attestations-couverture`, `recus-compte` et `rapprochement` derrière
   les outils de finance. C'est la règle du document appliquée à la
   lettre, et c'est ce qui remplit la catégorie `3` — quarante sujets,
   sans quoi elle serait restée vide.
6. **`journal` reste en `3` plutôt qu'en `off`.** Consulter le journal du
   site est une capacité d'administration qui vaut d'être connue, pas une
   opération d'hygiène ; ce qui la met en `3` et non en `1`, c'est qu'on
   n'y va que quand on cherche déjà quelque chose.

**Divergences avec le dépôt réel.**

- Le corpus compte **135 sujets** et non « ~120 » : 44 core et 91 livrés
  par des modules.
- Le document nomme sept exemples de priorité 1 ; six existent tels quels,
  et tous ont été retenus.
- Le document énumère huit `off` « au minimum » ; les huit existent et
  sont bien en `off`, plus cinq autres.

**Reporté.** Rien.
