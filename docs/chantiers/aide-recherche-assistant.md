# Chantier — Recherche dans l'aide et assistant conversationnel

Journal d'implémentation du document de chantier
`docs/chantiers/CHANTIER-aide-recherche-assistant.md` (itérations IT-01 à
IT-10). Une section par itération : ce qui a été livré, les décisions
prises en autonomie, les divergences constatées entre le document de
chantier et le dépôt réel, et ce qui a été reporté. Même format que
`docs/chantiers/aide-contextuelle.md`, dont ce chantier prolonge le
moteur (ARCHITECTURE.md §8.64).

---

## IT-01 — Le champ `question` et le lien vers la page documentée

**Livré.**

- **`question`, clé de front matter répétable** (`Core\Help\HelpFrontMatterParser`) :
  une ligne par question, accumulées dans `HelpTopic::$questions`
  (`string[]`, défaut `[]`). Le séparateur virgule de `paths`/`related`
  est délibérément écarté — une vraie question en contient une
  (« Comment prévenir les parents, y compris ceux d'une autre
  section ? ») et la couper en deux ne se verrait nulle part. Une valeur
  vide est refusée au chargement comme n'importe quel autre défaut.
- **L'id `assistant` est réservé** dans le parseur : `/aide/assistant`
  sera enregistrée avant `/aide/{topic}` (IT-09) et `Router::resolve()`
  retient la première route qui matche, donc un sujet portant cet id
  serait listé, cherchable et inatteignable. Refuser l'id au chargement
  est le seul endroit où cette panne est visible.
- **`HelpService::search()` cherche aussi dans `questions`**, avec la
  normalisation sans accents existante. C'est le repli `?q=` sans
  JavaScript : il reste un filtre par sous-chaîne, pas un second calcul
  de score qui divergerait du classement d'IT-02.
- **Le lien vers la page documentée** : `Core\Help\HelpPageLinkResolver`
  résout le premier `paths` **exact** et navigable en `{path, label}`,
  via un accesseur étroit ajouté au `Router` (`pageAtPath()`, qui rend
  exactement deux faits : le libellé du fil d'Ariane et le plancher de
  rôle de la route). Trois règles : seul un chemin `exact` peut devenir
  un lien ; le rôle vérifié est celui de **la route cible**, pas celui du
  sujet ; et le lien vers la page où l'on se trouve déjà n'est pas
  proposé.
- **Affichage** sur `/aide/{id}` (`help/show.html.twig`) et dans le
  panneau contextuel (`partials/help_panel.html.twig`), en
  `btn-outline-secondary btn-sm`.
- **Tests** dans le même commit : parseur (répétabilité, virgule
  conservée, valeur vide, id réservé), `HelpService::search()` sur les
  questions et sur le filtrage par rôle, `HelpPageLinkResolver` (5 cas),
  `Router::pageAtPath()`, `FrontController` (lien supprimé sur la page
  courante, lien proposé vers une autre page couverte), et deux nouveaux
  invariants de corpus.

**Décisions autonomes.**

1. **Un service dédié plutôt que `HelpTopic::pageLink()`.** Le document
   laissait le choix ; `HelpTopic` est un objet-valeur sans dépendance,
   lu par le registre et **sérialisé dans le cache d'index**
   (`storage/core/help`). Lui donner le `Router` en dépendance aurait
   rendu un sujet non sérialisable et fait entrer une couche HTTP dans
   une valeur de contenu.
2. **« Navigable » veut dire : route GET déclarée à ce chemin exact et
   portant un libellé de fil d'Ariane.** Ce qui exclut trois choses, et
   chacune est une page vers laquelle on ne peut pas envoyer quelqu'un :
   une route non-GET, un chemin à `{placeholder}` (aucun membre
   particulier à ouvrir), et une route sans breadcrumb — dans ce dépôt
   c'est un endpoint d'API, une cible de redirection ou un flux de
   fichier, jamais une page.
3. **Le rôle vérifié est celui de la route cible.** Le document le
   demandait ; c'est aussi la règle du fil d'Ariane
   (`Router::ancestorTrailFor()`), et elle fait disparaître le lien au
   lieu de mener à un 403. La visibilité reste une commodité, jamais une
   frontière (SECURITY.md §3) : la route continue d'appliquer son propre
   `role_min`.
4. **Le résolveur est injecté en paramètre optionnel de fin** de
   `FrontController` et de `HelpController`, comme `HelpService` l'est
   déjà : les dizaines d'appels de test qui construisent ces classes avec
   moins d'arguments continuent de fonctionner, et un sujet sans lien
   s'affiche exactement comme avant.
5. **Le sujet couvrant plusieurs pages propose la suivante.** Quand on
   est sur la première page qu'il documente, le résolveur passe à la
   suivante plutôt que de ne rien rendre — c'est le cas
   `/config/maintenance`, où plusieurs sujets se partagent une page.
6. **La règle d'unicité des questions compare une forme repliée**
   (minuscules, accents et ponctuation neutralisés), pour que
   « Comment inviter un animateur ? » et « Comment inviter un
   animateur? » ne passent pas à travers.
7. **Les deux nouveaux invariants s'assurent quand même quelque chose
   pendant que le corpus est vide de questions** (un total comparé, un
   corpus non vide) : un test sans assertion est signalé « risky » par
   PHPUnit et ne défend rien.

**Divergences constatées.**

1. **Les chiffres du document ne correspondent pas au corpus réel.** Il
   annonce « 86 sujets obtiennent un lien, 34 non (1 sans `paths`, 31
   n'ayant que des motifs `*`) » — dont la somme ne fait déjà pas 120. Le
   corpus actuel compte **120 sujets**, dont **89 obtiennent un lien**,
   **1 ne déclare aucun `paths`** et **30 n'ont que des règles `child` ou
   des motifs `*`**. L'écart ne change rien à la conception : c'est bien
   « environ un quart du corpus sans lien, et c'est normal ».
2. **Le `Router` exposait déjà un accesseur étroit**, `roleMinForPath()`,
   ajouté pour les ancêtres du fil d'Ariane. Le document demande d'en
   ajouter un ; il n'en manquait en réalité que la moitié (le libellé).
   `pageAtPath()` rend les deux faits d'un coup plutôt que d'obliger un
   appelant à croiser deux accesseurs.
3. **L'id `assistant` réservé (§4, piège 1) contredit IT-10**, qui
   demande un sujet d'aide nommé `assistant`. Les deux ne peuvent pas
   coexister. La réservation est appliquée ici ; le sujet d'IT-10 portera
   un autre id, ce qui sera consigné dans sa propre section.
4. **`ARCHITECTURE.md` §8.70 est déjà pris** (« A receipt's file follows
   its account »). La section demandée en IT-10 prendra le premier
   numéro libre après §8.86.
5. **`OfflineWhitelist` couvre déjà `/aide/assistant` sans le nommer.**
   Le §4 (piège 8) demande de ne pas l'y ajouter — mais l'entrée `child`
   `/aide/` existante l'attrape de toute façon, puisqu'elle couvre
   `/aide/` plus exactement un segment. Ne rien ajouter ne suffira donc
   pas : IT-09 devra traiter le cas explicitement. Constaté ici, traité
   là-bas.

**Reporté.**

- Aucun sujet n'est encore enrichi : c'est IT-03 à IT-06. La règle
  « tout sujet doit porter 2 à 4 questions » n'est donc pas encore
  active — elle arrive en IT-07, comme prévu.
- La documentation du champ (`design.md` §7.11,
  `docs/module-development.md`, `AGENTS.md`) est portée par IT-07 et
  IT-10.
