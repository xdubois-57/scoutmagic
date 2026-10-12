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
  réels au moment du dépôt) : un frère parti depuis compte encore. Le
  document ne précise pas davantage ; aucun autre signal n'existe.
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
