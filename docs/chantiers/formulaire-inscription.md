# Chantier — Formulaire d'inscription

Journal d'implémentation du document de chantier
`docs/chantiers/CHANTIER-formulaire-inscription.md` (issue #843 : le dépôt
IT-00, puis les itérations IT-01 à IT-11). Une section par itération : ce qui a été fait, les décisions
prises en autonomie, les divergences constatées entre le document de
chantier et le dépôt réel, et ce qui a été reporté. Même format que
`docs/chantiers/aide-contextuelle.md`.

La maquette est `docs/chantiers/maquettes/maquette-formulaire-inscription.jsx`
(v5) ; elle fait foi pour la hiérarchie des écrans, les libellés et les états
d'interaction.

---

## IT-00 — Dépôt des pièces

**Livré.**

- Le document de chantier, déposé tel qu'il a été arrêté avec le mainteneur,
  en `docs/chantiers/CHANTIER-formulaire-inscription.md`.
- La maquette, en `docs/chantiers/maquettes/maquette-formulaire-inscription.jsx`,
  et sa ligne dans `docs/chantiers/maquettes/README.md`.
- Ce journal.
- Le corps de l'issue #843 remplacé par la description reprise avec le
  mainteneur ; le corps d'origine est conservé mot pour mot en bas de page.

**Point de départ.** Le document a été écrit sur `e203dfd` ; `main` est sur ce
même commit au démarrage de l'exécution, il n'y a donc encore aucun écart à
noter. Chaque itération vérifie les faits qu'elle cite avant de s'appuyer
dessus et note ici ce qui a bougé.

**Vérification externe préalable.** `php scripts/check-external-sources.php`
a tourné avant le démarrage : 34 sources vérifiées, 34 conformes, aucune
divergence, donc aucune issue à ouvrir.

**Environnement.** Le conteneur de la session fournissait PHP 8.3, alors que
`composer.json` exige `^8.4` : `vendor/bin/phpunit` et `vendor/bin/phpstan`
refusaient de démarrer, et le hook de démarrage l'annonçait seulement par
« phpunit: unavailable ». PHP 8.4 a été installé à la main (paquets Sury, mêmes
extensions que la 8.3, plus `imap`) avant la première itération de code.

**Divergences entre le document et la maquette** (relevées à la revue de la PR
d'IT-00 ; le document dit lui-même que la maquette fait foi pour la hiérarchie
de l'écran, les libellés et les états d'interaction : c'est la maquette qui
l'emporte, et les passages du document concernés ont été alignés sur elle dans
la même PR, pour qu'un lecteur du seul document ne construise pas l'inverse) :

- **La ligne verrouillée « Limite : le nombre de places de l'année de
  parcours ».** D5 et IT-03 la disent « en tête du classement » ; la maquette
  la place **après** la zone « Classement », avec « Toujours appliquée, en fin
  de classement ». C'est aussi ce que fait le moteur de la file : le
  classement d'abord, la limite de places mord à la fin. IT-03 suit la
  maquette ; D5 et IT-03 le disent désormais.
- **L'exemple d'explication d'IT-03** (« Oui — 6 places restent en 1re année
  Louveteaux. Fratrie déjà présente dans l'unité. ») raisonne en places
  restantes, alors que le modèle raisonne en pourcentage d'occupation et
  qu'une demande « Toujours Oui » ne cite aucun chiffre de capacité. Les
  explications suivent celles que construit `recommend()` dans la maquette
  (« Oui — règle « Toujours Oui » : fratrie déjà présente dans l'unité. »,
  « 1re demande classée sur 4 pour … Avant elle, N places sur C seraient
  occupées (P %) … »). L'exemple du document est remplacé par deux exemples
  construits comme ceux de la maquette, chiffres recalculés sur ses données
  (Louveteaux · 1re année : la fratrie #416 passe d'abord, puis 4 demandes
  classées, la première jugée sur 7 places sur 15, soit 47 %).
- **Le fil d'Ariane.** Le document écrivait « Configuration › … › Demande
  n° … » ; la maquette et le `module.json` actuel ont « Espace chefs d'U » à
  la racine, et la maquette « Demande #… ». Le document suit la maquette.
- **L'acceptation automatique et un seuil « Oui » au-delà de 100 %.** Le
  seuil « Oui » va jusqu'à 120 %, et IT-10 « ne dépasse jamais la capacité
  sauf règle « Toujours Oui » ». Les deux tiennent ensemble à une condition,
  désormais écrite dans IT-10 et la définition de terminé : une demande
  « Oui » qui ferait dépasser la capacité n'est pas acceptée
  automatiquement, elle reste à décider à la main.
- **R1 et la phrase de la maquette sur les demandes encodées.** Sous la
  Vérification des capacités, la maquette dit que « les demandes acceptées
  comptent tant qu'elles occupent une place, qu'elles soient déjà encodées
  dans Desk ou non ». Ce n'est pas un arbitrage de R1, c'est le résultat
  attendu : une demande acceptée occupe **une** place, jamais deux (projetée
  et acceptée) et jamais zéro. R1 reste à trancher dans IT-02 par un test
  qui échoue avant le correctif : il vérifie que le code produit ce résultat.
- **Où vivent les réglages d'envoi, et leur libellé.** D11, IT-06 et IT-09
  parlaient d'une boîte « Réglages › E-mails » et D7 / IT-09 citaient
  l'interrupteur « Envoyer automatiquement les e-mails d'acceptation et de
  refus ». La maquette n'a pas de boîte « E-mails » : l'envoi automatique, son
  délai et l'acceptation automatique forment la sous-boîte « Décisions
  automatiques » de « Capacités par branche », la copie est la boîte « Copie
  des e-mails d'inscription », et le libellé est « Envoyer automatiquement les
  e-mails de décision ». Le document suit la maquette.
- **Grisé, pas masqué.** D3 disait la recommandation et le curseur « visibles
  seulement » si les listes d'attente sont gérées, puis « grisés » quand la
  case est décochée ; IT-03 ajoutait « Visible seulement si … ». La maquette
  garde les seuils affichés, grisés et désactivés (valeurs conservées), et
  laisse les Priorités modifiables avec « sans effet tant que … » ; seule la
  recommandation disparaît (ni calculée ni affichée). D3 et IT-03 le disent.
- **Un seul « Enregistrer » par sous-boîte.** La boîte « Capacités par
  branche » portait trois boutons « Enregistrer », contre la convention d'un
  seul par boîte. Dans la maquette, décisions automatiques, seuils et grille
  des capacités sont désormais trois sous-boîtes bordées, chacune avec son
  bouton ; la convention et D11 le précisent.
- **Le compteur « E-mails de décision à envoyer ».** La maquette ne comptait
  que les envois prévus (1) ; IT-01 le définit comme les acceptées / refusées
  dont l'e-mail n'est pas parti — prévu, en échec ou jamais planifié (2 sur
  les données de la maquette : #390 prévu, #380 en échec). La maquette suit
  IT-01.
- **À trancher dans IT-01 : une date récurrente au 29 février.** On stocke
  `MM-DD` et on affiche la prochaine occurrence ; « 02-29 » n'a pas de
  prochaine occurrence les années non bissextiles (la maquette affiche alors
  un champ vide et « 29/02/2027 »). IT-01 refuse cette date ou la traite
  explicitement, en partant de la validation existante, et note son choix ici.
- Corrections dans la maquette elle-même : les identifiants de la page
  « fiche » passent en anglais (`detailId`, `RequestDetail`) ; une phrase de
  confirmation qui disait « Les 1 décision déjà prise » est accordée ; vider un
  champ de seuil ne fait plus sauter la valeur à une borne (la saisie vide est
  ignorée — le produit fera de même) ; l'exemple chiffré des seuils dit en
  clair quand la bande « Peut-être » ne contient aucun nombre de places ; la
  ligne « Limite » décrit ce que fait vraiment la borne incluse (à 100 %
  d'occupation, « Peut-être » et non « Non ») ; le bouton de simulation de la
  maquette coupe les deux interrupteurs quand il repasse les gabarits « À
  rédiger » ; le refus groupé exige son motif avant « Confirmer », comme le
  dialogue rapide. Puis, à la revue suivante :
  - les champs numériques (seuils, capacité de l'exemple, délai) gardent la
    saisie en brouillon et ne la valident, arrondie et bornée, qu'à la sortie
    du champ ou sur Entrée — on pouvait ne taper que 71 ou 120 dans
    « Peut-être jusqu'à » ; un délai négatif n'est plus accepté ;
  - les capacités sont un état réel, branché sur le tableau de bord et la
    recommandation ; une capacité 0 dit « fermée aux inscriptions » au lieu
    d'un pourcentage vide ; l'acceptation automatique (phrase du dialogue,
    décompte de la confirmation, aide de l'interrupteur) exclut une demande
    classée « Oui » qui ferait dépasser le nombre de places ;
  - le bloc « E-mail de décision » de la fiche part de l'état réel de la
    demande (gabarits à rédiger, prévu à sa vraie date, envoyé, aucun), est
    absent pour une demande en attente, et garde le sélecteur des six
    situations ;
  - le dialogue de décision dit qu'aucun e-mail ne peut partir tant que les
    gabarits ne sont pas rédigés ; sa note est conservée et apparaît sur la
    fiche ; le motif n'est proposé d'après la recommandation que si elle
    existe ; la phrase « le traitement automatique ne la prendra pas » d'un
    doublon ne s'affiche qu'avec l'acceptation automatique ;
  - « Refuser » sur la fiche ouvre la même confirmation avec motif que le
    refus groupé ; le refus groupé repart sans motif à chaque ouverture, et
    ses deux boutons attendent une sélection ;
  - la confirmation des décisions automatiques signale l'e-mail déjà planifié
    qui partira quand même (ou échouera sans gabarit) ;
  - la copie refuse l'enregistrement d'une adresse invalide ;
  - accords « 1 place déjà occupée » dans l'exemple chiffré ; lâcher une
    priorité sur elle-même ne la déplace plus ; sur mobile, la zone
    « Désactivés » vide renvoie au menu plutôt qu'au glisser ;
  - le composant exporté s'appelle `RegistrationFormMockup` (identifiants en
    anglais).

**Faits à trancher en cours de route** (section « Faits vérifiés et pièges »
du document) : R1 dans IT-02, R2 dans IT-02, R3 dans IT-05, R4 dans IT-03, R5
dans IT-06, R6 dans IT-05, R7 dans IT-05, R8 à chaque itération qui touche
une action. Leur conclusion est notée dans la section de l'itération qui les
traite.

---

## IT-01 — Coquille : quatre sous-pages, Tableau de bord, nettoyage

**Livré.**

- `/config/inscriptions` découpé en quatre sous-pages reliées par le rail
  partagé (`views/config/_nav.html.twig` → `partials/page_picker.html.twig`,
  `match_prefix` pour que la fiche garde « Demandes » sélectionné) :
  **Tableau de bord** (`/config/inscriptions`), **Demandes**
  (`/config/inscriptions/demandes`), **Gabarits**
  (`/config/inscriptions/gabarits`), **Réglages**
  (`/config/inscriptions/reglages`). Trois routes GET neuves, `role_min:
  admin` comme les autres, déclarées avant `/demandes/{id}` ; `version`
  du module 6.11.0 → 6.12.0.
- La vue unique `config.html.twig` est remplacée par un gabarit par
  sous-page sous `views/config/`, assemblé de partiels (`_capacity_check`,
  `_notes`, `_states`, `_request_list`, `_non_final`, `_settings_form`,
  `_schedule_field`, `_settings_code`, `_settings_capacities`) : la
  « Vérification des
  capacités » et la boîte « Capacités par branche » (champs de seuil
  compris) sont déplacées telles quelles, commentaires traduits en anglais.
  Plus aucun bloc repliable (ni collapse, ni chevron).
- **Tableau de bord** : les quatre cartes de la maquette, libellés exacts
  (« Formulaire », « Demandes en attente », « E-mails de décision à
  envoyer », « Acceptées, pas encore dans Desk »), en grille
  `row-cols-2 row-cols-md-4`, puis la Vérification des capacités de l'année
  cible. Les trois compteurs sont définis dans
  `RegistrationRequestRepository` (`countPendingForYears()`,
  `countDecisionEmailsToSendForYears()`, `countAcceptedNotEncodedForYears()`),
  jamais dans la vue. La liste « Demandes acceptées non rapprochées » et sa
  méthode `findUnreconciledAcceptedForYear()` (devenue sans appelant)
  disparaissent.
- **Demandes** : notes internes, légende des états (textes de la maquette),
  liste de l'année (sélecteur, filtres, recherche, export, tableau) et
  « Demandes non clôturées » avec leurs actions groupées. La colonne
  « Email » s'appelle « E-mail de décision », comme dans la maquette.
- **Gabarits** : page d'attente avec son titre et son fil d'Ariane ; elle
  dit seulement « Aucun gabarit à afficher. ».
- **Réglages** : ouverture immédiate, programmation annuelle avec deux
  sélecteurs de date natifs qui affichent la prochaine occurrence (on
  stocke toujours `MM-DD`, `Service\RecurringMonthDay`), un seul bouton
  « Enregistrer » pour les deux dates ; boîte du code avec la phrase de la
  maquette ; boîte « Capacités par branche » ; la phrase sur la fenêtre de
  rattrapage a disparu de l'écran (le réglage
  `registration_scheduled_catch_up_days` reste).
- **Fiche** : sous-page de Demandes — titre du module, rail, fil
  d'Ariane Espace chefs d'U › Formulaire d'inscription › Demandes ›
  Demande #… (deux ancêtres déclarés dans `module.json`,
  `breadcrumb_current` « Demande #id ») ; l'onglet du navigateur ne porte
  plus le nom de l'enfant. « Rapprochement manuel » devient « Lier à un
  membre Desk » (bouton « Lier et encoder »).
- « rapproché » disparaît de l'interface du module : vues, aide, message de
  confirmation du lien, message du journal en cas de correspondance
  ambiguë. `grep -ri rapproch modules/registration/views
  modules/registration/help` ne trouve plus rien.
- Redirections : capacités, ouverture immédiate, programmation et code →
  `/config/inscriptions/reglages` ; actions groupées →
  `/config/inscriptions/demandes?year=…` (jeton CSRF refusé compris). Les
  liens de la page Année scoute et de son étape « Confirmer les
  inscriptions » pointent vers Demandes, où sont les actions groupées.
- Sujet d'aide `gerer-les-demandes` réécrit par sous-page, `paths` étendus
  aux trois routes neuves ; `specifications.md` (§ tableau des pages,
  §17.4, §17.6) et `ARCHITECTURE.md` (§8.36) à jour.
- Tests : `RegistrationConfigControllerTest` (rendu et rail de chaque
  sous-page, absence de collapse, de liste « non rapprochées » et de
  « Changer d'année », cartes et leurs chiffres, une seule « Enregistrer »
  par boîte, sélecteurs de date, redirection de chaque POST existant, 29
  février refusé), `RegistrationRbacTest` (désormais chargé depuis le vrai
  `module.json` : admin autorisé, chief refusé sur les trois routes neuves,
  fils d'Ariane complets, pas d'ombre entre `/demandes` et
  `/demandes/{id}`), `RegistrationRequestControllerTest` (fiche sous-page
  de Demandes), `RecurringMonthDayTest`, tests des trois compteurs dans
  `RegistrationRequestRepositoryTest`, et les deux scénarios de bout en bout
  `registration-flow` / `registration-grids` réécrits sur les sous-pages
  (verts localement). Aucun JavaScript ajouté : la conversion
  `MM-DD` ↔ date se fait côté serveur.

**Décisions autonomes.**

- **Le 29 février : refusé.** La validation existante le refusait déjà dans
  le champ texte ; elle le refuse désormais aussi quand il vient d'un
  sélecteur de date une année bissextile (`2028-02-29`), avec un message qui
  propose le 28 février ou le 1er mars, et rien n'est enregistré. Raison :
  `OpenRegistrationHandler::dueDateForYear()` passe par `DateInput`, qui
  refuse le 29 février les années non bissextiles — une telle programmation
  ne se déclencherait qu'une année sur quatre. Le réglage restant modifiable
  en texte libre sur la page Paramètres du site, un `02-29` déjà stocké est
  traité explicitement à l'affichage : le sélecteur montre le 29 février de
  la prochaine année bissextile, le jour où la tâche agirait réellement,
  plutôt qu'un champ vide, et une phrase dit qu'il faut choisir une autre
  date pour pouvoir enregistrer.
- **Le sélecteur montre la date sur laquelle la tâche agira**, pas
  seulement la prochaine occurrence du calendrier
  (`RecurringMonthDay::nextTransition()`, qui reprend la règle de
  `dueDateForYear()` et le marqueur `*_applied_on`) : une date choisie
  quelques jours en arrière, encore dans la fenêtre de rattrapage et pas
  encore appliquée, s'affiche telle quelle avec « Cette date vient de
  passer : l'ouverture aura lieu à la prochaine vérification automatique,
  dans l'heure, puis chaque année. » ; une date déjà appliquée aujourd'hui
  s'affiche à l'année suivante. Sans cela, la page annonçait l'année
  suivante pendant que la tâche ouvrait le formulaire dans l'heure — et la
  phrase qui expliquait la fenêtre de rattrapage a disparu de l'écran,
  comme le document le demande.
- **« E-mails de décision à envoyer » compte aussi les demandes encodées**
  dont l'e-mail d'acceptation n'est pas parti : c'est la définition de
  `RequestStatusService::visibleStatus()`, qui cache une acceptation —
  acceptée ou encodée — tant que l'e-mail n'est pas parti. Le compteur
  compte donc exactement les décisions que la famille ne voit pas encore.
  IT-05 reprend cette définition sur sa table.
- **Les trois compteurs portent sur l'année en cours et l'année cible**,
  jamais sur une année passée (consultation seule, R8). Une inscription en
  cours d'année (avec le code) vise l'année en cours ; une inscription
  ordinaire, l'année cible : compter la seule année cible aurait caché les
  premières.
- **Fil d'Ariane du Tableau de bord** : « Espace chefs d'U › Formulaire
  d'inscription », sans « › Tableau de bord » en dernier. C'est ce que fait
  le dépôt pour tout tableau de bord de module à sous-pages (`/finance`,
  `/config/reinscription`, `/support-dashboard`) : l'entrée du module est
  la page elle-même, et un dernier maillon renvoyant à la page courante
  n'apporte rien. Les trois autres sous-pages et la fiche ont le fil
  complet de la maquette. La racine reste « Espace chefs d'U », celle que
  le `module.json` affichait déjà (le document l'avait aligné à IT-00).
- **« Ouvrir maintenant / Fermer maintenant » reste un bouton d'action
  immédiate**, à côté du seul « Enregistrer » de la boîte (celui des dates).
  La maquette dessine un interrupteur enregistré avec les dates : fondre
  l'ouverture immédiate dans `POST /schedule` aurait changé un comportement
  (journal, règlement des occurrences en cours, `settleDueOccurrences()`),
  ce que IT-01 exclut. Le libellé « Enregistrer la programmation » devient
  « Enregistrer ».
- **Une seule largeur (`page-wide`) pour les quatre sous-pages et la
  fiche** : la largeur est centrée, et changer d'onglet ne doit pas faire
  sauter le titre et le rail.
- **Le titre `<h1>` de la fiche est celui du module**, comme dans la
  maquette ; le nom de l'enfant et le numéro de la demande passent dans le
  titre de la première carte (« Prénom Nom — demande #… »).

**Divergences avec le document.**

- **Ni bannière d'année ni bouton « Changer d'année » n'existaient** sur
  `/config/inscriptions` au commit de départ (ni sur `e203dfd`) : « Changer
  d'année » est le titre de la page Année scoute
  (`/admin/scout-year`), qui n'est pas touchée. Rien à supprimer ; un test
  garde leur absence.
- Le document (et la maquette) parlent d'une colonne « Action rapide », de
  filtres en pastilles et d'une boîte « Demandes non clôturées » sur les
  années précédentes avec sélection : ce sont IT-04 et suivantes. IT-01
  déplace la liste et les actions groupées telles qu'elles sont.
- Les textes des e-mails d'acceptation et de refus n'ont aujourd'hui
  **aucun écran d'édition** (`registration_email_accepted_body` /
  `_refused_body` ne sont écrits que par les tests) : la fiche renvoie à
  « Réglages » pour une rédaction impossible. C'est l'objet d'IT-07
  (Gabarits) ; la page d'attente ne prétend rien d'autre en attendant.
- La phrase « Les autres réglages (adresse d'alerte, textes des emails,
  rétention…) se trouvent sur la page Paramètres » est conservée telle
  quelle en tête de Réglages ; pour les textes des e-mails elle est
  inexacte pour la même raison, et IT-07 la reprendra.

**Reporté.** Rien.

**Faits tranchés.** R8 : les années passées restent en consultation seule —
la liste de Demandes et ses actions groupées les traitent comme avant
(actions masquées et refusées côté serveur), et le Tableau de bord ne les
compte pas.

---

## IT-02 — Fondations de capacité : projection unique, silence = reste, seuils en pourcentage d'occupation

**Livré.**

- **R1 tranché, par un test écrit avant le correctif.**
  `SlotServiceTest::testAnEncodedRequestOccupiesExactlyOnePlace` (une demande
  acceptée puis rattachée au membre que l'import Desk de l'année visée a
  produit) échouait sur le code de `main` : « Failed asserting that 0 is
  identical to 1 ». **Une demande encodée n'était comptée nulle part** : la
  projection simplifiée de `SlotService` ne lisait que l'année en cours (où
  l'enfant n'est pas), et `findAcceptedForYear()` exclut `encoded`. Ni deux
  fois, donc, mais zéro — le créneau s'affichait plus libre qu'il n'était.
  Désormais une demande acceptée occupe **une** place, encodée ou non :
  acceptée, par la ligne « inscription » de la projection ; encodée, par la
  ligne projetée du membre auquel elle a été rattachée (déplacée de
  « Effectif projeté » vers « Demandes acceptées », comme la maquette compte
  #371) ; encodée vers un membre que la projection ne contient pas (un enfant
  qui revient, rattaché à la main au numéro de tiers d'une année antérieure ;
  une ligne Desk de l'année visée sans fonction dans une section), par sa
  propre date de naissance. Seul Desk peut l'en retirer : une ligne de l'année
  visée inactive ou marquée « quitte ». Cinq tests couvrent ces formes,
  chacun vérifié en cassant la branche qu'il protège.
- **R2 tranché : une seule projection.** `SlotService` lit
  `ForecastService::projectedRows()` (les lignes mêmes des Prévisions et
  d'`Api\ProjectedPopulationProvider`) et perd sa projection simplifiée
  (`projectedHeadcountBySlot()`, `acceptedCountBySlot()`, et avec eux
  `SlotMath::feederSlot()` et `PassageRosterRepository::findAnimeAges()`,
  devenus sans appelant). Chaque ligne projetée porte maintenant
  `branch_sort_order` (tiré du même `getEffectiveAge()` qui l'a placée), ce
  qui permet de la ranger dans son créneau sans recalculer un âge : un animé
  en retard ou en avance d'un an tombe dans sa vraie année, y compris celui
  qui est aujourd'hui sous l'âge des Baladins — que l'ancienne projection
  perdait.
- **Silence = reste.** Un animé de l'année en cours sans réponse à la
  réinscription (aucune ligne `registration_reenrollments` pour l'année
  visée) compte comme restant ; seul `member_years.leaving` (posé par la
  réponse « quitte » de la famille ou par le staff) le retire. Le nombre est
  exposé par créneau (`silent`) et affiché « (dont N sans réponse) » dans la
  Vérification des capacités, avec le paragraphe explicatif de la maquette.
  Une ligne Desk de l'année visée est un fait, jamais « sans réponse ».
- **Seuils en pourcentage d'occupation.** `registration_threshold_yes_percent`
  (70) et `registration_threshold_maybe_percent` (100) remplacent les deux
  anciens ratios, sans migration ; type `number`, `validation_regex` 1–120,
  libellés de la maquette. `SlotMath::levelForOccupancy()` remplace
  `tierForRemaining()` : occupation ÷ capacité, borne incluse, calcul en
  entiers (`occupé × 100 ≤ pourcentage × capacité`), NULL toujours Oui, 0
  toujours Non. Les niveaux s'appellent `yes` / `maybe` / `no` ; le public lit
  la même valeur (« Disponible » / « Limitée » / « Complet »), le tableau du
  chef et les Prévisions aussi. Version du module : 6.12.0.
- **Correctif minimal de la page Réglages** : deux champs numériques en %
  (« Oui jusqu'à … % de places occupées », « Peut-être jusqu'à … % de places
  occupées »), validés côté serveur (entiers 1–120, Oui < Peut-être, partagé
  avec la lecture par `SlotMath::parseThresholdPercent()` /
  `isValidThresholdPair()`) ; une paire invalide n'enregistre rien.
- Documentation : `ARCHITECTURE.md` §8.35 et §8.38, `specifications.md`
  §17.6, l'aide (nouveau sujet `capacites-et-listes-d-attente`, voir
  ci-dessous), `schema.sql` (commentaire), `module.json`.

**Décisions autonomes.**

- `SlotService` consomme `ForecastService` directement, pas
  `ProjectedPopulationProvider` : même module, donc pas de frontière `Api\` à
  traverser ; et `ProjectedPopulationService` dépend déjà de `SlotService`
  (pour la date de référence), ce qui aurait fait une dépendance circulaire.
  `ProjectedPerson` ne porte d'ailleurs pas la branche.
- **Seuils grisés dès IT-02** (point d'IT-00 « Grisé, pas masqué ») : les deux
  champs restent affichés quand la gestion des listes d'attente est coupée,
  `disabled`, valeurs enregistrées affichées et conservées, avec la phrase de
  la maquette. Sans JavaScript : basculer l'interrupteur ne les réactive
  qu'après enregistrement ; la glissière et la synchronisation sont IT-03.
  Les champs affichent la paire réellement appliquée
  (`SlotService::levelThresholds()`), qui retombe sur 70 / 100 **en paire**
  si la paire stockée est inutilisable — ce que Configuration > Réglages
  permet, puisqu'il valide chaque clé seule ; l'encart le dit alors dans un
  avertissement.
- Le badge du tableau du chef dit maintenant « Limitée » / « Complet » (au
  lieu de « Attente limitée » / « Attente importante ») et celui des
  Prévisions « Complet » (au lieu de « Importante ») : un seul vocabulaire,
  celui de la maquette et de la page publique.
- La Vérification des capacités, qu'IT-01 a placée sur le Tableau de bord
  pour l'année visée seulement, compte les demandes encodées dans sa colonne
  « Demandes acceptées » : c'est le même R1.
- Fusion avec IT-01 (#887) : les seuils, « (dont N sans réponse) » et le
  paragraphe explicatif vivent dans ses gabarits partiels
  (`views/config/_settings_capacities.html.twig`, sur Réglages, et
  `_capacity_check.html.twig`, sur le Tableau de bord) ; le sujet d'aide
  `capacites-et-listes-d-attente` couvre ces deux pages ; module en 6.13.0.
- Coût : la projection unique est plus lourde que l'ancienne (elle
  déchiffre l'année en cours et l'année visée, résout les passages). La page
  publique, que tout le monde peut ouvrir, ne la calcule plus qu'une fois par
  requête (`birthYearSlotsForPublic()` reçoit les niveaux au lieu de les
  recalculer), et pas du tout quand les listes d'attente ne sont pas gérées.
  Les Prévisions la calculent deux fois (la page et la barre des places
  restantes) : page du staff, laissée telle quelle.
- Le sujet d'aide `gerer-les-demandes` dépassait la borne de 500 mots avec
  les seuils : les capacités, les places occupées et les seuils forment un
  sujet à part, `capacites-et-listes-d-attente` (même page, comme les deux
  sujets de la réinscription), lié depuis le premier.

**Divergences avec le document.**

- Aucun écart entre `e203dfd` et le code relu (`main` en `79cac0cd`) sur les
  faits cités par IT-02 : les deux anciens réglages, `SlotMath`,
  `SlotService`, `ForecastService` et la version 6.11.0 étaient bien ceux
  décrits.
- R1 parlait d'un double comptage possible des demandes **encodées** ; c'est
  l'inverse qui se produisait (zéro). Le double comptage existe en revanche
  pour une demande **acceptée non encodée** dont l'enfant figure déjà dans
  l'import Desk de l'année visée sans que le rapprochement ait pu la lier
  (homonymes, jumeaux) : la projection le compte (ligne Desk) et la demande
  aussi. Non corrigé ici (il faudrait refaire le rapprochement par index
  aveugle dans le calcul) ; signalé pour une issue.
- Écart antérieur, non introduit ici et signalé pour une issue : un membre
  est placé par `getEffectiveAge()` sur l'année de **début** de l'année
  visée, une demande par `SlotMath::referenceCalendarYear()`, qui passe à
  l'année suivante quand `registration_reference_date` tombe avant
  septembre. Avec la valeur par défaut (12-31) les deux coïncident.

**Reporté.**

- La glissière, les champs synchronisés et l'exemple chiffré : IT-03.

---

## IT-03 — Moteur de recommandation et réglages « Capacités et priorités »

**Livré.**

- **Service de recommandation** `Service\RecommendationService` (et son
  objet résultat `Service\Recommendation`), testable sans contrôleur :
  la file D5 par année de parcours — « Toujours Oui » d'abord par date de
  dépôt (seules à pouvoir dépasser la capacité), puis le classement
  lexicographique par les critères actifs dans l'ordre choisi, puis la
  date de dépôt, puis l'identifiant (résultat déterministe) ; chaque
  demande jugée par `SlotMath::levelForOccupancy()` sur l'occupation qui
  la précède (l'occupation de `SlotService::capacityBreakdownForYear()`
  plus les demandes devant elle). Sortent de la file, avec explication et
  sans niveau : le doublon possible (même `name_dob_blind_index`, même
  année) sauf la plus ancienne demande vivante de l'enfant, et la demande
  hors des tranches d'âge. Capacité vide : « Oui — aucune limite… » ;
  capacité 0 : « fermée aux inscriptions ». Rien n'est calculé quand
  « Gérer les listes d'attente » est décochée, ni pour une année passée.
  Les cinq explications des critères d'acceptation sont vérifiées mot pour
  mot sur un jeu de données qui reproduit celui de la maquette
  (Louveteaux · 1re et 2e année, numéros #416, #417, #409, #414, #398,
  #401 compris). La recommandation n'est affichée nulle part : IT-04 en
  sera le premier lecteur, et le service n'est donc pas encore construit
  dans `public/index.php`.
- **Motif de refus** : colonne `registration_requests.refusal_reason`
  (`ENUM('no_room', 'other') NULL`, sans migration), écrite par
  `updateStatus()` dans la même requête que le statut, effacée par toute
  autre transition ; `RequestStatusService::refuse()` prend un motif
  facultatif (les appels actuels — fiche, refus groupé — restent sans
  motif jusqu'à IT-04), refuse un motif inconnu avant toute écriture et
  journalise le code. `RegistrationRequest::$refusalReason` et ses
  libellés « Pas de place » / « Autre ».
- **Ordre des critères** : `Service\PriorityOrder` (structure immuable,
  validation unique : chaque critère une fois, aucun inconnu, « Ordre
  d'arrivée » jamais en « Toujours Oui ») et `Service\PriorityOrderService`
  (réglage `registration_priority_order`, JSON, `editable: false`) ;
  défauts *Fratrie* en « Toujours Oui », *Ancienne demande restée sans
  place* puis *Ordre d'arrivée* en « Classement ».
- **Réglages › Capacités par branche** : la case « Gérer les listes
  d'attente » enregistrée au changement (`POST
  /config/inscriptions/listes-attente`, JSON) ; la sous-boîte des seuils
  (`POST /config/inscriptions/seuils`) avec la glissière à deux poignées
  1–120 % (zones Oui / Peut-être / Non, repère 100 %), les deux champs
  synchronisés validés à la sortie du champ ou sur Entrée (saisie vide ou
  invalide ignorée), l'exemple chiffré (accords, bande « Peut-être »
  vide), la phrase au-delà de 100 % ; tout grisé et désactivé
  (`<fieldset disabled>`) quand la case est décochée, valeurs conservées ;
  la sous-boîte « Capacités » (`POST /config/inscriptions`, la grille
  seule, une ligne par année de parcours). Un « Enregistrer » par
  sous-boîte.
- **Réglages › Priorités** (`POST /config/inscriptions/priorites`) : trois
  zones connectées par `partials/list_editor.html.twig` (`sortable_group`,
  `group_key`) sans point d'enregistrement par déplacement, ligne
  verrouillée « Limite : … » après « Classement », libellés et aides de la
  maquette, mention « (sans effet tant que les listes d'attente ne sont
  pas gérées) ».
- JS : `public/assets/js/registration-capacity-settings.js` (glissière,
  synchronisation, exemple, case enregistrée au changement, intégration
  propre au module des listes connectées) et son test Vitest qui importe
  le vrai fichier ; `list-editor.js` expose `window.ScoutMagicListEditor
  .refresh()` pour la ligne déplacée par le menu sur petit écran.
- Tests : `RecommendationServiceTest` (22 cas : explications, file par
  année de parcours, chaque critère, ordre des zones, doublon, hors
  tranche, capacité NULL / 0 / dépassée, seuil au-delà de 100 %, accords,
  déterminisme, case décochée, année passée), `PriorityOrderTest`,
  `RegistrationRequestRepositoryTest` et
  `RegistrationRequestRepositoryOnMysqlTest` (motif, file, refus « Pas de
  place » antérieurs, sur MariaDB), `RequestStatusServiceTest`,
  `RegistrationConfigControllerTest` (enregistrement et validation des
  seuils, de la grille et des priorités, grisage, un « Enregistrer » par
  sous-boîte), `RegistrationRbacTest` (les trois POST neufs : admin
  autorisé, chief refusé), régression « aucune recommandation dans une vue
  publique » dans `TrackingControllerTest` et
  `PublicRegistrationControllerTest`, scénarios de bout en bout
  `registration-flow` / `registration-grids` adaptés (case enregistrée au
  changement, seuils et priorités enregistrés par leur bouton).
- Documentation : sujet d'aide `capacites-et-listes-d-attente` réécrit
  (seuils, priorités, recommandation), `gerer-les-demandes`,
  `specifications.md` §17.6, `ARCHITECTURE.md` §8.36. Version du module
  6.13.0 → 6.14.0.

**Décisions autonomes.**

- **La case « Gérer les listes d'attente » s'enregistre au changement**
  (design.md §7.13 : un contrôle indépendant, pas de bouton) ; la maquette
  ne lui donne aucun bouton, et chaque sous-boîte a le sien. La réponse du
  serveur grise ou dégrise les seuils sans recharger ; un refus remet la
  case dans l'état du serveur.
- **Les seuils ne s'enregistrent pas case décochée** : le bouton est
  désactivé, et le serveur refuse aussi une soumission qui arriverait
  quand même — des valeurs qu'on ne voit pas agir ne changent pas derrière
  le gris.
- **La grille refuse désormais une case ni vide ni entière** (« douze »,
  « -1 », « 2,5 ») : elle était lue « pas de limite », ce qui ouvrait une
  année sans le vouloir. Rien n'est enregistré dans ce cas.
- **Doublon** : la demande qui reste se cherche parmi les demandes
  vivantes de l'année — en attente, acceptées, encodées — et c'est la plus
  ancienne, **sauf** si l'enfant a déjà une place (une demande acceptée ou
  encodée, même plus récente) : c'est alors celle-là qui reste, et toute
  demande en attente du même enfant sort de la file en la citant — jamais
  une seconde place recommandée pour le même enfant. Une demande refusée
  ou retirée est close : l'enfant qui redépose après elle n'est pas un
  doublon à régler.
- **« Ancienne demande restée sans place »** : le libellé d'année cité est
  celui de la plus récente année antérieure ; un refus sans motif connu
  (tous ceux d'avant IT-04) ne compte jamais comme « Pas de place ».
- **Explications** : celles de `recommend()`, avec deux accords en plus
  — « 1 place sur 15 serait occupée », « aucune place sur 15 ne serait
  occupée » — et un pourcentage qui ne contredit jamais le seuil qu'il dit
  dépasser : 19 places sur 27 font 70,37 %, au-delà du « Oui » à 70 %,
  et « (70 %), au-delà de la limite du « Oui » (70 %) » se lirait comme
  une contradiction (la maquette l'aurait écrit) ; il est alors donné au
  dixième supérieur, « (70,4 %) ». Les critères cités (« règle « Toujours Oui » : … »,
  « Classement favorisé par : … ») le sont dans l'ordre de leur zone, ce
  qui donne le texte de la maquette avec les réglages par défaut.
- **Années concernées** : l'année en cours (inscriptions en cours d'année
  avec le code) et l'année cible ; une année passée n'a aucune
  recommandation (R8).
- **Priorités enregistrées par le bouton de la boîte**, pas à chaque
  déplacement : `list_editor` sans `reorder_url`, le script renomme les
  champs cachés `priority[<zone>][]` selon la liste où chaque ligne se
  trouve à l'envoi, remet « Ordre d'arrivée » à sa place s'il est lâché en
  « Toujours Oui » (avec la phrase de la maquette, que le serveur répète
  s'il reçoit quand même cet ordre), et propose sur petit écran le menu
  « Déplacer — … » de la maquette. Sur petit écran, la zone « Désactivés »
  vide renvoie au menu plutôt qu'au glisser.
- **Le réglage des priorités est `editable: false`** : un JSON n'a rien à
  faire sur Configuration > Réglages ; il est écrit par
  `setInternal()` une fois validé. Une valeur illisible se lit comme les
  défauts, en bloc, et la boîte le dit.
- **L'exemple chiffré est écrit par le script seulement** (une seule
  définition de son calcul, testée par Vitest) ; sans JavaScript, la
  sous-boîte garde les champs et la glissière, sans exemple.
- Messages de confirmation de la maquette : « Capacités enregistrées. »,
  « Seuils enregistrés. », « Priorités enregistrées. ».
- Les trois enregistrements (case, seuils, priorités) sont journalisés,
  sans donnée personnelle.

**Divergences avec le document.**

- Vérification des faits cités sur `e203dfd` contre `main` (`5b6e0a8d`) :
  la version du module était 6.13.0 (IT-01 et IT-02 l'avaient montée) ;
  `RequestStatusService::refuse()` ne prenait aucun motif ;
  `name_dob_blind_index` n'était lu que par le rapprochement Desk.
- *Fratrie déjà dans l'unité* est lue sur les frères et sœurs déclarés à
  la demande (`registration_request_siblings`, liens vers des membres
  réels au moment du dépôt) : ~~un frère parti depuis compte encore~~. Le
  document ne précise pas davantage ; aucun autre signal n'existe.
  **Dépassé (IT-03b)** : le mainteneur a tranché, seuls les frères et
  sœurs encore présents dans l'unité comptent — voir la section IT-03b.
- Le document parle d'« explications identiques » : elles le sont, aux
  deux accords et au dixième près ci-dessus, qui ne touchent aucun des
  cinq cas des critères d'acceptation.

**Reporté.**

- Le dialogue de décision, la colonne « Action rapide » et la saisie du
  motif de refus : IT-04. La sous-boîte « Décisions automatiques » :
  IT-09 / IT-10.
- #890 (double comptage d'une demande acceptée non rattachée dont l'enfant
  est déjà projeté) : hors périmètre, ni corrigé ni aggravé — le service
  lit l'occupation de `SlotService` telle quelle.

**Faits tranchés.** R4 : aucun signal « doublon » n'existait (le seul
lecteur de `name_dob_blind_index` était le rapprochement Desk) ; IT-03 le
crée dans `RecommendationService` — même `name_dob_blind_index`, même
année, parmi les demandes vivantes, la plus ancienne (ou celle qui tient
déjà une place) restant dans la file (`Recommendation::$duplicateOfRequestId`)
— sans encore l'afficher. R8 :
aucune recommandation pour une année passée.

---

## IT-07 — Sous-page Gabarits

**Livré.**

- **Gabarits** (`/config/inscriptions/gabarits`, nouveau
  `Controller\RegistrationTemplateController`) : la boîte « Gabarits des
  e-mails de demande » liste les quatre e-mails — « Accusé de réception de
  la demande », « Alerte à l'unité », « Acceptation de la demande », « Refus
  de la demande » — avec la description de la maquette, un badge « Par
  défaut » / « Personnalisé » / « À rédiger » et un crayon
  (`aria-label` / `title` « Modifier — {nom} »), dans la liste partagée
  (`list_editor`, sans glisser ni corbeille, comme les Gabarits d'un bien en
  location). Le bandeau de la maquette s'affiche tant que l'acceptation ou
  le refus est « À rédiger » ; la phrase « Les e-mails de réinscription se
  règlent dans la gestion des réinscriptions. » renvoie à
  `/config/reinscription/reglages`.
- **Édition** (`/config/inscriptions/gabarits/{gabarit}`, slugs
  `accuse-de-reception`, `alerte-unite`, `acceptation`, `refus`) : l'éditeur
  de texte riche du site (`rich_text_form_field`) avec les variables du
  gabarit en palette fermée (« Insérer un champ », pastilles insécables) et
  en tableau ouvert à côté ; une variable non déclarée est signalée.
  Enregistrer (CSRF, contenu assaini par `EditableContentService`, journal
  `registration_email_template_saved` avec la seule clé) revient à la
  liste ; un texte vide est refusé. « Rétablir le texte par défaut »
  (`…/defaut`, `data-confirm`, journal `registration_email_template_reset`)
  existe pour l'accusé et l'alerte une fois personnalisés.
- **Une seule définition de « À rédiger »** :
  `Service\RequestEmailTemplateService::isToWrite()` (aucun corps rédigé et
  aucun défaut). Elle donne l'état affiché, et `RequestEmailService::
  isAcceptedBodyReady()` / `isRefusedBodyReady()` (le garde-fou de D10,
  conservé) en sont la négation. Le service porte aussi le catalogue (clé,
  slug, nom, description, défaut, variables), les corps par défaut (sortis
  de `RegistrationService`) et la substitution commune aux deux expéditeurs.
- Fil d'Ariane : Espace chefs d'U › Formulaire d'inscription › Gabarits
  [› Modifier — {nom}] (ancêtres statiques dans `module.json`,
  `breadcrumb_current` posé par le contrôleur). Quatre routes, toutes
  `role_min: admin` ; la route GET existante change seulement de contrôleur.
  Version du module 6.14.0 → 6.15.0.
- La fiche d'une demande renvoie vers « Gabarits » (et non plus vers
  `/config/settings`) quand l'e-mail d'acceptation ou de refus n'est pas
  rédigé ; la phrase d'en-tête de Réglages dit que les textes se rédigent
  dans Gabarits, le reste dans « Configuration > Paramètres ».
- Aide : nouveau sujet `rediger-les-e-mails-de-demande` (trois questions,
  `paths` Gabarits et ses pages d'édition) ; `gerer-les-demandes` ne couvre
  plus Gabarits et y renvoie. `specifications.md` §17.3 et §17.6,
  `ARCHITECTURE.md` §8.36.
- Tests : `RequestEmailTemplateServiceTest` (catalogue, variables, états,
  enregistrement assaini, corps vides refusés, retour au défaut, défaut
  réenregistré, substitution sous toutes ses graphies, cohérence page /
  garde-fou sur quatre corps stockés), `RegistrationTemplateControllerTest`
  (quatre lignes, états, crayons, bandeau, rail, édition, enregistrement,
  rétablissement, jeton expiré, 404, RBAC admin / chief sur les quatre
  routes par le vrai `module.json`, fil d'Ariane complet),
  `RegistrationServiceTest` (accusé personnalisé substitué),
  `RegistrationRequestControllerTest` (lien de la fiche),
  `RegistrationConfigControllerTest` (phrase de Réglages). Chaque nouvelle
  assertion a été vue échouer en cassant ce qu'elle garde.

**Décisions autonomes.**

- **« Par défaut » suit toujours le texte livré** : rétablir le défaut
  supprime la ligne de `editable_contents`, et enregistrer un texte
  équivalent au défaut (mêmes mots, variables espacées ou non, lien encodé)
  la supprime aussi. Un corps stocké qui ne rend rien (`<p>&nbsp;</p>`)
  compte comme absent : « À rédiger » pour une décision, « Par défaut » pour
  l'accusé et l'alerte, qui repartent alors avec leur défaut.
- **Substitution par le moteur partagé `Core\Template\TokenEngine`** (celui
  des contrats de location et du publipostage) au lieu du `strtr()` de
  chaque expéditeur : l'éditeur enregistre `{{ prenom_enfant }}` espacé, et
  l'assainisseur encode une variable placée dans un lien
  (`%7B%7B…%7D%7D`) ; l'ancien remplacement n'aurait substitué ni l'un ni
  l'autre, et le lien de suivi de la famille serait parti cassé. Les corps
  par défaut s'écrivent désormais avec des variables espacées, comme ceux
  qu'insère l'éditeur.
- **Pas de nouvel argument de constructeur** pour `RequestEmailService` et
  `RegistrationService` : ils construisent le service des gabarits à partir
  de l'`EditableContentService` qu'ils tiennent déjà. IT-05 et IT-06
  reprennent ces deux constructeurs en parallèle ; la modification de
  `RequestEmailService` se limite à `hasContent()`, à la lecture du corps et
  à la substitution.
- **Pas d'aperçu** : le motif Rental n'en a pas. **Pas d'objet modifiable** :
  les objets restent écrits dans le code des expéditeurs.
- Le badge suit le vocabulaire de `status_badge` (gris, bleu-vert, rouge)
  plutôt que les couleurs Tailwind de la maquette.
- `/config/settings` s'appelle « Paramètres » dans le menu Configuration
  (et est réservé au super-administrateur) : la phrase de Réglages le nomme
  « Configuration > Paramètres », comme le menu.

**Divergences avec le document.**

- Vérification sur `main` (`d4efa13`, module 6.14.0) : `EditableContentService`,
  les corps par défaut de `RegistrationService` et `isAcceptedBodyReady()` /
  `isRefusedBodyReady()` sont bien ceux décrits. **Aucun des quatre corps
  n'avait d'écran d'édition** — pas seulement l'acceptation et le refus
  (IT-01) : l'accusé et l'alerte n'étaient modifiables nulle part non plus,
  et partaient toujours avec leur défaut.
- Le garde-fou lisait « rédigé » comme `trim(strip_tags())` non vide : un
  paragraphe vidé dans l'éditeur (`<p>&nbsp;</p>`) comptait comme rédigé et
  aurait envoyé un e-mail vide. La définition unique le compte comme vide.
- La phrase de Réglages et le lien de la fiche, relevés par IT-01, sont
  corrigés.

**Reporté.** Rien.

---

## IT-04 — Colonne « Action rapide », dialogue de décision, motif de refus

**Livré.**

- **Colonne « Action rapide »** dans la liste de « Demandes » : « Décider »
  sur chaque demande en attente, « — » sur toutes les autres, et aucun
  bouton sur une année passée. Le nom de l'enfant ouvre la fiche (il
  remplace la colonne sans titre « Ouvrir », comme dans la maquette) ; un
  doublon possible porte la pastille « Doublon possible de #… » sur sa
  ligne.
- **Dialogue de décision** (`views/config/_decide_dialogs.html.twig`, une
  ligne d'inclusion dans `requests.html.twig`) : une modale Bootstrap par
  demande en attente, rendue par le serveur et ouverte par l'API de
  données de Bootstrap — titre « Décider — {enfant} », année de parcours ·
  section prévue · reçue le … ; la recommandation et son explication sur
  fond vert / jaune / rouge, ou gris sans recommandation, **seulement si
  les listes d'attente sont gérées** ; l'avertissement « Doublon possible
  de la demande #… : même enfant, même date de naissance. » ; « Note
  interne » (le champ de la fiche, montré tel qu'enregistré) ; « Annuler »,
  « Refuser », « Accepter ». « Refuser » fait apparaître le « Motif du
  refus » (« Pas de place » / « Autre », déjà « Pas de place » quand la
  recommandation est Non) et devient « Confirmer le refus », désactivé
  sans motif. Les phrases sur l'e-mail : « aucun e-mail ne part tout
  seul » par décision dont le gabarit est rédigé, la phrase « gabarits pas
  rédigés » de la maquette quand aucun ne l'est (et une phrase par
  décision quand un seul l'est).
- **Nouveau contrôleur, nouvelle route** : `POST
  /config/inscriptions/demandes/{id}/decision`
  (`Controller\RequestDecisionController::decide()`, `role_min: admin`),
  sur `Service\RequestDecisionService` : `dialogContext()` (ce que la liste
  affiche) et `decide()` — décision connue, année non passée, demande
  toujours en attente, puis, dans une seule transaction
  (`RegistrationRequestRepository::transactional()`), la transition
  existante de `RequestStatusService` (accepter, ou refuser avec son
  motif), la note si elle a changé et le journal
  `registration_request_decided` (id, décision, `note_changed`, jamais la
  note). Retour à la liste avec son
  année, son filtre d'état et sa recherche, et un message qui dit
  qu'aucun e-mail n'est parti (et pourquoi : à envoyer depuis la fiche, ou
  gabarit pas rédigé). `RegistrationConfigController` ne reçoit que le
  service, en un paramètre, et passe son contexte à la vue.
- **Motif exigé partout** : `RequestStatusService::refuse()` prend
  désormais un motif obligatoire (`string`), et un motif absent, vide ou
  inconnu est refusé avant toute écriture
  (`MESSAGE_REFUSAL_REASON_REQUIRED`). La fiche (les deux formulaires
  « Refuser », demande en attente et acceptée) et « Tout refuser »
  portent un sélecteur obligatoire (`views/_refusal_reason_field.html.twig`,
  partagé) ; « Tout refuser » vérifie le motif avant la première demande,
  si bien qu'un motif manquant ne refuse rien du tout. La fiche affiche
  « Motif : … » à côté de « Refusée ».
- JS : `public/assets/js/registration-decide-dialog.js` (bascule
  Refuser → motif + « Confirmer le refus », envoi unique — un double clic
  ne poste pas deux fois —, réinitialisation à la fermeture ; aucune
  écriture de balisage ni d'adresse — puits CodeQL relus) et `tests/js/registration-decide-dialog.test.js`, qui importe le
  vrai fichier.
- Tests : `RequestDecisionServiceTest` (contexte, accepter, refuser,
  refus sans motif / motif inconnu / décision inconnue sans rien changer,
  note comprise, année passée, demande plus en attente, note inchangée
  non réécrite, journal sans donnée personnelle),
  `RequestDecisionControllerTest` (redirection et filtres, messages,
  jeton invalide, 404), `RegistrationRbacTest` (admin décide, chief
  refusé), `RegistrationConfigControllerTest` (colonne, dialogue rendu,
  recommandation colorée, motif pré-rempli, listes d'attente non gérées,
  doublon, note, phrases d'e-mail, année passée sans bouton, refus groupé
  avec et sans motif), `RegistrationRequestControllerTest` (refus depuis
  la fiche avec et sans motif, sélecteur présent, note du dialogue sur la
  fiche), `RequestStatusServiceTest`, `TrackingControllerTest` (rien de ce
  qu'écrit le dialogue — note, motif — n'apparaît dans le suivi par jeton
  ni dans le suivi complet). Scénario de bout en bout
  `registration-flow` : la décision passe par le dialogue (refus essayé
  puis annulé, note, acceptation, note retrouvée sur la fiche) ;
  `registration-grids` ouvre la fiche par le nom de l'enfant.
- Documentation : nouveau sujet d'aide `decider-une-demande` (le sujet
  `gerer-les-demandes` est à 477 mots, sous la borne de 500),
  `specifications.md` §17.6, `ARCHITECTURE.md` §8.36. Version du module
  6.15.0 → 6.16.0 (6.14.0 → 6.15.0 avant la fusion d'IT-07, qui a pris 6.15.0). Le service de recommandation est désormais construit
  dans `public/index.php`.

**Décisions autonomes.**

- **Une modale par demande, rendue par le serveur**, plutôt qu'une seule
  remplie en JavaScript : l'adresse du formulaire (`action`) et tout le
  texte (nom, explication, note) viennent de Twig, échappés, et le script
  n'a aucun puits CodeQL à garder ; le dialogue se teste en PHPUnit sur le
  vrai rendu. Seules les demandes en attente d'une année décidable en
  ont une.
- **Le dialogue est la confirmation du refus** : pas de `data-confirm` en
  plus sur son formulaire (l'adresse `/decision` ne relève d'ailleurs pas
  du motif destructif de `UxConventionsTest`) — « Refuser » puis
  « Confirmer le refus » (`btn-danger`, design.md §7.4) font déjà les deux
  temps. Sur la fiche et pour « Tout refuser », le sélecteur de motif est
  **dans le formulaire**, obligatoire (`required`), et le `data-confirm`
  existant reste : pas de nouveau script, et la règle de confirmation des
  POST destructifs est tenue telle quelle.
- **Le motif est obligatoire dans le service**, pas seulement dans les
  contrôleurs : `refuse(string $reason)`. Le constructeur du jeu de
  données de référence refuse désormais ses demandes « Autre » : sa table
  ne dit pas pourquoi, et « Pas de place » aurait changé le classement
  des demandes ultérieures du même enfant.
- **Boutons du dialogue** selon design.md §7.4 : « Accepter » en
  `btn-primary` (la maquette dessine un bouton vert), « Décider » en
  `btn-outline-primary` (un seul bouton primaire par écran), avec un
  `aria-label` « Décider — {enfant} » pour qu'un lecteur d'écran sache
  quelle ligne il ouvre.
- **La note n'est écrite que si elle a changé par rapport à celle que le
  dialogue affichait** (champ caché `internal_notes_shown`) : laissée
  telle quelle, elle n'écrase pas une note complétée sur la fiche depuis
  le chargement de la liste ; les fins de ligne ne comptent pas (un
  navigateur poste CRLF) ; vide, elle s'efface, comme dans la fiche.
- **Statut et note dans une transaction** : une note qui ne peut pas
  s'écrire annule la décision et ses entrées de journal.
- **Une décision n'est prise que sur une demande toujours en attente** :
  décidée entre-temps (autre onglet, autre chef), elle n'est pas décidée
  une seconde fois, et rien ne change.
- Le retour à la liste reprend le filtre d'état et la recherche du
  dialogue (champs cachés), filtre validé contre la liste des états.
- La couleur « Peut-être » est le jaune de Bootstrap (`warning`), et le
  fond des quatre états utilise les variantes `*-subtle`, lisibles en
  thème sombre.

- **Non retenu, à la relecture** : bloquer aussi les transitions de la
  fiche sur une année passée. La fiche les permet aujourd'hui, et c'est
  le seul moyen de clôturer une demande restée ouverte d'une année
  passée (le refus groupé y est refusé, et une demande non clôturée
  bloque le changement d'année) ; IT-04 ne change pas ce comportement.

**Divergences avec le document.**

- Vérifié sur `main` (`d4efa13`) : `RequestStatusService::refuse()`
  prenait un motif facultatif (IT-03) et la fiche comme le refus groupé
  n'en passaient aucun ; le service de recommandation n'était lu par
  aucune page ni construit dans `public/index.php` ; version 6.14.0.
- **Le refus groupé reste « Tout refuser »** sur les demandes non
  clôturées de l'année choisie, avec son motif. La maquette dessine une
  sélection de demandes (cases à cocher, « Refuser la sélection » /
  « Retirer la sélection ») ; ni la section IT-04 ni une autre itération
  ne la demandent, et IT-04 n'a demandé que le motif. Signalé au
  pilotage. De même, les filtres en pastilles de la maquette ne sont pas
  faits (le filtre par liste déroulante reste).
- L'en-tête du dialogue reprend le libellé de créneau de la liste
  (« Baladins — 2ᵉ année », « Non déterminé » hors des tranches d'âge)
  plutôt que celui de la maquette (« Louveteaux · 1re année », « Hors des
  tranches d'âge ») : le dialogue dit ce que dit sa ligne.
- Le motif de la fiche et du refus groupé est un sélecteur dans le
  formulaire, confirmé par la boîte de confirmation commune, et non un
  champ dans cette boîte comme dans la maquette (la boîte du site
  n'accepte pas de liste déroulante).

**Reporté.**

- La phrase « Le traitement automatique ne la prendra pas » et
  « Sans décision de votre part, cette demande sera acceptée
  automatiquement… » : IT-10. La planification des e-mails et la phrase
  « l'e-mail … partira le … » : IT-05 / IT-09 (le dialogue et la
  confirmation n'affichent que « aucun e-mail ne part tout seul »).
- L'édition indépendante de la note et le bloc « E-mail de décision » de
  la fiche : IT-08.

**Faits tranchés.** R8 : sur une année passée, ni bouton « Décider » ni
dialogue, et le serveur refuse une décision qui y arriverait quand même
(rien ne change). D6 : tout refus porte son motif — dialogue, fiche, refus
groupé — et le serveur refuse un refus sans motif valide.

---

## IT-05 — Modèle des e-mails de décision

**Livré.**

- **Table `registration_decision_emails`** (`schema.sql`) : une ligne par
  e-mail d'acceptation ou de refus prévu ou tenté — premier envoi,
  nouvelle tentative après un échec, renvoi après un succès —, jamais une
  ligne par demande réécrite. Décision (`accepted` / `refused`), état
  (`scheduled` → `sending` → `sent` | `failed`, ou `cancelled`), échéance
  (NULL pour un envoi lancé à la main), numéro de tentative, indicateur de
  renvoi, dates de tentative, d'envoi et d'échec, catégorie technique de
  l'échec (`body_not_written`, `mail_transport`, `interrupted`) et phrase
  en français filtrée à l'écriture (`UserFacingMessage`), motif
  d'annulation (`decision_changed`), `superseded_at`, horodatages ; index
  `(state, due_at)` ; clé étrangère vers `registration_requests` (même
  module) `ON DELETE CASCADE`. Les deux colonnes
  `accepted_email_sent_at` / `refused_email_sent_at` disparaissent, sans
  migration (`modules/registration/drops.sql`). Version 6.15.0 (IT-07) → 6.17.0
  (6.16.0 est pris par IT-04, en parallèle).
- **`Repository\DecisionEmailRepository`** (et l'objet `DecisionEmail`) :
  créer, réclamer, marquer envoyé ou en échec, reprogrammer, rendre
  obsolète, lire l'historique, les échéances et la date du premier envoi.
  Aucune ligne dans un état final n'est réécrite : chaque écriture est un
  UPDATE conditionnel qui nomme l'état qu'il quitte.
- **`Service\DecisionEmailService`** : planifier (`schedule()`, échéance
  donnée — le délai viendra d'IT-09), reprogrammer, ouvrir une tentative
  réclamée (`begin()`), enregistrer un succès ou un échec, historique,
  échéances et réclamation pour la tâche d'IT-09.
- **`RequestEmailService` repris** : chaque envoi passe par `begin()`
  (réclamation atomique), puis le garde-fou du corps non rédigé
  (l'échec est enregistré, l'exception levée, jamais un succès), le
  transport, et `recordSuccess()` / `recordFailure()`. Après la fusion
  d'IT-07, le garde-fou et la substitution sont ceux de
  `RequestEmailTemplateService` (`isToWrite()`, la seule définition
  d'« À rédiger », et `substitute()`) : un corps vidé par l'éditeur
  (`<p>&nbsp;</p>`) est un échec enregistré, comme un corps absent. Rotation du jeton
  de suivi après un succès seulement (inchangée, R6). Le journal de
  l'envoi porte le numéro de tentative et le renvoi, jamais la famille.
- **Changer une décision annule l'envoi devenu obsolète** :
  `RegistrationRequestRepository::updateStatus()` écrit le statut puis,
  dans la même transaction, rend obsolètes les e-mails de l'ancienne
  décision — un e-mail encore prévu est annulé (« Annulé suite à un
  changement de décision ») et ne peut plus être réclamé, un e-mail
  envoyé, en échec ou en cours garde son état et est seulement daté. Le
  nombre d'annulés revient à `RequestStatusService`, qui le journalise.
  Aucun e-mail de l'ancienne décision ne peut plus être lancé ; un envoi
  dont le transport était déjà en cours à cet instant (quelques secondes)
  se termine et est consigné, sans rien rendre visible à la famille.
  `linkToMemberAndEncode()` fait de même pour une demande liée depuis un
  autre état qu'« acceptée » (le lien manuel ne vérifie pas le statut),
  en gardant les e-mails d'acceptation.
- **Compteur « E-mails de décision à envoyer »** : lit la nouvelle table
  (acceptées, encodées ou refusées sans e-mail envoyé de leur décision en
  cours — prévu, en échec ou jamais planifié), test sur MariaDB.
- Documentation : `ARCHITECTURE.md` §8.36, `specifications.md` §17.2,
  §17.3, §17.5, §17.6, contenu RGPD par défaut et consigne du prompt
  (`RgpdContentService`, règle 27), sujet d'aide `gerer-les-demandes`.
- Tests : `DecisionEmailRepositoryTest`, `DecisionEmailRepositoryOnMysqlTest`
  (prémisses du moteur, INSERT … SELECT, index UNIQUE, deux sessions qui
  créent ou réclament le même e-mail, deux envois simultanés de bout en
  bout → un seul e-mail, cascade), `DecisionEmailServiceTest`,
  `RequestEmailServiceTest` (gabarit absent → échec enregistré, envoi
  manuel, renvoi, échec SMTP puis nouvelle tentative, envoi interrompu,
  envoi concurrent), `RequestStatusServiceTest` (masqué avant envoi,
  visible après, changement de décision, ré-acceptation),
  `TrackingControllerTest`, `RegistrationRequestRepositoryTest` et
  `…OnMysqlTest` (compteur, obsolescence, effacement),
  `PurgeRegistrationRequestsHandlerTest`, `RegistrationRequestControllerTest`
  (renvoi, e-mail d'acceptation d'une demande refusée).

**Décisions autonomes.**

- **Une ligne par tentative**, pas une ligne par e-mail mise à jour : une
  nouvelle tentative et un renvoi sont des lignes à part, ce qui rend
  l'historique d'IT-08 exact par construction (« jamais écrasé »).
- **La réclamation vit sur la ligne du domaine**, pas dans
  `sent_email_claims` : le passage `scheduled` → `sending` par UPDATE
  conditionnel, ou la création directement en `sending`, et la colonne
  `active_request_id` (l'id de la demande tant que l'e-mail est prévu ou en
  cours, NULL ensuite) sous index UNIQUE, qui refuse un second e-mail en jeu
  pour la même demande (SQLSTATE 23000, la règle de
  `SentEmailClaimRepository`). La création est un `INSERT … SELECT … FROM
  registration_requests WHERE status IN (…)` : un envoi qui croise un
  changement de décision ne crée rien. C'est le précédent de
  `NotificationRepository::claimForEmail()` (une ligne de domaine existe),
  que le document autorisait (« ou verrou équivalent »).
- **L'obsolescence est faite par `updateStatus()`, pas par un service** :
  c'est la seule écriture par laquelle passe tout changement de statut
  (fiche, actions groupées, dialogue d'IT-04), et la même transaction
  garantit qu'aucun e-mail de l'ancienne décision ne reste réclamable. Le
  constructeur de `RequestStatusService` ne change pas, ce qui laisse IT-04,
  qui tourne en parallèle, sans conflit sémantique.
- **L'erreur technique n'est jamais stockée telle quelle** : la ligne garde
  une catégorie et une phrase en français écrite au moment de l'échec ; le
  texte du serveur SMTP, qui cite souvent l'adresse du destinataire, n'est
  pas conservé — le précédent de la sonde de délivrabilité. Il reste dans
  le journal, comme avant cette itération.
- **Un e-mail ne part que pour la décision en cours** : l'e-mail
  d'acceptation d'une demande refusée (ou en attente) est refusé avec un
  message, et rien n'est enregistré. La fiche montrait et acceptait les
  deux boutons quel que soit le statut : un e-mail d'acceptation pouvait
  partir après un refus.
- **Un envoi réclamé il y a plus de 10 minutes sans issue** (processus mort
  entre la réclamation et le résultat) est enregistré « interrompu » au
  prochain envoi manuel, avec une phrase qui dit qu'on ne sait pas s'il est
  parti ; il n'est jamais renvoyé tout seul. Un envoi seulement lent qui
  finit par aboutir transforme cette interruption en « envoyé » : c'est la
  seule ligne finale qu'une écriture modifie, parce qu'elle consignait une
  issue inconnue.
- **Le succès est consigné avant l'enregistrement du nouveau jeton** : une
  erreur de base de données après l'envoi laisse l'ancien lien valable et
  l'e-mail marqué envoyé, jamais un e-mail parti qui paraît en cours.
- **La fiche n'est pas refaite** (IT-08) : `RegistrationRequest` expose
  `acceptedEmailSentAt()` / `refusedEmailSentAt()` (le premier envoi de la
  décision en cours, null sinon), ce que les vues lisaient déjà sous ce
  nom ; aucun gabarit Twig n'est modifié.
- **Après la fusion d'IT-04** : `RegistrationRequestRepository` n'a plus
  qu'un utilitaire de transaction, `transactional()` d'IT-04, rendu
  générique (il renvoie la valeur du travail) et utilisé par
  `updateStatus()`, `linkToMemberAndEncode()` et `deleteById()`. Le
  dialogue de décision appelle la transition dans sa propre transaction :
  l'annulation des e-mails obsolètes s'y exécute et s'annule avec elle.
  Les phrases du dialogue (« aucun e-mail ne part tout seul ») restent
  vraies : IT-05 ne planifie rien.
- Les deux colonnes supprimées sont retirées par `drops.sql` plutôt que
  laissées en place : elles n'auraient plus rien voulu dire, et le
  comparateur de schéma signale une colonne non déclarée à chaque
  migration.

**Divergences avec le document.**

- Faits vérifiés contre `main` (`d4efa13`) : `visibleStatus()` lisait bien
  un horodatage par type ; le compteur d'IT-01 aussi ; la rotation du jeton
  se faisait déjà après le succès seulement (R6 tenait avant cette
  itération), alors qu'`ARCHITECTURE.md` décrivait encore une rotation
  avant l'envoi (`rotateTrackingToken()`, méthode disparue) — corrigé.
- #740 (effacement RGPD) et #724 (sa première partie) sont ouverts et non
  implémentés : aucun effacement ciblé n'existe aujourd'hui. Le seul chemin
  de suppression d'une demande est la purge de rétention.

**Reporté.**

- Le bloc « E-mail de décision » de la fiche (états, historique,
  « Envoyer maintenant », « Réessayer », « Renvoyer l'e-mail ») : IT-08.
- La planification avec délai et la tâche qui consomme les échéances
  (`schedule()`, `findDue()`, `claim()` sont prêts) : IT-09.

**Faits tranchés.**

- **R3** : `visibleStatus()` suit l'envoi réel de l'e-mail **de la décision
  en cours** — `decisionEmailSentAt`, le premier `sent_at` des lignes
  envoyées, non obsolètes, de la décision que porte la demande. Un e-mail
  d'une décision précédente (acceptée puis refusée, ou acceptée, remise en
  attente puis acceptée à nouveau) ne rend jamais la nouvelle visible ; un
  renvoi est une ligne à part et ne déplace jamais la date du premier
  envoi. Le mécanisme à un horodatage par type est supprimé.
- **R6** : le jeton n'est enregistré qu'après l'acceptation du message par
  le transport ; un échec SMTP ou un gabarit absent laisse le lien
  précédent valable (tests dédiés). La réclamation ne touche pas au jeton.
- **R7** : la table suit la demande — `deleteById()`, par lequel passe la
  purge `purge_registration_requests`, supprime ses lignes dans la même
  transaction, et `ON DELETE CASCADE` couvre tout autre chemin (testé sur
  MariaDB). Elle est rattachée à la demande par son id seul, donc tout
  effacement futur d'une demande (#740) l'emporte. La liste blanche de
  `BackupService::CONFIG_ONLY_TABLES` / `InstanceReset` ne grandit pas :
  la table est une donnée, vidée par la remise à zéro et sauvegardée comme
  les autres. Contenu RGPD mis à jour (historique des e-mails, réservé au
  staff, supprimé avec la demande).

---

## IT-06 — Copie (CC) des e-mails à la famille

**Livré.**

- **Cœur : `MailService::send(cc: …)`** — une adresse ou une liste, en
  **dernier** paramètre (`string|array|null $cc = null`), toujours passé
  par son nom : aucun des appelants existants n'a changé (PHPStan vert sur
  `core/`, `modules/`, `public/`) ; le seul double de test qui surcharge
  `send()` (`EmailTemplateControllerTest`) répète la signature, comme PHP
  l'exige. Chaque adresse est validée par le validateur de PHPMailer
  **avant tout**, porte de suppression comprise : une adresse invalide
  (mal formée, vide, porteuse d'un saut de ligne, pas une chaîne) lève une
  `MailException` (« Invalid Cc address: the message was not sent. »),
  journalisée `mail_send_failed` sans l'adresse, et **rien ne part**. Les
  copies deviennent un en-tête `Cc:` (`addCC()` ; une adresse répétée,
  quelle que soit la casse, ou égale au destinataire, est écartée), dans le
  message que signe DKIM (PHPMailer signe `Cc` par défaut — vérifié sur le
  message assemblé, `h=` compris), multipart intact. Pas de reçu de rebond
  pour une copie, et la porte de suppression ne lit que `$to`. **File
  différée** : `payloadFor()` stocke la liste validée sous `cc`,
  `DrainDeferredMailHandler` la rejoue (`cc: $payload['cc'] ?? []`), les
  quatre descriptions de la charge le disent. **Une copie refusée par le
  relais ne fait plus échouer un message parti** : en SMTP, PHPMailer
  envoie le DATA aux destinataires acceptés puis lève « recipients
  failed » pour les autres ; `PhpMailerTransport` suit le sort de chaque
  destinataire et, quand tout `To:` a été accepté et seules des copies
  refusées, arrête l'exception ; `MailService` journalise
  `mail_copy_refused` (avertissement, adresses masquées). Sans cela, la
  chaîne de fournisseurs renvoyait le message par le relais suivant, et
  `RequestEmailService` croyait à un échec et ne stockait pas le jeton du
  lien que la famille venait de recevoir. **À l'inverse, un destinataire
  refusé n'atteint jamais la copie** : PHPMailer envoie le DATA dès qu'un
  seul destinataire est accepté ; un message SMTP portant une copie passe
  donc par `RecipientFirstSmtp`, qui ne demande plus aucune copie une fois
  un `To:` refusé — pas de DATA, l'unité ne reçoit pas copie d'un message
  que la famille n'a pas eu (une adresse mal tapée sur le formulaire
  public), ni une fois par relais ou par nouvel essai.
- **Module : boîte « Copie des e-mails d'inscription »** sur Réglages
  (`views/config/_settings_copy.html.twig`, incluse par une ligne) :
  interrupteur « Mettre une adresse en copie » (activé par défaut), champ
  « Adresse e-mail en copie » avec, en indication, l'adresse de l'unité
  (« … (adresse de l'unité) »), textes de la maquette, un seul
  « Enregistrer » → `POST /config/inscriptions/copie`, nouveau contrôleur
  `RegistrationCopySettingsController` (`role_min: admin`, CSRF,
  journal sans adresse, « Réglages enregistrés. »). Règles dans le
  nouveau `Service\FamilyEmailCopyService` : enregistrement refusé —
  rien n'est écrit, interrupteur compris — pour une adresse invalide
  (« Cette adresse n'est pas valide. Rien n'a été enregistré. », comme la
  maquette) ou pour une copie activée sans adresse résoluble. Le champ est
  désactivé quand l'interrupteur est coupé (`registration-copy-settings.js`,
  testé par Vitest) ; non soumis, il garde l'adresse enregistrée.
  Deux réglages neufs dans `module.json`, en un bloc :
  `registration_email_cc_enabled` (`1`) et `registration_email_cc_address`
  (vide, type `email`), tous deux `editable: false` ; `version` 6.18.0
  (IT-07 a pris 6.15.0 ; 6.16.0 et 6.17.0 vont à IT-04 et IT-05, ouvertes
  en même temps).
- **Appliquée aux trois e-mails à la famille** : l'accusé de réception
  (`RegistrationService`) et l'acceptation et le refus
  (`RequestEmailService`, renvois compris — un renvoi est le même
  `send()`), par un paramètre de constructeur facultatif en dernière
  position et une ligne `cc:` dans chaque appel (point d'injection minimal,
  IT-05 reprenant `RequestEmailService`).
- **RGPD** : `rgpd_default.html` (« Copie des emails envoyés à la
  famille », sous-section Inscriptions) et la règle 27 (c bis) du prompt de
  `RgpdContentService` : l'adresse en copie reçoit exactement ce que reçoit
  la famille (prénom de l'enfant, année visée, adresse de la famille, lien
  de suivi vers la vue minimale), et aucun autre e-mail du module n'est
  copié. Aucun sous-traitant nouveau : la copie part par le même relais.
- Documentation : `ARCHITECTURE.md` §8.7 (le paramètre), §8.106 (la file
  différée), §8.36 (la boîte et ses règles) ; `docs/module-development.md`
  § E-mails ; `specifications.md` §17.3 et §17.6 ; nouveau sujet d'aide
  `copie-des-e-mails-d-inscription` (Réglages).
- Tests : `MailServiceCopyTest` (sans copie, une adresse, une liste
  dédoublonnée, copie au destinataire écartée, cinq adresses invalides →
  rien ne part et le journal ne nomme personne, en-tête signé DKIM et
  multipart ; sur un faux relais SMTP derrière le vrai
  `PhpMailerTransport` : copie refusée → envoi réussi, DATA une fois,
  `mail_copy_refused` ; destinataire refusé → échec, copie jamais demandée
  ni DATA envoyé ; rappel déjà posé conservé ; règle d'adresse
  indépendante du validateur global de PHPMailer), `MailServiceDeferralTest` (la copie est mise en file),
  `DrainDeferredMailHandlerTest` (rejouée avec sa copie ; ligne ancienne
  sans clé → aucune), `FamilyEmailCopyServiceTest`,
  `RequestEmailServiceTest` (acceptation, renvoi et refus en copie ;
  adresse saisie ; option coupée), `RegistrationServiceTest` (accusé en
  copie, alerte jamais ; adresse saisie ; option coupée),
  `RegistrationCopySettingsControllerTest` (routeur chargé du vrai
  `module.json` : admin autorisé, chief refusé ; validation, jeton, rendu
  de la boîte), `RegistrationConfigControllerTest` (un « Enregistrer » par
  boîte : cinq), test Vitest, et le scénario `registration-flow` : la boîte
  enregistre une adresse et l'accusé reçu par la famille porte `Cc:`.

**Audit des chemins d'envoi du module.** Sept appels à
`MailService::send()` dans `modules/registration` :

- **copiés** : l'accusé de réception (`RegistrationService::
  sendReceiptEmail()`), l'acceptation et le refus, premier envoi et
  renvois (`RequestEmailService::send()`) ;
- **jamais copiés, volontairement** : l'alerte à l'unité
  (`RegistrationService::sendUnitAlertEmail()`) — elle va déjà à l'unité ;
  les e-mails de réinscription (`Task\SendReenrollmentEmailsHandler`,
  ouverture, deux rappels, clôture) — D9 les exclut ; la confirmation d'une
  adresse de suivi secondaire (`SecondaryEmailService::
  sendConfirmationEmail()`) — son lien confirme une adresse qui voit ensuite
  toute la demande (coordonnées comprises) : une copie remettrait cette
  capacité à une boîte partagée. Aucun autre chemin (pas de notification,
  pas de tâche qui écrive à une famille).

**Décisions autonomes.**

- **« L'adresse par défaut de l'unité » est le réglage du cœur
  `contact_email`** (« Email de contact », l'adresse publiée sur la page
  Contact, remplie à l'installation à partir de l'adresse d'expédition).
  Ni `mail_from_address` (l'expéditeur, souvent une boîte sans réponse), ni
  `registration_unit_alert_email` (un interrupteur d'alerte, vide quand
  l'alerte est coupée). Le champ l'affiche en indication.
- **Enregistrer refuse, envoyer ne refuse jamais.** L'enregistrement de
  la boîte refuse une configuration qui ne peut pas marcher ; mais
  l'adresse de contact peut être effacée plus tard sur une autre page, et
  l'accusé d'une famille n'est pas tenu en otage par la copie de l'unité :
  à l'envoi, une copie non résoluble fait partir l'e-mail sans copie,
  journalisé `registration_email_copy_unresolved` (sans adresse), et la
  boîte affiche un avertissement. Côté cœur, en revanche, une adresse
  invalide refuse tout le message, comme demandé : le module ne lui en
  passe jamais, la boîte vérifiant l'adresse par la même fonction que
  `send()` (`MailService::isValidAddress()`, exposée pour cela, qui nomme
  le motif `'php'` de PHPMailer plutôt que son défaut global, que PHPMailer
  assouplit pour tout le processus après une adresse UTF-8).
- **Une adresse invalide est refusée même l'option coupée** : le réglage
  est de type `email`, et une valeur qu'on ne voit pas agir ne s'enregistre
  pas fausse. Option coupée, le champ n'est pas soumis et l'adresse
  enregistrée est conservée.
- **Les deux réglages sont `editable: false`** (absents de Configuration
  > Paramètres, écrits par `setManyInternal()`) : la règle « pas de copie
  activée sans adresse résoluble » porte sur le couple, qu'une liste de
  champs indépendants ne peut pas vérifier — c'est le cas que
  `docs/module-development.md` § « `editable: false` » décrit. Leur
  description renvoie à la boîte.
- **Le lien de suivi part aussi dans la boîte de l'unité**, avec la copie
  de l'accusé et des décisions : D9 l'impose (une copie visible du même
  message). Son jeton n'ouvre que la vue minimale (prénom, date de
  réception, statut), que le staff connaît déjà ; la confirmation d'adresse
  secondaire, qui ouvrirait la demande complète, n'est pas copiée. Le
  durcissement antérieur qui avait retiré ce lien de l'alerte à l'unité
  reste vrai : l'alerte ne le porte toujours pas.
- **Contexte de la boîte construit dans `buildSettingsContext()`** à partir
  du `SettingService` que `RegistrationConfigController` détient déjà : le
  constructeur de ce contrôleur, que plusieurs itérations parallèles
  touchent, ne change pas. L'enregistrement a son propre contrôleur.

**Divergences avec le document.**

- Faits vérifiés sur `d4efa13` (version 6.14.0) : `MailService::send()`
  n'avait pas de `cc` (R5 confirmé), ni sa file différée ; les trois
  e-mails à la famille passaient bien par `RegistrationService` et
  `RequestEmailService`.
- « Renvois compris » : seul un e-mail de décision a un renvoi (le bouton
  « Renvoyer » de la fiche, même chemin que l'envoi) ; l'accusé de
  réception n'en a aucun dans le module aujourd'hui.
- La phrase de la maquette « qu'ils partent à la main ou automatiquement »
  est reprise telle quelle : l'accusé part déjà automatiquement, et l'envoi
  automatique des décisions (IT-09) devra passer par `RequestEmailService`
  construit avec `FamilyEmailCopyService` — dans `scheduler-bootstrap.php`
  si c'est une tâche — pour hériter de la copie.

**Reporté.** Rien. Fusion avec IT-04 et IT-05 : le paramètre facultatif
`FamilyEmailCopyService` suit désormais `DecisionEmailService` dans le
constructeur de `RequestEmailService`, et la ligne `cc:` est sur le chemin
d'envoi d'IT-05 — envoi manuel, nouvel essai après un échec et renvoi
passent tous par `RequestEmailService::send()` et portent la copie (testés
dans `RequestEmailServiceTest`). À l'attention d'IT-08 et d'IT-09 :
« Renvoyer l'e-mail » et l'envoi automatique héritent de la copie tant
qu'ils passent par ce service.

**Faits tranchés.** R5 : `MailService::send()` prend désormais `cc`
(validation, en-tête, DKIM, multipart, file différée), documenté et testé.

---

## IT-03b — La fratrie ne compte que les frères et sœurs encore présents

Complément d'IT-03, sur `main` en `6303f64c` (module 6.18.0).

**Livré.**

- **Décision du mainteneur (fait foi)** : pour le critère « Fratrie déjà
  dans l'unité » de la recommandation, **seuls les frères et sœurs encore
  présents dans l'unité comptent**. Jusqu'ici `RecommendationService`
  lisait tous les frères et sœurs déclarés à la demande, si bien qu'un
  frère parti depuis le dépôt forçait encore « Toujours Oui ».
- **Définition de « présent »** : le membre déclaré a une inscription
  active (`member_years.is_active = 1`, le filtre de
  `HouseholdRepository` et de tout le roster) pour **l'année scoute en
  cours** — `ScoutYearResolver::getCurrentPublicYear()`, l'année que le
  module appelle partout « en cours » (capacités, Passage, fiche) — **ou
  pour l'année visée par la demande** (`registration_requests.scout_year_id`,
  lue sur la demande elle-même, pas une année commune au lot). Un frère
  déjà réinscrit pour l'année visée compte ; un frère sans aucune
  inscription active dans l'une ou l'autre ne compte plus. Un membre
  fusionné depuis dans une autre identité est cherché sous celle-ci
  (`members.merged_into_member_id` : la fusion déplace les `member_years`,
  pas les liens du module). Toute fonction compte (un frère animateur est
  dans l'unité).
- `RegistrationRequestRepository::findPresentSiblingMemberIdsForRequests()`
  : une requête préparée pour toute la file (pas de N+1), un `EXISTS`
  corrélé sur `member_years` (table du cœur), lu par la recommandation
  **seulement**. Le bloc fratrie de la page Passage (et le regroupement
  de la répartition automatique), la fiche, la colonne « Fratrie » de la
  liste et de l'export continuent de lire les frères et sœurs déclarés
  (`findSiblingMemberIdsForRequests()`, `findSiblingMemberIds()`,
  `countSiblingsForRequests()`), sans changement de sens.
- Explications inchangées dans leur forme (« fratrie déjà présente dans
  l'unité », « fratrie dans l'unité ») : elles deviennent vraies.
- Tests, chacun vu rouge avant d'être vert (sur le code de `main`, ou par
  mutation de la requête pour les cas que l'ancien code satisfaisait
  déjà — année en cours seule, année visée seule, `is_active` ignoré,
  jointure qui duplique, premier frère déclaré seul, fusion ignorée) :
  `RecommendationServiceTest` (frère actif cette année, réinscrit pour
  l'année visée seulement, inactif cette année mais réinscrit → « Toujours
  Oui » ; parti, jamais inscrit, inactif partout → retombe dans le
  classement, 4e sur 5 ; parti et critère en « Classement » → ne favorise
  rien ; deux frères dont un parti → « Toujours Oui ») — le jeu de données
  de la maquette inscrit désormais ses frères et sœurs dans l'année en
  cours ; `RegistrationRequestRepositoryTest` (SQLite) et
  `RegistrationRequestRepositoryOnMysqlTest` (MariaDB, avec sa prémisse
  sur `member_years.is_active`) ; les lecteurs des frères déclarés
  épinglés dans `PassageServiceTest`, `RegistrationRequestControllerTest`
  (fiche) et `RegistrationConfigControllerTest` (export), chacun vu rouge
  en restreignant les lectures déclarées aux présents.
- Documentation : sujet d'aide `capacites-et-listes-d-attente`,
  `specifications.md` §17.6, `ARCHITECTURE.md` §8.36. `decider-une-demande`
  ne décrit pas le critère : inchangé. `module.json` et `schema.sql`
  inchangés, donc version du module inchangée (6.18.0).

**Décisions autonomes.**

- La requête prend l'année en cours en paramètre et lit l'année visée sur
  chaque demande (jointure) : la règle « l'année de la demande » est dans
  la requête, pas dans l'appelant.
- Fusion de membres suivie sur un niveau (`merged_into_member_id`) : sans
  cela, un frère déclaré sous une identité fusionnée depuis aurait cessé
  de compter alors qu'il est toujours inscrit — une régression que ce
  changement aurait introduite. La fiche et la page Passage, qui
  résolvent le nom sur l'identité déclarée, ne la suivent pas (constat
  antérieur, non touché ici).

**Divergences avec le document.**

- Aucune sur les faits cités : `RecommendationService` lisait bien
  `findSiblingMemberIdsForRequests()`, partagé avec Passage.
- Lecture de « présent » laissée ouverte pour le mainteneur : un frère
  encore actif cette année mais marqué « quitte » (`member_years.leaving`,
  réponse de la famille ou décision du staff) compte, selon la définition
  retenue, alors que la projection d'IT-02 le retire de l'année visée ; de
  même un frère actif cette année mais absent (ou inactif) de l'import Desk
  déjà fait pour l'année visée. Non tranché ici.

**Reporté.** Rien.
