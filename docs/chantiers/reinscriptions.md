# Chantier — Réinscriptions

Journal d'implémentation du document de chantier
`docs/chantiers/CHANTIER-reinscriptions.md` (issue #796, itérations IT-01
à IT-05). Une section par itération : ce qui a été fait, les décisions
prises en autonomie, les divergences constatées entre le document de
chantier et le dépôt réel, et ce qui a été reporté. Même format que
`docs/chantiers/aide-contextuelle.md`.

---

## IT-01 — Le plan d'envoi, et la fermeture qui écrivait à tort

**Livré.**

- `Service\ReenrollmentSavePlanner` calcule le plan (D1), et
  `Service\ReenrollmentSavePlan` le porte. Le plan contient :
  - ce qui change ;
  - l'ouverture ou la fermeture que l'enregistrement provoque ;
  - les e-mails qui partiront : type, campagne, nombre de familles,
    immédiat ou différé ;
  - sinon, la raison pour laquelle rien ne part. Six raisons possibles :
    rien ne change, réglages seuls, e-mails désactivés, e-mail déjà parti,
    campagne terminée, aucune campagne.
- `ReenrollmentConfigController` lit ce plan :
  - `save()` met en file exactement les e-mails immédiats du plan, et aucun
    autre ;
  - la garde existante (`confirm_opening`, `/config/reinscription/apercu`)
    interroge le même plan.
- `openingSendsEmail()` disparaît. Ses règles vivent dans le planificateur ;
  `openingOnSave()` reste, et le planificateur s'appuie dessus.
- **Le correctif de l'incident.** Fermer une campagne dont la date de
  clôture est passée n'écrit à personne (raison « campagne terminée »).
- **Les e-mails différés.** Le plan annonce aussi ce que l'enregistrement
  rend dû à la prochaine passe horaire de `ReenrollmentCampaignHandler`, à
  savoir :
  - un rappel dont le délai tombe aujourd'hui sur une campagne ouverte ;
  - une date de fermeture déplacée à aujourd'hui.

  Un e-mail déjà dû avant l'enregistrement n'est pas annoncé : ce n'est pas
  l'enregistrement qui l'envoie.
- `ReenrollmentCampaignService` gagne trois fonctions :
  - `campaignKeyFor()` et `reminderDueOn()`, deux fonctions pures sur des
    réglages explicites (stockés ou soumis), dont `reminderDate()` dérive
    maintenant ;
  - `years()`, la paire « année publique / année visée », partagée par le
    suivi et le décompte des familles.

**Décisions autonomes.**

- **Une horloge injectable dans le contrôleur.** Les tests de la page
  lisaient l'horloge murale ; celui qui fermait la campagne passait en
  avril et reproduisait l'incident en octobre. Ils se placent maintenant au
  20/04/2027, et le test de l'incident au 04/10/2026.
- **Le nombre de familles** vient du même `ReenrollmentRecipientService` que
  l'envoi : toutes les familles pour l'ouverture, les familles sans réponse
  pour les autres e-mails. Le test du planificateur le remplace par un
  compteur fixe (41 / 27).
- **Ce que le plan « applique ».** `save()` met en file les e-mails immédiats
  du plan. Les différés partent à la passe horaire, par la même garde
  `handOver()` : rien ne les met en file deux fois.

**Écarts constatés avec le document (commit `fd08ff3`).**

- **Le libellé d'année.** Confirmé dans `SendReenrollmentEmailsHandler` :
  l'année visée de l'e-mail vient de l'année publique actuelle
  (`getCurrentPublicYear()` puis `nextLabel()`), pas de la campagne. C'est
  traité en IT-02, comme prévu.
- **`ReenrollmentConfigControllerTest`.** `testOpeningTheCampaignByHandOpensIt`
  ouvrait la campagne sans confirmation parce qu'il tournait, selon la date,
  dans une campagne terminée. Au 20/04/2027, l'ouverture écrit aux familles
  et demande donc le « oui ». Le test le donne.

**Reporté (prévu).** La confirmation de la fermeture et de tout autre
enregistrement relève d'IT-03 ; le choix de la campagne courante, d'IT-02.

---

## IT-02 — Une campagne par année visée

**Livré.**

- `currentCampaignKey()` rend la campagne de l'**année visée** (D3).
  - L'année visée est l'année scoute qui suit l'année publique (`years()`).
  - Sa campagne est la dernière fenêtre qui s'ouvre avant le 1er septembre
    de cette année visée : 2027-2028 se ferme le 2027-05-15 (ouverture et
    fermeture au printemps 2027), mais une fenêtre entièrement en automne
    (10-01 → 12-15) s'ouvre et se ferme l'automne 2026. Une fenêtre à cheval
    sur le nouvel an (11-01 → 02-15) se ferme en 2027.
  - La clé reste la date de clôture (D5). Les marqueurs écrits avant ce
    chantier gardent donc leur sens.
  - Elle ne dépend plus du jour : c'est « la campagne pour 2027-2028 »
    avant ses dates, pendant et après.
- **La règle de distance disparaît** de `openingOnSave()`
  (`keyForManualOpening()` est supprimée, avec `keyAt()` et
  `keyForOpening()`). L'interrupteur ouvre la campagne de l'année visée, et
  le docblock dit pourquoi la règle n'existe plus (D3, D4).
- **Fermer une campagne qui n'a pas commencé n'écrit à personne.** Le plan
  donne alors la nouvelle raison « pas encore commencée ». C'est le cas d'un
  interrupteur resté allumé en octobre : la campagne courante est désormais
  celle de mai prochain. Une campagne « a commencé » dans deux cas
  (`hasStarted()`) :
  - sa date d'ouverture est passée, celle de l'enregistrement en cours
    quand on planifie un enregistrement, pas celle qu'il remplace ;
  - elle a été ouverte avant, par l'horloge ou à la main avec son e-mail,
    parti ou encore en file.
- **Le libellé d'un e-mail vient de sa campagne.**
  - `SendReenrollmentEmailsHandler` lit l'année de
    `ReenrollmentCampaignService::targetLabelOf($campaignKey)` : l'année qui
    commence après l'ouverture de la campagne (la clé donne la fermeture,
    le réglage l'ouverture). Il ne lit plus l'année publique du moment de
    l'envoi.
  - Un e-mail de clôture de la campagne du 15/05/2026 dit « 2026-2027 », en
    mai comme en octobre.
- **Fenêtre à cheval sur le nouvel an.** `openingDateOf()` place
  l'ouverture l'année civile d'avant quand elle suit la fermeture dans le
  calendrier (novembre → février). Les rappels sautés se comptent depuis
  cette ouverture.
- **Ordre des raisons dans le plan.** « Déjà parti » passe avant « campagne
  terminée » : rouvrir après la clôture dit que l'e-mail d'ouverture est
  déjà parti (matrice de la maquette).

**Vérifié : quand l'année scoute bascule.** L'année publique est le réglage
`current_scout_year_id`. Seul `ScoutYearAdminService` le modifie, quand le
chef lance « Changer d'année ». Non configuré, il retombe sur l'année
calculée à la date (`ScoutYearService::getCurrentYear()`, label du jour).
C'est ce changement qui déplace la campagne courante vers l'année suivante.

**Le jour du changement.** La campagne change avec l'année visée, même
quand elle tourne : les réponses des familles, le menu, les compteurs et
les e-mails lisent tous la même année visée. Une campagne qui survivrait au
changement d'année ferait lire à l'envoi une année et aux réponses une autre :
une famille qui a répondu après le changement recevrait un rappel. Une
campagne en cours à ce moment-là se termine donc sans e-mail de clôture, et
celle de l'année suivante prend la relève. La revue de la PR a fait renoncer
à une première version qui la maintenait jusqu'à sa clôture.

---

## IT-03 — La confirmation universelle

**Livré.**

- **Le serveur impose la confirmation** à tout enregistrement qui change
  quelque chose (D2, D7). `save()` calcule le plan. S'il ne change rien,
  `save()` répond « Aucun changement à enregistrer » et n'ouvre aucun
  dialogue. Sinon, il n'écrit rien sans le champ `plan_fingerprint`, et ce
  champ doit être l'empreinte du plan qu'il calcule à ce moment-là.
  - **Sans empreinte**, le serveur rend lui-même l'écran de confirmation
    (`reenrollment_confirm.html.twig`). Le formulaire y est repris tel quel,
    et « Annuler » revient à la page. C'est ce qu'obtient un navigateur sans
    script, ou un script qui n'a pas pu joindre `/apercu`. Personne n'est
    bloqué, personne n'enregistre sans question.
  - **Avec une empreinte périmée**, l'enregistrement est refusé. Le même
    écran revient, avec « La situation a changé depuis la question ».
    L'empreinte change, par exemple, si une famille a répondu, si un e-mail
    est parti ou si un autre chef a enregistré entre-temps.
- **Une seule façon de confirmer.** `confirm_opening` et l'ancienne question
  d'ouverture disparaissent. `/config/reinscription/apercu` reste, mais rend
  désormais le plan : le dialogue, l'empreinte et « change ou non ».
- **`Service\ReenrollmentSavePlanPresenter`** met le plan en mots, et l'écran
  serveur comme le dialogue du script lisent ses phrases. Le dialogue
  contient :
  - l'encadré de la campagne concernée, avec son année et ses dates (D6),
    et la durée quand une ouverture à la main la laisse ouverte plusieurs
    mois (D4) ;
  - « Ce qui change » ;
  - puis l'un ou l'autre :
    - **« N e-mails vont partir »**, en ambre, avec « Un e-mail envoyé ne se
      rappelle pas » ;
    - **« Aucun e-mail ne partira »**, en vert, avec la raison ;
  - un bouton « Enregistrer et envoyer » (style danger) ou « Enregistrer ».
- **Le message après enregistrement** dit ce qui est parti et ce qui ne l'est
  pas (D8).
- **Sous l'interrupteur**, une phrase nomme la campagne : « Interrupteur
  manuel pour la campagne de réinscription pour 2027-2028. »
- **`window.ScoutMagicConfirm.ask()` accepte un contenu structuré**
  (`content` : encadrés et listes, rendus en texte seulement). Le composant
  est étendu de façon générique, sans second dialogue.

**Décision autonome — un e-mail « en route ».** Le plan considère comme
déjà parti un e-mail déjà mis en file mais pas encore marqué envoyé. C'est
la garde `hasLiveStartingWith()` de `handOver()` : `handOver()` ne le
remettrait pas en file, le plan ne doit donc pas l'annoncer. Les tests l'ont
trouvé en rouvrant une campagne juste après l'avoir ouverte : le plan
annonçait un second e-mail d'ouverture qui ne partait pas.

**Le mécanisme sans script (D7), noté comme demandé.** Le serveur rend
l'écran de confirmation, et le script se contente de l'anticiper dans un
dialogue. Les deux lisent le même plan et le même texte.

**Tests.**

- `ReenrollmentConfigControllerTest` :
  - une ligne par cas de la matrice : le dialogue annonce exactement ce qui
    est ensuite mis en file ;
  - sans confirmation, rien n'est écrit ;
  - l'écran serveur renvoie le formulaire et enregistre ;
  - un plan périmé est refusé ;
  - une ouverture nomme sa campagne et sa durée ;
  - le message après enregistrement dit ce qui est parti.
- Vitest :
  - `reenrollment-config.test.js` est réécrit pour le plan ;
  - `confirm.test.js` vérifie le contenu structuré, rendu en texte
    seulement.
- L'attente de `ReenrollmentConfigControllerTest` sur « Aucun autre rappel
  automatique n'est prévu | Prochain rappel… » reste inchangée : elle porte
  sur le dialogue « Relancer maintenant », qu'IT-04 reprend.

---

## IT-04 — Deux sous-pages, et les dates prévues

**Livré.**

- **Le rail et les deux routes (D9).** Le rail `page_picker`
  (`_reenrollment_nav.html.twig`) mène à deux pages :
  - « Tableau de bord » sur `/config/reinscription` : l'état et « Relancer
    maintenant » ;
  - « Réglages » sur `/config/reinscription/reglages` : les dates, les
    rappels et les deux interrupteurs.

  L'enregistrement passe sur `POST /config/reinscription/reglages`, et
  l'écran de confirmation y renvoie. `/config/reinscription/relance` et
  `/apercu` ne bougent pas. Le fil d'Ariane de la page « Réglages » porte
  tous les niveaux, par `ancestors` : Espace chefs d'U / Réinscriptions /
  Réglages. Le module passe en 6.11.0, puisque `module.json` change.
- **`ReenrollmentCampaignService::timeline()`** est la seule source de la
  boîte (D10) et de son dialogue (D11). Elle donne la campagne de l'année
  visée, puis ses quatre étapes, chacune dans l'un de cinq états :
  - envoyé, avec son moment, et « (ouverte à la main) » quand l'ouverture
    est partie avant sa date ;
  - prévu le …, avec la date ;
  - pas envoyé, prévu le …, date passée ;
  - sauté, la date tombe avant l'ouverture ;
  - désactivé.

  Les rappels se comptent depuis la fermeture de CETTE campagne.
  `automaticReminders()` et `emailStates()` disparaissent, remplacés par
  cette chronologie.
- **La boîte nomme sa campagne.** Elle affiche « Campagne pour 2027-2028 :
  du 01/03/2027 au 15/05/2027 », ou « ouverte le 04/10/2026, fermeture le
  15/05/2027 » quand l'ouverture s'est faite à la main. Tant que la campagne
  de l'année visée n'a pas commencé, une ligne grise dit comment la
  précédente s'est terminée.
- **La carte « État »** n'annonce plus une clôture prévue déjà passée. Elle
  dit « Ouverte à la main, avant la date prévue. » quand c'est le cas.
- **Le dialogue « Relancer maintenant »** compte les familles avec le même
  `ReenrollmentSavePlanner::families()` que le plan d'envoi. Il lit le
  dernier rappel parti et le prochain prévu dans la même chronologie que la
  boîte.
- **E-mails désactivés.** Chaque étape dit « Désactivé » et le bouton est
  grisé, avec « Les réactiver dans les réglages ».

**Écart avec la maquette.** La ligne « Campagne précédente » s'affiche tant
que la campagne de l'année visée n'a pas commencé : entre deux campagnes,
mais aussi juste avant l'ouverture. La maquette ne la montre que dans la
situation « Entre deux campagnes ». Elle reste vraie et utile jusqu'à
l'ouverture ; le test ne l'exclut donc pas avant l'ouverture.

**Tests.**

- `ReenrollmentCampaignServiceTest` couvre les cinq états, l'ouverture à la
  main et la ligne de la campagne précédente.
- `ReenrollmentConfigControllerTest` couvre :
  - les huit situations de la maquette sur le tableau de bord ;
  - le dialogue de relance, qui lit les mêmes étapes que la boîte ;
  - le rail et le fil d'Ariane sur les deux pages ;
  - la matrice RBAC des deux nouvelles routes : autorisé à `admin`, refusé
    à `chief`.
- L'attente de la ligne 562 (« Aucun autre rappel automatique n'est prévu
  | Prochain rappel… ») porte maintenant sur des dates exactes.

---

## IT-05 — Documentation et aide

**Livré.**

- `ARCHITECTURE.md` gagne une section §8.37quinquies. Elle couvre :
  - le plan d'envoi, et pourquoi le serveur le calcule une seule fois ;
  - la règle « une campagne par année visée, identifiée par sa clôture » ;
  - la règle « tout enregistrement qui peut écrire aux familles se
    confirme et annonce le résultat » ;
  - le tableau de bord à deux sous-pages et sa chronologie unique.
- `specifications.md` §18.5 décrit :
  - la campagne de l'année visée et l'ouverture à la main ;
  - « une fois par campagne » ;
  - les deux sous-pages et la boîte « Relancer maintenant » ;
  - la confirmation universelle.

  La référence périmée à « Configuration > Réinscription » disparaît : la
  page est dans l'Espace chefs d'U.
- **Sujets d'aide.**
  - `config-reinscription` est réécrit pour couvrir les deux sous-pages :
    l'année visée, le tableau de bord, les réglages, la confirmation.
  - `config-reinscription-emails` dit ce qui ne repart jamais, et que tout
    enregistrement est confirmé.
  - Les deux sujets déclarent les deux routes dans `paths:`. Ces chemins
    sont arrivés dès IT-04 : `HelpMenuCoverageTest` exigeait une aide
    pour la nouvelle page.

---

## Récapitulatif final

| # | Livré |
|---|---|
| IT-01 | Le plan d'envoi (`ReenrollmentSavePlanner` / `ReenrollmentSavePlan`) ; fermer une campagne terminée n'écrit plus à personne — l'incident rejoué en test |
| IT-02 | La campagne de l'année visée, identifiée par sa clôture ; la règle de distance supprimée ; la campagne suit l'année visée, y compris au changement d'année public ; le libellé d'un e-mail vient de sa campagne ; fenêtres à cheval sur le nouvel an |
| IT-03 | La confirmation universelle sur le plan exact, imposée par le serveur avec ou sans script ; un plan périmé refusé ; le message après enregistrement ; `ScoutMagicConfirm.ask()` structuré |
| IT-04 | Deux sous-pages sur un rail, fil d'Ariane complet ; les cinq états d'étape ; le dialogue « Relancer maintenant » lit la même chronologie que la boîte |
| IT-05 | `ARCHITECTURE.md`, `specifications.md`, les deux sujets d'aide |

**Les décisions autonomes les plus structurantes** :

- **Un e-mail en file compte comme parti.** `handOver()` ne le remettrait
  pas en file, donc le plan ne l'annonce pas.
- **Une campagne qui n'a pas commencé ne reçoit pas d'e-mail de clôture.**
  C'est la forme définitive du correctif de l'incident, une fois la
  campagne courante devenue celle de mai prochain.
- **La campagne suit l'année visée, sans exception.** Au changement
  d'année publique, une campagne encore ouverte se termine sans e-mail de
  clôture et celle de l'année suivante prend la place : elle ne survit pas
  à son année (revue d'IT-02).
- **La clé d'une campagne est la dernière fenêtre qui s'ouvre avant le
  1er septembre de l'année visée.** Les fenêtres d'automne et celles qui
  chevauchent le nouvel an ont leur clôture dans l'année d'avant ; le
  libellé et « a commencé » se lisent sur la date d'ouverture enregistrée.
- **La ligne « campagne précédente » est un enregistrement, pas un
  calcul** : dernier marqueur de clôture, daté par son moment réel ; rien
  sans campagne passée.
- **« Ouverte avant la date » se calcule une fois**, dans `timeline()` ;
  une campagne ouverte à la main, e-mails coupés, compte comme commencée.
- **Sans script, le serveur rend lui-même l'écran de confirmation.** Le
  script ne fait que l'anticiper.
