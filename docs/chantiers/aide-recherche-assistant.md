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

---

## IT-02 — Recherche instantanée, locale et hors ligne

**Livré.**

- **L'index sérialisé dans la page** : `Core\Help\HelpSearchIndex` aplatit
  `HelpService::listForRole()` en `{id, title, summary, category,
  questions, link}` et `public/index.php` le pose en global Twig à
  l'endroit exact où il pose déjà `offline_whitelist` — après
  `loadEnabledModules()`, filtré au rôle effectif. `base.html.twig` le
  rend en `<script type="application/json" id="help-search-index">`,
  précédent `#offline-config-data` : pas de nonce CSP, le bloc ne
  s'exécute pas. **Aucun corps de sujet n'y voyage** (un test le vérifie) :
  le front matter est déjà en mémoire, un corps est une lecture de fichier
  par sujet et ferait passer le bloc de ~15 Ko à plusieurs centaines.
- **`public/assets/js/help-search.js`**, sans dépendance : normalisation
  `NFD` + retrait des diacritiques, découpage, liste courte de mots vides
  français (dont « comment », qui ouvre chaque `question:`), désuffixage
  minimal (`s`/`x` final au-delà de trois lettres) appliqué
  **symétriquement** à l'index et à la requête, pondération
  `title ×5 / questions ×4 / summary ×2 / category ×1`, facteur de préfixe
  0,5, seuil de couverture, 0 à 5 résultats, tri déterministe
  (score puis titre).
- **Deux surfaces, un seul fichier** : le panneau d'aide et `/aide`
  portent les mêmes trois marqueurs (`data-help-search-scope`,
  `-input`, `-results`, `-default`) et `help-search.js` les lie de la même
  façon. Aucune seconde implémentation.
- **Le panneau est désormais rendu sur toutes les pages** et le bouton
  d'aide l'ouvre partout (piège §4.2) : la recherche est utile précisément
  là où aucun sujet ne couvre la page. Sur une telle page le panneau
  s'ouvre sur le champ, avec une phrase qui dit quoi faire.
- **`HelpService::search()` (`?q=`) reste le repli sans JavaScript**,
  inchangé ; avec le script actif la soumission du formulaire est
  interceptée, le round-trip n'est simplement pas pris.
- **État vide rédigé** : ce qui n'a pas marché, puis quoi faire — chercher
  un mot du problème plutôt qu'un nom d'écran, et se connecter si ce n'est
  pas fait (la liste s'élargit selon l'accès).
- **Tests** : 18 cas Vitest sur le vrai fichier (accent, pluriel dans les
  deux sens, préfixe, ordre des quatre champs, couverture insuffisante,
  requête de mots vides seuls, limite à 5, tri déterministe, rendu d'un
  résultat, état vide, retour à la liste, `submit` non suivi), 4 cas
  PHPUnit sur `HelpSearchIndex` (dont le filtrage par rôle et l'absence de
  corps), 5 cas PHPUnit sur le rendu (`HelpSearchBlobTest`, modelé sur
  `OfflineConfigBlobTest` : le bloc sérialisé, le panneau présent sur
  **toutes** les pages avec ses marqueurs, le bouton qui l'ouvre toujours),
  `help-search.js` ajouté à l'app shell de `sw.js`
  (`AppShellCoverageTest`), et `types/window-globals.d.ts` déclare
  `window.ScoutMagicHelpSearch` pour `npm run typecheck`.

**Décisions autonomes.**

1. **Le seuil de couverture dépend du nombre de mots.** Une ou deux mots :
   tous doivent tomber (« photo inconnue » ne doit pas répondre par tous
   les sujets photo). Trois et plus : les deux tiers, arrondis au
   supérieur — au-delà de deux mots la personne tape une phrase, ce que le
   champ `question` l'invite précisément à faire, et un mot que le corpus
   n'emploie pas ne doit pas jeter le tout.
2. **Le préfixe est un facteur, pas une pénalité fixe.** Dans un même
   champ le mot entier gagne toujours ; mais la moitié d'un titre (2,5)
   passe devant un résumé entier (2), ce qui est le bon comportement
   pendant qu'on tape un titre à moitié retenu.
3. **Le score est exposé sur `window.ScoutMagicHelpSearch`** pour que
   Vitest exerce le vrai classement plutôt qu'une copie — précédent
   `window.ScoutMagicAttestationsDeposit`.
4. **Le rendu d'un résultat est construit nœud par nœud**
   (`createElement`/`textContent`), jamais en `innerHTML` : l'index est du
   texte du DOM au moment où le script le lit, et l'id comme le chemin de
   page sont validés par une expression rationnelle avant de devenir une
   URL — la forme `js/xss-through-dom` que `help-panel.js` garde déjà.
5. **Les entrées préparées sont mémorisées dans une `WeakMap`** : sans
   cela le corpus entier serait retokenisé à chaque frappe.
6. **Le bouton d'aide n'a plus qu'une forme.** Il était un lien `/aide`
   sur une page sans sujet ; il ouvre maintenant le panneau partout, et le
   pied du panneau garde le lien vers la liste complète.

**Divergences constatées.**

1. **Le poids réel de l'index, mesuré.** Le document annonce « 8 à 16 Ko
   selon le rôle ». Sur le corpus actuel (avant enrichissement) :
   **3,5 Ko** pour un visiteur public (17 sujets), **14,2 Ko** pour un
   animateur/chef (66 sujets), **26,1 Ko** pour un superadmin (120
   sujets). Les deux premiers sont dans la fourchette ; le troisième la
   dépasse, et le dépassera davantage avec les questions d'IT-03 à IT-06.
   Il n'y a rien à corriger : un superadmin est une personne par
   installation, la page est servie compressée, et c'est le prix assumé de
   la décision D2 (une recherche qui marche hors ligne). S'il devenait un
   problème, la piste serait de ne sérialiser l'index que sur `/aide` et
   de le charger à l'ouverture du panneau — ce qui coûterait exactement le
   hors-ligne, donc non.
2. **« ~80 lignes de JS » couvrait l'algorithme, pas le fichier.**
   `help-search.js` fait 431 lignes, dont environ un tiers de code : le
   classement lui-même est proche de l'estimation, le rendu d'un résultat
   (construit nœud par nœud, jamais en `innerHTML`) et la liaison des deux
   surfaces sont le reste. Le point du document — aucune dépendance, rien
   de servi en plus — tient.
3. **Rien à ajouter à `UxConventionsTest` pour les 44 px.** Le test scanne
   déjà tous les templates et refuse un `min-height: 44px` en style en
   ligne où qu'il apparaisse ; les nouveaux champs sont des
   `form-control`/`input-group` Bootstrap standard, que design.md §7.2
   interdit justement de gonfler.

**Reporté.**

- Le bouton « Demander à l'assistant » sous les résultats : IT-09, comme
  prévu (il n'a rien à quoi parler avant IT-08).

---

## Note transverse — la suite n'est plus verte, et ce n'est pas ce chantier

Constaté pendant IT-02, à consigner parce que le critère « fait quand » de
chaque itération dit « la suite passe » et qu'il n'est plus atteignable en
l'état.

**Le fait.** `vendor/bin/phpunit` sur `main` (b09e49d, arbre vierge) rend
**77 échecs et 1 erreur**. La liste est identique, ligne pour ligne, à
celle obtenue avec IT-01 et IT-02 appliqués : les itérations de ce
chantier n'y ajoutent rien.

**La cause.** L'application tourne sur son propre fuseau
(`Core\Config\AppClock` → `Europe/Brussels`), que `tests/bootstrap.php`
applique délibérément à la suite. Le 31 août à 22 h 00 UTC il est devenu
**le 1er septembre 2026** pour l'application — un jour après la fin de
l'année scoute `2025-09-01 → 2026-08-31` que les fixtures codent en dur
comme « l'année en cours ». Tout ce qui résout « l'année scoute
courante » ne résout plus rien : `FamilyPaymentServiceTest`,
`LeadershipRbacTest`, `SosAdminControllerTest`, `ImportRetentionServiceTest`,
`CoverageControllerTest`, `CalendarChiefControllerTest`… La preuve directe :
la suite était verte à 21 h 52 UTC (23 h 52 à Bruxelles, encore le 31 août)
et rouge à 22 h 24 UTC, sur le même commit.

**Ce que ça implique.** Ce n'est pas un artefact de conteneur : la CI
tombera de la même façon à partir de cette nuit, et à chaque 1er septembre.
201 fichiers de test citent `2025-2026` ; une vingtaine de classes en
dépendent pour « l'année en cours ». Le correctif est de leur faire
calculer leur année scoute par rapport à `now` au lieu de la coder en dur.

**Ce qui a été décidé.** Rien n'a été corrigé ici : c'est un chantier à
part entière, sans rapport avec l'aide, et le mélanger à celui-ci rendrait
les deux illisibles. À la place, chaque itération est validée contre la
**référence de `main`** — la suite ne doit ajouter aucun échec à ces 77 —
et les listes des deux exécutions sont comparées à l'identique.
