# Chantier — Lisibilité de la page d'une réservation (issue #462)

Journal d'exécution de la roadmap « Lisibilité de la page d'une
réservation » (itérations IT-01 à IT-05). Une section par itération : ce
qui a été livré, les décisions prises en autonomie, les divergences
constatées entre la roadmap et le dépôt réel, et ce qui a été reporté.
Même format que `docs/chantiers/aide-contextuelle.md`.

La maquette qui fait foi est `docs/chantiers/maquettes/maquette-reservation-462.jsx`.

---

## Vérification des faits de la roadmap

La roadmap a été écrite sur le commit `5af544e` du 23 septembre 2026 ;
`main` était encore exactement sur ce commit au démarrage, donc aucun
écart ne vient d'un changement survenu entre-temps. Chaque fait cité a
été vérifié contre le code. Ce qui tient :

- `BookingMilestone` ne porte que `key`, `label`, `isDone`,
  `isApplicable` et `detail` ; rien ne dit comment une étape se coche.
- « L'action suivante » et « Où en est cette location » sont bien deux
  cartes distinctes de `booking.html.twig`, nourries par la même
  dérivation (`BookingJourney`).
- `BookingBox` a les huit cas cités, et `STAY` est déjà une page à part.
- **D1** : le fil d'Ariane est dynamique. `FrontController` documente le
  cas (« a booking under ITS asset ») et le contrôleur passe un
  `breadcrumb_trail` : Mes locations / le bien / Réservations, puis la
  référence. Vérifié par un rendu réel dans les tests du contrôleur, sur
  les quatre pages, et par le scénario de bout en bout.
- **D8** : la règle « a chip that does nothing is worse than one that is
  not there » est bien écrite dans `modules/rental/views/management/_nav.html.twig`.
- La page « Année scoute » (`core/View/templates/admin/scout_year.html.twig`)
  porte le motif de frise (`step-list`, `step-item`, `step-circle`,
  `app.css`), la phrase « L'ordre est un conseil, pas un verrou » et la
  case « Marquer cette étape comme faite ». Ses phases ont des dates
  indicatives, que la roadmap écarte à raison pour une réservation.
- `InboundMailInterface` et `MailboxPurpose` (`SHARED`, `DEDICATED`)
  existent ; `isDedicatedTo()` répond déjà à la question « cette boîte
  est-elle celle de ce module ? ».
- `MailService` applique bien l'adresse de réponse du site quand
  l'appelant ne passe rien — sauf quand l'appelant remplace l'expéditeur,
  le cas du publipostage, que son code documente.

### Écarts

1. **Les partiels `_journey` et `_box_*` n'existaient pas.** Le parcours
   et les huit boîtes étaient écrits en ligne dans `booking.html.twig`,
   les boîtes s'appuyant sur `_dossier_header.html.twig` et leurs corps
   (`_price`, `_payment`, `_documents`, `_communications`, `_changes`,
   `_comments`). IT-01 crée les `_box_*`.
2. **La maquette jointe était un `.tsx`**, alors que la roadmap la nomme
   `maquette-reservation-462.jsx` et que toutes les autres maquettes du
   dossier sont des `.jsx`. Elle est déposée sous le nom de la roadmap ;
   son contenu est inchangé.
3. **Aucune étape n'est aujourd'hui « hors du site ».** La maquette
   classe les états des lieux et les relevés de compteurs « hors du
   site », avec une case à cocher. Dans le code, les trois se dérivent de
   ce qui est saisi sur la page « Séjour » (`MilestoneEvidence` : chaque
   ligne d'inventaire vérifiée, chaque compteur relevé aux deux bouts), et
   sont « sans objet » quand le bien n'a ni inventaire ni compteur. Une
   case à côté de ces lignes serait précisément la seconde vérité que D5
   interdit. À trancher en IT-02, par le principe de D5.
4. **Les libellés d'étapes de la maquette ne sont pas ceux du code** :
   « État des lieux d'arrivée / de départ » contre « d'entrée / de
   sortie », « Décompte final » contre « Décompte final réglé » ; et le
   code a deux étapes que la maquette ne dessine pas, « Réservation
   confirmée » et « Location clôturée ». La maquette fait foi pour la
   forme du composant, pas pour la liste des étapes : les libellés du code
   sont conservés.
5. **Courrier des camps (IT-03)** : la route `/chefs/camps/courrier/{id}/supprimer`
   **détache** un message d'un séjour (son bouton dit « Détacher de
   « … » ») ; l'API du courrier entrant n'offre aucune suppression d'un
   message. L'écran ne propose pas non plus de recherche plein texte des
   messages : il filtre par état (À trier, Rattachés, Tous, Écartés) et
   cherche des séjours dans le sélecteur de rattachement. La maquette
   montre « Supprimer » et une recherche d'expéditeur ou d'objet. À
   trancher en IT-03.
6. **Le `Reply-To` des locations est déjà explicite (IT-04).** Chaque
   e-mail de `RentalBookingMailService` passe `replyAddressFor()`, une
   adresse signée en `+` qui retombe sur la boîte **dédiée** au module
   quand il y en a une (`ReplyAddressService::mailboxFor()`). Le trou qui
   reste est ailleurs : quand l'opérateur désactive les adresses signées,
   `null` est passé, et c'est alors l'adresse du site qui s'applique. À
   traiter en IT-04.
7. **Un exemple de la maquette se contredit** (relevé en relecture) : le
   dossier « Avant le séjour » a un séjour du 2 au 9 août 2026 et un solde
   attendu pour le 15 septembre, échu de 4 jours — un séjour déjà passé
   dans une phase qui le précède. La maquette est déposée telle quelle,
   puisqu'elle fait foi et n'a pas été écrite ici ; l'incohérence ne touche
   que ses données d'exemple, pas la forme qu'elle fixe.
8. **L'aide de la réservation parlait d'un état antérieur du site** —
   « la carte « État » qui le répétait plus bas n'existe plus », « Où sont
   passés le prix et les paiements », « Pourquoi la carte « État » a-t-elle
   disparu » — ce que la roadmap interdit désormais. Réécrite en IT-01,
   puisque c'est IT-01 qui change la structure qu'elle décrit.

---

## IT-01 — Le rail et la répartition des boîtes

### Livré

- **`Booking\BookingPage`**, les quatre pages d'une réservation :
  Tableau de bord (l'URL de la réservation elle-même, pour que chaque lien
  existant y arrive encore), Finances, Documents, Courrier. L'énumération
  porte l'association page ↔ boîtes ; `BookingBox::page()` et
  `BookingBox::href()` remplacent `isPage()`, si bien qu'un lien du
  parcours vers une boîte vise la page où elle vit, ancre comprise, et
  que `collapse-anchor.js` l'ouvre à l'arrivée.
- **Trois routes** `GET /mes-locations/{slug}/reservations/{id}/finances`,
  `/documents` et `/courrier`, `identified` comme la réservation, avec la
  même autorisation (`RentalAuthorizationService`) : une réservation d'un
  autre bien, ou d'aucun bien géré, est un 404 sur chacune. `rental`
  passe en 1.24.0.
- **Le rail de la réservation** (`_booking_nav.html.twig`, sur
  `partials/page_picker.html.twig`) remplace celui du bien sur ces quatre
  pages. « Courrier » n'y figure que si le courrier entrant relève une
  boîte, et sa route répond 404 sinon. « Séjour » n'y est jamais (D2).
- **La répartition** : Tableau de bord — détails, action suivante,
  parcours, puis demandes, séjour, commentaires et historique ; Finances —
  Prix et Paiements ; Documents — Documents ; Courrier — Courrier. Une
  boîte par partiel `_box_*.html.twig`, sur un cadre commun
  `_booking_frame.html.twig` (titre, badge « En cours », rail, scripts).
- **Le vocabulaire** : « Les détails de la réservation », « Où en est
  cette réservation », « Rien n'attend de vous sur cette réservation ».
- **Tests** : l'autorisation de chaque page (gestionnaire, non-gestionnaire,
  autre bien, anonyme), le fil d'Ariane qui ramène au bien depuis les
  quatre pages, le rail qui remplace celui du bien, « Courrier » absent
  sans boîte, **aucune boîte perdue** (chaque cas de `BookingBox` rendu sur
  sa page, et sur elle seule), le retour à la bonne page sans JavaScript,
  les liens du parcours vers la page de leur boîte ; `BookingPageTest`
  pour l'énumération seule. Chaque test neuf a été vérifié en cassant le
  code qu'il garde.

### Décisions autonomes

1. **Le même dernier maillon sur les quatre pages** : la référence de la
   réservation, comme la maquette le dessine. Le rail, pas le fil
   d'Ariane, dit quelle partie du dossier est ouverte ; chaque ancêtre
   reste un lien, et c'est le chemin du retour vers le bien.
2. **Une boîte s'ouvre dépliée sur la page qui existe pour elle**, et
   reste repliée sur le tableau de bord. Une page Finances dont les deux
   boîtes demandent un clic ne dit rien qu'une porte fermée ne dirait pas.
3. **Un formulaire envoyé sans JavaScript revient à sa page.** Chaque
   formulaire des boîtes déplacées porte `booking_page`, lu contre
   l'énumération fermée : la valeur ne peut désigner qu'une page de la
   réservation, jamais une URL, et tout le reste retombe sur le tableau de
   bord. Avec JavaScript, rien ne change : `rental-booking.js` recharge
   déjà l'URL de la page où il est.
4. **Le sous-titre suit la maquette** — le locataire et les dates, au lieu
   du bien et des dates : le bien est déjà dans le fil d'Ariane.
5. **La page « Séjour » et l'éditeur de document gardent le rail du bien.**
   « STAY ne bouge pas » : ces pages vivent un cran plus bas, sous la
   référence, et leur fil d'Ariane y ramène.
6. **« Mes locations » est conservé** dans le fil d'Ariane et le menu : la
   maquette l'y dessine, et c'est aussi le nom de la route que la roadmap
   exclut de renommer. Le vocabulaire corrigé est celui qui désigne la
   réservation.
7. **La page Courrier montre, en IT-01, le courrier de la réservation** —
   la boîte existante, sous sa condition existante (le module relève une
   boîte). IT-04 change la condition (une boîte dédiée) et le contenu (le
   composant partagé d'IT-03).
8. **Chaque page ne charge que ce qu'elle affiche** (relevé en relecture) :
   le tableau de bord calcule le parcours, Finances lit le prix et les
   paiements, Documents les documents, Courrier la boîte. Le découpage
   est d'abord pour le lecteur, mais une page Finances rechargée après
   chaque ligne de prix n'a pas à relire la boîte e-mail ni l'historique.
9. **Les sujets d'aide suivent dès IT-01** : les trois nouvelles pages
   sont couvertes par les sujets existants (`HelpMenuCoverageTest`, où
   `*` vaut un seul segment, l'exigeait), et le sujet de la réservation
   décrit les quatre pages. Un sujet par page reste l'affaire d'IT-05.

### Le point à trancher : « Espace membres »

Ces routes déclarent `breadcrumb.parents: ["Espace membres"]`, et c'est
le bon menu. « Espace membres » est le menu `espace_animes`, ouvert à
`identified`, celui où vit l'entrée « Mes locations ». Un gestionnaire de
bien n'est pas forcément animateur — la gestion est accordée par bien, à
n'importe quel compte — alors qu'« Espace animateurs » commence à
`intendant` : un parent gestionnaire y verrait un parent de fil d'Ariane
menant à un menu qu'il n'a pas. Aucun autre menu n'est plus juste.

### Reporté

Rien. Le parcours (IT-02), le composant de courrier partagé (IT-03) et la
page Courrier sur boîte dédiée (IT-04) sont les itérations suivantes, pas
des reports.

---

## IT-02 — Le parcours

### Livré

- **Un seul composant** (`_journey.html.twig`) remplace « L'action
  suivante » et « Où en est cette réservation » (D3), en tête du tableau
  de bord comme la maquette le dessine, au-dessus des détails. Il porte
  deux régions rafraîchissables : l'en-tête (`next-step`) et les étapes
  (`milestones`).
- **L'en-tête nomme ce qui bloque** (`BookingJourney::headline()`) — « En
  attente du solde : 350,00 € attendus — échéance dépassée de 4 jours. »,
  jamais « Avant le séjour » — et met en avant **une seule action**
  (`primaryAction()`), les autres décisions repliées derrière « Autres
  décisions (n) » (`otherDecisions()`). L'action proposée est confirmer ou
  clôturer, ou le chemin vers la page où l'étape se règle ; **jamais un
  refus ni une annulation** (D7). Les boutons de transition parlent en
  verbes (`BookingTransition::actionLabel()` : « Confirmer la
  réservation », « Refuser la demande »…) au lieu du nom de l'état visé.
- **La frise** reprend le motif de la page « Année scoute » (D4) : les
  classes `step-list` / `step-item` / `step-circle` d'`app.css`, une
  section par phase, la phase en cours dépliée, chaque étape numérotée,
  avec sa phrase, son bouton ou sa case. Pas de date par phase.
- **`BookingMilestone` porte désormais sa nature** (`MilestoneKind` : se
  dérive du site, à faire ici, en attente du locataire, hors du site), la
  phrase de ce qu'elle demande, l'action qui la fait avancer
  (`MilestoneAction` : une transition ou le chemin vers une boîte) et les
  autres décisions possibles (D6).
- **Les lignes de paiement disent ce qui est dû et quand**
  (`MilestoneEvidence`) : « 350,00 € attendus — échéance dépassée de
  4 jours », « … attendus pour le 15/09/2026 ».
- **« Marquer comme fait »** (`POST /mes-locations/etape`) sur les seules
  étapes hors du site : une case, qui enregistre la date et l'auteur
  (`rental_booking_milestone_marks`) et s'inscrit dans l'historique
  (« Étape hors du site ») comme toute action. `rental` passe en 1.25.0.
- **Tests** : l'en-tête, un par statut de `BookingStatus` ; l'action
  proposée jamais un retrait, pour chaque statut ; chaque décision offerte
  une seule fois ; la nature de chaque étape ; aucune case ailleurs que
  sur une étape hors du site, au rendu comme dans le modèle ; une phase non
  atteinte sans bouton ni case active ; la case qui enregistre qui et
  quand, et l'historique ; un POST fabriqué qui ne coche ni une étape
  dérivée, ni une phase non atteinte, ni un inventaire que la page Séjour
  tient ; le dépôt et le service contre la base.

### Décisions autonomes

1. **Quelles étapes sont « hors du site »** (écart 3). Par le principe de
   D5 : une étape est hors du site quand le site ne tient rien dont la
   dériver. Ce sont les deux **états des lieux** d'un bien dont le site ne
   tient pas l'inventaire — module Séjour inactif, ou bien sans modèle
   d'inventaire. Là où la page Séjour tient l'inventaire, l'état des lieux
   se fait là, ligne par ligne, et n'a pas de case. Les **relevés de
   compteurs** restent « sans objet » quand le site ne connaît aucun
   compteur : un relevé que personne ne peut voir ne se facture pas, et
   une case pour lui ne dirait rien.
2. **Une phase « future » est ce que la machine à états interdit**, pas ce
   que la checklist n'a pas encore atteint. Avant la confirmation, tout ce
   qui suit la demande est inerte. Après, l'ordre reste un guide mais plus
   rien n'est verrouillé : un séjour qui a eu lieu pendant que le contrat
   signé était encore à la poste doit pouvoir voir son état des lieux
   coché. Le premier jet verrouillait tout ce qui suivait la phase en
   cours, et les tests l'ont attrapé sur ce cas exact.
3. **Chaque décision de statut n'apparaît qu'une fois, dans l'en-tête.**
   La maquette montre le bouton deux fois (en-tête et étape) ; deux
   boutons « Confirmer la réservation » sur une page sont une page où
   appuyer sur l'un ou l'autre est un pari sur celui qui compte. Les
   transitions vivent donc toutes dans l'en-tête — l'action proposée et
   les « Autres décisions » —, et une étape de la frise n'offre que le
   chemin vers la page où elle se règle. La relecture de #477 a trouvé le
   cas qui la violait : une réservation confirmée dont l'action proposée
   est un lien offrait « Clôturer la location » et « Annuler la
   réservation » deux fois ; un test compte désormais chaque bouton de
   transition sur la page rendue.
4. **Pour une étape dérivée ou en attente du locataire, l'en-tête mène à la
   page où la réponse apparaîtra** (« Voir les paiements », « Voir les
   documents ») : l'étape elle-même reste sans contrôle (D6).
5. **Aucune relance manuelle n'existe** : les rappels au locataire sont
   automatiques (`RentalReminderService`). Une étape en attente du
   locataire montre donc sa phrase seule — D6 dit « une relance si elle
   existe ».
6. **La case se coche d'un geste**, comme sur la page « Année scoute » :
   `rental-booking.js` soumet le formulaire au changement, par
   `requestSubmit()` pour passer par le chemin asynchrone de la page ; sans
   JavaScript, un bouton `<noscript>` fait la même chose.
7. **Le statut quitte la carte des détails**, comme la maquette le dit : le
   parcours répond déjà à « où en est cette réservation ? ».

### Écarts

- **« Proposer un contrat » n'existe pas.** La maquette en fait l'action
  proposée d'une nouvelle demande. Dans le code, le contrat se prépare
  après la confirmation, et `PROPOSED` est une contre-proposition au
  locataire (« Faire une proposition »). L'action qui fait avancer une
  demande est donc « Confirmer la réservation ».
- **Code mort retiré** : `BookingBox::forMilestone()` et
  `BookingPhase::ofTransition()` n'avaient plus d'appelant — chaque étape
  dit elle-même où elle se règle, et toutes les décisions sont dans
  l'en-tête.

### Reporté

Rien.

---

## IT-03 — Le composant « Courrier » partagé

### Livré

- **Un seul écran de tri, deux modules** (D9) :
  `@inbound_mail/partials/triage.html.twig`, extrait de l'écran du
  courrier des camps. Il porte les onglets et leurs compteurs, « Relancer
  l'analyse », la lecture du message dans une boîte de dialogue, les
  pièces jointes, les rattachements (avec leur origine : un rattachement
  deviné est dit « incertain »), les propositions à confirmer ou écarter,
  le rattachement libre, le détachement, l'écartement et sa reprise. Ce
  qui diffère d'un module à l'autre — le nom de ses objets, l'adresse de
  ses actions, le sélecteur de rattachement — lui est passé dans
  `triage_ui`.
- **La liste elle-même est partagée** : `InboundMailInterface::triageRows()`
  donne les lignes, `Api\TriageScreen` et `Api\TriageFilter` les filtres
  et les compteurs, `Api\ReanalysisReport` la phrase de « Relancer
  l'analyse ». Les camps et les locations en font la même lecture.
- **Les camps** passent sur le composant sans changer de comportement :
  leurs 43 tests d'écran passent tels quels, aux identifiants de la boîte
  de dialogue près.
- **Les locations** : la page « Courrier » d'une réservation rend le même
  composant, sur le courrier de toutes les réservations des biens que le
  gestionnaire gère. Sept routes (`/mes-locations/courrier/…`) passent par
  `bookingAction()` comme tout formulaire de la réservation : même
  autorisation, réponse asynchrone, retour à la page. `rental` passe en
  1.26.0.
- **`mail-message-dialog.js`** (ex-`camps-message-dialog.js`) écoute
  désormais sur le document : la page des locations recharge sa liste en
  place, et « Lire le message » doit s'ouvrir sur les boutons qu'un
  rafraîchissement apporte.
- **Tests** : un même scénario (`TriageScreenScenario`) — rattacher,
  détacher, écarter, remettre — joué par l'écran des camps et par celui
  des locations sur une boîte en mémoire, avec les mêmes états attendus ;
  la liste des locations lue avec les seules références du gestionnaire ;
  un rattachement vers la réservation d'un autre bien refusé ; Vitest de
  la boîte de dialogue sur un bouton arrivé après le chargement.

### Décisions autonomes

1. **La portée des locations est celle du gestionnaire, pas celle de la
   page.** La page Courrier vit sous une réservation, mais le courrier
   d'une boîte ne se trie pas réservation par réservation : la liste
   montre tout ce que le gestionnaire peut rattacher, la réservation de la
   page est simplement le choix proposé par défaut. Chaque action recalcule
   cette portée (`triageBookings()`) au lieu de la croire sur parole.
2. **Un rattachement à la main exige que le message soit dans la liste du
   gestionnaire.** `attach()` laisse la vérification à l'appelant ;
   rattacher un identifiant quelconque à sa propre réservation serait le
   lire.
3. **« Déplacer » disparaît de l'écran**, remplacé par détacher puis
   rattacher, comme aux camps : deux écrans identiques ne peuvent pas
   offrir deux gestes différents pour la même chose. Les méthodes du
   service qui le faisaient restent, avec leurs tests.
4. **Les libellés complets passent par la configuration** (« Rattacher à
   un autre séjour », « … à une autre réservation ») plutôt que d'être
   composés dans le gabarit : l'aide cite ces libellés, et
   `HelpLabelDriftTest` les cherche tels quels.
5. **Un gestionnaire ne lit que le courrier à sa portée** (relevé en
   revue de la PR #480). Une boîte dédiée aux locations est lisible en
   entier par le *module* ; ses gestionnaires, eux, sont chacun sur leurs
   biens. La liste d'un gestionnaire garde ce qui est rattaché ou proposé
   à l'une de SES réservations — et sur chaque ligne, seulement cela — et
   le courrier que rien ne rattache seulement pour qui gère tous les biens
   (le Staff d'U, ou un gestionnaire nommé sur chacun) : il peut concerner
   n'importe lequel. Rattacher, écarter et reprendre passent par la même
   liste. Pour qui ne gère pas tous les biens, la liste est restreinte
   dans la requête, avant la limite de cent messages
   (`findForTriage(…, ownReferencesOnly: true)`) : filtrer après coup les
   cent plus récents de toute la boîte aurait pu ne rien lui laisser.
6. **La liste partagée vit hors de `Api\`** au sens où ARCHITECTURE.md
   §7.5 l'entend (relevé en revue) : une première version y mettait une
   classe de service statique. Les lignes sont une méthode de
   l'interface, écrite une fois (`Service\TriageRowBuilder`) ; ce qui
   reste dans `Api\` est un enum et deux objets-valeurs.

### Écarts (suite de l'écart 5)

- L'écran des camps n'avait **ni suppression ni recherche plein texte**,
  et l'API n'en offre pas : le composant partagé n'en a pas non plus. La
  route `/supprimer` des camps garde son nom et détache, comme avant.
- **Le courrier automatique n'était jamais replié.** L'écran des camps
  lisait `InboundMessage::$isBulk`, une propriété qui n'existait pas :
  PHP levait un avertissement, la lecture valait `null`, et chaque lettre
  d'information comptait comme un message de quelqu'un. Le composant
  partagé en avait hérité. `InboundMessage` porte désormais `isBulk`, lu
  depuis `inbound_messages.is_bulk`.

### Reporté

Rien.

---

## IT-04 — Le courrier sur boîte dédiée

### Livré

- **La page « Courrier » n'existe qu'avec une boîte dédiée aux
  locations, et une seule** (D8). `InboundMailInterface` dit à un module
  quelles boîtes actives lui sont dédiées (`dedicatedMailboxesFor()`,
  `Api\DedicatedMailbox`) ; `RentalCommunicationService::dedicatedMailbox()`
  n'en rend une que s'il y en a exactement une. Sans elle, ni onglet dans
  le rail ni page : l'adresse répond 404. La page nomme sa boîte.
- **Deux boîtes dédiées au même module : dit et journalisé.** La liste
  des boîtes (`/config/courrier-entrant`) affiche un avertissement qui
  nomme le module et les boîtes et dit ce qui disparaît ; l'enregistrement
  d'une portée ou l'activation d'une boîte qui crée ce cas écrit
  `inbound_mailbox_dedication_conflict` (avertissement) au journal.
- **`Reply-To` explicite vers cette boîte.** L'adresse signée reste la
  première (elle tombe déjà sur la boîte dédiée, écart 6) ; quand
  l'opérateur l'a désactivée, `RentalBookingMailService` passe l'adresse
  de la boîte dédiée au lieu de `null`, qui aurait laissé partir la
  réponse vers l'adresse générale du site. Deux boîtes : aucune.
- `inbound_mail` passe en 1.13.0, `rental` en 1.27.0. L'aide
  « Le courrier des locations » et « Ce que chaque module fait d'une
  boîte » disent la condition.
- **Tests** : pas de boîte dédiée ou deux → ni onglet ni page (404) ; la
  page nomme sa boîte ; l'avertissement et l'entrée de journal
  apparaissent à la deuxième boîte dédiée, pas avec une boîte par module ;
  `Reply-To` sur une, deux ou aucune boîte ; `dedicatedMailboxesFor()`
  ignore les boîtes partagées, inactives ou dédiées à un autre module.

### Décisions autonomes

1. **Deux boîtes, c'est aucune**, comme le demande la roadmap, plutôt que
   « la première » : un choix que l'opérateur ne voit pas serait un choix
   arbitraire. Le cas est dit là où il se corrige.
2. **Le journal est écrit au moment où la configuration change**, pas à
   chaque affichage de page : une entrée par changement, lisible à
   distance, plutôt qu'une entrée par visite d'un gestionnaire.
3. **Le courrier de masse n'est pas touché.** Le changement de
   `Reply-To` vit dans `RentalBookingMailService`, que le module
   `mass_mail` n'appelle pas ; ses envois gardent l'adresse de réponse
   qu'ils avaient. Rien à tester de son côté sinon qu'il ne consulte pas
   les boîtes dédiées, ce qu'une recherche dans son code établit.

### Reporté

Rien.
