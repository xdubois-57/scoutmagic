# Chantier — Locations : le parcours d'une réservation, de la demande à la facture

Roadmap d'exécution en **7 lots, livrés en 3 vagues**. Chaque lot est **une PR**. Les sections
IT-01 à IT-20 plus bas sont la spécification détaillée : leur numéro sert de référence, pas d'ordre ;
c'est la section « Les lots » qui dit quoi faire ensemble et quand.

Le chantier touche surtout le module `modules/rental`, quelques composants génériques du cœur, et une
règle CSS globale découverte sur le formulaire de demande de location.

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `specifications.md` (§22 pour les locations),
`design.md` et `docs/module-development.md`. Ils priment sur ce fichier sur toute règle générale ; si
tu découvres qu'ils décrivent une réalité que le code contredit, **mets-les à jour dans la même PR**.

- **Un lot = une branche, une PR.** Dans un lot, **un commit par partie** (IT-xx), dans l'ordre que
  le lot indique. Rebase sur `main` avant de merger.
- **Lance PHPUnit, PHPStan, et `npm run typecheck` + Vitest localement avant chaque poussée.** La CI
  doit confirmer, pas découvrir les erreurs une par une : chaque aller-retour avec elle est du temps
  perdu pour tout le chantier.
- **Merge et push sur `main` dès que la CI complète est verte** (auto-merge armé :
  `gh pr merge <n> --squash --auto`), sans demander de confirmation. Un test rouge arrête le lot :
  tu corriges, tu ne contournes pas, tu ne désactives rien.
- Tests obligatoires : PHPUnit, PHPStan, et `npm run typecheck` + Vitest dès que tu touches
  `public/assets/js/`.
- Toute modification de `schema.sql` impose de relever la `version` dans `module.json`. **Au rebase,
  un conflit sur cette ligne se règle en reprenant la version de `main` et en la relevant d'un cran** ;
  les autres conflits se règlent en gardant les deux changements.
- Code, commentaires et identifiants en anglais ; interface en français.
- Chaque écran modifié ship son sujet d'aide dans la même PR ; `tests/Core/Help/` échoue sinon.
- Aucune donnée personnelle dans le journal ni dans un message d'erreur.
- Tu avances seul. Tu ne poses une question que sur une **ambiguïté fonctionnelle réelle** ou un
  **changement de conception** non tranché ici.
- Le site est en test : **aucune reprise de données, et aucun texte d'interface ne mentionne un
  état antérieur** (« désormais », « auparavant 48 heures »…).
- **Quand les sept lots sont fusionnés, clôture l'issue d'implémentation** avec un commentaire qui
  liste les PR.

---

## Les lots

### Pourquoi des lots plutôt que vingt itérations

Vingt PR enchaînées sur neuf vagues faisaient attendre la CI neuf fois de suite, alors que beaucoup
d'itérations réécrivaient le travail des précédentes — un bouton posé par l'une et remplacé par une
autre, une liste d'étapes classée puis modifiée, des textes réécrits en fin de parcours —, et que les
itérations dites parallèles se disputaient les mêmes fichiers. Les lots regroupent ce qui touche les
mêmes fichiers, et gardent pour la fin ce qui décrit le résultat de tous les autres.

### Les sept lots

| Vague | Lot | Parties, dans l'ordre |
|---|---|---|
| 1 | **Lot 1 — Finitions indépendantes** | IT-02, IT-03, IT-04, IT-06 |
| 1 | **Lot 2 — Calendriers** | IT-08, puis IT-07 |
| 1 | **Lot 3 — Pages de gestion du bien** | IT-09, puis IT-10 |
| 1 | **Lot 4 — Statuts, blocages et « à traiter »** | IT-11, IT-01, IT-05, IT-12, IT-13, IT-14 |
| 2 | **Lot 5 — Le contrat** | IT-16, puis la section « Un contrat devient caduc » d'IT-20 |
| 2 | **Lot 6 — Les sous-pages de la réservation** | IT-20 (hors caducité), IT-17, IT-18 |
| 3 | **Lot 7 — Ce que voient le gestionnaire et le locataire** | IT-15, puis IT-19 |

**Vague 1 — lance les lots 1, 2, 3 et 4 en même temps**, dans quatre branches et quatre espaces de
travail distincts.

**Vague 2 — lance les lots 5 et 6 en même temps**, dès que leurs prérequis sont fusionnés :

- le lot 5 attend le lot 4 (statut « Contrat envoyé », classification des étapes, coche à la main) ;
- le lot 6 attend les lots 3 et 4 (sortes d'éléments d'état des lieux d'IT-10, étapes et règle « à
  traiter » d'IT-12).

**Vague 3 — le lot 7, une fois les six autres fusionnés.** Le bloc « Et maintenant ? » et les cartes
« Prochaine action » et « Cycle de vie » sont calculés à partir des étapes et des statuts : on les écrit
une fois, quand tout existe. **Les e-mails ajoutés par les lots 5 et 6 partent d'abord sans ce bloc** ; le
lot 7 le leur ajoute, comme à tous les autres, et son test vérifie qu'aucun n'en est privé.

### Ce qui se croise entre lots d'une même vague

- **Vague 1** : `module.json` (lots 2, 3 et 4) — conflit de version à régler selon les conventions ;
  `_attention_row.html.twig` (lots 1 et 4).
- **Vague 2** : `_documents.html.twig` — le lot 5 en retire la génération du contrat, le lot 6 celle de la
  facture et le formulaire de facturation — ; `BookingMilestones`, `module.json` et le service d'envoi des
  e-mails de location.
- Dans chaque cas, la seconde PR à fusionner se rebase et garde les deux changements.

### Les renvois entre parties

Quand une partie cite une autre — « la règle d'IT-05 », « le bloc d'IT-15 » —, c'est un renvoi vers sa
spécification, pas une dépendance d'ordre : l'ordre est celui des lots. Une partie qui modifie une
liste posée par une autre — la classification des étapes d'IT-12, par exemple — la met à jour dans son
propre commit.

---

## IT-01 — Le blocage automatique des dates

### Ce que fait le code aujourd'hui

- Une demande reçue retient ses dates par un **blocage automatique** (`HoldOrigin::AUTOMATIC`)
  d'une durée fixée par le réglage `automatic_hold_hours`, **48 heures par défaut**.
- Une demande encore provisoire n'occupe le bien **que tant qu'un blocage court**
  (`RentalBooking::occupiesTheAsset()`). Passé le délai, les dates redeviennent libres pour les
  autres visiteurs et la demande reste en attente, sans protection. Ce principe est juste et **ne
  change pas** : une demande abandonnée ne doit jamais bloquer ses dates indéfiniment.
- Une **option posée à la main** (`HoldOrigin::MANAGER`) fonctionne autrement : si elle passe sans
  confirmation, la réservation **expire**.
- Sur la page de gestion d'une réservation, la liste « Le dossier » montre une ligne unique pour
  les deux (`BookingMilestones`, clé `hold`). Quand le blocage automatique est passé, cette ligne
  apparaît **comme une tâche non faite**, avec une explication qui décrit l'option manuelle
  (« passée, la demande expire ») — ce qui est faux pour le blocage automatique.

### Ce qui change — **Décidé**

**La durée**

- Le réglage passe **en jours** : `automatic_hold_days`, **défaut 30**, modifiable dans les
  paramètres du module. Il **remplace** `automatic_hold_hours` (site en test : pas de conversion).
  0 désactive toujours le blocage automatique.
- **Le blocage ne dépasse jamais le début du séjour** : son échéance est la première des deux, trente
  jours après la demande ou l'arrivée. Sinon, une demande reçue le 1er octobre pour un séjour du 10
  annoncerait au locataire « Nous bloquons ces dates jusqu'au 31 octobre ».
- La description du réglage est réécrite : jours, plafond au début du séjour, sens de 0, et ce qui se
  passe à l'échéance.

**La ligne « Dates bloquées » devient un état, jamais une tâche**

- Tant que le blocage court : « Dates bloquées automatiquement jusqu'au 29 octobre à 14 h »,
  cochée.
- Une fois passé, si la demande attend toujours une décision : un **avertissement** — « Les dates ne
  sont plus bloquées depuis le 29 octobre : un autre visiteur peut les demander. Confirmez, ou posez
  une option pour les garder. » Vérifie si la tâche d'expiration efface `hold_until` à la levée ; si
  oui, garde ce qu'il faut pour pouvoir afficher cette date.
- Cette ligne **ne devient jamais « L'action suivante »** et ne compte jamais comme une étape restant
  à faire : confirmer bloque les dates définitivement, il n'y a rien à bloquer d'abord.
- **Son explication dépend de l'origine** : pour le blocage automatique, la demande reste en attente
  quand il passe ; pour une option, la réservation expire.

**Le champ « Option jusqu'au »**

- Il affiche **l'échéance du blocage en cours**, automatique ou non. Une échéance passée ne s'affiche
  pas comme une échéance en cours.
- **L'aide sous le champ dit lequel des deux court.** Une date dans un champ nommé « Option » laisse
  croire qu'une option est posée, alors que les deux ne finissent pas pareil — et qu'enregistrer le
  formulaire sans rien changer transforme le blocage automatique en option. Quand c'est le blocage
  automatique : dire que, s'il passe, la demande reste en attente, et qu'enregistrer une date pose
  une option qui, elle, fait expirer la réservation si elle passe sans confirmation.

**Les textes**

- Tout ce qui promet « 48 heures » suit la nouvelle valeur : les docblocks de `HoldOrigin` et de
  `RentalBookingService`, et tout texte vu par le locataire qui en parlerait. L'e-mail qui annonce
  « Nous bloquons ces dates jusqu'au … » reprend l'échéance réelle, plafond compris.

### Tests

Durée par défaut et valeur 0 ; plafond au début du séjour ; disponibilité pendant et après le
blocage ; ligne cochée pendant, avertissement après, jamais « action suivante » ; explication et aide
selon l'origine ; option enregistrée depuis un blocage automatique.

---

## IT-02 — Les conditions retirées de la page du bien

- Sur la page publique d'un bien (`views/public/show.html.twig`), où l'on estime le prix et commence
  une demande, la carte **« Conditions de location »** affiche le texte intégral des conditions et
  son lien de version. **Retire-la.**
- Le formulaire de demande (`views/public/request.html.twig`) a déjà tout ce qu'il faut et **ne
  change pas** : deux cases obligatoires — conditions de location et politique de confidentialité —,
  chacune avec son lien, et la version des conditions acceptée enregistrée via le champ caché
  `conditions_version`, que le serveur vérifie.
- **Le contrôleur cesse de préparer les conditions pour cette page** : c'était leur seul usage dans
  ce gabarit, les garder calculées pour rien serait du code mort.
- **La page permanente des conditions reste en ligne**, à `/locations/{bien}/conditions` et pour
  chaque version archivée : c'est vers elle que pointent la case du formulaire et les e-mails.
- Le commentaire du gabarit qui justifie la carte disparaît avec elle ; l'aide de la page est reprise
  si elle la mentionne.

---

## IT-03 — Les libellés des cases à cocher sur téléphone

### Le défaut

Sur le formulaire de demande de location, sur téléphone, le libellé « J'accepte les conditions de
location » s'affiche **« J'accepte lesconditions de location »**, et **plus bas que sa case**. La
seconde case, dont le libellé passe à la ligne, est correctement alignée.

### La cause — une règle globale

Dans `public/assets/css/app.css`, sur les appareils **sans `pointer: fine`** uniquement,
`.form-check-label` reçoit `display: inline-flex; flex-wrap: wrap; align-items: center;
min-height: 44px`, pour agrandir la zone tactile.

- **L'espace disparaît** : dans une boîte flex, le texte et le lien deviennent des éléments
  séparés, et l'espace à leur frontière est supprimé.
- **Le texte descend** : le libellé est centré verticalement dans une boîte de 44 px, alors que la
  case reste en haut. Un libellé d'une ligne descend d'une demi-ligne ; un libellé de deux lignes
  dépasse 44 px, le centrage n'a plus d'effet, et il paraît aligné.
- Le commentaire au-dessus de la règle affirme que l'alignement case-libellé « n'est pas touché » :
  c'est faux pour tout libellé d'une ligne.

La règle touche **toutes** les cases à cocher et tous les interrupteurs du site sur téléphone : le
consentement de l'inscription, la case « Groupe de discussion » et les noms de groupes du dialogue de
sélection sur la page Médias sociaux, et le reste.

### Ce que le correctif doit garantir

- La **première ligne du libellé alignée sur la case ou l'interrupteur**, qu'il tienne sur une ligne
  ou plusieurs.
- Le texte qui **s'écoule comme un paragraphe normal**, espaces autour des liens compris.
- La **zone tactile de 44 px conservée** : c'est la raison d'être de la règle.
- **Rien de changé sur ordinateur**, où la règle est déjà annulée par le bloc `pointer: fine`.
- Le **commentaire du CSS réécrit** pour dire ce qui est vrai.
- Vérification sur une largeur de téléphone, sur au moins : le formulaire de demande de location, le
  consentement de l'inscription, un interrupteur de configuration, la case « Groupe de discussion »
  et son dialogue. Si le dépôt dispose d'un outil de rendu dans un navigateur, sers-t'en ; sinon,
  dis dans la PR ce qui a été vérifié et comment.

### Lien avec le chantier Médias sociaux

Ce correctif règle le point « Finitions — alignement de la case « Groupe de discussion » et des noms
de groupes » du chantier Médias sociaux. Une fois fusionné, **commente l'issue de ce chantier** pour
le signaler, afin que l'agent qui le prendra ne refasse pas le travail.

---

## IT-04 — « Vos contacts » sur la page de suivi

- Sur la page de suivi d'une location (`views/public/tracking.html.twig`), la section **« Vos
  contacts »** liste les gestionnaires marqués comme contacts du locataire, puis éventuellement un
  numéro d'urgence. Rien ne dit qui sont ces personnes.
- Ajoute **sous le titre une phrase courte** : « Les personnes de l'unité qui gèrent cette location.
  Contactez-les pour toute question sur votre réservation. »
- **Elle n'apparaît que si la liste des contacts n'est pas vide.** Quand la section ne montre que le
  numéro d'urgence, qui n'est pas forcément celui d'un gestionnaire, la phrase serait fausse.
- Pas de nom du bien dans la phrase : l'accord (« du », « de la ») dépend du nom et se tromperait.
- L'aide de la page de suivi est reprise si elle décrit cette section.

---

## IT-05 — La notification de nouvelle demande

### Ce que fait le code aujourd'hui

- À l'arrivée d'une demande, `RentalRequestController::sendSubmissionEmails()` envoie aux
  gestionnaires un **e-mail direct** (`RentalBookingMailService::sendManagerNotification()`, gabarit
  `rental.manager_notification`). **Aucune notification** dans l'application ni en push n'existe pour
  une nouvelle demande.
- Ses destinataires viennent de `managerEmails()` : tous les gestionnaires **Staff d'U implicite
  compris**, avec l'adresse lue dans la **fiche membre de l'année scoute en cours**. Un gestionnaire
  pas encore affilié pour l'année, ou sans e-mail dans sa fiche, est ignoré. Une liste vide et un
  échec d'envoi sont **avalés sans aucune trace**.
- C'est le **seul** envoi direct vers des gestionnaires dans le module. Les autres e-mails du module
  vont au locataire ; les rappels destinés aux gestionnaires passent déjà par le système de
  notifications, avec leurs destinataires calculés par `RentalReminderService::managersOf()` : les
  gestionnaires **actifs déclarés sur le bien** (`rental_asset_managers`), **sans le Staff d'U**,
  retrouvés par leur compte.

### Ce qui change — **Décidé**

**Une notification remplace l'e-mail direct**

- Un nouveau type, **`rental.new_request`**, « Nouvelle demande de location », groupe Locations.
- Canaux par défaut : **application, push et e-mail activés**. Toutes les autres notifications du
  module ont l'e-mail désactivé par défaut ; celle-ci non, sinon un gestionnaire sans application ni
  push n'apprendrait l'existence d'une demande qu'en ouvrant le site. Chacun peut ensuite régler ses
  canaux.
- Elle mène **directement à la réservation** dans la gestion, comme le faisait l'e-mail.
- Son texte **ne contient aucune identité du locataire** — le bien et les dates suffisent —, comme
  l'e-mail qu'elle remplace.
- **L'e-mail direct disparaît** : `sendManagerNotification()`, le gabarit `rental.manager_notification`
  et `managerEmails()` sont retirés. Le système de notifications apporte ce qui manquait : l'adresse
  du compte plutôt que celle de la fiche, les échecs inscrits au journal, la réservation de chaque
  envoi contre les doublons, et le réglage de discrétion du compte.

**Les destinataires : les gestionnaires déclarés, avec repli sur le Staff d'U**

- Le calcul des destinataires sort de `RentalReminderService::managersOf()` et devient **une méthode
  partagée de `RentalManagerService`**, utilisée par la nouvelle notification **et** par les rappels
  existants. Une seule règle pour tous les messages destinés aux gestionnaires.
- Règle : les **gestionnaires actifs déclarés sur le bien qui ont un compte**. Le Staff d'U n'est
  **pas** destinataire par défaut.
- **Repli** : si le bien n'a **aucun gestionnaire joignable** — aucun gestionnaire actif, ou aucun
  avec un compte —, les messages vont aux **membres du Staff d'U de l'année en cours qui ont un
  compte**. Le repli vaut pour la nouvelle demande comme pour les rappels, puisque la règle est
  partagée.
- Le recours au repli, et le cas où **personne** n'est joignable, même au Staff d'U, s'inscrivent au
  journal, sans donnée personnelle.

**Un avertissement sur le bien**

- Sur la page de gestion d'un bien, et sur la liste de ses gestionnaires : quand aucun gestionnaire
  n'est joignable, un avertissement dit que **les demandes et les rappels de ce bien sont envoyés au
  Staff d'U**, et comment y remédier.
- Dans la liste des gestionnaires, **chacun qui ne peut pas être prévenu est signalé**, avec la
  raison (pas de compte, gestionnaire inactif). C'est là qu'on peut corriger, pas au moment où une
  demande se perd.

### Tests

Notification créée pour chaque gestionnaire joignable ; Staff d'U non destinataire quand un
gestionnaire est joignable ; repli sur le Staff d'U sinon ; journal au repli et quand personne n'est
joignable ; aucune identité du locataire dans le texte ; plus aucun e-mail direct aux gestionnaires ;
rappels existants passant par la même règle ; avertissement et signalement sur la page du bien.

---

## IT-06 — Le nom du demandeur dans les réservations de la vue d'ensemble

- Sur la **vue d'ensemble d'un bien** (`/mes-locations/{bien}`, `views/management/overview.html.twig`),
  les réservations de « À traiter » et de « En cours en ce moment » ne montrent que leur référence et
  leurs dates. Un gestionnaire reconnaît une réservation à la personne, pas à son code.
- Chaque réservation y affiche **le nom du demandeur**, et **l'organisation qu'il représente quand il
  en a indiqué une**. Les deux sont déjà portés par la réservation (`RentalBooking::$renterName`,
  `$renterOrganisation`) : aucune requête ni aucun déchiffrement supplémentaire.
- Disposition : **le nom en première ligne, en gras**, suivi de l'organisation en texte secondaire ;
  la **référence passe en deuxième ligne**, avec les dates. C'est le nom qu'on cherche des yeux.
- « À traiter » est dessiné par `views/management/_attention_row.html.twig`, **partagé** avec la page
  « Gérer mes locations ». Le changement se fait dans ce gabarit partagé, et les deux pages en
  profitent — c'est la raison d'être du partage, que son docblock rappelle.
- « En cours en ce moment » suit la même disposition.
- Un nom ou une organisation longs passent à la ligne ; rien n'est tronqué.
- Ces pages sont réservées aux gestionnaires du bien : afficher l'identité du demandeur n'y change
  rien à ce qui est déjà visible sur la page de la réservation.

---

## IT-07 — Bloquer des dates directement sur le calendrier

### Ce qui existe aujourd'hui

- La page Calendrier d'un bien (`/mes-locations/{bien}/calendrier`,
  `views/management/calendar.html.twig`) affiche le mois dans une grille, puis la liste des
  « Périodes réservées par l'unité », puis un formulaire pour en créer une en tapant les dates : du,
  au, une **quantité** quand le bien existe en plusieurs exemplaires, et un motif facultatif.
- La grille est le gabarit **partagé du cœur** `partials/month_day_grid.html.twig`, aussi utilisé par
  la fiche d'un membre, la page des staffs et les départs de l'inscription.
- Une période réservée est **une période** — début, fin, motif, quantité —, pas un ensemble de jours.
  Une période peut recouvrir une réservation existante : les deux coexistent, c'est voulu.

### Une période réservée bloque toujours le bien entier — **Décidé**

- **Le champ « Quantité » disparaît** du formulaire, et la mention « (n unités) » de la liste.
- **Une période ne porte plus de quantité** : elle couvre le bien entier, quel que soit son nombre
  d'exemplaires, aujourd'hui comme après un changement de ce nombre. Stocker une quantité ferait
  qu'un bien passé de 10 à 12 exemplaires laisserait 2 exemplaires louables sur une période censée
  être fermée.
- Un membre de l'unité qui ne veut que quelques exemplaires fait une demande comme n'importe quel
  locataire.
- Changement de schéma, donc relèvement de la version de `module.json`. Site en test : pas de reprise
  des périodes partielles existantes.

### Le geste — **Décidé**

- **Toucher un jour et garder le doigt appuyé un court instant**, puis glisser sur d'autres jours :
  tous les jours parcourus sont traités ensemble. L'appui prolongé est ce qui distingue la sélection
  du défilement de la page : un glissé rapide fait défiler normalement, un appui prolongé, signalé par
  un changement visuel, commence la sélection.
- **Le premier jour touché décide du mode** : s'il était libre, tout le glissé bloque ; s'il était
  bloqué par l'unité, tout le glissé débloque.
- **Un simple toucher, ou un clic, bascule un seul jour.** C'est aussi l'alternative sans glisser
  qu'exigent les règles d'accessibilité. **Au clavier**, chaque jour est un bouton qu'on bascule avec
  Entrée ou Espace, avec un nom accessible qui dit la date et son état.
- À la souris, le même geste fonctionne : bouton enfoncé, puis glisser.
- **L'enregistrement part au relâchement**, en une seule requête pour tout le geste. La grille se met
  à jour aussitôt, et revient en arrière avec un message si le serveur refuse. Un message « 5 jours
  bloqués — Annuler » reste quelques secondes ; « Annuler » défait le geste entier.
- **Pas de glissé à travers deux mois** : la grille n'en montre qu'un. Un glissé qui atteint le bord
  du mois s'y arrête.
- **Les jours passés ne réagissent pas.**
- Un jour occupé par une réservation reste traitable, comme aujourd'hui avec le formulaire : les deux
  coexistent. La grille doit alors montrer les deux sur le jour.
- **Un jour à moitié pris** — demi-journée de départ ou d'arrivée, introduites par IT-08 — **se bloque
  et se débloque comme un jour libre** : le premier jour touché, s'il est à moitié pris, met le geste en
  mode « bloquer ».

### Des jours aux périodes — **Décidé**

- Le serveur reçoit les jours et le mode, et **recalcule les périodes** : des jours bloqués contigus à
  une période existante la **prolongent** ; débloquer le milieu d'une période la **coupe en deux**,
  chaque morceau gardant son motif ; débloquer une période entière la supprime.
- Deux périodes voisines ne fusionnent que si elles ont le même motif — le plus souvent aucun, pour
  des jours bloqués au glisser. Une période avec un motif n'avale jamais une période qui en a un autre.
- Ce calcul est **testé par PHPUnit** : prolongement, coupure, suppression, fusion, motifs différents.
  Le geste côté navigateur est testé par Vitest : mode décidé par le premier jour, jours parcourus,
  jours passés ignorés.

### Ce qui reste

- **Le formulaire de saisie des dates disparaît**, avec son bouton « Réserver » : les dates se
  bloquent directement sur le calendrier, sans bouton d'enregistrement. La route qui le recevait
  disparaît avec lui si plus rien ne s'en sert.
- **Une période sur plusieurs mois se bloque en un geste par mois**, puisque la grille n'en montre
  qu'un ; les gestes successifs se rejoignent en une seule période grâce à la fusion décrite plus haut.
- **Le motif d'une période se donne et se modifie dans la liste**, puisqu'aucune période n'en reçoit
  à sa création.
- La suppression d'une période entière depuis la liste, avec sa confirmation, ne change pas : c'est la
  façon la plus rapide de libérer une longue période.

### Le gabarit partagé

L'interaction est **activée sur ce seul calendrier**, par un script à lui. Le gabarit partagé
`month_day_grid` peut gagner ce qu'il faut pour être piloté — des attributs sur les jours —, mais
**rien ne doit changer** dans son rendu ni son comportement sur les autres pages qui l'utilisent.

---

## IT-08 — Les demi-journées sur les calendriers

### Ce qui existe déjà — à réutiliser, pas à réinventer

- Le calendrier du locataire et celui du gestionnaire utilisent **le même mécanisme** : le calcul
  `Modules\Rental\Availability\AvailabilityCalculator` (via `monthDayStates()`), l'état générique du
  cœur `Core\View\MonthGrid\DayState`, la grille partagée `partials/month_day_grid.html.twig`, et
  la feuille `components.css`.
- La demi-journée de **départ** y existe déjà : `DayState::STATE_DEPARTING`, libellé « Départ le
  matin, libre ensuite », dessinée par `.daygrid-day--departing` — une case coupée en diagonale,
  occupée d'un côté, libre de l'autre. Elle n'existe que pour un bien facturé à la nuit, et pas quand
  une nuit tampon est configurée, qui occupe déjà ce jour-là. Ces deux limites restent.

### Défaut 1 — le gestionnaire ne voit pas les demi-journées — **Décidé**

Pour un gestionnaire (`discloseOccupancy: true`), le calcul fait passer les jours **entièrement
occupés** avant la vérification de la fenêtre de réservation, pour qu'une réservation passée ne se
lise pas « Date passée ». Mais **seulement eux** : une demi-journée de départ, ou l'occupation partielle
d'un bien en plusieurs exemplaires, tombe ensuite sur cette vérification et devient **grise,
« indisponible »**, dès qu'elle est passée, dans le délai de préavis, ou au-delà de l'horizon. Le
gestionnaire ne voit donc une demi-journée que si un visiteur pourrait lui-même la réserver.

Correctif : **sur le calendrier du gestionnaire, la fenêtre de réservation ne masque aucun état
d'occupation** — occupé, partiel, demi-journée de départ ou d'arrivée. Elle ne concerne que les
visiteurs. Le calendrier public ne change pas sur ce point.

### Défaut 2 — la demi-journée d'arrivée n'existe pas — **Décidé**

Le jour où commence un séjour est libre le matin, mais s'affiche entièrement occupé. **Il s'affiche
désormais coupé en deux, partout** : chez le locataire comme chez le gestionnaire.

- **Un nouvel état générique au cœur**, `DayState::STATE_ARRIVING`, pendant exact de
  `STATE_DEPARTING`, avec son docblock sur le même modèle ; et **`.daygrid-day--arriving`**, la même
  diagonale inversée : libre d'un côté, occupée de l'autre. Aucun autre mécanisme.
- **Règle symétrique, en nuits** : un jour est « départ » si la nuit précédente est occupée et la
  sienne libre ; « arrivée » si la nuit précédente est libre et la sienne occupée ; si les deux sont
  occupées — un séjour part le matin, un autre arrive l'après-midi —, le jour est **occupé**. Pour un
  bien en plusieurs exemplaires, l'occupation partielle garde la priorité, comme aujourd'hui.
- Mêmes limites que le départ : bien facturé à la nuit uniquement, et aucune demi-journée quand une
  nuit tampon est configurée.
- **Libellé accessible** obligatoire, comme pour tout état : « Libre le matin, arrivée ensuite », en
  pendant de « Départ le matin, libre ensuite ».
- **Côté locataire, le jour d'arrivée devient sélectionnable comme jour de départ, jamais comme jour
  d'arrivée** : on peut quitter le bien le matin où d'autres arrivent l'après-midi. Le serveur
  revalide toujours la plage choisie, en nuits ; c'est lui qui fait foi, pas l'affichage.
- Vérifie ce que produisent les **bords d'une période réservée par l'unité** : si elle couvre des
  journées entières, ses jours de début et de fin ne doivent pas apparaître à moitié libres. Les
  demi-journées disent la vérité de l'occupation existante ; elles n'en changent pas les règles.
- Si une légende des couleurs accompagne une grille, elle gagne la demi-journée d'arrivée.

### Tests

États départ, arrivée et changement le même jour ; bien facturé à la journée ; nuit tampon ; bien en
plusieurs exemplaires ; gestionnaire : aucun état d'occupation masqué par la fenêtre, y compris dans
le passé et le préavis ; locataire : jour d'arrivée sélectionnable en départ et refusé en arrivée ;
bords d'une période de l'unité.

---

## IT-09 — La page Conformité, sur le modèle de la page Documents

### Le modèle à suivre

La page **Documents** de l'Espace chefs d'U (`modules/documents/views/manage.html.twig`) : un en-tête
avec son bouton d'action « Ajouter un document », une liste construite sur le composant partagé
`partials/list_editor.html.twig` — une ligne par document, ses informations, un crayon, une
corbeille —, et **une page séparée** (`form.html.twig`) pour ajouter ou modifier, qui ramène à la
liste après enregistrement. **L'expérience de la page Conformité doit être la même.**

### Ce qui existe aujourd'hui

La page Conformité d'un bien (`/mes-locations/{bien}/conformite`,
`views/management/compliance.html.twig`) montre un tableau « Documents et échéances », avec la
modification et la suppression dans les lignes, puis une carte « Ajouter une entrée » avec son
formulaire : intitulé avec des suggestions, échéance facultative, remarque, document.

### Ce qui change — **Décidé**

- **L'en-tête porte le bouton « Ajouter une entrée »**, comme Documents.
- **Les entrées s'affichent dans `partials/list_editor.html.twig`**, sans le recopier : intitulé,
  échéance avec son badge d'état (expirée, bientôt), remarque, lien vers le document, date du
  dernier rappel ; un crayon et une corbeille par ligne.
- **Ajouter et modifier se font sur une page séparée**, sur le modèle de `documents/form.html.twig` :
  `/mes-locations/{bien}/conformite/nouvelle` et `/mes-locations/{bien}/conformite/{id}/modifier`,
  avec un fil d'Ariane qui ramène à Conformité, et un retour à la liste après enregistrement.
- **La suppression passe par la corbeille du composant de liste**, avec sa confirmation.
- **Le tableau et la carte « Ajouter une entrée » disparaissent.** Les routes `conformite-ajouter` et
  `conformite-modifier` disparaissent si plus rien ne s'en sert ; `conformite-supprimer` s'adapte au
  contrat de suppression du composant de liste.
- **Pas de réordonnancement à la main** : les entrées restent triées par échéance, la plus proche en
  premier, et les entrées sans échéance à la fin. Le composant de liste affiche aujourd'hui toujours
  ses poignées de glisser ; **ajoute-lui une option générique pour s'en passer** — par exemple quand
  aucune adresse de réordonnancement n'est fournie —, sans rien changer pour les pages qui
  l'utilisent déjà.
- **Rien ne se perd** : les intitulés suggérés, l'échéance facultative, la remarque, le téléversement
  du document — et son remplacement lors d'une modification —, la date du dernier rappel.
- Mêmes droits qu'aujourd'hui : les gestionnaires du bien.
- La page de formulaire ship son sujet d'aide ; celui de Conformité est repris.

### La notification existe déjà, et ne change pas

« Document de conformité expirant » (`rental.compliance_expiring`) part déjà vers les gestionnaires
du bien, 60 jours avant l'échéance par défaut (`reminder_compliance_expiring_days`), délai réglable
ou désactivable par bien, et toujours pour une entrée déjà expirée. Elle suivra la règle de
destinataires d'IT-05. Une entrée sans échéance ne déclenche rien : la page de formulaire le dit sous
le champ Échéance.

### Tests

Liste triée par échéance sans poignée de glisser ; ajout, modification avec et sans nouveau
document, suppression ; droits d'accès des deux nouvelles pages ; pages existantes du composant de
liste inchangées.

---

## IT-10 — La page Gabarits : une liste, une page par document, des listes sans rechargement

### Ce qui existe aujourd'hui

- La page **Gabarits** d'un bien (`/mes-locations/{bien}/gabarits`,
  `views/management/templates.html.twig`) empile, pour chaque gabarit — contrat, facture —, une carte
  avec son éditeur riche complet, ses alertes (modèle standard, personnalisé avec réinitialisation,
  mots-clés non reconnus) et, pour la facture, la phrase d'exonération de TVA. Le panneau des
  mots-clés (`_keywords.html.twig`) est en tête de page. Suivent les cartes « Publication dans le
  calendrier », « Compteurs » et « Modèle d'état des lieux ».
- Les **conditions de location** se modifient ailleurs, sur la page **Réglages**
  (`_conditions.html.twig`), dans une carte de lecture avec un dialogue. Cette place a été choisie
  pour les sortir du mode configuration réservé au superadmin, pas parce qu'elle leur convenait.
- Le **document d'une réservation** a déjà sa page dédiée (`document_editor.html.twig`) : éditeur,
  mots-clés, avertissement des mots-clés non reconnus. C'est le modèle de page à suivre.

### Ce qui change — **Décidé**

**La page Gabarits devient une liste**

- En tête, la liste des **documents modifiables** du bien : le contrat, la facture, et les
  **conditions de location**, qui quittent la page Réglages. **Sans leur contenu.**
- Chaque ligne donne le nom du document, son état — « Modèle standard » ou « Personnalisé » pour un
  gabarit, la date de la version en vigueur pour les conditions —, un signal quand des mots-clés ne
  sont pas reconnus, et **un crayon qui ouvre la page dédiée**.
- La liste utilise **le même composant que Documents et Conformité**, `partials/list_editor.html.twig`,
  sans glisser ni corbeille : un document ne se supprime ni ne se réordonne. C'est l'option ajoutée
  par IT-09, d'où l'ordre des deux parties dans le lot 3.
- Le panneau des mots-clés quitte la page de liste : il vit sur les pages dédiées.
- **La carte « Publication dans le calendrier » reste telle quelle.** Les cartes « Compteurs » et
  « Modèle d'état des lieux » changent, voir plus bas.

**Une page dédiée par document**

- `/mes-locations/{bien}/gabarits/{document}`, sur le modèle de la page du document d'une
  réservation : un en-tête au nom du document, un fil d'Ariane qui ramène à Gabarits, l'éditeur, et
  **les mots-clés disponibles pour ce document-là**, visibles d'emblée plutôt que repliés — c'est la
  raison d'être de la page. Sur un grand écran, ils peuvent se placer à côté de l'éditeur.
- **Contrat et facture** arrivent avec tout ce que porte leur carte aujourd'hui : l'explication du
  modèle standard, la réinitialisation avec son aperçu et sa confirmation, l'avertissement des
  mots-clés non reconnus, et pour la facture la phrase d'exonération de TVA. Le bandeau « chaque
  réservation en prend sa propre copie » vit sur ces deux pages.
- **Conditions de location** arrivent avec tout ce que porte leur carte : le texte standard de secours
  et son alerte, l'explication des versions et de l'empreinte, le lien vers la page publique de la
  version en vigueur. Le bandeau des gabarits ne s'y affiche **pas** : les conditions ne sont pas
  copiées dans chaque réservation, elles sont publiées et versionnées, et ce bandeau mentirait à leur
  sujet. Elles ne passent pas par le moteur de mots-clés et **n'en gagnent pas** : c'est le texte
  exact qu'un locataire accepte et dont l'empreinte est enregistrée. Leur page le dit en une phrase à
  la place du panneau des mots-clés.
- **Enregistrer ramène à la liste**, avec un message de confirmation, comme Documents et Conformité.

**Les compteurs et le modèle d'état des lieux, modifiables sans recharger la page**

- Aujourd'hui, chacune de ces deux cartes est une liste simple avec un bouton « Retirer » par ligne,
  et un formulaire d'ajout dessous ; chaque action recharge la page.
- **Les deux listes passent sur `partials/list_editor.html.twig`**, comme la liste des documents
  au-dessus : **ajouter, retirer et réordonner se font sans rechargement**.
- **Le composant de liste recharge aujourd'hui la page après un ajout.** Apprends-lui, de façon
  générique, à **insérer la nouvelle ligne sur place** — par exemple à partir du fragment de ligne
  rendu par le serveur —, sans rien changer pour les pages qui l'utilisent déjà. Le retrait et le
  réordonnancement se font déjà sans rechargement.
- **Modèle d'état des lieux** : le champ **« Ordre » disparaît**. C'était un numéro de position,
  0 par défaut, réglable seulement à l'ajout et jamais ensuite ; on ne pouvait déplacer un élément
  qu'en le retirant et en le recréant. Il est remplacé par **le glisser du composant** — poignée sur
  grand écran, flèches sur téléphone —, l'ordre étant enregistré en arrière-plan. L'ordre reste celui
  qui est recopié dans chaque réservation à sa confirmation ; les copies déjà figées ne changent pas.
- **Compteurs** : **pas de réordonnancement**, l'ordre n'y a pas d'importance — c'est l'option
  « sans glisser » d'IT-09. Les champs d'ajout — nom, type, unité, frais « relevé de compteur » — ne
  changent pas.
- **Le formulaire d'ajout des compteurs s'affiche mal sur ordinateur.** L'aide du champ « Prix
  unitaire » — six lignes sur l'absence de frais « relevé de compteur » — est placée **sous le champ,
  dans une colonne étroite** : elle s'étire sur toute la hauteur, casse l'alignement de la rangée, et
  les listes « Type » et « Prix unitaire » sont tronquées (« Électricit », « Relevé s »). C'est
  exactement le cas que `design.md` §7.6 décrit : une aide qui concerne une rangée alignée par le bas
  va **sous la rangée**, pas sous un champ. Corrige ainsi :
  - **Quand un frais « relevé de compteur » existe**, aucune aide : la première option de la liste,
    « Relevé seul, non facturé », dit déjà ce qui se passe sans frais.
  - **Quand aucun n'existe**, la liste reste désactivée et **une seule ligne sous la rangée** suffit :
    « Pour facturer la consommation, ajoutez un frais « relevé de compteur » dans la tarification. »,
    avec le lien vers la tarification.
  - Les colonnes laissent aux deux listes la place d'afficher leur valeur en entier.
- **Modèle d'état des lieux — deux sortes d'éléments.** Chaque élément est de l'une des deux :
  - **« Quantité »** — ce qui se compte, avec un **nombre attendu**, un entier d'au moins 1 :
    « Chaises — 40 », « Clés — 3 ». C'est la sorte par défaut, avec 1 comme nombre.
  - **« Oui / Non »** — ce qui ne se compte pas et se constate : « Cuisine propre », « Chauffage
    coupé », « Poubelles vidées ». L'intitulé s'écrit comme **ce qui doit être vrai** : la réponse
    attendue est toujours « Oui ». L'aide de la rangée d'ajout le dit en une phrase.
  - La sorte et le nombre attendu se choisissent **à côté de l'intitulé** dans la rangée d'ajout — le
    nombre n'apparaît que pour « Quantité » —, et se **modifient sur place** dans la ligne, enregistrés
    sans rechargement.
  - La sorte et le nombre attendu sont **recopiés dans chaque réservation à sa confirmation**, avec
    l'intitulé et l'ordre, dans `rental_booking_inventory` : une copie figée ne change pas quand le
    modèle change. Les copies déjà
    figées n'en ont pas — site en test, pas de reprise.
  - Elle s'affiche partout où l'état des lieux se lit : sur la page du séjour, à l'entrée et à la
    sortie, et dans tout document qui en est tiré.
  - Changement de schéma pour le modèle et pour la copie : relève la version de `module.json`.
- **Rien ne se perd** : la confirmation « Retirer « … » ? Les relevés déjà pris sont conservés. »,
  l'explication de chaque carte, et l'aide qui renvoie vers la tarification quand aucun frais
  « relevé de compteur » n'existe.

**La page Réglages**

- La carte « Conditions de location » et son lien dans la navigation interne de la page
  (`href="#conditions"`) disparaissent. Le contrôleur des Réglages cesse de préparer les conditions.
- Site en test : pas de redirection. Tout lien interne qui visait la carte vise désormais la page
  dédiée des conditions.

### Tests

Liste des trois documents avec leur état ; page dédiée de chacun, avec ses droits d'accès ; mots-clés
affichés pour le contrat et la facture, aucun pour les conditions ; réinitialisation au modèle
standard ; nouvelle version des conditions à l'enregistrement ; retour à la liste ; Réglages sans les
conditions. Compteurs et éléments d'état des lieux ajoutés et retirés sans rechargement ;
éléments d'état des lieux réordonnés, et l'ordre recopié dans une réservation confirmée ensuite ;
sorte « Quantité » ou « Oui / Non » et nombre attendu saisis, modifiés sur place, recopiés à la
confirmation et affichés pendant le séjour ; aide du prix unitaire selon qu'un frais « relevé de compteur » existe ou non ; pages
existantes du composant de liste inchangées après l'ajout sans rechargement.

---

## IT-11 — Le retrait de l'étape « Mettre en examen »

### Ce qui existe aujourd'hui

- Une demande reçue peut passer au statut **« En cours d'examen »** (`BookingStatus::REVIEWING`) par
  l'action **« Mettre en examen »**. C'est une étiquette interne — « quelqu'un l'a vue et s'en
  occupe » — : **aucun e-mail** ne part au locataire (`RenterDecision::forStatus()` renvoie `null`),
  qui ne voit le changement que sur le badge de sa page de suivi.
- **Elle coupe le rappel « Demande de location sans réponse »**, qui ne se déclenche que pour le
  statut « Demande reçue » (`ReminderPlanner`) : une demande mise en examen puis oubliée n'est plus
  jamais signalée, alors que le locataire n'a toujours reçu aucune réponse.
- Son libellé reprend l'expression judiciaire « mise en examen ».

### Ce qui change — **Décidé**

- **L'action et le statut disparaissent.** Une demande est soit en attente d'une décision de l'unité
  (« Demande reçue »), soit en attente du locataire (« Informations demandées », « Proposition
  envoyée »), soit tranchée.
- **Les transitions qui menaient à « En cours d'examen »** — depuis « Informations demandées » et
  depuis « Proposition envoyée » (`BookingTransition`) — **mènent désormais à « Demande reçue »**,
  sous le libellé existant « Remettre en attente ». C'est la même situation — la demande attend la
  décision de l'unité —, et le rappel « sans réponse » s'y applique.
- **Les réservations déjà au statut `reviewing`** sont ramenées à `received` avec le relèvement de
  version du schéma. Ce n'est pas une reprise de données : sans cela, une ligne portant un statut qui
  n'existe plus ne peut plus être lue.
- **Tout ce qui nomme ce statut suit** : `BookingStatus`, `BookingTransition`, le texte de
  `BookingJourney`, le docblock de `RenterDecision`, le badge `views/_status_badge.html.twig`, le
  contrôleur de gestion, les commentaires de `schema.sql`, `docs/rental-guide.md`,
  `specifications.md` (§22) et l'aide du module.

### Tests

Plus aucune action « Mettre en examen » ; transitions « Remettre en attente » depuis « Informations
demandées » et « Proposition envoyée » ; rappel « sans réponse » après une remise en attente ; une
réservation `reviewing` existante relue comme « Demande reçue ».

---

## IT-12 — « À traiter » tant que l'unité a quelque chose à faire

### Ce qui existe aujourd'hui

- « À traiter » est défini à **un seul endroit**, `Booking\BookingAttention`, qui répond à
  « quelqu'un attend-il après cette réservation ? ». Il alimente quatre affichages : la liste « À
  traiter » de la vue d'ensemble d'un bien, son chiffre, le filtre de la liste des réservations, et le
  badge par bien de « Gérer mes locations ».
- Deux critères seulement (`AttentionReason`) : le **statut** (réservation pas encore tranchée), et
  une **demande de modification en attente**. **Une réservation confirmée disparaît donc de la
  liste**, alors qu'il reste presque toujours des étapes à mener jusqu'à la clôture.
- La page de chaque réservation sait déjà ce qui reste : ses étapes (`BookingMilestones`) et « L'action
  suivante » (`BookingJourney::next()`).

### Ce qui change — **Décidé**

Une réservation non finale est aussi « à traiter » quand :

1. **son étape suivante est à faire par l'unité** ; ou
2. **son étape suivante est à faire par le locataire, et il est en retard.**

**Qui fait l'étape**

- Chaque étape de `BookingMilestones` porte désormais **qui doit la faire** : l'unité ou le
  locataire. À classer explicitement, sans valeur par défaut, et un test vérifie que chaque clé
  d'étape a la sienne.
- Du côté du **locataire** : `CONTRACT_ACCEPTED`, `DEPOSIT_RECEIVED`, `BALANCE_RECEIVED`,
  `SECURITY_DEPOSIT_RECEIVED`. Du côté de **l'unité** : `CONTRACT_SENT`, `ARRIVAL_INVENTORY`,
  `METER_READINGS`, `DEPARTURE_INVENTORY`, `FINAL_SETTLEMENT`, `SECURITY_DEPOSIT_RETURNED`. (IT-16
  remplace `CONTRACT_ACCEPTED` et IT-17 retire `METER_READINGS` : chacune met cette classification à
  jour.) Toute
  autre étape est classée de la même façon, en se demandant qui doit agir pour qu'elle soit faite.
- L'étape considérée est **celle que la page de la réservation présente comme l'action suivante**,
  `BookingJourney::next()`, pour que la liste et la page ne puissent jamais dire deux choses
  différentes.

**Quand le locataire est « en retard »**

- **Au moment où le rappel correspondant partirait** : la même règle et le même délai que
  `ReminderPlanner`, via les rappels déjà définis — `CONTRACT_MISSING`, `DEPOSIT_MISSING`,
  `BALANCE_MISSING`, `SECURITY_DEPOSIT_MISSING`. La liste et les rappels ne se contredisent jamais.
- Le délai compte **même si ce rappel est désactivé** pour le bien : couper une notification ne doit
  pas faire disparaître une réservation de la liste.
- Une étape du locataire **sans délai défini** ne met jamais, à elle seule, une réservation « à
  traiter ».
- `BookingAttention` reste **pur**, sans base ni horloge : la date du jour lui est passée, comme au
  planificateur.

**Ce qui s'affiche**

- **Les deux raisons existantes pour les modifications ne changent pas** : une demande du locataire en
  attente (`RENTER_REQUEST`) et **une proposition de l'unité sans réponse** (`UNIT_PROPOSAL`) mettent
  toujours la réservation « à traiter ». Pour la proposition, c'est **une exception voulue** à la règle
  « le locataire n'est à surveiller que s'il est en retard » : une proposition que personne ne suit
  est la façon dont une réservation se tait pendant trois semaines.
- Deux nouvelles raisons dans `AttentionReason`, et la ligne dit **pourquoi** la réservation est là :
  « À faire : envoyer le contrat », « État des lieux de sortie », ou « En retard : acompte attendu
  depuis le 3 octobre ».
- La liste, le chiffre, le filtre et le badge suivent ensemble, puisque la définition est unique.
- Une réservation finale n'apparaît jamais, comme aujourd'hui.

### Tests

Réservation confirmée avec une étape de l'unité : présente ; avec une étape du locataire dans les
délais : absente ; en retard : présente ; rappel désactivé mais délai dépassé : présente ; étape du
locataire sans délai : absente ; réservation finale : absente ; chaque étape classée ; la raison
affichée correspond à l'action suivante de la page de la réservation.

---

## IT-13 — Le contrat comme réponse : le statut « Contrat envoyé »

### Ce qui existe aujourd'hui

- Le dossier d'une réservation contient **deux lignes pour la même chose**. Dans « La demande », la
  ligne **« Décision prise sur la demande »**, dont le bouton mis en avant est **« Confirmer la
  réservation »**. Dans « L'accord », la ligne **« Réservation confirmée »**, placée après « Contrat
  envoyé », « Conditions et contrat acceptés » et « Acompte reçu ». Les deux se contredisent : confirmer
  dès la décision coche la ligne de L'accord avant même que le contrat soit parti.
- **Envoyer le contrat ne change pas le statut** : la réservation reste « Demande reçue ». Le locataire
  voit « Demande reçue » sur sa page de suivi alors qu'il a un contrat entre les mains, le rappel
  « Demande de location sans réponse » peut partir alors que l'unité a répondu, et rien ne distingue
  dans la liste une demande sans réponse d'une demande dont le contrat est parti.
- **« Proposition envoyée »** (`PROPOSED`) désigne une **contre-offre** : l'unité propose autre chose
  que ce qui a été demandé — d'autres dates, un autre prix —, le locataire accepte ou refuse, et la
  proposition peut expirer à son échéance. Elle ne convient pas pour le contrat, qui accepte la demande
  telle quelle.

### Le déroulé — **Décidé**

1. **La demande** : « Demande reçue », « Dates bloquées » (l'état d'IT-01).
2. **L'accord** : **« Contrat envoyé »** — c'est la réponse de l'unité, et l'action suivante d'une
   demande reçue —, puis « Conditions et contrat acceptés » et « Acompte reçu », côté locataire, puis
   **« Réservation confirmée »**, en dernier, côté unité, avec le bouton « Confirmer la réservation ».

- **La ligne « Décision prise sur la demande » disparaît**, avec son rattachement à la phase « La
  demande » (`BookingPhase`) et le commentaire qui la justifiait : la décision, c'est désormais l'envoi
  du contrat. Sa clé sort aussi de la classification des étapes d'IT-12.
- **« Réservation confirmée » ne vit que dans L'accord**, et c'est une étape de l'unité.
- **Les autres réponses restent disponibles** dans le menu des autres décisions : faire une
  proposition, demander une précision, refuser, annuler. **Confirmer n'en fait plus partie** : voir
  la règle ci-dessous.

### On ne confirme qu'au bout de L'accord — **Décidé**

- **Une réservation ne peut être confirmée que si toutes les étapes applicables qui précèdent
  « Réservation confirmée » sont faites** — par le site ou cochées à la main (IT-14). Une étape « sans
  objet » (un bien sans contrat, sans acompte) ne bloque rien.
- **La règle est appliquée côté serveur, dans `RentalOperationsService::confirm()`**, qui est déjà le
  seul chemin vers le statut confirmé (`BookingTransition` et les autres services le refusent et
  renvoient vers lui). Son refus nomme les étapes qui manquent.
- À l'écran, tant que la règle n'est pas remplie, le bouton « Confirmer la réservation » est remplacé
  par la liste de ce qui manque. Le rond de l'étape « Réservation confirmée » suit la même règle (IT-14).
- Un cas de confiance — un contrat signé sur papier, un acompte remis en main propre — se règle en
  cochant à la main les étapes concernées : c'est explicite, et ça laisse une trace.
- **Un bien sans contrat** : l'action suivante passe à la ligne applicable d'après, l'acompte puis la
  confirmation.

### Le nouveau statut « Contrat envoyé » — **Décidé**

- `BookingStatus::CONTRACT_SENT`, valeur `contract_sent`, libellé **« Contrat envoyé »**.
- **Il est posé automatiquement quand le contrat est envoyé au locataire**, depuis un statut non
  confirmé et non final (« Demande reçue », « Informations demandées », « Proposition envoyée »).
  Il ne fait jamais reculer une réservation déjà confirmée ou finale ; renvoyer le contrat le laisse
  inchangé.
- **Transitions depuis « Contrat envoyé »** : « Confirmer la réservation », « Annuler », et « Remettre
  en attente » vers « Demande reçue ». Toute autre transition suit la logique de `BookingTransition`
  et se justifie dans la PR.
- **Aucun e-mail de décision supplémentaire** : l'e-mail qui porte le contrat suffit
  (`RenterDecision::forStatus()` renvoie `null` pour ce statut).
- **Côté locataire** : sa page de suivi affiche « Contrat envoyé » ; c'est à lui d'agir.
- **Côté unité** : ce statut **n'appelle pas de décision** (`needsAttention` faux). La réservation
  n'est « à traiter » que si le locataire est en retard sur le contrat, par la règle d'IT-12 et le
  délai du rappel `CONTRACT_MISSING`. Le rappel « Demande de location sans réponse » ne concerne
  toujours que « Demande reçue ».

### Les dates restent protégées pendant la signature — **Décidé**

- **À chaque envoi du contrat, le blocage des dates est prolongé pour qu'il reste au moins 15 jours.**
  Si le blocage en cours se termine plus de 15 jours après l'envoi, sa date ne change pas ; s'il se
  termine avant, ou s'il n'y en a plus, il court jusqu'à 15 jours après l'envoi.
- **Toujours plafonné au début du séjour**, comme le blocage automatique d'IT-01.
- Le délai est un réglage du module, **`contract_hold_min_days`, défaut 15**, à côté de
  `automatic_hold_days`.
- **L'origine du blocage ne change pas** : un blocage automatique prolongé reste automatique, une
  option reste une option. Quand aucun blocage ne courait, le nouveau est automatique.
- À l'échéance, les règles d'IT-01 s'appliquent : les dates se libèrent, la réservation reste
  « Contrat envoyé », et l'avertissement de levée s'affiche.

### Ce qui nomme les statuts suit

Le badge `views/_status_badge.html.twig`, les filtres de la liste des réservations, `BookingJourney`,
`BookingTransition`, `specifications.md` (§22), `docs/rental-guide.md` et l'aide du module.

### Tests

Envoi du contrat depuis chaque statut non confirmé ; aucun recul depuis « Confirmée » ; transitions
depuis « Contrat envoyé » ; plus de ligne « Décision prise sur la demande » ; confirmation refusée
tant qu'une étape applicable précédente manque, acceptée quand elles sont faites ou cochées à la main,
non bloquée par une étape sans objet ; action suivante d'une
demande reçue = « Contrat envoyé » ; « Réservation confirmée » dernière ligne de L'accord ; bien sans
contrat ; blocage conservé au-delà de 15 jours, prolongé en deçà, créé s'il n'y en avait plus, plafonné
au début du séjour ; pas d'e-mail de décision ; rappel « sans réponse » arrêté ; « à traiter » seulement
en cas de retard.

---

## IT-14 — Compléter une étape à la main

### Ce qui existe aujourd'hui

- Deux étapes seulement peuvent être cochées à la main — les états des lieux d'entrée et de sortie
  (`BookingMilestones::MARKABLE`), et seulement sur un bien dont le site ne tient pas l'inventaire
  (`MilestoneKind::OFFSITE`). Les coches sont rangées dans `rental_booking_milestone_marks`, avec
  **qui** et **quand**, et tracées par `Core\Audit`.
- Le principe écrit au-dessus de cette table est l'inverse de ce qui est demandé ici : « une étape
  que le site peut déduire n'est jamais enregistrée, pour qu'une coche à la main ne puisse jamais
  côtoyer la vraie réponse ». **Ce chantier change ce principe, délibérément** : des choses se font
  hors du site — un contrat accepté par e-mail, un acompte payé en liquide —, et le gestionnaire doit
  pouvoir le dire. Le commentaire de la table, `specifications.md` et l'aide sont réécrits en
  conséquence.

### Ce qui change — **Décidé**

**Compléter**

- **Toute étape à faire peut être complétée à la main**, en touchant **le rond bleu numéroté** de la
  ligne du temps, même quand le site ne peut pas la vérifier.
- **Un dialogue de confirmation explicite** s'ouvre, parce que ce n'est pas la bonne pratique :
  « Marquer « Acompte reçu » comme fait sans que le site ait pu le vérifier ? À ne faire que si c'est
  réglé ailleurs — par e-mail, en liquide… Le site ne le vérifiera plus. » Il utilise le squelette
  partagé `partials/modal.html.twig`.
- **La coche à la main a exactement les effets de la coche automatique** : l'étape compte comme faite
  pour « L'action suivante », pour « À traiter » (IT-12) et pour **les rappels**, qui ne relancent plus
  une étape cochée — un acompte payé en liquide ne doit plus déclencher « acompte non reçu ».
- **La ligne dit qu'elle a été cochée à la main, par qui et quand** : « Coché à la main par Xavier
  Dubois le 3 octobre ». La table `rental_booking_milestone_marks` existe pour ça ; elle sert
  désormais à toutes les étapes, et l'historique `Core\Audit` garde la trace.

**Rouvrir**

- **Une étape cochée à la main se rouvre** en touchant de nouveau son rond, avec une confirmation :
  « Rouvrir « Acompte reçu » ? L'étape redeviendra à faire. »
- **Une étape complétée automatiquement par le site ne se rouvre pas** : son rond n'est pas un bouton.
- **Si le site vérifie lui-même une étape déjà cochée à la main** — l'acompte finit par être pointé
  dans les Finances —, elle devient une étape automatique : la vraie réponse l'emporte, et elle ne se
  rouvre plus.

**Les étapes qui sont un statut ou un état**

- **« Réservation confirmée »** et **« Location clôturée »** ne sont pas des faits qu'on coche : ce sont
  des statuts. Toucher leur rond **déclenche la transition elle-même** — confirmer, clôturer —, avec sa
  confirmation habituelle et les mêmes conditions — confirmer exige que les étapes précédentes soient
  faites (IT-13) —, et ne se « rouvre » pas par le rond : on revient en arrière par les
  transitions de la réservation.
- **« Contrat envoyé »** coché à la main — le contrat est parti par e-mail — produit les effets
  d'IT-13 : le statut passe à « Contrat envoyé » et le blocage des dates est prolongé. Rouvrir cette
  coche remet la réservation à « Demande reçue » si elle est encore « Contrat envoyé » ; le blocage
  n'est pas raccourci.
- **« Demande reçue »** est toujours faite par le site, et **« Dates bloquées »** est un état depuis
  IT-01, pas une tâche : leurs ronds ne sont pas des boutons.

**Accessibilité**

- Chaque rond actionnable est un vrai bouton, cible de 44 px, avec un nom accessible : « Marquer
  « Acompte reçu » comme fait », « Rouvrir « Acompte reçu » ».

### Tests

Coche à la main de chaque sorte d'étape, avec confirmation ; effets sur l'action suivante, « à
traiter » et les rappels ; auteur et date affichés ; réouverture d'une coche à la main ; aucune
réouverture d'une étape automatique ; étape cochée à la main puis vérifiée par le site ; confirmation
et clôture par le rond ; « Contrat envoyé » coché puis rouvert ; ronds inertes de « Demande reçue » et
« Dates bloquées » ; droits d'accès des routes de coche et de réouverture.

---

## IT-15 — Chaque e-mail au locataire dit ce qui l'attend

### Ce qui existe aujourd'hui

- Le locataire reçoit cinq e-mails déclarés dans `module.json` (`emails`) : l'accusé de réception
  (`rental.acknowledgement`), les décisions (`rental.decision` — proposition, précision demandée,
  confirmation, refus, annulation, réponse à une demande de modification), l'envoi d'un document
  (`rental.document` — contrat, facture, décompte), les informations pratiques
  (`rental.practical_info`, aussi envoyé par le rappel du même nom) et le lien de suivi
  (`rental.tracking_link`).
- **Aucun ne dit systématiquement ce que le locataire doit faire ensuite.** L'e-mail du contrat, par
  exemple, dit seulement « Vous trouverez en pièce jointe votre contrat… Conservez ce document » : rien
  sur le fait qu'il doit l'accepter, ni où, ni avant quand. Il ne contient même pas le lien de suivi,
  alors que c'est là qu'il l'accepte.
- Ces e-mails sont personnalisables par un administrateur (`Core\Mail\Template\EmailTemplateRenderer`) :
  un corps personnalisé remplace le corps livré, par simple substitution de variables, et s'insère dans
  un cadre (`email/base.html.twig`) qui, lui, reste du code.

### Ce qui change — **Décidé**

**La règle**

- **Tout e-mail envoyé au locataire se termine par un bloc « Et maintenant ? »**, qui dit, en une ou
  deux phrases :
  - **s'il a quelque chose à faire** — et alors quoi, où, et avant quand : « À vous : acceptez le
    contrat depuis votre page de suivi. Les dates vous sont réservées jusqu'au 17 octobre. » ;
  - **ou qu'il n'a rien à faire** — et ce qui va se passer : « Rien à faire de votre côté pour
    l'instant : nous étudions votre demande et vous enverrons le contrat. »
- **Le lien de suivi** accompagne le bloc dès qu'une action se fait sur la page de suivi. L'e-mail du
  contrat le gagne.

**Une seule source : l'action suivante de la réservation**

- Le bloc est calculé **à partir de l'étape suivante de la réservation**, `BookingJourney::next()`, et
  de **qui doit la faire**, tel qu'IT-12 l'a établi — jamais écrit à la main dans chaque e-mail. Le
  dossier du gestionnaire, « À traiter » et les e-mails au locataire lisent ainsi la même vérité.
- Chaque étape — et chaque statut où aucune étape n'est ouverte : demande reçue, informations
  demandées, proposition envoyée, refus, annulation, expiration, clôture — a **sa phrase pour le
  locataire**, dans ses mots à lui : pas de « jalon », pas de « statut », pas de nom de table. Un test
  vérifie qu'aucune étape ni aucun statut n'en est dépourvu.
- **L'échéance** figure quand elle existe : la fin du blocage des dates (IT-01, IT-13), l'échéance
  d'une proposition, le délai d'un paiement.
- Une étape **cochée à la main** (IT-14) compte comme faite : le bloc passe à la suivante.

**Un bloc que la personnalisation ne peut pas retirer**

- Le bloc fait partie **du cadre**, pas du corps personnalisable : il est ajouté par le code après le
  corps, qu'il soit livré ou personnalisé. Sinon, le premier administrateur qui personnalise un e-mail
  le ferait disparaître sans le savoir, et la règle ne tiendrait plus.
- C'est une **capacité générique du cadre des e-mails du cœur** — un bloc « Et maintenant ? »
  facultatif, fourni par l'appelant —, pas une particularité des locations : un autre module pourra
  s'en servir. Sans bloc fourni, les e-mails existants des autres modules ne changent pas.
- La page de personnalisation des e-mails de location le dit en une phrase : ce bloc est ajouté
  automatiquement, en fonction de la réservation.
- En version texte comme en version HTML.

**Les e-mails concernés**

Les cinq : accusé de réception, décisions, documents (contrat, facture, décompte), informations
pratiques, lien de suivi. Pour un document, le bloc dépend de ce que le document appelle : accepter le
contrat, régler la facture, régler le solde ou attendre la restitution de la caution.

### Tests

Bloc présent dans chaque e-mail au locataire, en texte et en HTML, avec un corps livré et avec un
corps personnalisé ; une phrase pour chaque étape et chaque statut ; échéance affichée quand elle
existe ; lien de suivi dans l'e-mail du contrat ; étape cochée à la main sautée ; e-mails des autres
modules inchangés.

---

## IT-16 — Les deux signatures du contrat

### Ce qui existe aujourd'hui

- **Le locataire n'a aucun moyen d'accepter ni de renvoyer le contrat.** Le bouton « Accepter » de
  sa page de suivi ne sert qu'aux propositions de l'unité. L'étape « Conditions et contrat acceptés »
  ne se coche que quand un gestionnaire dépose lui-même un document `SIGNED_CONTRACT` — et son aide,
  « Le locataire accepte depuis sa page de suivi », décrit une fonction qui n'existe pas.
- **Personne ne signe pour l'unité.**
- Les **conditions de location** sont déjà acceptées lors de la demande : case obligatoire, version et
  empreinte enregistrées. Les faire accepter une seconde fois avec le contrat n'a pas de sens.
- Le projet a déjà ce qu'il faut pour les PDF : **dompdf** génère les documents, **FPDI** sait
  reprendre les pages d'un PDF existant. Pas de nouvelle dépendance.

### Le parcours — **Décidé**

1. **L'envoi.** Le contrat part **sans signature**, avec deux emplacements prévus — le locataire et
   l'unité. Statut « Contrat envoyé », blocage des dates d'au moins 15 jours (IT-13).
2. **Le locataire signe et dépose sa copie** sur sa page de suivi — PDF, scan ou photo : signer un PDF
   sur un téléphone Android est la principale difficulté de ce parcours, une photo d'un exemplaire
   imprimé et signé doit suffire. Le dépôt est confirmé à l'écran.
3. **Le gestionnaire vérifie et contresigne, en un seul geste.** Il ouvre la copie reçue et choisit :
   - **« Valider et contresigner »**, avec confirmation ;
   - ou **« Refuser la copie »**, avec un court motif, 300 caractères au plus, envoyé au locataire,
     qui peut en déposer une autre.
4. **Le document final.** À la contresignature, le site reprend la copie du locataire — convertie en
   PDF si c'est une photo — et **lui ajoute une dernière page** : « Contresigné pour l'unité par
   Xavier Dubois, le 5 octobre », la signature enregistrée, la référence de la réservation et la date
   de la copie reçue. Une page ajoutée plutôt qu'une signature posée sur une page existante : sur un
   scan ou une photo, le site ne peut pas savoir où se trouve l'emplacement. Le résultat est rangé
   comme `SIGNED_CONTRACT`.
5. **Le locataire reçoit le contrat signé par les deux parties** par e-mail, et peut le télécharger
   depuis sa page de suivi.

**Un contrat modifié après son envoi repart pour un tour complet** : la copie signée porterait
l'ancienne version.

### La signature du gestionnaire — **Décidé**

- Chaque gestionnaire **enregistre la sienne une fois** : tracée au doigt ou à la souris, ou importée
  depuis une image. Une page « Ma signature » dans l'espace des locations, et un accès direct depuis
  le dialogue de contresignature quand il n'en a pas encore.
- **C'est une donnée sensible** — elle permettrait de fabriquer un faux : **chiffrée**, rangée hors de
  toute adresse publique, **visible et utilisable par son seul propriétaire**, jamais par un autre
  gestionnaire, **supprimable à tout moment**.
- Elle n'est apposée **qu'à la contresignature**, jamais à l'envoi : la signature de l'unité ne circule
  sur aucun exemplaire que le locataire n'a pas encore signé.

### Le téléchargement depuis la page de suivi — **Décidé, et c'est un changement de règle**

- Aujourd'hui, l'e-mail d'un document dit expressément qu'il « ne peut pas être téléchargé depuis le
  site ». **Le contrat signé par les deux parties devient l'exception** : c'est le seul document
  téléchargeable depuis la page de suivi.
- Cette page n'est protégée que par un lien personnel : la route sert **ce seul document, validé, de
  cette seule réservation**, après vérification du jeton, et rien d'autre. L'e-mail du contrat dit
  désormais la vérité sur ce point.

### Les conditions, déjà acceptées — **Décidé**

- **L'étape « Conditions et contrat acceptés » disparaît.**
- L'acceptation des conditions reste visible **comme un fait** : la ligne « Demande reçue » l'indique
  en détail — « conditions acceptées, version du 12 septembre ».
- **Le contrat renvoie à la version des conditions acceptée lors de la demande**, pas à celle en
  vigueur au moment de l'envoi. Vérifie ce que le contrat reprend aujourd'hui, et corrige-le si
  besoin : le locataire ne peut pas être lié par un texte qu'il n'a jamais accepté.

### Le tableau de bord — **Décidé**

Dans « L'accord », dans cet ordre :

1. **Contrat généré** — l'unité. C'est **la première action de L'accord**, et donc l'action suivante
   d'une demande reçue.
2. **Contrat envoyé** — l'unité.
3. **Contrat signé reçu** — le locataire. Se coche seule au dépôt de sa copie ; redevient à faire si
   la copie est refusée.
4. **Contrat contresigné** — l'unité, bouton « Vérifier et contresigner ». Se coche seule à la
   contresignature.
5. **Acompte reçu** — le locataire.
6. **Réservation confirmée** — l'unité.

Les nouvelles étapes remplacent `CONTRACT_ACCEPTED` partout : leur classification (IT-12), leur coche
à la main (IT-14) — un contrat rédigé et envoyé hors du site, une copie remise en main propre, une
contresignature sur papier —, et leur phrase dans le bloc « Et maintenant ? » (IT-15) : tant que le
contrat n'est pas envoyé, le locataire n'a rien à faire, l'unité le prépare.

### Générer et envoyer le contrat depuis le tableau de bord — **Décidé**

- Aujourd'hui, le contrat se génère et s'envoie **depuis la page Documents** (`_documents.html.twig`),
  et le bouton « Préparer le contrat » d'IT-13 y renvoie. **Ces gestes passent sur le tableau de
  bord**, à leur étape, là où le gestionnaire se trouve déjà.
- **À l'étape « Contrat généré »** : un bouton **« Générer le contrat »**, et à côté le lien « Ajuster le
  texte pour cette réservation » vers la page du document de la réservation, pour qui veut le modifier
  avant. L'avertissement « L'adresse du bailleur est vide », qui s'affiche aujourd'hui sur Documents,
  suit le bouton : c'est au moment de générer qu'il sert.
- Une fois généré, l'étape montre le contrat — version et lien pour le relire — et permet de le
  **générer à nouveau** tant qu'il n'est pas envoyé ; le versionnage existant ne change pas.
- **À l'étape « Contrat envoyé »** : un bouton **« Envoyer le contrat »**, avec une confirmation qui dit
  à quelle adresse il part et jusqu'à quand les dates restent bloquées (IT-13). Deux étapes plutôt
  qu'une, pour que le gestionnaire puisse relire le PDF avant qu'il parte.
- **La page Documents perd les boutons « Générer le contrat » et le lien de rédaction du contrat.** Elle
  continue de lister tous les documents, contrat compris, et de permettre de renvoyer un document déjà
  envoyé.

### Les notifications et les e-mails — **Décidé**

**Aux gestionnaires**

- **« Contrat signé reçu »**, nouvelle notification (`rental.signed_contract_received`) : application et
  push activés par défaut, e-mail désactivé, destinataires selon la règle d'IT-05. La réservation passe
  aussi dans « À traiter », puisque l'étape suivante est à l'unité.
- Le rappel existant `CONTRACT_MISSING` porte désormais sur la **copie signée non reçue** ; son
  libellé suit.

**Au locataire**, chacun avec son bloc « Et maintenant ? » d'IT-15 :

- **L'envoi du contrat** : le signer et le déposer sur sa page de suivi avant la fin du blocage des
  dates, avec le lien.
- **Copie refusée** : le motif, et l'invitation à en déposer une autre.
- **Contrat signé par les deux parties** : le PDF final en pièce jointe, et l'étape suivante.
- **Un rappel avant la fin du blocage des dates** si sa copie n'est toujours pas déposée, envoyé une
  fois : sans lui, ses dates se libéreraient sans qu'il en soit prévenu. Délai réglable par bien, comme
  les autres rappels, **3 jours** par défaut.

### Tests

Génération et envoi depuis le tableau de bord, avec confirmation ; nouvelle génération avant envoi ;
Documents sans bouton de génération du contrat ; envoi sans signature ; dépôt d'un PDF, d'un scan et
d'une photo ; refus avec motif et nouveau dépôt ;
contresignature et document final — pages reprises, page ajoutée, signature du bon gestionnaire ;
contresignature sans signature enregistrée ; signature inaccessible à un autre gestionnaire ;
téléchargement depuis la page de suivi limité au document final de cette réservation ; contrat
modifié après envoi ; version des conditions reprise par le contrat ; étapes, notifications, e-mails
et rappel au locataire.

---

## IT-17 — La sous-page « État des lieux »

### Ce qui existe aujourd'hui

- La page **Séjour** d'une réservation (`/mes-locations/{bien}/reservations/{id}/sejour`,
  `views/management/stay.html.twig`) empile quatre parties : **Compteurs**, **État des lieux**,
  **Incidents et dégâts**, **Décompte final**.
- L'état des lieux y est un tableau qui montre **l'entrée et la sortie côte à côte**, chaque élément
  avec un état — Non vérifié, Conforme, Problème, Manquant (`Stay\InventoryState`) — et une note. Il
  n'y a ni quantité, ni validation, ni document produit.
- Les pages d'une réservation (`BookingPage`) sont : Tableau de bord, Finances, Documents, Courrier.
- IT-10 donne à chaque élément du modèle **une sorte** — « Quantité », avec un nombre attendu, ou
  « Oui / Non » —, recopiée dans chaque réservation à sa confirmation.

### Une nouvelle sous-page — **Décidé**

- **« État des lieux »**, `BookingPage::INVENTORY`, à l'adresse `…/etat-des-lieux`, placée **juste
  après « Documents »** dans le menu de la réservation : Tableau de bord, Finances, Documents, État des
  lieux, Courrier.
- **Les parties Compteurs, État des lieux et Incidents et dégâts quittent la page Séjour** pour cette
  sous-page. Séjour ne garde que le Décompte final, qui part à son tour dans la sous-page « Facture »
  en IT-18, où la page Séjour disparaît. Le bouton du tableau de bord « Faire l'état des lieux » mène à
  la nouvelle sous-page ; le bouton « Relever les compteurs » disparaît avec son étape.
- **Les incidents et dégâts y vivent entièrement** : on les **constate** — description, montant
  estimé, photo — et on les **tranche** — ajouter au décompte, retenir sur la caution, ne pas facturer
  —, comme aujourd'hui. On peut en constater pendant tout le séjour, jusqu'à la validation de l'état
  des lieux de sortie ; **les trancher reste possible après**, puisque c'est une décision de
  facturation, pas un constat. Leurs montants sont **reportés dans la sous-page « Facture »** (IT-18),
  en lecture seule, avec un lien vers l'État des lieux pour changer une décision.
- Elle n'apparaît que si le bien a des éléments d'état des lieux ou des compteurs. Un bien dont
  l'inventaire n'est pas tenu sur le site n'y montre que ses compteurs ; ses états des lieux se
  cochent à la main (IT-14).

### Un état des lieux à la fois — **Décidé**

- La sous-page montre **l'état des lieux d'entrée** tant qu'il n'est pas validé, puis **celui de
  sortie**, puis, une fois les deux validés, un récapitulatif en lecture seule avec les deux PDF.
- **Pour chaque élément** : son intitulé, **la valeur de référence**, **la valeur constatée**, et un
  **commentaire**.
  - **Un élément « Quantité »** se constate par **un nombre** ; sa référence est le nombre attendu à
    l'entrée, le nombre constaté à l'entrée pour la sortie.
  - **Un élément « Oui / Non »** se constate par **une liste déroulante** — « — », « Oui », « Non » —,
    pas par une case à cocher : une case non cochée ne distingue pas « non » de « pas encore regardé ».
    Sa référence est « Oui » à l'entrée, la réponse de l'entrée pour la sortie.
- **La valeur saisie est la vérification.** Il n'y a **plus d'état** « Non vérifié / Conforme /
  Problème / Manquant » : `Stay\InventoryState` et les colonnes d'état disparaissent de la page, du PDF
  et du schéma (relèvement de version). Un manque se lit dans le nombre, un « Non » dans la réponse,
  un problème s'écrit dans le commentaire — et un vrai dégât devient un incident, sur la même page.
- **Rien n'est pré-rempli** : sinon chaque élément paraîtrait vérifié sans que personne l'ait regardé.
  Le champ reste vide tant qu'on n'a rien constaté, et un petit bouton **« = »** recopie la référence
  d'un geste quand tout est conforme. Nom accessible : « Reprendre la référence pour *élément* ».
- **Les compteurs** : le relevé d'entrée en mode entrée, le relevé de sortie en mode sortie.
- **Les saisies s'enregistrent sans recharger la page**, ligne par ligne.
- **L'état des lieux de sortie ne commence qu'une fois celui d'entrée validé** — ou coché à la main.

### La validation, le PDF et l'envoi — **Décidé**

- Un bouton **« Valider l'état des lieux d'entrée »** — puis **« … de sortie »** — ouvre une
  **confirmation explicite** : « Valider et envoyer au locataire ? Un PDF sera ajouté aux documents de la
  location et envoyé à *adresse*. L'état des lieux ne pourra plus être modifié. » S'il reste des
  éléments **sans valeur**, la confirmation le dit et en donne le nombre ; la validation reste
  possible, et le PDF les marque « non vérifié ».
- À la validation, le site **génère le PDF** (dompdf, comme les autres documents) : le bien, la
  réservation, les dates, le locataire, chaque élément avec sa référence, sa valeur constatée et son
  commentaire,
  **les relevés de compteurs**, et qui a validé, quand.
  - **Le PDF d'entrée** signale les écarts avec le modèle : « 38 chaises au lieu de 40 attendues »,
    « Cuisine propre : non ».
  - **Le PDF de sortie** calcule **automatiquement la différence entre l'entrée et la sortie** —
    « Il manque 1 chaise », « Cuisine propre : oui à l'entrée, non à la sortie » —, liste les éléments
    commentés, **les incidents
    et dégâts** enregistrés pour la réservation, et **la consommation de chaque compteur** entre les
    deux relevés.
- Le PDF est **rangé dans les documents de la location** (`DocumentType::INVENTORY`) et **envoyé au
  locataire** par l'e-mail de document, avec son bloc « Et maintenant ? » (IT-15).
- **Un état des lieux validé est figé** : il a été envoyé au locataire. Il ne se modifie plus.
- Dans le tableau de bord, les étapes « État des lieux d'entrée » et « État des lieux de sortie » se
  cochent **à la validation**, pas avant.
- **L'étape « Relevés de compteurs » disparaît du tableau de bord** : les relevés font désormais partie
  de chaque état des lieux. La clé `METER_READINGS` sort de `BookingMilestones`, de son rattachement à
  une phase, de `MilestoneEvidence`, de la classification d'IT-12 et des phrases d'IT-15 et d'IT-19.
- **Quand le bien a des compteurs, chaque état des lieux ne se valide qu'avec tous ses relevés** — ceux
  d'entrée pour l'entrée, ceux de sortie pour la sortie. Contrairement aux éléments sans valeur, un
  relevé manquant bloque la validation : l'état des lieux validé est figé, et un relevé de sortie absent
  laisserait le décompte sans consommation, sans moyen de la rattraper.
- Un bien **avec compteurs mais sans éléments d'état des lieux** valide ses relevés de la même façon :
  le PDF ne contient alors que les compteurs. Un bien **sans l'un ni l'autre** garde ses états des lieux
  cochés à la main (IT-14), comme un inventaire tenu hors du site.
- Le décompte final continue d'utiliser la consommation des compteurs, comme aujourd'hui.

### Tests

Sous-page présente ou absente selon le bien ; ordre du menu ; rien de pré-rempli, et le bouton « = »
pour chaque sorte d'élément, à l'entrée et à la sortie ; liste déroulante « Oui / Non » ; plus aucun
état ; saisie sans rechargement ; sortie bloquée avant l'entrée ; confirmation, avec et sans
éléments non vérifiés ; PDF d'entrée avec écarts au modèle ; PDF de sortie avec différences
automatiques, problèmes, incidents et consommations ; document rangé et e-mail envoyé ; état des lieux
figé après validation ; validation refusée tant qu'un relevé manque ; bien à compteurs sans éléments ;
plus d'étape « Relevés de compteurs » ; étapes du tableau de bord ; incidents constatés jusqu'à la validation de la
sortie et tranchés après ; Séjour sans compteurs, état des lieux ni incidents.

---

## IT-18 — La sous-page « Facture », et la fin de la page Séjour

### Ce qui existe aujourd'hui

- Après IT-17, la page **Séjour** ne contient plus que le **Décompte final** : ses propres lignes —
  consommations, incidents tranchés, caution —, qui **ne modifient jamais le prix convenu**.
- Sur la page **Documents** de la réservation (`_documents.html.twig`) : le bouton **« Générer la
  facture »**, et le formulaire des **coordonnées de facturation** — raison sociale, e-mail, adresse —,
  que le locataire remplit depuis sa page de suivi.

### Ce qui change — **Décidé**

**Une sous-page « Facture »**

- `BookingPage::INVOICE`, **« Facture »**, à l'adresse `…/facture`, dans le menu de la réservation
  **juste après « État des lieux »** : Tableau de bord, Finances, Documents, État des lieux, Facture,
  Courrier — dans l'ordre où les choses arrivent.
- Elle réunit, dans cet ordre :
  1. **les coordonnées de facturation**, déplacées depuis Documents, telles quelles ;
  2. **le décompte final**, déplacé depuis Séjour, tel quel — avec **les montants des incidents
     tranchés**, reportés depuis l'État des lieux, en lecture seule, et un lien pour changer une
     décision ;
  3. **la facture** : le bouton **« Générer la facture »**, déplacé depuis Documents, et un bouton
     **« Envoyer la facture »**, qui passe par l'e-mail de document, avec son bloc « Et maintenant ? »
     (IT-15) et une confirmation qui dit à quelle adresse elle part.
- **La page Documents continue de lister** tous les documents de la réservation, facture comprise.
  Elle perd seulement le bouton de génération de la facture et le formulaire de facturation.

**La facture attend la fin de l'état des lieux**

- **« Générer la facture » n'est possible qu'une fois l'état des lieux de sortie complété** — validé
  (IT-17) ou coché à la main (IT-14). Avant, le bouton est remplacé par une phrase qui dit pourquoi,
  avec un lien vers l'État des lieux.
- Un bien **sans état des lieux ni compteurs** — qui n'a donc pas de sous-page « État des lieux » —
  n'attend rien : il n'y a rien à compléter.
- La règle est vérifiée **côté serveur** aussi, pas seulement par l'affichage.

**La fin de la page Séjour**

- **La route `…/sejour`, son gabarit `stay.html.twig` et la boîte `BookingBox::STAY` disparaissent**,
  et avec eux **le raccourci « Séjour » du tableau de bord**.
  Tout ce qui y menait — le bouton « Établir le décompte » du tableau de bord, les liens des
  notifications et des aides — mène à la sous-page qui porte désormais la partie concernée : « État
  des lieux » ou « Facture ». Site en test : pas de redirection.

### Tests

Ordre du menu ; coordonnées de facturation, décompte et facture sur la nouvelle sous-page ; montants
des incidents reportés ; plus de raccourci « Séjour » sur le tableau de bord ; génération refusée
avant la sortie complétée, à l'écran et côté serveur ;
autorisée après validation ou coche à la main ; bien sans état des lieux ; envoi de la facture avec
confirmation et bloc « Et maintenant ? » ; Documents qui liste toujours la facture ; plus aucun lien
vers Séjour.

---

## IT-19 — « Prochaine action » et « Cycle de vie », accordés à tout ce qui précède

### Ce qui existe aujourd'hui

- Le tableau de bord d'une réservation s'ouvre sur la carte **« Où en est cette réservation »**
  (`views/management/_journey.html.twig`), dérivée de `BookingJourney` : un **titre** qui dit ce qui
  retient la réservation (`headline()`), **l'action mise en avant** (`primaryAction()`), les **autres
  décisions** repliées (`otherDecisions()`), puis les **cinq phases** avec leurs étapes numérotées et
  leur description (`BookingPhase::description()`).
- Côté locataire, la page de suivi ouvre sur **« Où en est votre demande »** : le badge du statut et,
  s'il y en a un, le blocage des dates.
- Les autres parties changent presque tout ce que ces textes décrivent : plus de ligne de
  décision ni de statut « en cours d'examen », le blocage devenu un état, le statut « Contrat envoyé »,
  les étapes de génération, d'envoi et de double signature du contrat, les retards du locataire, les
  sous-pages « État des lieux » et « Facture », la fin de la page Séjour. **Cette partie accorde les
  deux cartes à l'ensemble**, dans le dernier lot, une fois tous les autres fusionnés.

### Ce qui change — **Décidé**

**Deux cartes au lieu d'une**

- La carte « Où en est cette réservation » se sépare en **deux cartes** :
  - **« Prochaine action »** — le titre qui dit ce qui retient la réservation, l'action mise en avant et
    les autres décisions ;
  - **« Cycle de vie »** — les cinq phases, avec leurs étapes numérotées et leur description.
- **Une seule dérivation, deux cartes.** Le commentaire de `_journey.html.twig` rappelle que deux cartes
  avaient été fusionnées parce qu'elles lisaient chacune la liste des étapes à leur façon, et finissaient
  par se contredire. Ce qui est séparé ici, c'est l'affichage, pas le calcul : les deux cartes restent
  tirées du même `BookingJourney`, et gardent leurs deux régions rafraîchissables (`next-step` et
  `milestones`), pour qu'une action mette à jour les deux. Le commentaire est réécrit en conséquence.
- Les ancres et liens qui visaient `#parcours` visent la carte qui convient.

**Le titre de « Prochaine action »**

- **Une demande de modification du locataire en attente passe avant tout le reste** (IT-20) :
  « Le locataire demande à changer ses dates : du 14 au 16 novembre. », avec l'action mise en avant
  « Répondre à la demande », qui mène à la sous-page « Modifications ». Une proposition de l'unité sans
  réponse se dit aussi — « Une proposition attend la réponse du locataire. » —, sans action mise en
  avant.
- **Puis le statut, quand c'est lui qui retient la réservation** : « Une précision a été demandée au
  locataire : la suite attend sa réponse. », « Une proposition attend la réponse du locataire. »,
  « Le contrat attend la signature du locataire : les dates sont bloquées jusqu'au 17 octobre. »
- **Puis l'étape suivante**, avec une phrase pour chacune : « Le contrat reste à générer. », « Le
  contrat reste à envoyer. », « Une copie signée attend votre vérification. », « Tout est prêt : la
  réservation peut être confirmée. », « L'état des lieux d'entrée reste à faire. », « La facture reste à
  générer. », etc.
- **Le retard du locataire se dit** (IT-12) : « En retard : acompte attendu depuis le 3 octobre. »
- **La levée du blocage se dit** (IT-01) : une seconde ligne quand les dates ne sont plus protégées
  alors que la réservation n'est pas confirmée.
- Les branches devenues sans objet disparaissent : la ligne de décision, « en cours d'examen »,
  l'option échue comme tâche.
- **Un test énumère chaque clé d'étape et chaque statut**, et échoue s'il en manque la phrase.

**L'action mise en avant**

- Celle de l'étape suivante, quand elle revient à l'unité : « Générer le contrat », « Envoyer le
  contrat », « Vérifier et contresigner », « Confirmer la réservation » — seulement quand la règle
  d'IT-13 est remplie —, « Faire l'état des lieux » — relevés compris — vers la sous-page « État des lieux », « Établir le
  décompte » et « Générer la facture » vers la sous-page « Facture ».
- **Aucune action mise en avant quand la balle est chez le locataire** : le titre dit ce qu'on attend ;
  la coche à la main reste dans l'étape (IT-14).
- **Les autres décisions** : faire une proposition, demander une précision, refuser, annuler. Plus
  « Confirmer » (IT-13).

**Les phases de « Cycle de vie »**

Leurs descriptions suivent le nouveau découpage :

- **La demande** — « Ce que le locataire a demandé, et les conditions qu'il a acceptées. »
- **L'accord** — « Le contrat, généré, envoyé et signé par les deux parties, puis l'acompte : ce qui
  engage les deux parties avant la confirmation. »
- **Avant le séjour** — inchangée.
- **Le séjour** — « Les états des lieux, les relevés et les incidents, pendant et autour du séjour. »
- **Après le séjour** — « La facture, le décompte, la restitution de la caution et la clôture du
  dossier. »

**Côté locataire, « Où en est votre demande »**

- Sous le badge du statut, **la même phrase que le bloc « Et maintenant ? » de ses e-mails** (IT-15),
  tirée de la même source : ce qu'il doit faire, où, avant quand — ou qu'il n'a rien à faire. La page
  et les e-mails ne peuvent pas se contredire.
- Quand son action se fait sur cette page — déposer sa copie signée (IT-16) —, le lien y mène
  directement.

### Tests

Deux cartes tirées du même calcul, toutes deux rafraîchies par une action ; titre, action mise en
avant et autres décisions pour chaque statut et chaque étape suivante ;
« Confirmer » absent des autres décisions et mis en avant seulement quand la règle est remplie ; aucune
action mise en avant quand on attend le locataire ; retard et levée du blocage dits ; descriptions des
phases ; phrase du locataire identique à celle de l'e-mail ; aucune clé sans phrase.

---

## IT-20 — La sous-page « Modifications »

### Ce qui existe aujourd'hui

- Les **demandes de modification du locataire** et les **propositions de l'unité** — « deux bouts du même
  objet », dit la spécification — vivent dans la boîte **« Demandes et propositions »**
  (`BookingBox::CHANGES`, `_box_changes.html.twig` et `_changes.html.twig`), repliée **en bas du tableau
  de bord** : la liste avec leur statut et leur message, « Accepter » et « Refuser » pour une demande du
  locataire, et le formulaire « Proposer ».
- Une demande du locataire en attente, et une proposition de l'unité sans réponse, mettent déjà la
  réservation « à traiter » (`BookingAttention`), et ça ne change pas (IT-12).
- **Aucune notification ne prévient les gestionnaires** qu'un locataire a demandé une modification : ils
  ne l'apprennent qu'en ouvrant la liste « À traiter ».

### Ce qui change — **Décidé**

**Une sous-page**

- `BookingPage::CHANGES`, **« Modifications »**, à l'adresse `…/modifications`. Pas « Demandes et
  propositions » : le mot « demande » entrerait en collision avec « la demande de location ». Le
  locataire, lui, fait une « demande de modification » : c'est son mot qu'on reprend.
- Dans le menu de la réservation, **juste après « Tableau de bord »** : c'est la sous-page qu'on ouvre
  quand quelqu'un attend une réponse. Ordre complet : Tableau de bord, Modifications, Finances, Documents,
  État des lieux, Facture, Courrier.
- **Le nombre de demandes et propositions en attente s'affiche à côté du nom**, « Modifications (1) »,
  pour qu'on voie qu'il faut y aller sans y aller. Si le rail partagé (`partials/nav_rail.html.twig`) ne
  sait pas afficher un compteur, **ajoute-le de façon générique**, facultatif, sans rien changer pour les
  pages qui l'utilisent déjà.
- **La boîte arrive telle quelle** : la liste, les statuts, les messages, « Accepter », « Refuser » et sa
  confirmation, le formulaire « Proposer ». Rien n'est retiré. Seuls ses champs de commentaire
  changent, voir plus bas.
- **La boîte disparaît du tableau de bord**, avec `BookingBox::CHANGES`. Tout lien qui visait la boîte
  vise la sous-page.

**Dans « Où en est cette réservation »**

- Une demande du locataire en attente **passe avant l'étape suivante**, avec « Répondre à la demande »
  (IT-19).

**Des commentaires sur trois lignes au moins**

- Côté locataire, sur sa page de suivi, « Pourquoi ce changement » passe d'une zone de texte de deux
  lignes à **trois**.
- Côté unité, « Message au locataire » du formulaire « Proposer », aujourd'hui un champ d'une ligne,
  devient **une zone de texte de trois lignes**.
- Les mots qui accompagnent « Accepter », « Refuser » et une demande d'annulation sont demandés par le
  dialogue de confirmation partagé (`public/assets/js/confirm.js`, attribut `data-confirm-note`). Il sait
  déjà afficher une zone de texte de trois lignes, mais pas pour ces notes : **ajoute-lui une option
  générique**, par exemple `data-confirm-note-multiline`, et sers-t'en ici, sans changer les autres
  dialogues du site.

**Un contrat devient caduc dès que la réservation change** — **Décidé**

- **Toute modification de ce que dit le contrat le rend caduc** : les dates, le nombre de participants,
  le prix et ses lignes, l'identité et les coordonnées du locataire, le bien — que la modification
  vienne d'une demande acceptée, d'une proposition acceptée ou d'une correction faite par un
  gestionnaire ailleurs dans la réservation.
- **Mécanisme générique plutôt qu'une liste de cas** : à la génération, le site garde l'empreinte des
  valeurs que le contrat a reprises (ses mots-clés résolus). Dès que cette empreinte ne correspond plus à
  la réservation, le contrat est caduc. Ce qui n'apparaît pas dans le contrat — un commentaire interne,
  un paiement pointé, une étape cochée — ne compte pas. Une annulation ne rend rien caduc : la
  réservation s'arrête.
- Cela vaut pour **tout contrat généré** — envoyé ou non, signé ou non —, puisqu'un contrat en attente
  de signature décrirait lui aussi une réservation qui n'existe plus.
- **Le contrat caduc n'est pas supprimé** : il reste dans les documents, marqué « Remplacé — la
  réservation a changé le 12 octobre ». Il n'est plus téléchargeable depuis la page de suivi (IT-16).
- **Les étapes du contrat se rouvrent** : générer, envoyer, signature du locataire, contresignature.
- **Une réservation pas encore confirmée revient à « Demande reçue »** si elle était « Contrat envoyé » :
  un nouveau contrat doit partir. Le blocage des dates n'est pas raccourci.
- **Une réservation déjà confirmée le reste** — revenir en arrière libérerait ses dates —, mais les
  étapes rouvertes la remettent dans « À traiter » (IT-12).
- **Le locataire le lit** dans l'e-mail qui lui annonce le changement : son bloc « Et maintenant ? »
  (IT-15) dit qu'un nouveau contrat va lui être envoyé et que l'ancien ne vaut plus.

**Une notification**

- **« Demande de modification reçue »**, nouvelle notification (`rental.change_request`) : application
  et push activés par défaut, e-mail désactivé, destinataires selon la règle d'IT-05. Elle mène à la
  sous-page « Modifications ». Son texte ne contient aucune identité du locataire : le bien, la
  référence et la nature de la demande suffisent.

### Tests

Ordre du menu et compteur ; boîte déplacée à l'identique, accepter, refuser, proposer ; commentaires sur
trois lignes des deux côtés et dans le dialogue, autres dialogues inchangés ; contrat rendu caduc par
un changement de dates, de participants, de prix ou de coordonnées, pas par un commentaire ni un
paiement ; contrat caduc gardé et marqué, plus téléchargeable ; étapes rouvertes ; retour à « Demande
reçue » avant confirmation, statut confirmé conservé après ; bloc « Et maintenant ? » ; plus de boîte
sur le tableau de bord ; « à traiter » inchangé pour une demande et pour une proposition ; notification
à la demande d'un locataire, sans son identité ; pages existantes du rail inchangées.

---

## Écarté, explicitement

- **Bloquer les dates tant qu'aucune décision n'est prise**, sans échéance.
- **Afficher « Dates bloquées » comme une étape à accomplir** avant de confirmer.
- **Modifier le formulaire de demande** : ses deux cases et l'enregistrement de la version acceptée
  existent déjà.
- **Retirer la page permanente des conditions.**
- **Réduire la zone tactile** des cases à cocher pour corriger leur alignement.
- **Garder un e-mail direct** aux gestionnaires à côté de la notification.
- **Prévenir le Staff d'U** quand un gestionnaire déclaré est joignable.
- **Bloquer une partie seulement des exemplaires** d'un bien par une période de l'unité.
- **Un glissé qui traverse deux mois.**
- **Un dialogue** pour ajouter ou modifier une entrée de conformité : c'est une page séparée, comme
  Documents.
- **Recopier `list_editor`** pour la page Conformité ou la page Gabarits.
- **Des mots-clés dans les conditions de location.**
- **Un statut interne « en cours d'examen »**, sous ce nom ou un autre.
- **Une seconde définition de « à traiter »**, ou un calcul de l'étape suivante propre à la liste.
- **Réutiliser « Proposition envoyée »** pour l'envoi du contrat : c'est une contre-offre.
- **Une ligne de décision** distincte de l'envoi du contrat dans le dossier.
- **Rouvrir une étape que le site a vérifiée lui-même.**
- **Cocher « Réservation confirmée » ou « Location clôturée » sans changer le statut.**
- **Un bloc « Et maintenant ? » écrit à la main dans chaque gabarit d'e-mail**, ou retirable par la
  personnalisation.
- **Un service de signature électronique externe**, et **l'apposition de la signature de l'unité
  avant** que le locataire ait signé.
- **Rendre téléchargeable depuis la page de suivi** un autre document que le contrat signé par les
  deux parties.
- **Modifier un état des lieux validé.**
- **Générer la facture avant que l'état des lieux de sortie soit complété.**
- **Garder une page Séjour**, même réduite.
- **Nommer la sous-page « Demandes et propositions »** : « demande » y désignerait deux choses.
- **Sortir une proposition de l'unité sans réponse de « À traiter ».**
- **Garder un contrat signé valable** après une modification de la réservation, quelle qu'elle soit.
- **Déconfirmer une réservation** parce que son contrat est devenu caduc.
- **Deux calculs** pour « Prochaine action » et « Cycle de vie » : deux cartes, une seule dérivation.
- **Confirmer une réservation** tant qu'une étape applicable de L'accord manque, y compris « pour un
  cas de confiance ».
- **Modifier la carte « Publication dans le calendrier »** de la page Gabarits.
- **Un numéro de position** pour ordonner les éléments de l'état des lieux.
- **Une demi-journée pour un bien facturé à la journée**, ou quand une nuit tampon est configurée.
- **Un mécanisme de demi-journée propre au gestionnaire** : c'est le même état, la même grille et la
  même feuille de style que chez le locataire.
