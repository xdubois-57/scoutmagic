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
