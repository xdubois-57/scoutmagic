# Chantier ScoutMagic — Formulaire d'inscription : sous-pages, recommandation Oui / Peut-être / Non, décisions automatisées

Issue #843. Module `modules/registration`.

Roadmap d'exécution complète, en **11 itérations**. Traite-les dans l'ordre du tableau ci-dessous,
**une par une**, sans poser de question sauf en cas de **changement de design** ou de **manque de
clarté fonctionnelle** réel. **Les itérations marquées « ∥ » sont indépendantes : lance-les en
parallèle** (voir « Exécuter en parallèle ») pour gagner du temps.

**Où se trouvent les pièces de ce chantier :** ce document est
`docs/chantiers/CHANTIER-formulaire-inscription.md` ; sa maquette est
`docs/chantiers/maquettes/maquette-formulaire-inscription.jsx` (à lister dans le `README.md` de ce
dossier). Au démarrage de l'exécution, ouvre un journal d'exécution
`docs/chantiers/formulaire-inscription.md`, au format de `docs/chantiers/aide-contextuelle.md`.

**À la fin du chantier : commente l'issue #843 avec ce qui a été livré, itération par itération.
La clôture est faite par `issue-fixed-comment.yml` à la fusion de la PR qui porte `Corrige #843`
(la dernière) ; vérifie-la, termine à la main si besoin (`AGENTS.md` § « Fix the backlog », étape 7).**

---

## Ordre d'exécution et parallélisme

| Étape | Itération | Contenu | Dépend de |
|---|---|---|---|
| 0 | **IT-00** | Dépôt de ce document, de la maquette, de son entrée au `README` des maquettes, du journal | — |
| 1 | **IT-01** ∥ | Coquille : quatre sous-pages, Tableau de bord, déplacement des blocs, nettoyage de l'interface | IT-00 |
| 1 | **IT-02** ∥ | Fondations de capacité : projection unique, silence = reste, seuils en pourcentage d'occupation | IT-00 |
| 2 | **IT-03** | Moteur de recommandation + réglages « Capacités et priorités » | IT-01, IT-02 |
| 3 | **IT-04** ∥ | Colonne « Action rapide » et dialogue de décision, motif de refus | IT-03 |
| 3 | **IT-05** ∥ | Modèle des e-mails de décision (table, service, annulation/reprogrammation, statut parent) | IT-01 |
| 3 | **IT-06** ∥ | Copie (CC) des e-mails à la famille : `MailService` du cœur + réglage | IT-01 |
| 3 | **IT-07** ∥ | Sous-page Gabarits | IT-01 |
| 4 | **IT-08** | Fiche d'une demande : bloc « E-mail de décision », note interne indépendante | IT-04, IT-05, IT-06 |
| 5 | **IT-09** | Envoi automatique des e-mails de décision : réglage, délai, tâche planifiée | IT-05, IT-07, IT-08 |
| 6 | **IT-10** | Acceptation automatique des demandes « Oui » | IT-03, IT-09 |
| 7 | **IT-11** | Finition : documentation, aide, tests de bout en bout, vérification de la définition de terminé | tout |

```
IT-00 → ( IT-01 ∥ IT-02 ) → IT-03 → ( IT-04 ∥ IT-05 ∥ IT-06 ∥ IT-07 ) → IT-08 → IT-09 → IT-10 → IT-11
```

### Exécuter en parallèle

- **Une itération = une branche = une PR, et rien d'autre dedans.** Les branches s'appellent
  `claude/issue-843-it-NN` (la branche de réclamation `claude/issue-843` porte IT-00).
- Pour les groupes ∥ : lance **un sous-agent au premier plan par itération** (plusieurs appels
  d'agent dans le même message ; `.claude/hooks/subagents-in-foreground.sh` et
  `SubagentsStartInForegroundTest` interdisent l'arrière-plan). Chaque sous-agent travaille dans
  **son propre `git clone`** du dépôt (jamais un `git worktree` : `AGENTS.md` § « The working tree,
  and three ways it reports green while lying »), avec son propre `vendor/`.
- **Une seule suite PHPUnit complète à la fois** : `test_db` est partagé (`AGENTS.md` § « Fix the
  backlog », étape 6). Le sous-agent qui a fini son code prend le tour de la suite ; les autres
  attendent, ou travaillent sur leurs tests ciblés. Si l'environnement ne permet pas du tout de
  cloner une seconde copie, **note-le au journal et enchaîne le groupe en séquence** : ne contourne
  jamais cette règle.
- **Chaque PR du groupe se fusionne seule, dès qu'elle est verte** (armement de l'auto-merge). Une PR
  qui attend une autre du même groupe n'est pas une PR indépendante : c'est un défaut de découpage ;
  note-le au journal.
- Fichiers à risque de conflit dans le groupe IT-04/05/06/07 : `RegistrationRequestController.php`,
  `module.json`, `schema.sql`. **Crée des fichiers neufs plutôt que d'enrichir ceux-là** (nouveau
  contrôleur, nouveau service), et laisse l'entrée de routes dans `module.json` au plus petit
  changement. **La `version` du module monte dans chaque PR qui touche `module.json` ou
  `schema.sql`** : au rebase, prends la valeur suivante, ne résous jamais le conflit en reprenant
  la tienne.

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `specifications.md`, `design.md` et
`docs/module-development.md`. Ils priment sur ce fichier sur toute règle générale ; si tu découvres
qu'ils décrivent une réalité que le code contredit, **mets-les à jour dans la même PR**.

Lis aussi, avant IT-01 :

- `modules/registration/module.json` (réglages, six `scheduled_tasks`, routes, e-mails) et `schema.sql` ;
- `modules/registration/src/Controller/RegistrationConfigController.php` et
  `RegistrationRequestController.php` en entier ;
- `modules/registration/src/Service/SlotMath.php`, `SlotService.php`, `RequestEmailService.php`,
  `RequestStatusService.php`, `ForecastService.php`, `ProjectedPopulationService.php`,
  `ReenrollmentDepartureService.php` ;
- `modules/registration/src/Repository/RegistrationRequestRepository.php` ;
- `modules/registration/src/Task/SendReenrollmentEmailsHandler.php` : le modèle à suivre pour une
  tâche qui envoie en lot en réclamant ses envois (`sent_email_claims`) ;
- `modules/registration/views/config.html.twig`, `request_detail.html.twig`,
  `reenrollment_settings.html.twig` ;
- `core/Mail/MailService.php`, `core/Member/MemberYearService.php`, `core/Scheduler/*` ;
- `core/View/templates/partials/page_picker.html.twig` et `list_editor.html.twig`
  (listes connectées `sortable_group` / `group_key`) ;
- `modules/finance/views/_nav.html.twig` et `modules/rental/views/management/templates.html.twig` :
  les motifs à reprendre pour les sous-pages et les gabarits ;
- le chantier voisin `docs/chantiers/CHANTIER-reinscriptions.md` et sa maquette.

**Ce document a été écrit sur le commit `e203dfd` du 11 octobre 2026.** Si le code a bougé, c'est lui
qui fait foi : vérifie chaque fait cité avant de t'appuyer dessus et note tout écart au journal.

- **Le travail suit `AGENTS.md` § « Fix the backlog », pour #843 seulement** : branche
  `claude/issue-843` créée par l'API, `status:in-progress` posé sur l'issue, une PR par itération,
  **`Corrige #843` uniquement dans la dernière** (les autres disent « Partie de #843 (IT-NN) » ;
  **n'écris jamais `Closes`, `Fixes` ni `Resolves`**), claim et label gardés entre les PR.
- **Arme l'auto-merge dès qu'une PR est prête** (`AGENTS.md` § « Merging a pull request »), sans
  demander de confirmation : la PR se fusionne sur `main` dès que la CI est verte. Un test rouge arrête
  l'itération : tu corriges, tu ne contournes rien, tu ne désactives rien, tu n'ajoutes rien à une
  baseline.
- Tests obligatoires avant chaque poussée : PHPUnit (suite complète, une à la fois), PHPStan
  (`vendor/bin/phpstan analyse`), et `npm run typecheck` + `npm run test:coverage` si tu touches
  `public/assets/js/`. Couverture RBAC sur toute nouvelle route : autorisé au plancher du module,
  refusé un cran en dessous.
- **Toute modification d'un `module.json` ou d'un `schema.sql` de module impose de monter la
  `version` de `registration`** dans le même changement (aujourd'hui `6.11.0`).
- Code, commentaires, identifiants en **anglais** ; UI en **français**.
- **Le site est en test : aucune migration, aucune compatibilité.** Un champ, un réglage ou une
  colonne qui change de sens est remplacé, pas converti. **Aucun texte d'interface ne parle d'un état
  antérieur du site.**
- **Ne jamais mentionner « Desk »** dans un écran destiné aux parents ou aux membres.
- Cibles tactiles ≥ 44 px ; sous-pages par `partials/page_picker.html.twig` (onglets soulignés,
  `nav_rail`) ; **fil d'Ariane complet** sur chaque sous-page de Configuration ; un seul bouton
  « Enregistrer » par boîte ; sélecteurs de date **natifs** ; icônes pour les actions de ligne.
- Listes ordonnables : `list_editor` avec listes connectées. **Pas de case d'activation, pas de
  nouveau moteur de glisser-déposer.** Poignée sur grand écran, chevrons sur petit écran, jamais les deux.
- Les actions sensibles passent par `JournalService`. Aucune donnée personnelle dans les journaux
  au-delà des identifiants techniques autorisés. Les envois passent **toujours** par `MailService::send()`.
- Toute tâche différée passe par le Scheduler (`module.json`, `scheduled_tasks`), jamais par du
  temps ad hoc.
- **Ne pose de question que sur un changement de design ou une ambiguïté fonctionnelle réelle.**
  Pose-la dans la conversation et prends l'itération suivante qui n'en dépend pas pendant l'attente.

---

## Ce qui est décidé (fait foi sur le texte d'origine du ticket)

La maquette `maquette-formulaire-inscription.jsx` (v5) montre tout cela ; **la hiérarchie de l'écran,
les libellés français et les états d'interaction y font foi**, pas les classes CSS (Bootstrap 5,
`design.md` §7) ni l'iconographie. Elle remplace le texte d'origine du ticket partout où elles divergent.

### D1. Navigation
Quatre sous-pages non repliables : **Tableau de bord** (`/config/inscriptions`), **Demandes**,
**Gabarits**, **Réglages**. La fiche d'une demande est une sous-page de Demandes, avec le fil
d'Ariane complet (Configuration › Formulaire d'inscription › Demandes › Demande n° …).

### D2. Tableau de bord
Quatre cartes (Formulaire ouvert / fermé ; Demandes en attente ; E-mails de décision à envoyer ;
Acceptées pas encore dans Desk), en grille 4 par ligne sur grand écran, 2 sur petit. Puis la
« Vérification des capacités ». **Ni bannière d'année, ni bouton « Changer d'année », ni liste
« Demandes acceptées non rapprochées »** (nulle part). Le mot « rapprochée » disparaît de cette UI
au profit de « encodée dans Desk » et « Lier à un membre Desk ».

### D3. Recommandation Oui / Peut-être / Non — au niveau de l'**année de parcours**
La capacité est un nombre de places par « branche × année de parcours »
(`registration_slot_capacities`) : **NULL = illimité (toujours Oui), 0 = fermé (toujours Non)**.
Il n'existe aucune capacité par section : le texte d'origine du ticket sur « une autre section de la
même branche » n'a pas de sens et est abandonné.

Les seuils sont des **pourcentages d'occupation** de l'année de parcours :
**« Oui jusqu'à X % »** (défaut 70) et **« Peut-être jusqu'à Y % »** (défaut 100), glissière de 1 à
120 %, borne incluse (`occupation / capacité ≤ limite`), **Non au-delà**. Le niveau public du
formulaire (Oui = Disponible, Peut-être = Limitée, Non = Complet) utilise **exactement les mêmes
seuils** que la recommandation. Les deux anciens réglages
(`registration_waitlist_threshold_available` / `_limited`) sont **remplacés** par deux entiers
(pas de migration). La recommandation et le curseur ne sont visibles que si **« Gérer les listes
d'attente »** est coché ; décochée, les contrôles sont grisés et les valeurs conservées.

Occupation = acceptées (`status = 'accepted'` seulement, voir R1) + effectif projeté de l'année
suivante (voir D4).

### D4. Projection
Une seule projection, celle de `ForecastService` / `ProjectedPopulationProvider`, qui honore
`scout_year_offset` (animés en retard / en avance, via `MemberYearService::getEffectiveAge`).
`SlotService` perd sa projection simplifiée. **Une réinscription sans réponse compte comme un
enfant qui reste** ; seule une réponse « quitte » (`member_years.leaving`) ou une décision du staff
le retire. Le chiffre projeté s'affiche avec « dont N sans réponse ».

### D5. Évaluation en file
Les demandes d'une même année de parcours sont évaluées **dans une file** :

1. **Règles « Toujours Oui »** d'abord, **par date de dépôt**, et elles **peuvent dépasser la
   capacité**. Par défaut : *Fratrie déjà dans l'unité*. *Ancienne demande restée sans place* peut y
   être placée. *Ordre d'arrivée* ne le peut pas.
2. Les autres demandes sont **classées lexicographiquement** par les critères actifs, dans l'ordre
   choisi, puis par date de dépôt.
3. Chaque demande est jugée sur **l'occupation qui la précède, comme si toutes celles qui sont
   devant elle étaient acceptées**. Une ligne verrouillée suit la zone « Classement » :
   « Limite : le nombre de places de l'année de parcours » (toujours appliquée, en fin de
   classement).

Exclusions : un **doublon** (même nom + même date de naissance — `name_dob_blind_index` — dans la même
année) sort de la file, **sauf la plus ancienne** ; une demande **hors des tranches d'âge** n'a pas de
recommandation.

*Ancienne demande restée sans place* : même enfant (`name_dob_blind_index`) avec une demande refusée
d'une année antérieure dont le motif est « Pas de place ».

Les trois zones de la liste de priorités (« Toujours Oui », « Classement », « Désactivés ») sont des
listes connectées.

### D6. Motif de refus
Tout refus (dialogue rapide, fiche, refus groupé) demande un motif : **« Pas de place »** ou
**« Autre »**. Stocké sur la demande ; il alimente la règle « Ancienne demande restée sans place ».

### D7. Deux interrupteurs, désactivés par défaut, sous la case « Gérer les listes d'attente »
1. **Envoyer automatiquement les e-mails d'acceptation et de refus**, avec un **délai en heures**
   (défaut 24). Impossible à activer tant que le gabarit d'acceptation ou de refus est « À rédiger ».
   Les décisions prises **avant** l'activation ne sont pas planifiées.
2. **Accepter automatiquement les demandes « Oui »**, avec une **date de prise d'effet** (rien avant
   elle). Activable seulement si les listes d'attente **et** l'envoi automatique sont actifs.
   Traitement **dans l'ordre de la file, une demande à la fois, avec recalcul et verrou par année de
   parcours**. Le journal distingue ces acceptations des actions manuelles. Peut-être et Non ne sont
   jamais acceptées automatiquement.

### D8. E-mails de décision (option B du ticket)
Table dédiée aux envois planifiés / tentés (type de décision, échéance, état, date d'envoi, erreur
technique non exposée, numéro de tentative, annulation). Changer une décision **annule** l'envoi
devenu obsolète et **reprogramme** avec un délai complet. La fiche porte le bloc **« E-mail de
décision »** : *Non planifié, Prévu le …, Envoyé le …, Échec le …, Annulé suite à un changement de
décision, Gabarit à rédiger*, l'historique, et les actions **Envoyer maintenant**, **Réessayer**,
**Renvoyer l'e-mail**. La note interne est modifiable indépendamment et **ne déclenche jamais** un
e-mail. L'enregistrement d'une décision affiche une confirmation qui répond à « un e-mail va-t-il
partir ? ».

### D9. Copie (CC)
Option **activée par défaut**, champ adresse (défaut : l'adresse par défaut de l'unité), invalide ou
non résoluble → activation refusée. S'applique aux **trois e-mails à la famille** : accusé de
réception, acceptation, refus — **renvois compris**. Les alertes à l'unité et les e-mails de
réinscription n'en reçoivent pas (le ticket d'origine disait « tous les e-mails » ; cette décision
le restreint). `MailService::send()` n'a pas de paramètre `cc` aujourd'hui : il faut l'ajouter au
cœur, y compris pour la file différée de l'envoi.

### D10. Gabarits
Sous-page à la manière de Gabarits d'un bien en location : accusé de réception
(`registration_email_receipt_body`), alerte à l'unité (`registration_email_unit_alert_body`),
acceptation (`registration_email_accepted_body`), refus (`registration_email_refused_body`), chacun
avec son état **Par défaut / Personnalisé / À rédiger** et un bouton crayon. Les gabarits de
réinscription restent ailleurs. Le garde-fou de `RequestEmailService` (corps non rédigé → exception)
est conservé : une automatisation qui n'a pas pu envoyer ne prétend jamais avoir réussi.

### D11. Réglages
Ouverture / fermeture et programmation annuelle avec **sélecteurs de date natifs récurrents** (on
stocke `MM-DD`, on affiche la prochaine occurrence) ; boîte du code d'inscription avec « Ce code
permet à une famille d'accéder au formulaire même lorsque les inscriptions sont fermées. » ; la phrase
sur la fenêtre de rattrapage disparaît **de l'interface seulement** (le réglage
`registration_scheduled_catch_up_days` reste) ; capacités par branche (case d'attente, curseurs,
grille) ; priorités ; e-mails (automatique, délai, copie).

---

## Faits vérifiés et pièges à connaître avant de coder

- **R1. Les demandes « encodées » (`status = 'encoded'`) sont-elles comptées dans l'occupation ?**
  `acceptedCountBySlot` ne compte que `accepted`. Une demande encodée dans Desk devient un membre de
  l'année suivante et pourrait donc être comptée deux fois (projetée **et** acceptée) ou plus du tout.
  **Vérifie-le en premier, dans IT-02, par un test qui échoue avant ton correctif**, et note la
  conclusion au journal. Ne devine pas.
- **R2. Deux projections coexistent** (`SlotService` et `ForecastService`) : IT-02 les unifie.
- **R3. `visibleStatus()`** masque accepté / refusé tant que `accepted_email_sent_at` /
  `refused_email_sent_at` est nul. Avec la table d'IT-05, cette propriété **doit rester vraie** : le
  parent voit « en attente » jusqu'à l'envoi réel. Attention aux renvois : un renvoi ne doit jamais
  écraser la date du premier envoi, et un changement de décision ne doit pas laisser une ancienne
  date rendre la nouvelle décision visible avant son propre e-mail (un seul horodatage par type
  aujourd'hui : à reprendre proprement).
- **R4. Aucun signal « doublon » n'existe aujourd'hui** : IT-03 le crée à partir de
  `name_dob_blind_index` (le même enfant, la même année).
- **R5. `MailService::send()` n'a pas de `cc`** : à ajouter au cœur (IT-06), file différée comprise,
  avec ses tests et sa documentation.
- **R6. Rotation du jeton de suivi** : `RequestEmailService` fait tourner le jeton après un envoi
  réussi. La file différée ne doit pas la casser ni la faire tourner sur un envoi échoué.
- **R7. RGPD (#740)** : la nouvelle table contient des données liées à une demande. Elle suit les
  mêmes règles de purge / d'effacement que `registration_requests` ; vérifie le chantier #740 et la
  liste blanche mentionnée dans `AGENTS.md` (« If that whitelist grows a third table, the reset must
  follow »).
- **R8. Les années passées restent en consultation seule**, comme aujourd'hui.

---

## IT-00 — Dépôt des pièces *(seule, première)*

Branche `claude/issue-843` (la réclamation), PR « Partie de #843 (IT-00) ».

- Ce document → `docs/chantiers/CHANTIER-formulaire-inscription.md`.
- La maquette → `docs/chantiers/maquettes/maquette-formulaire-inscription.jsx`, plus une ligne dans
  `docs/chantiers/maquettes/README.md` (colonnes : Fichier / Chantier / Écran / Itération) :
  « Formulaire d'inscription : sous-pages, recommandation et décisions automatisées (#843) —
  `/config/inscriptions` : Tableau de bord, Demandes (liste, dialogue de décision), Gabarits,
  Réglages, fiche d'une demande — IT-01, IT-03, IT-04, IT-07, IT-08, IT-09, IT-10 ».
- Le journal `docs/chantiers/formulaire-inscription.md` (format `aide-contextuelle.md`), avec la
  conclusion de la vérification des faits ci-dessus au fil des itérations.
- Aucun code. Fusionne avant de lancer la suite.

---

## IT-01 — Coquille : quatre sous-pages, Tableau de bord, nettoyage ∥ IT-02

**But.** Découper `/config/inscriptions` sans changer aucun comportement métier.

- `partials/page_picker.html.twig` : Tableau de bord, Demandes, Gabarits, Réglages. Routes :
  `/config/inscriptions` (tableau de bord), `/config/inscriptions/demandes`,
  `/config/inscriptions/gabarits`, `/config/inscriptions/reglages`. Les routes existantes (save,
  export, toggle-open, schedule, code, bulk, `demandes/{id}` …) **gardent leur chemin** ; leur
  redirection de retour pointe vers la bonne sous-page.
- Déplace les blocs existants : Notes internes, États d'une demande, liste « Demandes — année … »
  (sélecteur d'année, filtres, recherche, export, tableau) et « Demandes non clôturées » (actions
  groupées) vers **Demandes** ; ouverture, programmation annuelle, code d'inscription et capacités
  vers **Réglages** ; **non repliables** (plus de collapse, de chevron, d'état mémorisé).
- **Gabarits** : page vide d'attente avec son titre et son fil d'Ariane (le contenu vient d'IT-07).
- **Tableau de bord** : quatre cartes (D2), puis la « Vérification des capacités » reprise telle quelle.
  Supprime la bannière d'année, le bouton « Changer d'année » et la liste des acceptées non
  rapprochées. Le compteur « acceptées pas encore dans Desk » = demandes `accepted` non encodées.
  « E-mails de décision à envoyer » = acceptées / refusées dont l'e-mail n'est pas parti (la
  définition se précisera en IT-05, qui la reprend).
- **Réglages**, ce que la maquette montre déjà et qui ne dépend de rien : sélecteurs de date natifs
  récurrents (stockage `MM-DD`), explication du code, retrait de la phrase de rattrapage.
- Fil d'Ariane de la fiche : Configuration › Formulaire d'inscription › **Demandes** › Demande n° ….
- Remplace « rapprochée » par « encodée dans Desk » / « Lier à un membre Desk » dans cette UI.

**Critères d'acceptation.** Quatre sous-pages visibles, aucune repliable ; pas de bannière ni de
bouton d'année ; plus aucune liste « non rapprochées » ; grille 4/2 ; `grep -ri rapproch` dans les
vues du module ne trouve plus ce sens ; les routes existantes répondent comme avant (RBAC inchangé).
**Tests** : `RegistrationConfigControllerTest` (rendu des sous-pages, redirections), RBAC des
nouvelles routes, test JS du sélecteur de date si du JS est ajouté.

## IT-02 — Fondations de capacité ∥ IT-01

**But.** Un seul calcul d'occupation, juste, avec des seuils en pourcentage.

- Règle **R1** : tranche-la d'abord, par un test.
- `SlotService` consomme `ForecastService` / `ProjectedPopulationProvider` (offsets via
  `MemberYearService::getEffectiveAge`). Supprime la projection simplifiée. **Une réinscription sans
  réponse compte comme restant** ; « quitte » (`member_years.leaving`) la retire. Expose aussi le
  nombre « dont N sans réponse ».
- Remplace les deux réglages de `module.json` par `registration_threshold_yes_percent` (70) et
  `registration_threshold_maybe_percent` (100) — entiers, pas de migration. Adapte `SlotMath` :
  ratio = occupation / capacité, `≤` inclusif, NULL toujours Oui, 0 toujours Non. `tierForRemaining`
  et ses appelants sont réécrits sur ce modèle, **sans** garder d'ancienne branche.
- Le niveau public (Disponible / Limitée / Complet) lit **les mêmes** deux seuils.
- La « Vérification des capacités » du tableau de bord affiche la projection unifiée.
- Un correctif d'interface minimal pour que les anciens champs de seuil de la page Réglages ne
  pointent plus vers des clés disparues : le remplacement visuel est fait par IT-03.

**Critères d'acceptation.** Tableau de cas (capacité, occupation → niveau) passant aux bornes
(70 %, 100 %, 120 %) ; NULL et 0 ; décalage d'un an en retard / en avance ; réinscription sans
réponse comptée ; encodées conformément à la conclusion de R1. **Tests** : `SlotServiceTest`, tests
de `SlotMath`, `PublicRegistrationControllerTest` (niveau public), tests de projection.

## IT-03 — Moteur de recommandation + réglages « Capacités et priorités »

**But.** Calculer la recommandation et la rendre configurable.

- Service de recommandation : file D5, règles « Toujours Oui » / classement / désactivées, doublons,
  hors tranche d'âge, « Ancienne demande restée sans place » (lit le motif de refus de D6 — la
  colonne est créée ici, l'écran de saisie vient d'IT-04), explication courte construite **depuis les
  critères réellement évalués**, sur le modèle de `recommend()` dans la maquette (« Oui — règle
  « Toujours Oui » : fratrie déjà présente dans l'unité. », « Oui — 1re demande classée sur 3 pour
  Louveteaux · 1re année. Avant elle, 6 places sur 15 seraient occupées (40 %), dans la limite du
  « Oui » (70 %). »).
- Réglage de l'ordre des critères : stockage d'une structure (zone + rang) dans les settings.
  Défauts : *Fratrie* en « Toujours Oui » ; *Ancienne demande restée sans place* et *Ordre d'arrivée*
  en classement. La ligne « Limite : … » est verrouillée après la zone « Classement ».
- **Réglages › Capacités** : case « Gérer les listes d'attente » ; glissière à deux poignées
  (1–120 %, vert à gauche) + deux champs numériques synchronisés + exemple chiffré sur une capacité
  (« places déjà occupées ») ; tout grisé / désactivé si la case est décochée, **valeurs conservées**.
- **Réglages › Priorités** : trois zones connectées (`list_editor`, `sortable_group`, `group_key`).
- Visible seulement si la gestion des listes d'attente est active.

**Critères d'acceptation.** Les deux exemples de la maquette (#409/#414 doublon, #398…) donnent les
mêmes résultats ; la fratrie dépasse la capacité mais pas les autres ; un classement change l'ordre
sans toucher aux « Toujours Oui » ; case décochée → aucune recommandation calculée ni affichée ;
la recommandation **n'apparaît dans aucune vue publique** (test de régression ci-dessous).
**Tests** : nouveaux tests du service (par année de parcours, critères, doublon, hors tranche,
explication, déterminisme), `RegistrationConfigControllerTest` (enregistrement des seuils, des
priorités, grisage), tests JS de la glissière et de l'intégration des listes connectées (sans
dupliquer `tests/js/list-editor*.test.js` ni `sortable.test.js`).

## IT-04 — Colonne « Action rapide » et dialogue de décision ∥ IT-05 ∥ IT-06 ∥ IT-07

- Colonne **Action rapide** avec un bouton « Décider » par ligne ; dialogue : recommandation + courte
  explication, champ « Note interne », **Accepter**, **Refuser** (avec motif obligatoire D6),
  **Annuler**. La note enregistrée apparaît sur la fiche (édition en IT-08).
- Le motif « Pas de place / Autre » est aussi exigé par la fiche et par le refus groupé.
- Nouveau contrôleur / nouvelle route plutôt qu'un gonflement de `RegistrationRequestController`.
- Le dialogue appelle les transitions existantes de `RequestStatusService` ; la planification des
  e-mails est branchée **par IT-05**, pas ici : tant qu'elle n'existe pas, le comportement d'envoi
  est celui d'aujourd'hui.
- Les années passées : le bouton n'apparaît pas.

**Tests** : `RegistrationRequestControllerTest` (dialogue, note, transitions, motif obligatoire,
RBAC), test de non-exposition aux parents.

## IT-05 — Modèle des e-mails de décision ∥ IT-04 ∥ IT-06 ∥ IT-07

- `schema.sql` : table dédiée (type de décision, échéance, état, date d'envoi, erreur technique non
  exposée, tentative, annulation, lien à la demande), index sur l'échéance et l'état.
- Repository + service : planifier, annuler, reprogrammer, enregistrer une tentative, un échec, un
  renvoi. **Un changement de décision annule l'envoi devenu obsolète et reprogramme avec un délai
  complet.** Aucun ancien e-mail ne part jamais après un changement.
- Reprend `RequestEmailService` : envoi réclamé de façon atomique (modèle `sent_email_claims`),
  garde-fou du corps non rédigé, rotation du jeton après succès seulement (R6), journal.
- `visibleStatus()` suit **l'envoi réel** (R3) ; rien d'autre ne change côté parent.
- Statut du tableau de bord « E-mails de décision à envoyer » branché sur la table.
- Règle R7 (RGPD) appliquée.

**Tests** : `RequestEmailServiceTest` (gabarit absent, envoi manuel, renvoi, échec), nouveau service
et repository, `RequestStatusServiceTest`, `TrackingControllerTest` (masqué avant envoi, visible
après), concurrence (deux réclamations simultanées → un seul envoi).

## IT-06 — Copie (CC) ∥ IT-04 ∥ IT-05 ∥ IT-07

- `core/Mail/MailService` : paramètre `cc` (validation, en-tête, DKIM, multipart), file différée
  comprise ; documentation (`ARCHITECTURE.md`) et tests du cœur.
- Réglages › E-mails : option « Copie des e-mails d'inscription » (activée par défaut), champ adresse
  (défaut = adresse par défaut de l'unité), activation refusée si l'adresse est invalide ou introuvable.
- Appliquée aux **trois e-mails à la famille** (accusé, acceptation, refus), **renvois compris**.
  Audite tous les chemins d'envoi du module, et note au journal ceux qui n'en reçoivent volontairement
  pas (alerte à l'unité, réinscription).

**Tests** : `MailService` (cc valide / invalide / absent), `RequestEmailServiceTest` (cc sur envoi et
renvoi), contrôleur des réglages (validation).

## IT-07 — Sous-page Gabarits ∥ IT-04 ∥ IT-05 ∥ IT-06

- Quatre lignes (D10) avec état Par défaut / Personnalisé / À rédiger et un bouton crayon
  (`aria-label` « Modifier — … »), comme `rental/views/management/templates.html.twig`.
- L'état « À rédiger » est calculé par la même fonction que celle du garde-fou de
  `RequestEmailService` (une seule définition).
- Fil d'Ariane complet.

**Tests** : rendu, états, RBAC, cohérence avec le garde-fou.

## IT-08 — Fiche d'une demande : bloc « E-mail de décision »

- Bloc D8 : état, historique des tentatives (jamais écrasé), **Envoyer maintenant**, **Réessayer**
  après échec, **Renvoyer l'e-mail** après succès (avec CC, IT-06).
- Note interne modifiable indépendamment ; enregistrer la note ne déclenche aucun e-mail.
- Confirmation après toute décision, qui répond à « un e-mail va-t-il partir ? » (automatique activé
  ou non, délai, gabarit à rédiger).
- Le dialogue d'IT-04 et la fiche utilisent le même service de décision (IT-05).

**Tests** : `RegistrationRequestControllerTest` (envoyer maintenant, réessayer, renvoyer, note sans
envoi), états du bloc, journal.

## IT-09 — Envoi automatique des e-mails de décision

- Réglages › E-mails : interrupteur « Envoyer automatiquement les e-mails d'acceptation et de refus »
  (défaut off) et délai en heures (défaut 24). Désactivé tant qu'un gabarit de décision est « À rédiger ».
- Les décisions prises **avant** l'activation ne sont pas planifiées.
- Tâche planifiée (`module.json`, modèle `SendReenrollmentEmailsHandler`) qui consomme les envois
  arrivés à échéance : réclame, envoie, journalise ; **reprend après une interruption sans envoyer
  deux fois** ; un gabarit absent marque l'échec, il ne passe pas pour un succès.
- Désactiver l'envoi automatique n'annule pas les envois déjà planifiés : la fiche le dit clairement
  et propose « Envoyer maintenant ».

**Tests** : tâche (délai de 24 h, annulation / reprogrammation, reprise sans double envoi, gabarit
absent), réglages (activation refusée sans gabarit).

## IT-10 — Acceptation automatique

- Réglages : interrupteur « Accepter automatiquement les demandes « Oui » » (défaut off), activable
  seulement avec la gestion des listes d'attente **et** l'envoi automatique ; date de prise d'effet
  (rien avant elle).
- Tâche planifiée : par année de parcours, **dans l'ordre de la file, une demande à la fois, avec
  recalcul et verrou** (`GET_LOCK` ou équivalent déjà utilisé dans le dépôt) ; s'arrête à la
  première demande qui n'est pas « Oui » ; ne dépasse jamais la capacité sauf règle « Toujours Oui ».
- Les e-mails passent par IT-05 / IT-09 ; le journal marque ces acceptations comme **automatiques**.
- Idempotente : deux passages rapprochés ne produisent pas deux acceptations.

**Tests** : prise d'effet, ordre, capacité, concurrence, idempotence, journal distinct, aucune
acceptation de Peut-être / Non.

## IT-11 — Finition

- `specifications.md`, `ARCHITECTURE.md`, `design.md` si besoin, et les sujets d'aide
  (`docs/…` / sujets du module) à jour ; RGPD (`AGENTS.md` § « RGPD ») si un nouveau flux sortant est apparu.
- **Test de régression** : la recommandation, ses explications et le motif interne ne figurent dans
  aucune vue publique de suivi.
- Passe finale sur la **définition de terminé** ci-dessous, case par case, avec la preuve (test ou
  capture) au journal.
- Commentaire final sur #843 : ce qui est livré, itération par itération ; la dernière PR porte
  `Corrige #843`.

---

## Définition de terminé

- [ ] `/config/inscriptions` est découpé en **Tableau de bord / Demandes / Gabarits / Réglages** via `page_picker`, avec fil d'Ariane complet.
- [ ] Aucun des blocs déplacés n'est repliable.
- [ ] Le Tableau de bord affiche les 4 métriques (grille 4/2) puis la vérification des capacités ; ni bannière, ni bouton d'année.
- [ ] Plus aucune liste « demandes acceptées non rapprochées » ; le mot « rapprochée » n'est plus utilisé pour l'encodage Desk.
- [ ] Demandes possède une colonne **Action rapide** ouvrant le dialogue de décision (recommandation expliquée, note, Accepter / Refuser avec motif, Annuler).
- [ ] Seuils : « Oui jusqu'à X % » / « Peut-être jusqu'à Y % » (1–120 %), mêmes seuils pour le niveau public, champs synchronisés, exemple chiffré.
- [ ] Case « Gérer les listes d'attente » décochée : contrôles grisés, valeurs conservées, aucune recommandation.
- [ ] Priorités configurables par listes connectées (Toujours Oui / Classement / Désactivés) ; la limite de places est verrouillée.
- [ ] Projection unique : décalages d'année pris en compte, réinscription sans réponse = reste, « dont N sans réponse » ; R1 tranché.
- [ ] Refus avec motif « Pas de place / Autre » partout ; « Ancienne demande restée sans place » fonctionne.
- [ ] Doublons exclus sauf le plus ancien ; hors tranche d'âge sans recommandation.
- [ ] E-mails automatiques de décision différés du délai configuré (24 h par défaut), désactivés par défaut et tant qu'un gabarit est « À rédiger ».
- [ ] Changement de décision avant envoi : annulation et reprogrammation, jamais d'ancien e-mail.
- [ ] La fiche détaille l'état et l'historique, avec **Envoyer maintenant**, **Réessayer**, **Renvoyer** ; la note est indépendante et n'envoie rien ; la confirmation répond « un e-mail va-t-il partir ? ».
- [ ] La page parent reste « en attente » jusqu'à l'envoi effectif ; la recommandation n'apparaît dans aucune vue publique.
- [ ] Acceptation automatique : désactivée par défaut, prise d'effet respectée, ordre de la file, un à la fois, capacité jamais dépassée (hors « Toujours Oui »), journal distinct, idempotente.
- [ ] Gabarits : les 4 gabarits avec leur état et un crayon.
- [ ] Copie : activée par défaut, adresse de l'unité par défaut, sur les 3 e-mails à la famille et leurs renvois, `MailService` du cœur étendu.
- [ ] Phrase de rattrapage retirée de l'interface ; explication du code d'inscription ajoutée ; sélecteurs de date natifs.
- [ ] Version du module montée dans chaque PR concernée ; `specifications.md`, `ARCHITECTURE.md` et l'aide à jour ; PHPUnit, PHPStan, Vitest et typecheck verts.

## Hors périmètre

- Repenser le flux de réinscription.
- Le critère « Transfert depuis une autre unité Les Scouts ».
- Un nouveau composant de glisser-déposer.
- Supprimer `registration_scheduled_catch_up_days`.
- Une capacité par section (elle n'existe pas dans le modèle).
