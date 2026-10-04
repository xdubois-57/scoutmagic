# Chantier ScoutMagic — Réinscriptions : une campagne par année visée, deux sous-pages, dates prévues, et confirmation de tout enregistrement qui peut écrire aux familles

Issue #796.

Roadmap d'exécution complète, en **5 itérations séquentielles**. Traite-les une par une, dans
l'ordre. **IT-01 est un correctif de sécurité : elle passe en premier et seule.**

**Où se trouvent les pièces de ce chantier :** ce document est
`docs/chantiers/CHANTIER-reinscriptions.md` ; sa maquette est
`docs/chantiers/maquettes/maquette-reinscriptions.jsx` (déjà listée dans le `README.md` de ce
dossier). Au démarrage de l'exécution, ouvre un journal d'exécution
`docs/chantiers/reinscriptions.md`, au format de `docs/chantiers/aide-contextuelle.md`.

**À la fin du chantier : commente l'issue avec ce qui a été livré, itération par itération, puis
clôture-la.**

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `specifications.md`, `design.md` et
`docs/module-development.md`.

Lis aussi, avant IT-01 :

- `modules/registration/src/Controller/ReenrollmentConfigController.php` en entier : `save()`,
  `preview()`, `remind()`, `remindQuestion()`, `openingFor()`, `queueEmails()` ;
- `modules/registration/src/Service/ReenrollmentCampaignService.php` en entier :
  `currentCampaignKey()`, `keyAt()`, `closeDate()`, `reminderDate()`, `openingOnSave()`,
  `openingSendsEmail()`, `keyForManualOpening()` **et son docblock**, `automaticReminders()`,
  `tracking()` ;
- `modules/registration/src/Task/ReenrollmentCampaignHandler.php` (`handOver()` et les passes
  horaires) et `modules/registration/src/Task/SendReenrollmentEmailsHandler.php` ;
- `modules/registration/views/reenrollment_config.html.twig` et
  `public/assets/js/reenrollment-config.js` ;
- `tests/Modules/Registration/Controller/ReenrollmentConfigControllerTest.php` ;
- `core/ScoutYear/ScoutYearResolver.php` et `ScoutYearService::nextLabel()` : c'est d'eux que
  dépend l'année visée ;
- `modules/support_dashboard/views/_nav.html.twig` et son commentaire : le rail de sous-pages
  (`partials/page_picker.html.twig`) dont ce chantier reprend le motif.

**Ce document a été écrit sur le commit `fd08ff3` du 4 octobre 2026.** Si le code a bougé, c'est lui
qui fait foi : vérifie chaque fait cité avant de t'appuyer dessus et note tout écart au journal.

- **Une itération = une branche = une PR, et rien d'autre dedans.** Rebase sur `main` avant de
  merger.
- **Merge et push sur `main` dès que tous les tests et la CI complète sont verts.**
- Tests obligatoires : PHPUnit, PHPStan, Vitest et `npm run typecheck`. Couverture RBAC sur toute
  nouvelle route : autorisé au plancher (`admin`), refusé un cran en dessous.
- **Toute modification d'un `module.json` ou d'un `schema.sql` de module impose de monter la
  `version` de `registration` dans le même changement.**
- Code, commentaires, identifiants en **anglais** ; UI en **français**.
- **Aucun texte d'interface ne parle d'un état antérieur du site.**
- **Ne jamais mentionner « Desk »** dans un écran destiné aux parents ou aux membres.
- **Ne pose de question que sur une ambiguïté fonctionnelle réelle.**

---

## L'incident qui déclenche ce chantier

Le 4 octobre 2026, un chef d'unité a éteint l'interrupteur « Campagne ouverte » depuis la page des
réglages. **Un e-mail de clôture est parti vers les familles sans réponse, sans aucune question
posée.** Cet e-mail disait :

> La réinscription 2027-2028 est clôturée — la campagne de réinscription 2027-2028 s'est terminée le
> 15/05/2026 sans que nous ayons reçu votre réponse.

Il cumule trois défauts.

**Aucune confirmation sur la fermeture.** `ReenrollmentConfigController::save()` met en file l'e-mail
de clôture dès que `is_open` passe de vrai à faux, sans garde ni aperçu
(`queueEmails(EMAIL_CLOSING, currentCampaignKey())`). La garde de #732 (`confirm_opening`,
`/config/reinscription/apercu`) ne couvre que l'**ouverture**.

| Enregistrement | Écrit aux familles | Confirmé |
|---|---|---|
| Ouvrir à la main | e-mail d'ouverture | oui |
| Date d'ouverture = aujourd'hui | e-mail d'ouverture | oui |
| **Fermer à la main** | **e-mail de clôture** | **non** |

**Une campagne déjà terminée est fermée une seconde fois.** `currentCampaignKey()` rend « la
campagne dont la fenêtre contient ou précède le plus récemment aujourd'hui » : de mars à mars de
l'année suivante, c'est la même. En octobre 2026, c'est donc celle du 15 mai 2026, **finie depuis
cinq mois**. `handOver()` ne vérifie que l'interrupteur des e-mails, le marqueur « déjà envoyé » et
l'absence d'une tâche en cours ; `SendReenrollmentEmailsHandler` calcule les familles sans réponse de
l'année en cours et écrit, avec comme date de clôture celle de la clé. Rien ne regarde si la
campagne est terminée.

**Le libellé de l'année est incohérent avec la date** : « 2027-2028 » pour une campagne close le
15/05/2026. L'année visée se calcule depuis l'année publique actuelle
(`getCurrentPublicYear()` puis `ScoutYearService::nextLabel()`), pas depuis la campagne ; il semble
que le gestionnaire d'envoi fasse de même, ce qui décale le libellé dès que l'année scoute change.
**À vérifier dans `SendReenrollmentEmailsHandler`**, pas à supposer.

**Et la règle qui choisit « quelle campagne ouvre l'interrupteur » est invisible.**
`keyForManualOpening()` compare, hors de toute fenêtre, les jours écoulés depuis la dernière clôture
et les jours avant la prochaine ouverture : la plus proche gagne, et en cas d'égalité la campagne qui
vient de se terminer. Avec une ouverture au 01/03 et une fermeture au 15/05, le même clic **rouvre la
campagne terminée sans e-mail jusqu'au 7 octobre, puis ouvre la prochaine avec un e-mail à toutes les
familles à partir du 8**. Rien ne l'affiche.

---

## Décisions verrouillées — ne pas les rouvrir

**D1 — Un seul plan d'envoi, calculé côté serveur, une seule fois.** Pour un enregistrement donné,
le serveur calcule ce qui partira : quels e-mails, vers combien de familles, pour quelle campagne.
**C'est ce plan que le dialogue affiche, que l'enregistrement applique, et que le serveur compare au
moment d'écrire.** Deux calculs qui divergent donneraient un dialogue qui dit « aucun e-mail » pour
un envoi qui part.

**D2 — Tout enregistrement qui change quelque chose se confirme, et le dialogue répond toujours à la
même question : un e-mail va-t-il partir ?** Oui : combien, lesquels, à qui, et le bouton devient
« Enregistrer et envoyer », en couleur d'avertissement. Non : « Aucun e-mail ne partira », avec la
raison, et un bouton neutre « Enregistrer ». Un enregistrement qui ne change rien n'ouvre aucun
dialogue (« Aucun changement à enregistrer »). **La question ne doit jamais se poser** : c'est
l'objet de ce chantier.

**D3 — Il n'y a, à tout moment, qu'une campagne qu'on puisse ouvrir : celle de l'année visée**, c'est
à dire l'année scoute qui vient. C'est elle que l'interrupteur « Campagne ouverte » ouvre et ferme,
que la boîte « Relancer maintenant » décrit, et que tout e-mail nomme. **La règle de distance de
`keyForManualOpening()` disparaît** : hors des dates, il n'y a plus à deviner de quelle campagne il
s'agit.

**D4 — Ouvrir à la main ouvre cette campagne maintenant ; sa fermeture, ses rappels et sa clôture
restent ceux des réglages.** Ouvrir en octobre une campagne qui se ferme au 15/05 est donc permis, et
le dialogue dit que la campagne restera ouverte près de sept mois.

**D5 — Les e-mails sont marqués par campagne, donc ils ne repartent jamais.** Rouvrir une campagne
dont l'e-mail d'ouverture est déjà parti n'écrit à personne ; refermer une campagne dont l'e-mail de
clôture est déjà parti non plus. L'ouverture planifiée du 01/03 qui suit une ouverture manuelle
d'octobre ne renvoie rien. **La clé d'une campagne reste sa date de clôture (`Y-m-d`)**, pour que les
marqueurs existants gardent leur sens ; ce qui change est la façon de choisir laquelle est courante
(IT-02).

**D6 — Le dialogue nomme la campagne : l'année visée et ses dates.** « Ouvre la campagne de
réinscription pour 2027-2028 — fermeture le 15/05/2027, rappels le 01/05/2027 et le 13/05/2027 ».
Cette ligne aurait empêché l'incident : un libellé 2027-2028 ne peut plus se retrouver collé à une
date de 2026.

**D7 — Le serveur reste garant, sans script.** Un navigateur sans JavaScript ne doit ni pouvoir
enregistrer sans confirmation, ni rester bloqué sans issue. Le comportement actuel sur l'ouverture
(*« un navigateur sans le script ne peut pas sauter la question »*) est la bonne direction ; il
s'étend à tout enregistrement qui change quelque chose. À toi de choisir le mécanisme — par exemple
un rendu serveur de l'écran de confirmation, que le script se contente d'anticiper — et de le noter
au journal.

**D8 — Après chaque enregistrement, un message dit ce qui est parti et ce qui ne l'est pas.** « Aucun
e-mail n'est parti : l'e-mail d'ouverture de cette campagne est déjà parti » plutôt que « Campagne
enregistrée ».

**D9 — La page de gestion devient deux sous-pages**, sur le rail `page_picker` :

| Pastille | Route | Contenu |
|---|---|---|
| **Tableau de bord** | `/config/reinscription` | « État » et « Relancer maintenant » |
| **Réglages** | `/config/reinscription/reglages` | dates, rappels, les deux interrupteurs |

Le fil d'Ariane porte tous les niveaux : `Espace chefs d'U / Réinscriptions / Réglages`.
`/config/reinscription` est un chemin statique : un `ancestors` classique suffit. L'entrée de menu
« Réinscriptions » ne change pas. Vocabulaire choisi par le mainteneur : « Tableau de bord » et
« Réglages ».

**D10 — Chaque étape de « Relancer maintenant » dit ce qui est parti OU ce qui est prévu**, à côté de
« Pas encore envoyé » : *prévu le 01/05/2027*. Cinq états, ceux de la maquette : envoyé (avec sa
date, et « ouverte à la main » quand c'est le cas), prévu (avec sa date), **manqué** (prévu le …,
date passée — une date manquée est manquée), **sauté** (la date tombe avant l'ouverture),
**désactivé** (e-mails de la campagne éteints).

**D11 — Le dialogue « Relancer maintenant » et la boîte lisent les mêmes données.** Il dit
aujourd'hui « Aucun autre rappel automatique n'est prévu » alors que les réglages en prévoient : les
rappels se comptent depuis la fermeture d'une campagne passée. Il ne doit plus pouvoir contredire la
page.

---

## La maquette

`maquette-reinscriptions.jsx` fait foi pour : les deux sous-pages, les cinq états d'étape, les huit
situations de l'année (avant l'ouverture, entre deux campagnes, ouverte à la main, en cours, date
manquée, rappel sauté, e-mails désactivés, après la clôture), et les dialogues de confirmation. Elle ne
fait pas foi pour les classes CSS : Bootstrap 5 et `design.md` §7.

Sa logique de plan est déjà éprouvée et sert de jeu de tests : ouvrir entre deux campagnes
(e-mail d'ouverture, année nommée), date d'ouverture = aujourd'hui (idem), fermer une campagne
ouverte à la main (clôture), fermer en cours (clôture), rouvrir après la clôture (rien : l'e-mail est
déjà parti), fermer avec e-mails éteints (rien), ouvrir avant l'ouverture (ouverture), changer un
rappel (rien ne part), éteindre les e-mails (rien ne part, et plus rien ne partira).

---

## IT-01 — Le plan d'envoi, et la fermeture qui écrivait à tort

**Correctif de sécurité, indépendant de la suite. Rien de visible n'est ajouté.**

### À faire

**Un service qui calcule le plan** (D1) : à partir de l'état enregistré et de ce qu'on s'apprête à
enregistrer, il rend la liste de ce qui change, et ce qui partira — type d'e-mail, nombre de familles,
campagne concernée — ou pourquoi rien ne part. Il remplace `openingOnSave()` /
`openingSendsEmail()` comme source unique, sans en perdre les règles.

**Fermer une campagne déjà terminée n'écrit à personne.** Tant qu'IT-02 n'a pas changé ce que
« courante » veut dire, fais refuser à la fermeture ce que `keyForManualOpening()` refuse déjà pour
l'ouverture : écrire pour une campagne dont la date de clôture est passée. C'est le correctif qui
ferme l'incident ; IT-02 le rend sans objet en changeant la campagne courante, mais il doit exister
seul, avant.

**Vérifie ce qu'un enregistrement rend dû aujourd'hui.** Un rappel ou un e-mail qu'un enregistrement
rend « dû aujourd'hui » part à la prochaine passe horaire, pas à l'enregistrement. Regarde dans
`ReenrollmentCampaignHandler` quelles conditions les font partir, et fais annoncer par le plan tout
e-mail que l'enregistrement déclenche, même différé.

**Ne touche pas encore à l'interface.** Les gardes existantes (`confirm_opening`, `/apercu`)
continuent de fonctionner ; IT-03 les remplace.

### Tests

Un test d'intégration qui rejoue l'incident : campagne « ouverte » en octobre, interrupteur éteint,
e-mails actifs → **aucune tâche `send_reenrollment_emails`**. La matrice de la maquette, pour ce qui
ne dépend pas encore d'IT-02.

---

## IT-02 — Une campagne par année visée

C'est la partie délicate du chantier : elle change ce que « la campagne courante » veut dire.

### À faire

**Identifie la campagne par son année visée** (D3). La campagne courante est celle de l'année
scoute qui vient, et non « la fenêtre qui précède ou contient aujourd'hui ». **Sa clé reste sa date
de clôture** (D5) : pour une année visée qui commence en septembre 2027, la campagne se ferme en mai
2027, clé `2027-05-15`. Les marqueurs écrits avant ce chantier, par exemple ceux de la campagne du
15 mai 2026, gardent leur sens et ne sont pas touchés.

**Supprime la règle de distance** de `keyForManualOpening()` (D3, D4) : ouvrir à la main ouvre la
campagne de l'année visée. Conserve le principe que ses e-mails sont marqués par campagne (D5), et
écris dans le docblock pourquoi la règle de distance n'existe plus.

**Vérifie, et note au journal, quand l'année scoute bascule** : automatiquement à une date, ou quand
le chef lance « Changer d'année ». C'est ce qui décide du moment exact où la campagne courante change
d'année visée. Gère explicitement le jour du changement : une campagne dont des e-mails sont déjà
partis ne doit pas être remplacée en silence par la suivante.

**Le libellé et la date de clôture d'un e-mail viennent de la même campagne** (le défaut d'année de
l'incident). Vérifie dans `SendReenrollmentEmailsHandler` d'où vient l'année affichée ; si elle vient
de l'année publique actuelle, fais-la venir de la campagne. Un e-mail de clôture d'une campagne close
en mai porte le même libellé en mai et en octobre.

**Cas limite à traiter :** une fenêtre qui chevauche le nouvel an (ouverture en novembre, clôture en
février). `keyForOpening()` le gère aujourd'hui ; ne le casse pas.

### Tests

Le choix de la campagne courante à six dates de l'année, dont la veille et le jour du changement
d'année scoute, avec les dates par défaut puis avec une fenêtre à cheval sur le nouvel an. Un e-mail
porte le même libellé en mai et en octobre. Ouvrir à la main en octobre ouvre la campagne de l'année
visée, et l'ouverture planifiée de mars ne renvoie rien.

---

## IT-03 — La confirmation universelle

### À faire

**Le serveur impose la confirmation** à tout enregistrement qui change quelque chose (D2, D7), et
refuse sans elle avec un message clair. **Elle porte sur le plan exact** (D1) : si l'état a changé
entre l'affichage du dialogue et l'écriture, l'enregistrement est refusé et le chef relit.

**Le dialogue** — `window.ScoutMagicConfirm` est le composant existant : regarde s'il accepte un
contenu structuré. Si non, étends-le de façon générique plutôt que d'en écrire un second. Contenu,
tel que la maquette le montre : l'encadré « campagne concernée » avec l'année et les dates (D6),
« Ce qui change » (liste), puis l'encadré **« N e-mails vont partir… »** (ambre, avec « Un e-mail
envoyé ne se rappelle pas ») ou **« Aucun e-mail ne partira »** (vert, avec la raison), et le bouton
« Enregistrer » ou « Enregistrer et envoyer ».

**Le message après enregistrement** (D8).

**Sous l'interrupteur « Campagne ouverte »**, une phrase nomme la campagne : « Interrupteur manuel
pour la campagne de réinscription pour 2027-2028. »

**`/config/reinscription/apercu` et `confirm_opening` sont remplacés**, pas doublés : une seule façon
de confirmer.

### Tests

Un test par ligne de la matrice : le dialogue annonce exactement ce que l'enregistrement fait. Sans
confirmation, rien n'est écrit. Un plan périmé entre le dialogue et l'écriture est refusé. Vitest sur
le script. **Mets à jour `ReenrollmentConfigControllerTest`**, dont une attente (ligne 562) porte sur
le texte « Aucun autre rappel automatique n'est prévu | Prochain rappel automatique prévu le ».

---

## IT-04 — Deux sous-pages, et les dates prévues

`maquette-reinscriptions.jsx` fait foi.

### À faire

**Le rail et les deux routes** (D9). L'enregistrement des réglages passe sur
`/config/reinscription/reglages` ; `/config/reinscription/relance` reste sur le tableau de bord. **Le
fil d'Ariane porte tous les niveaux**, sur les deux pages.

**La boîte « Relancer maintenant » décrit la campagne de l'année visée** (D3). Plus de campagne
terminée affichée entre deux campagnes. Une ligne grise dit ce qui est parti la dernière fois. La
carte « État » n'annonce plus une « clôture prévue » déjà passée.

**Les cinq états d'étape** (D10), sur le même plan de calcul que les rappels réellement envoyés.
`reminderDate()` se calcule aujourd'hui depuis `closeDate($now)` : il lui faut prendre la campagne à
calculer. Un rappel dont la date tombe avant l'ouverture s'affiche « sauté » avec sa raison, jamais
avec une date.

**Le dialogue « Relancer maintenant » lit les mêmes données** (D11), et annonce le nombre de
familles. Il garde son propre dialogue : c'est déjà une confirmation.

**Les e-mails désactivés** : « Désactivé » sur chaque étape et le bouton grisé, avec un lien vers les
réglages.

### Tests

Chacun des cinq états rendu. La campagne affichée, aux huit situations de la maquette. Le dialogue ne
contredit jamais la boîte. Le fil d'Ariane complet sur les deux pages. RBAC sur la nouvelle route.

---

## IT-05 — Documentation et aide

### À faire

- `ARCHITECTURE.md` : le plan d'envoi, la règle « une campagne par année visée, identifiée par sa
  clôture », la règle « tout enregistrement qui peut écrire aux familles se confirme et annonce le
  résultat », et pourquoi le serveur calcule le plan une seule fois.
- `specifications.md` : les deux sous-pages et la boîte « Relancer maintenant ».
- **Sujets d'aide** : celui de cette page couvre les deux sous-pages (`paths:` des deux routes).
  `HelpMenuCoverageTest` tombera si un titre de page et son sujet divergent.
- **Commente l'issue avec ce qui a été livré, itération par itération, puis clôture-la.**

---

## Écarté, explicitement

- **Une règle de distance, ou une règle de 30 jours,** pour deviner si le chef rouvre l'ancienne
  campagne ou ouvre la suivante. Il n'y en a qu'une (D3).
- **Décaler la fermeture sur la date d'ouverture manuelle.** La fermeture reste celle des réglages
  (D4), même si ça fait une campagne de sept mois. Le dialogue le dit ; ce n'est pas au code de le
  corriger.
- **De nouveaux réglages.** Aucun champ ajouté à la page.
- **Les transitions planifiées** (ouverture et fermeture à la date, rappels horaires) : leur date est
  la confirmation, il n'y a rien à demander.
