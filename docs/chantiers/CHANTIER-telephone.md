# Chantier ScoutMagic — Numéros de téléphone : appeler, SMS, WhatsApp ou Signal en un tap

Issue #757.

Partout où le site affiche un numéro de téléphone, on doit pouvoir appeler, envoyer un SMS ou
ouvrir une conversation WhatsApp ou Signal en **un ou deux taps**, directement dans l'application
concernée. Sur mobile surtout.

Roadmap d'exécution complète, en **4 itérations séquentielles**. Traite-les une par une, dans
l'ordre.

**Où se trouvent les pièces de ce chantier :** ce document est
`docs/chantiers/CHANTIER-telephone.md` ; sa maquette est
`docs/chantiers/maquettes/maquette-telephone.jsx` (déjà listée dans le `README.md` de ce dossier).
Au démarrage de l'exécution, ouvre un journal d'exécution `docs/chantiers/telephone.md`, au format
de `docs/chantiers/aide-contextuelle.md`.

**À la fin du chantier : commente l'issue avec ce qui a été livré, itération par itération, puis
clôture-la.**

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `specifications.md`, `design.md` et
`docs/module-development.md`.

Lis aussi, avant IT-01 :

- `core/Service/TextNormalizerService.php` : `normalizePhone()`, `formatBelgian()`, `groupDigits()` ;
- `core/View/TextNormalizerExtension.php` (le filtre `|normalize_phone`) ;
- `tests/Core/Service/TextNormalizerServiceTest.php` et
  `tests/Core/Member/Export/MemberExportRowBuilderTest.php`, qui exercent tous deux
  `normalizePhone()` ;
- `core/View/templates/partials/help_panel.html.twig` et `modules/sos_staff/views/admin.html.twig`,
  les deux endroits qui utilisent déjà un `offcanvas-bottom` ;
- un script de `public/assets/js/` qui teste `matchMedia` (`camps-map.js`, `push-invitation.js`) et
  son pendant dans `tests/js/` : la convention JS et Vitest du dépôt.

**Ce document a été écrit sur le commit `8e58ef1` du 30 septembre 2026.** Si le code a bougé, c'est
lui qui fait foi : vérifie chaque fait cité avant de t'appuyer dessus et note tout écart au journal.

- **Une itération = une branche = une PR, et rien d'autre dedans.** Rebase sur `main` avant de
  merger.
- **Merge et push sur `main` dès que tous les tests et la CI complète sont verts.**
- Tests obligatoires : PHPUnit, PHPStan, Vitest et `npm run typecheck`.
- **Aucune nouvelle dépendance Composer ni npm.** Pas de `libphonenumber` : voir D3.
- Code, commentaires, identifiants en **anglais** ; UI en **français**.
- **Aucun texte d'interface ne parle d'un état antérieur du site.**
- **Ne pose de question que sur une ambiguïté fonctionnelle réelle.**

---

## L'objectif et ce que ça n'est pas

Le site affiche des numéros dans une dizaine d'écrans. Aujourd'hui, ce sont au mieux des liens
`tel:` : on peut appeler, pas écrire. Or c'est très souvent un SMS ou un message WhatsApp qu'on
veut envoyer à un parent ou à un animateur.

**Il n'existe pas d'API de navigateur qui ouvre une conversation avec un numéro donné, dans
l'application de son choix.** La Web Share API envoie du contenu vers une application, elle ne
cible aucun contact. Ce qui existe, c'est un lien par service : `tel:` et `sms:` sont confiés à
l'application par défaut de l'appareil, et WhatsApp et Signal publient chacun un lien
`https://`. Le composant de ce chantier ne fait que les assembler.

---

## Ce qui existe déjà — à ne pas refaire

**Le filtre `|normalize_phone`** est utilisé 8 fois dans les gabarits. Il formate pour
l'affichage, et ne modifie jamais la base.

**11 liens `tel:` copiés-collés dans 8 gabarits**, avec trois façons différentes de nettoyer le
numéro :

- `core/View/templates/admin/members/show.html.twig`
- `core/View/templates/chefs/section_roster.html.twig`
- `core/View/templates/chefs/staffs.html.twig`
- `core/View/templates/members/show.html.twig`
- `modules/camps/views/camp.html.twig`
- `modules/rental/views/management/booking.html.twig`
- `modules/rental/views/public/tracking.html.twig`
- `modules/trombinoscope/views/partials/contacts.html.twig`

**Des numéros affichés sans aucun lien** : `emergency_phone` sur
`modules/rental/views/public/tracking.html.twig` ; le téléphone du conducteur et celui d'un
demandeur dans `modules/covoiturage/views/_offer_card.html.twig` ;
`modules/camps/views/contact_anonymise.html.twig`.

**Bootstrap Icons v1.13.1 est déjà embarqué** (`public/assets/vendor/bootstrap-icons/`) et contient
`bi-telephone`, `bi-chat-dots`, `bi-whatsapp`, `bi-signal` et `bi-clipboard`. Vérifié.

---

## Décisions verrouillées — ne pas les rouvrir

**D1 — Un numéro cliquable qui ouvre un menu, pas des icônes à côté.** Deux icônes de 44 px à côté
de chaque numéro font trois cibles par ligne, et dans un tableau ou une liste dense on touche la
mauvaise. Un tap sur le numéro ouvre le menu ; un second tap choisit.

**D2 — Les entrées du menu, dans cet ordre** : Appeler, SMS, WhatsApp, Signal, Copier le numéro.

**D3 — Tous les numéros s'affichent au format international, belges compris** (`+32 472 54 67
71`). Un seul format partout. **Rien n'est modifié en base.** Pas de bibliothèque de numéros :
voir IT-01 pour ce que ça implique.

**D4 — Un seul point de conversion vers l'international.** Les cibles n'attendent pas le même
format :

| Cible | Lien | Numéro |
|---|---|---|
| Appeler | `tel:` | `+32478123456` |
| SMS | `sms:` | `+32478123456` |
| WhatsApp | `https://wa.me/…` | `32478123456` — **sans** le `+` |
| Signal | `https://signal.me/#p/…` | `+32478123456` — le `+` est **obligatoire** |

Pour Signal, le code de l'application Android ne reconnaît le lien que si le numéro commence par
`+` ; sans lui, le lien est rejeté. Chaque cible tire son format du même numéro international.

**D5 — Liens `https://` pour WhatsApp et Signal, pas leurs schémas propres** (`whatsapp://`,
`sgnl://`). Si l'application est installée, le lien l'ouvre ; sinon l'utilisateur arrive sur une
page d'explication au lieu d'une erreur muette.

**D6 — Pas de corps de message dans `sms:`.** Le paramètre `body` ne s'écrit pas de la même façon
sur iOS et sur Android ; sans lui, le lien est identique partout.

**D7 — Un numéro qui ne peut pas recevoir de message ne propose que « Appeler » et « Copier ».**
Un fixe n'a ni SMS ni messagerie. Règle belge : un mobile est `+32 4[5-9]…` sur neuf chiffres ; tout
autre numéro belge ne reçoit pas de message. **Un numéro étranger est proposé en entier** : on ne
peut pas deviner s'il est fixe.

**D8 — Sur un écran non tactile, le numéro reste en texte simple avec un bouton « Copier ».** Un
SMS n'y a pas de sens, et WhatsApp ou Signal s'ouvriraient dans leur version web.

**D9 — Le HTML de base est un vrai lien `tel:`**, qui fonctionne sans JavaScript. Le script
l'améliore sur écran tactile (feuille d'actions) et le remplace sur écran non tactile (D8).

**D10 — Un seul élément cliquable par cellule.** C'est ce qui rend le numéro plus sûr qu'une paire
d'icônes dans un tableau.

**D11 — Les cibles tactiles font au moins 44 px** (`AGENTS.md`). Le numéro lui-même, et chaque
ligne de la feuille (48 px dans la maquette).

**D12 — Les liens externes portent `rel="noopener noreferrer"`.** `noreferrer` n'est pas un détail :
sans lui, l'en-tête `Referer` apprendrait à WhatsApp ou à Signal de quelle page du site de l'unité
le clic est parti.

**D13 — L'e-mail est hors périmètre.** Un message n'a pas de JavaScript, donc pas de feuille. Les
numéros affichés dans les e-mails (`modules/rental/views/email/practical_info.*`) ne changent pas.

---

## La maquette

`docs/chantiers/maquettes/maquette-telephone.jsx` fait foi pour : la feuille d'actions, les quatre trames (liste, tableau,
page d'un membre, affichage), le comportement d'un fixe et celui d'un écran non tactile. Elle ne
fait pas foi pour les classes CSS : Bootstrap 5, `design.md` §7 et le `offcanvas-bottom` déjà
utilisé par le panneau d'aide.

Les icônes y sont teintées aux couleurs de marque (vert WhatsApp, bleu Signal) : c'est un choix de
design facultatif. Sans la teinte, elles prennent la couleur du texte.

---

## IT-01 — Le numéro : conversion, affichage, nature

Aucun écran nouveau. Le chantier commence par fiabiliser ce qu'il va consommer.

### À faire

**Un service unique** qui, pour une chaîne saisie, rend le numéro international (`+32478123456`),
le texte à afficher (`+32 478 12 34 56`) et la nature : peut-il recevoir un message (D7). C'est
l'unique source de vérité de D4 ; `normalizePhone()` s'appuie dessus plutôt que de refaire sa
propre analyse.

**Deux défauts de `normalizePhone()` à corriger**, tous deux vérifiés dans le code :

- **Le préfixe `00` est mal lu.** `0033 6 12 34 56 78` commence par `0`, donc la fonction le prend
  pour un numéro belge et lui accole `32`. C'est pourtant la façon courante d'écrire un numéro
  étranger en Belgique. Un `00` initial équivaut à un `+`.
- **Les indicatifs de pays sont coupés à deux chiffres** (`$cc = substr($e164, 0, 2)`).
  `+352 691 123 456` (Luxembourg, proche des unités frontalières) devient `+35 …`. Les indicatifs
  E.164 sont à préfixe unique : une table des indicatifs de l'UIT et une recherche du plus long
  préfixe suffisent, **sans nouvelle dépendance**. Prends la liste dans une source faisant autorité
  plutôt que de la reconstituer de mémoire.

**Un point à vérifier, pas à supposer.** `formatBelgian()` ne traite que `02` comme indicatif à un
chiffre. Or `03`, `04` et `09` (Anvers, Liège, Gand) le sont aussi, et un numéro d'Anvers serait
groupé comme `+32 31 23 45 67`. Écris le test d'abord ; si le défaut est confirmé, corrige-le.

**Pour un indicatif absent de la table**, affiche le numéro en `+` suivi des chiffres, sans
prétendre connaître son groupement. Sans bibliothèque dédiée, on ne connaît le groupement correct
que pour quelques pays : garantis seulement que le préfixe est bien séparé.

**Les numéros belges s'affichent comme aujourd'hui** (`+32 …`) : l'affichage ne change donc pas
pour eux, et les liens `tel:` existants, construits sur le texte affiché sans espaces, restent
valides. Les exports qui passent par `normalizePhone()` ne changent pas non plus.

### Tests

Chaque cas de la maquette : `0472546771`, `+32 472 54 67 71`, `02/123.45.67`,
`+33 6 12 34 56 78`, `0033 6 12 34 56 78`, `+352 691 123 456`. Mobile belge contre fixe belge.
Un numéro étranger est proposé pour les messages. Les tests existants de `normalizePhone()` et de
`MemberExportRowBuilder` passent sans modification de leurs attentes pour les numéros belges.

---

## IT-02 — Le composant

`maquette-telephone.jsx` fait foi.

### À faire

**Une fonction Twig** `phone(...)` qui rend le lien : un `<a href="tel:+32478123456">` portant le
texte à afficher (D3), le numéro international et la nature en attributs `data-*`. **Le service
d'IT-01 décide ; le JavaScript n'a pas à ré-analyser un numéro.**

**Le script `public/assets/js/phone-link.js`** :

- sur écran tactile (`matchMedia('(pointer: coarse)')`), un tap ouvre la feuille — un
  `offcanvas-bottom` Bootstrap, comme le panneau d'aide, pour récupérer gratuitement le focus
  piégé, la fermeture au clavier et le fond cliquable ;
- les liens des quatre cibles se construisent ici, d'après le tableau de D4 : c'est **l'unique
  endroit où ces formats existent**, ce qui les rend testables en Vitest ;
- sur écran non tactile, le lien est remplacé par du texte et un bouton « Copier » (D8) ;
- « Copier » confirme par un libellé qui change brièvement (« Numéro copié ») et ferme la feuille ;
- sans JavaScript, le lien `tel:` reste (D9).

**La feuille** : le numéro affiché et sa nature (« Mobile » ou « Téléphone fixe ») en en-tête, puis
les lignes de D2, chacune avec son icône Bootstrap (`bi-telephone`, `bi-chat-dots`, `bi-whatsapp`,
`bi-signal`, `bi-clipboard`). Un numéro qui ne peut pas recevoir de message n'affiche que « Appeler »
et « Copier », avec une phrase qui dit pourquoi.

**Accessibilité** : le déclencheur est annoncé comme ouvrant un menu ; chaque ligne a un libellé
explicite ; la feuille se referme avec Échap.

### Tests

Vitest : le tableau de D4 cas par cas (en particulier le `+` de Signal et son absence pour
WhatsApp) ; une cible absente pour un fixe ; le comportement non tactile ; le copier. PHPUnit : la
fonction Twig rend le bon lien et les bons attributs, et **n'affiche rien pour un numéro vide**.

### À tester sur de vrais appareils, et à noter au journal

Une partie de ce comportement n'a été vérifiée que par la lecture des sources. Teste sur **iOS
Safari et Android Chrome**, **navigateur et application installée** (le site est une PWA) : l'ouverture
de `wa.me` et de `signal.me` depuis une PWA installée peut se comporter autrement que depuis un
onglet. Note le résultat plutôt que de le supposer, et dis-le si une plateforme ne fait pas ce qu'on
attend.

---

## IT-03 — Déploiement sur tous les écrans

### À faire

**Remplace les 11 liens `tel:` des 8 gabarits** listés plus haut par la fonction Twig, et **donne un
lien aux numéros qui n'en ont pas** : `emergency_phone` (suivi de location), le téléphone du
conducteur et celui d'un demandeur (`_offer_card.html.twig`), `contact_anonymise.html.twig`.

**Ne change rien à ce que chaque gabarit décide d'afficher.** Le composant rend ce qu'on lui
donne : un numéro que la page a décidé de ne pas montrer ne doit jamais apparaître dans le HTML.
Le téléphone d'un conducteur, par exemple, n'est révélé qu'après acceptation de la demande ; le
composant ne doit ni contourner cette règle ni la rendre plus facile à contourner.

**Un seul élément cliquable par cellule** dans les tableaux (D10).

**Cherche les numéros que ce relevé aurait manqués** — par exemple dans les autres modules, ou
sous un autre nom de variable — et note au journal ce que tu trouves et ce que tu fais.

### Tests

Chaque gabarit migré rend toujours son numéro. Un numéro masqué par la page n'apparaît pas dans le
HTML. Aucun `href="tel:` écrit à la main ne subsiste dans `core/View/templates/` ni dans
`modules/*/views/` : ajoute ce contrôle à la suite de tests, il empêche le retour du copier-coller.

---

## IT-04 — RGPD, documentation et aide

### À faire

**La page RGPD.** Un clic sur WhatsApp ou Signal envoie le numéro à leur domaine (`wa.me`,
`signal.me`) : l'action est celle de l'utilisateur, comme n'importe quel lien externe, mais la
page doit le dire. Regarde comment `Core\View\RgpdContentService` traite déjà les tiers — et le
commentaire de `MapTiles`, qui explique pourquoi trois endroits doivent s'accorder sur la mention
d'un sous-traitant — et suis la même approche. **Un `tel:` et un `sms:` ne passent par aucun
serveur** : ne les mentionne pas comme tiers.

- `ARCHITECTURE.md` : le service de numéro, la fonction Twig, le script, et pourquoi les formats
  de D4 vivent à un seul endroit.
- `specifications.md` : le comportement du numéro cliquable.
- **Un sujet d'aide** : « Appeler ou écrire à un contact depuis son numéro », avec `discovery: 2` —
  c'est le genre de chose qu'on ignore et qui fait gagner du temps.
- **Commente l'issue avec ce qui a été livré, itération par itération, puis clôture-la.**

---

## Écarté, explicitement

- **Un corps de message pré-rempli** (D6).
- **D'autres messageries** : Telegram, Messenger. Leur lien par numéro n'est pas vérifié, et une
  entrée de plus dans le menu a un coût. Le tableau de D4 est le seul endroit à étendre si on le
  décide plus tard.
- **Détecter qu'un numéro est inscrit sur WhatsApp ou Signal.** Impossible depuis le navigateur ;
  WhatsApp ou Signal affiche sa propre erreur.
- **Une bibliothèque de numéros de téléphone** (D3).
- **Modifier les numéros stockés en base.** L'affichage seul change.
- **Les numéros dans les e-mails** (D13).
