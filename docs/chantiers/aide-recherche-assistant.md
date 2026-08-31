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

## IT-03 — Premiers pas + Espace membres (33 sujets)

**Livré.**

- **2 à 4 `question:` sur chacun des 33 sujets** des catégories
  « Premiers pas » (16) et « Espace membres » (17) — 82 questions au
  total sur le corpus, formulées comme un parent ou un animateur les
  taperait, pas comme un sommaire : « Combien dois-je payer pour
  l'inscription de mon enfant ? », « J'ai payé, pourquoi le montant
  s'affiche-t-il toujours ? », « Est-ce que mon message de rétrospective
  est anonyme ? ». Chaque sujet en a trouvé au moins deux vraies : aucun
  ne décrivait un écran au lieu de documenter une tâche, donc aucun n'a
  eu à être réécrit.
- **Une correction de contenu** : `cookies` citait les trois catégories
  comme « Strictement nécessaires », « Fonctionnels » et « D'analyse »
  alors que l'écran affiche « Cookies strictement nécessaires »,
  « Cookies fonctionnels » et « Cookies d'analyse »
  (`CookieConsentService`). Alignées.
- **Une correction dans la recherche**, révélée par le corpus enrichi et
  pas par un test : voir « Décision autonome 2 » ci-dessous.

**Audit — ce qui a été vérifié et n'a rien donné.**

- **Les libellés cités.** Les 33 corps ont été passés au crible, chaque
  libellé entre guillemets français confronté aux vues Twig, au JS et aux
  sources PHP. En dehors des cookies, tous existent. Les neuf
  « manquants » restants sont des faux positifs à retenir pour IT-07 :
  un libellé coupé par une balise (`J'accepte la <a>politique de
  protection des données</a>` sur la page de connexion), un libellé de
  navigateur ou de système (« Ajouter à l'écran d'accueil »), un titre de
  sujet d'aide cité comme tel, une valeur d'exemple
  (« Téléphone de Marie »), un gabarit à variable (« Il reste N places »),
  et un glyphe (« ⋮ »). **Le test de dérive d'IT-07 devra dépouiller les
  balises avant de comparer**, sinon il produira exactement ces
  faux positifs.
- **Les `role_min`.** Comparés au plancher réel de chaque route exacte
  couverte : un seul écart, `mes-paiements` (`identified`) sur `/`
  (`public`). Il est volontaire et correct — la page d'accueil est
  publique, le bandeau de paiement qu'elle documente n'existe que pour
  une famille connectée.

**Décisions autonomes.**

1. **Les questions sont insérées après `role_min`**, avant `paths` et
   `related` : le bloc de front matter se lit alors identité (id, titre,
   résumé, catégorie, rôle), puis ce que la personne cherche, puis le
   câblage technique.
2. **Un mot que le corpus n'emploie nulle part ne compte plus contre la
   couverture.** « empreinte digitale » ne répondait rien alors qu'un
   sujet dit « se connecter avec l'empreinte » : « digitale » n'existe
   dans aucun sujet, donc il ne discrimine rien, mais il faisait échouer
   la règle « deux mots, les deux doivent tomber ». Il est désormais
   écarté avant de mesurer la couverture — ce qui reste **différent** d'un
   mot que d'autres sujets portent et pas celui-ci, qui lui compte
   toujours contre lui. Défaut trouvé en interrogeant le vrai corpus, pas
   en théorie ; deux cas Vitest le tiennent.
3. **La liste de mots vides s'allonge de ce qui ouvre une question** :
   « où » (replié en « ou »), « quand », « pourquoi », plus « tous »,
   « tout », « toutes » et « tou » (ce que le désuffixage fait de
   « tous »). Sans eux, « pourquoi je ne reçois plus les mails » ne
   trouvait pas le sujet de désinscription.

**Vérifié sur le vrai corpus.** La vraie `help-search.js` exécutée sur le
vrai index d'un chef : « photos du camp » → la galerie ; « mot de passe
oublié » → se connecter, puis Mon compte ; « combien je dois payer » →
le sujet des paiements ; « pourquoi je ne reçois plus les mails » → la
désinscription ; « zzz » → rien.

**Reporté.**

- « Espace animateurs » (34), « Espace chefs d'U » (28) et
  « Configuration » (25) : IT-04, IT-05 et IT-06.
- La règle « tout sujet doit porter 2 à 4 questions » reste inactive
  jusqu'à IT-07.

---

## IT-04 — Espace animateurs (34 sujets)

**Livré.**

- **2 à 4 `question:` sur chacun des 34 sujets** — 76 questions, dont le
  vocabulaire réel des gens plutôt que celui des titres : « Un parent a
  payé pour trois enfants en un virement, que faire ? » pour
  `rapprochement`, « Le même terrain existe deux fois, comment les
  recoller ? » pour `camps-fusionner`, « Comment se tester soi-même avant
  un envoi groupé ? » pour `envoi-de-mails`. Le sujet `publipostage`
  reçoit exactement la question que le document de chantier cite en
  exemple.
- **Une quatrième question sur `envoi-de-mails`**, ajoutée après coup :
  interrogé sur la question modèle du §6 du document — « Comment prévenir
  tous les parents d'une section ? » — le corpus enrichi répondait le
  calendrier et les documents de section, pas l'envoi groupé. Le sujet
  disait « écrire à », la personne dit « prévenir ». C'est exactement ce
  que le champ existe pour rattraper, donc la formulation a rejoint le
  sujet plutôt que d'être laissée au hasard du classement.
- **Une correction de contenu** : `importer-extraits` citait le filtre
  « à catégoriser » alors que le tableau de bord affiche
  « À catégoriser » (`dashboard.html.twig`). Aligné.

**Audit — ce qui a été vérifié.**

- **Les libellés.** Dix-sept citations n'ont pas été retrouvées telles
  quelles ; une seule était une vraie dérive (ci-dessus). Les seize
  autres ajoutent **deux familles de faux positifs** à celles d'IT-03, et
  IT-07 devra les traiter :
  - **Les entités HTML.** « Affiche & QR code » existe bien, écrit
    `Affiche &amp; QR code` dans `editor.html.twig` — le test devra
    décoder avant de comparer.
  - **Les renvois vers un autre sujet d'aide.** « Rapprocher un paiement
    qui tombe de travers », « L'écran du courrier des camps », « Qui voit
    quels comptes » : ce sont des titres de sujets ou des titres de
    section, pas des libellés d'écran. Le test devra les reconnaître, ou
    ils iront dans l'ALLOWLIST.
  Le reste est de la prose en gras et un exemple de texte saisi par un
  humain (« On est allés là en 2012 »).
- **Les `role_min`.** Deux écarts avec le plancher de la route, tous deux
  volontaires et corrects : `documents-de-section` (`chief`) sur
  `/chefs/staffs` (`intendant`) et `animer-un-groupe` (`chief`) sur
  `/groups` (`identified`). C'est le motif « deux sujets sur une page, à
  deux planchers » : `staffs` et `groupes` documentent ce qu'on y
  regarde, les deux autres ce qu'un animateur y fait. Un intendant garde
  donc de l'aide sur la page qu'il peut ouvrir.

**Reporté.** « Espace chefs d'U » (28) et « Configuration » (25) :
IT-05 et IT-06.

---

## IT-05 — Espace chefs d'U (28 sujets)

**Livré.**

- **2 à 3 `question:` sur chacun des 28 sujets** — 59 questions. La
  tranche est celle du Staff d'Unité, et les questions le disent : « Qui a
  changé cela, et quand ? », « La facture de la fédération est-elle
  juste ? », « La même personne apparaît deux fois, comment réunir ses
  fiches ? », « Quels animateurs vont bientôt avoir 20 ans ? ».
- **Une correction de contenu** : `attestations-verifier` citait les deux
  compteurs de l'écran comme « Affichées » et « À distribuer », en
  capitales et comme s'il s'agissait de libellés. L'écran écrit
  « N affichées sur M » et « à distribuer », en minuscules, dans une
  phrase. La citation suit maintenant l'écran.
- **`role_min` : aucun écart** avec le plancher des routes couvertes.

**Un défaut dans l'outil d'audit, corrigé.**

Le script qui confronte les libellés cités aux sources utilisait
`core/View/templates/**/*.twig` : **le `**` de `glob()` en PHP ne
récurse pas**, donc un gabarit à trois niveaux
(`admin/members/search.html.twig`) n'était jamais lu et tous ses
libellés ressortaient « manquants ». Corrigé en parcourant les arbres.
Les tranches IT-03 et IT-04 ont été **repassées** avec le script
réparé : leurs listes sont inchangées, donc aucune vraie dérive n'y
avait été manquée et aucune correction n'y avait été faite à tort.
C'est le troisième piège pour IT-07, et le plus coûteux : **un test de
dérive qui ne lit pas tous les gabarits accuse le corpus à tort.**

**Les faux positifs restants** (onze) sont tous d'une famille déjà
répertoriée : titres de sujets d'aide cités en renvoi, paroles
rapportées (« ça a affiché une erreur »), valeurs d'exemple
(« Attestation présence camp 2026 »), gabarits à points de suspension
(« SOS Staff d'U : … »), et le raccourci « Du / Au » qui nomme d'un
trait deux champs voisins réellement libellés « Du » et « Au ».

---

## IT-06 — Configuration (25 sujets)

**Livré.**

- **2 à 3 `question:` sur chacun des 25 sujets** — 54 questions. C'est la
  tranche du superadministrateur, et les questions sont celles qu'on se
  pose quand quelque chose ne marche pas : « Pourquoi les e-mails du site
  n'arrivent-ils pas ? », « Comment vérifier que le cron du site
  tourne ? », « Que se passe-t-il si je désactive un module ? »,
  « Combien coûte l'IA branchée sur le site ? ».
- **Deux corrections de contenu**, les deux de la même famille que celles
  d'IT-04 et IT-05 — une citation reconnaissable mais pas littérale :
  - `actions-planifiees` renvoyait au bloc « Tâche planifiée (cron) » de
    la page Installation & serveur, qui s'appelle **« Tâche cron »** ;
  - `connecteur-ia` citait le mode « généré par IA » de la page RGPD, qui
    s'affiche **« Généré par IA »**.
- **`role_min` : aucun écart** avec le plancher des routes couvertes.

**Le corpus est enrichi en entier.** 120 sujets, **272 questions**, de 2 à
4 par sujet, `loadErrors()` vide. L'index de recherche pèse **5,8 Ko**
pour un visiteur public, **23,3 Ko** pour un animateur, **34,5 Ko** pour un
chef d'unité et **42,0 Ko** pour un superadministrateur — au-delà de la
fourchette annoncée pour les deux derniers rôles, ce qui reste le prix
assumé de la décision D2 (voir la divergence 1 d'IT-02).

**Ce que les quatre tranches ont appris à IT-07.** Le prototype du test de
dérive tourne sur le corpus complet et ne laisse plus que **25 citations
non résolues sur 18 sujets** — toutes des exceptions légitimes, aucune
dérive. Il a fallu quatre corrections successives du dépouillement pour
arriver là, et chacune est un piège qu'un test naïf tomberait dedans :

1. **`glob()` ne récurse pas sur `**`** — un gabarit à trois niveaux
   n'était pas lu (IT-05).
2. **Une balise coupe un libellé** (« J'accepte la `<a>`politique de
   protection des données`</a>` ») : il faut une lecture *sans* les
   balises pour le recoller.
3. **Un libellé vit dans un attribut** (« Mois précédent » est un
   `aria-label`) : il faut une lecture *avec* les attributs. Les deux
   lectures sont incompatibles, donc le test en fait deux et accepte une
   correspondance dans l'une ou l'autre.
4. **Un libellé vit dans une balise Twig.** L'idiome dominant de ce dépôt
   pour un bouton, un état vide ou un éditeur de liste est
   `{% include ... with { action_label: "Télécharger l'album (ZIP)" } %}`
   — dépouiller le Twig efface exactement ce qu'un sujet cite.

**Décision autonome.** Le document demande d'extraire les libellés
« entre guillemets français ou en gras ». **Le gras est écarté** : dans ce
corpus il sert massivement l'emphase de prose (« **Un champ vide est
rempli.** ») et pas la citation d'un libellé. L'inclure produirait des
dizaines de faux positifs et une ALLOWLIST qui ne serait plus courte —
donc plus lue. Les guillemets français sont la convention réelle du corpus
pour nommer un contrôle, et c'est celle que le test contrôle.

---

## IT-07 — Verrouillage des invariants du corpus

**Livré.**

- **`question` devient obligatoire**, 2 à 4 par sujet, sur tout le corpus
  (`HelpInvariantsTest::testEveryTopicCarriesTwoToFourQuestions`). Le cas
  « aucune question » a son propre message, parce que c'est l'erreur d'un
  sujet nouveau, et il dit quoi faire : écrire deux à quatre questions
  comme on les taperait — et si une deuxième vraie question ne vient pas,
  réécrire le sujet plutôt que le remplir.
- **`HelpLabelDriftTest`**, le test de dérive des libellés : chaque
  citation entre guillemets français d'un corps doit exister dans
  l'interface (gabarit Twig, script du navigateur, source PHP qui
  construit un libellé). Une ALLOWLIST de 25 entrées porte les exceptions,
  **en toutes lettres et non en nombre**.
- **Documentation** : `docs/module-development.md` § Help topics décrit le
  champ `question` (répétable, 2 à 4, obligatoire, formulées comme on les
  tape, uniques dans tout le corpus, et le diagnostic quand une deuxième
  ne vient pas) et la règle de citation littérale d'un libellé ;
  `AGENTS.md` § Module creation checklist, point 14, les rappelle toutes
  les deux avec le test qui les tient.

**Les deux tests ont été vérifiés en les faisant échouer.** Une fausse
dérive injectée dans `journal` (« Filtre par rubrique ») est nommée
citation par citation ; le même sujet privé de ses questions est refusé
avec le message de diagnostic. Les deux repassent au vert une fois le
fichier restauré.

**Décisions autonomes.**

1. **Les guillemets français seulement, pas le gras.** Le document demande
   les deux. Dans ce corpus le gras sert massivement l'emphase de prose
   (« **Un champ vide est rempli.** ») ; le contrôler produirait des
   dizaines de faux positifs et une ALLOWLIST trop longue pour être lue —
   ce qui est le mode de panne de toute liste d'exceptions. Les guillemets
   sont la convention réelle du corpus pour nommer un contrôle.
2. **L'ALLOWLIST porte les chaînes exactes, pas des compteurs.** Le
   document renvoie à la forme de cliquet de
   `UxConventionsTest::INLINE_TOUCH_PATCH_ALLOWLIST`, qui compte. Le
   cliquet est conservé dans les deux sens (une citation non listée
   échoue ; une citation listée qui se met à résoudre échoue aussi, il
   faut réduire la liste) mais avec les chaînes : « une exception
   remplacée par une autre » est précisément la dérive que ce test
   cherche, et un compteur ne la voit pas.
3. **Deux règles mécaniques évitent trois exceptions** : une citation
   portant des points de suspension (« Référent … », « Nécessite : ... »)
   est un raccourci par construction, et une citation sans la moindre
   lettre (« ⋮ ») n'est pas un libellé.
4. **Un renvoi vers un autre sujet n'est pas une citation d'écran.** Le
   test connaît les titres et les titres de section de tout le corpus et
   les laisse passer, plutôt que de les faire vivre dans l'ALLOWLIST.

**Les 25 exceptions, et pourquoi il n'y en a que six sortes** : une parole
rapportée, une valeur d'exemple, un libellé du navigateur ou du système,
un gabarit à variable, un raccourci nommant deux contrôles voisins, une
fonction nommée en prose. Le docblock de l'ALLOWLIST les énumère, et rien
d'autre n'a le droit d'y entrer.

---

## IT-08 — Le moteur de l'assistant

**Livré** — `Core\Help\Assistant\` :

- **`AssistantCatalog`** : une ligne par sujet,
  `id | titre | résumé | questions`, construite depuis
  `HelpService::listForRole()`. Le filtre de rôle **est** le catalogue :
  un sujet au-dessus du lecteur n'est pas dans le texte que le modèle
  reçoit, donc il n'y a rien à divulguer, à ignorer ou à contourner. Un
  `|` dans un titre est neutralisé pour qu'un sujet ne puisse pas se lire
  comme deux.
- **`AssistantService`** : l'enchaînement complet. Sélection en tier
  `CHEAP` avec `responseSchema` forçant `{"ids": [...]}`, **revalidation
  de chaque id par `HelpService::findById()` au rôle de l'appelant**,
  puis réponse en tier `CAPABLE` sur les corps retenus. Retour :
  `AssistantAnswer` — le texte, les sujets réellement consultés (des
  `HelpTopic`, jamais les ids du modèle), un indicateur « rien trouvé »
  et un indicateur de cache.
- **Le prompt de réponse interdit explicitement** le tableau, le bloc de
  code et le lien : `MarkdownRenderer` ne les connaît pas et les
  afficherait en Markdown brut. Il impose aussi le lexique de design.md
  §7.11 et cinq phrases au maximum.
- **`maxTokens` borné à 500** côté réponse, et `truncated` traité comme
  un échec : une demi-phrase sur une procédure est pire que rien.
- **`AssistantException`** pour ce que l'assistant refuse (quota, question
  vide ou démesurée, connecteur absent, réponse tronquée) ; une
  `LlmException` remonte **telle quelle**, parce qu'elle implémente déjà
  `UserFacingException` et porte déjà une phrase française — la
  ré-emballer relabelliserait un message technique en message visiteur
  (AGENTS.md § Exception messages).
- **Deux tables dans `schema/core.sql`** : `help_assistant_rate_limits`
  (compte par compte, pas par IP — l'assistant est `role_min: chief`,
  donc il y a toujours un compte à débiter, et une empreinte d'IP
  facturerait tout un staff derrière une connexion comme une personne) et
  `help_assistant_cache` (empreinte SHA-256 de la question normalisée + le
  rôle + la version applicative). **Ni l'une ni l'autre ne conserve la
  question** : SECURITY.md §11.
- **`Task\PurgeHelpAssistantHandler`**, auto-replanifié quotidiennement,
  enregistré dans `CoreTaskHandlers`.
- **Journal** : `help_assistant_query` (`info`) avec les ids sélectionnés,
  les jetons et le cache hit/miss, **jamais le texte de la question**.

**Décisions autonomes.**

1. **Le cache est consulté avant que le quota ne soit débité**, et une
   réponse servie depuis le cache ne coûte rien. Servir un travail déjà
   payé ne doit pas dépenser l'allocation de quelqu'un.
2. **Un appel qui échoue chez le fournisseur consomme quand même son
   allocation.** Sinon un fournisseur en panne devient une boucle de
   réessais sans limite.
3. **« Rien trouvé » est mis en cache comme une réponse.** Poser deux fois
   la même question ne doit pas coûter deux fois simplement parce que la
   réponse était « rien ».
4. **Le rôle entre dans la clé de cache**, pas en filtre par-dessus : deux
   rôles posant les mêmes mots sont deux questions différentes, puisque le
   catalogue dont elles sont tirées diffère, et l'une ne doit jamais être
   servie à l'autre.
5. **La fenêtre de quota est une constante du service, pas un réglage.**
   Contrairement à la vérification humaine, dont un administrateur règle
   la fenêtre contre du vrai spam, celle-ci borne ce que l'unité paie à
   son fournisseur d'IA et n'a pas de raison de varier d'une installation
   à l'autre. 20 questions par heure et par compte.
6. **Une réponse tronquée lève `AssistantException`, pas `LlmException`.**
   Le connecteur a fait son travail ; c'est le plafond posé par ce service
   qui a été atteint. Et le cœur ne lève pas l'exception de contrat d'un
   module à la place du module.
7. **Le service est câblé après le bloc `llm_connector`** de
   `public/index.php` et non à côté de `$helpService` : c'est le seul
   consommateur du connecteur qui vive dans le cœur, et
   `$llmConnectorForOthers` n'existe qu'une fois ce bloc passé.

**Divergences constatées.**

1. **Le piège §4.5 est déjà refermé dans ce dépôt.** Le document demande
   qu'un handler de tâche soit « atteignable depuis les deux points
   d'entrée ». Depuis les bugs de §8.17/§8.20, `public/index.php` et
   `public/cron.php` passent tous deux par
   `public/scheduler-bootstrap.php`, qui appelle
   `CoreTaskHandlers::registerAll()` **une seule fois pour les deux**. Une
   ligne dans `CoreTaskHandlers` suffit donc, et l'oubli que le document
   craint n'est plus exprimable.
2. **Le test de comptage des tables de `schema/core.sql` était épinglé à
   45** (`SqlParserTest`) et passe à 47.

**Vérifié par 24 cas** avec un faux `LlmConnectorInterface` qui **conserve
les prompts envoyés** — parce que « aucune donnée de l'unité n'entre dans
un prompt » et « le catalogue est filtré au rôle » sont des affirmations
sur ce qui a été *envoyé*, et ne sont testables qu'ainsi. Couvrent : le
connecteur absent, un tier sans modèle, le catalogue filtré, un id
inventé, un id au-dessus du rôle (refusé même si le modèle le renvoie, et
l'appel de réponse n'a alors pas lieu), le prompt de réponse qui ne porte
que des corps et la question, la question jamais journalisée ni mise en
cache, « rien trouvé », la troncature, l'échec du connecteur, le quota par
compte, l'échec qui consomme quand même, le hit de cache, deux rôles, une
release, la question vide ou démesurée refusée avant tout appel, et
l'ancrage sur la page courante.

**Reporté.** Aucune route, aucune interface : IT-09.

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
