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
- Pour une année passée, la colonne « Demandes acceptées » compte aussi les
  demandes encodées de cette année (elle ne comptait que les acceptées non
  encodées) : c'est le même R1, et c'est plus juste historiquement.
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
