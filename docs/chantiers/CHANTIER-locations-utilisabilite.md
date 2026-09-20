# Chantier — Utilisabilité du module Locations

Roadmap d'exécution en **7 itérations séquentielles**, issue #357. Traite-les une par une,
dans l'ordre. N'en commence pas une avant que la précédente soit fusionnée sur `main`.

Il n'y a pas de maquette dans le dépôt pour ce chantier : la description des écrans dans IT-05 et
IT-06 fait autorité sur la hiérarchie des pages et les libellés français. Le produit est en
Bootstrap 5 et suit `design.md` §7.

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `specifications.md`, `design.md` et
`docs/module-development.md`. Ils priment sur ce fichier sur toute règle générale ; si tu
découvres qu'ils décrivent une réalité que le code contredit, **mets-les à jour dans la même PR**.

- Une itération = une branche, une PR. Rebase sur `main` avant de merger.
- **Merge et push sur `main` dès que la CI complète est verte** (auto-merge armé :
  `gh pr merge <n> --squash --auto`). Un test rouge arrête le chantier : tu corriges, tu ne
  contournes pas, tu ne désactives rien, tu n'ajoutes rien à une baseline.
- Tests obligatoires : PHPUnit, PHPStan, et `npm run typecheck` + Vitest dès que tu touches
  `public/assets/js/`. Couverture RBAC explicite sur chaque frontière de rôle touchée.
- **Toute édition de `modules/rental/schema.sql` impose de bumper `version` dans son
  `module.json`, dans le même changement.** Le module est en v1.20.0. Sans le bump, la colonne
  n'est créée que sur une activation neuve et toutes les installations existantes tombent sur
  `Unknown column`.
- Code, commentaires, identifiants et noms de colonnes en anglais ; interface en français.
  Aucune donnée personnelle dans le journal ni dans un message d'erreur.
- Chaque écran modifié voit son sujet d'aide mis à jour dans la même PR
  (`modules/rental/help/*.md`, charte éditoriale `design.md` §7.11). `tests/Core/Help/` échoue
  sinon.
- Tu avances seul. Tu ne poses une question que sur une **ambiguïté fonctionnelle réelle** ou un
  **changement de conception** non tranché ici. Tout le reste se décide et se documente.
- Un problème réel que tu décides de ne pas corriger devient une issue GitHub, jamais un
  commentaire de PR (`AGENTS.md`). Une faille de sécurité ne devient jamais une issue publique.
- **L'issue #357 reste unique : elle n'est pas scindée.** Les PR d'IT-01 à IT-06 la référencent
  sans la fermer (« Refs #357 ») ; **seule la PR d'IT-07 porte « Closes #357 »**, et c'est sa
  fusion qui clôt le chantier. Si tu reportes quoi que ce soit en cours de route, ouvre-lui son
  issue propre avant de fermer celle-ci — une issue close ne doit rien laisser derrière elle.

---

## Ce qui est verrouillé pour tout le chantier

**Le locataire n'a pas de compte.** Sa page de suivi est atteinte par un jeton de capacité, elle
ne télécharge aucun fichier, elle ne montre ni commentaire interne ni note de gestionnaire. Rien
dans ce chantier ne change cela.

**Toute action visible du locataire demande confirmation.** Une action qui déclenche un email,
une notification, ou qui apparaît sur la page de suivi, passe par un dialogue qui dit trois
choses : ce que le locataire verra, ce qui devient irréversible, et un mot facultatif du
gestionnaire. Le mécanisme existe déjà (`data-confirm`, `data-confirm-note`,
`public/assets/js/confirm.js`) — il s'agit de l'appliquer partout, pas d'en écrire un second.

**On n'édite jamais du HTML à la main dans une interface.** Partout où un texte est rédigé, c'est
le composant de texte riche générique.

---

## IT-01 — Le moteur de jetons partagé

La seule itération qui ne touche pas à l'utilisabilité, et elle passe en premier parce que IT-06
en dépend.

### Le constat

Deux moteurs de substitution `{{ … }}` coexistent, avec les mêmes règles et sans rien partager :

- `Modules\MassMail\Service\MergeRenderer` — publipostage, jetons = en-têtes de colonnes Excel,
  plus les sections `{{#Colonne}} … {{/Colonne}}`.
- `Modules\Rental\Document\DocumentKeywords` — contrats et factures, catalogue fermé.

Les deux substituent **après** assainissement, échappent **toujours** la valeur, et laissent
visible un jeton inconnu. `MergeRenderer` documente en plus le piège `%7B%7B` : un jeton placé
dans un `href` ressort percent-encodé par le `DOMDocument` de l'assainisseur et ne substitue plus
jamais — silencieusement.

### À faire

Extraire au cœur (`core/Template/` ou équivalent) : le motif de jeton, la récupération du jeton
percent-encodé, la détection des jetons inconnus, l'échappement à la substitution, et **la passe
de réparation d'un jeton coupé par du balisage inline** — c'est la pièce neuve, celle dont IT-06 a
besoin.

Un champ de texte riche avec palette de variables, alimenté par un catalogue déclaré, réutilisable
par les deux modules.

Restent chez chacun : le catalogue lui-même, et les sections `{{#Colonne}} … {{/Colonne}}`, qui
n'ont de sens que pour le publipostage.

### La contrainte non négociable — **Décidé**

`mass_mail` est en production. L'extraction est à **comportement strictement identique** : ses
tests actuels passent verts **sans être retouchés**. Si un test doit changer, c'est que le
comportement a changé : arrête-toi et dis-le.

---

## IT-02 — Le formulaire public de demande

### À faire

Dans `modules/rental/views/public/request.html.twig` et
`Modules\Rental\Controller\RentalRequestController::submit()` :

- Exemples sur deux champs : objet de la location → « Week-end de section » ; organisation →
  « Unité du Petit Ry SV025 ».
- **Téléphone et objet de la location deviennent obligatoires**, côté client *et* côté serveur.
  Ils sont aujourd'hui facultatifs des deux côtés (`Support::optionalString()`).
- **L'organisation reste facultative** — une famille qui loue le local pour une communion n'en a
  pas, et la rendre obligatoire la force à inventer une réponse.

### Les conditions de location — **Décidé**

Le défaut actuel est pire que « pas documentées ». Le texte est un contenu `editable()` par bien
(clé `rental_asset_{id}_conditions`), **sans valeur par défaut livrée**, éditable uniquement en
mode configuration sur la page publique du bien — donc par un superadmin, pas par le gestionnaire.
Tant que personne ne l'a écrit, le formulaire n'affiche aucune condition mais présente quand même
la case « J'accepte les conditions de location », obligatoire : le locataire accepte le vide, et le
hash de preuve atteste du vide.

Trois niveaux, exactement comme le contrat :

1. **Un texte standard belge livré avec le module** (`Document\StandardTemplates`, même précédent)
   sert de valeur par défaut. Le texte n'est donc jamais vide, et une unité qui n'a rien écrit
   produit quand même des conditions complètes. Ce n'est pas un conseil juridique, et le dire.
2. **Édition en texte riche depuis les réglages du bien**, par son gestionnaire — plus par le mode
   configuration, plus depuis la page publique.
3. Le versionnement par hash existant (`conditions_version` / `conditions_hash`) ne change pas :
   il continue de prouver ce qui a été accepté.

### Documentation à reprendre

`specifications.md` §22 et le sujet d'aide `modules/rental/help/locations-demande.md`.

---

## IT-03 — La page de suivi du locataire

### La boîte « Modifier votre demande »

**Repliée par défaut** (`<details>` ou collapse Bootstrap). Elle occupe aujourd'hui la moitié de
la page alors qu'elle sert une fois sur dix.

### Le type de demande disparaît du formulaire — **Décidé**

`rental_change_requests` stocke déjà `arrival`, `departure`, `units` et `persons` **sur la même
ligne** : seul `kind` interdit de les combiner. C'est donc un changement de sémantique, pas de
schéma.

- `ChangeRequestKind` cesse d'être choisi par le locataire. Il devient **dérivé** de ce qui diffère
  de la réservation : « Dates », « Participants », « Dates et participants ».
- `ChangeRequestKind::affectsAvailability()` devient « des dates sont présentes », plus
  « `$this === self::DATES` ».
- Le formulaire est **prérempli** avec les valeurs actuelles de la réservation, et **refuse l'envoi
  si rien n'a changé**.
- **Le message devient obligatoire.**

### L'annulation quitte le menu — **Décidé**

Elle devient un **bouton distinct**, avec confirmation. Annuler n'est pas une variante de « changer
mes dates », et les mettre dans le même menu déroulant est la façon la plus sûre d'annuler par
erreur.

### La validation devient réelle — **Décidé**

`Modules\Rental\Service\RentalOperationsService::requestChange()` ne vérifie aujourd'hui que le
format des dates, leur ordre, et un plafond de trois demandes en attente. Ni disponibilité, ni
règles du bien, ni capacité.

Elle appelle désormais **`RentalAvailabilityService::validateRange()`** — la même méthode que le
formulaire public, mêmes règles, même capacité, mêmes messages. Une demande qui ne passe pas n'est
pas enregistrée.

La revalidation à l'acceptation **reste** : entre la demande et la réponse du gestionnaire, les
dates ont pu partir. Les deux contrôles coexistent, ce n'est pas une redondance.

### Les coordonnées de facturation — **Décidé**

Le locataire les saisit lui-même, sur sa page de suivi, dans les mêmes champs chiffrés que le
gestionnaire remplit à la main aujourd'hui. Le bloc apparaît comme une tâche à faire tant qu'il est
vide. Le gestionnaire garde le droit de les corriger.

**Aucun email supplémentaire.** L'email de confirmation part déjà avec le lien de suivi et une
phrase d'appel à l'action par décision : c'est une case de `Booking\RenterDecision::callToAction()`
à écrire, pas un gabarit de plus. **Et aucune relance** : ni bouton, ni rappel automatique.

### Les mots anglais dans l'historique — **Décidé**

Trois appels enregistrent `$enum->value` au lieu de `->label()`, et c'est toute l'explication du
« renter » et du « refused » que tu vois à l'écran :

- `RentalOperationsService.php` — `CHANGE_REQUESTED` (origine et type) et `CHANGE_DECIDED` (type et
  statut) ;
- `RentalBookingService.php` — `CHANGE_DECIDED` au refus automatique.

Les changements de statut, eux, utilisent déjà `->label()`. Corrige les trois.

**Les lignes déjà enregistrées ne sont pas réécrites.** Un journal d'audit dont on récrit le passé
ne prouve plus rien, et `partials/audit_timeline.html.twig` pose explicitement qu'il n'a jamais à
formater une valeur : ce que `Core\Audit` a stocké est ce qu'un lecteur voit. Les anciennes lignes
resteront en anglais, et c'est le moindre mal.

---

## IT-04 — La vue d'ensemble du gestionnaire

### À faire

`RentalManagementController::overview()` construit `needs_attention` en filtrant sur
`$b->status->needsAttention()` — c'est-à-dire sur le **statut seul**. Une réservation confirmée
portant une demande de modification en attente n'apparaît donc nulle part dans « À traiter », alors
que c'est exactement une chose à traiter.

« À traiter » intègre désormais :

- les réservations dont le statut le demande, comme aujourd'hui ;
- celles portant une **demande du locataire en attente** ;
- celles portant une **proposition de l'unité** que le locataire n'a pas encore tranchée — elle
  attend quelqu'un elle aussi, et l'unité doit savoir qu'elle attend.

Chaque ligne dit pourquoi elle est là. Le compteur « Demandes en attente » des trois chiffres suit
la même définition, sans quoi la tuile et la liste se contrediront.

### Le piège

`findAllForAssets()` fait déjà une seule requête, puis tout est filtré en mémoire. Ne rajoute pas
une requête par réservation pour connaître ses demandes en attente : charge-les en une fois.

---

## IT-05 — La page d'une réservation

La plus grosse. Elle ne change aucune règle métier : elle remet la page dans l'ordre où on la lit.

### L'ordre de la page — **Décidé**

1. **Les détails de la location** : locataire, organisation, objet, dates, participants, total et
   reçu, statut.
2. **L'action suivante** : un seul encart, le **premier jalon applicable non fait**, avec son
   bouton. Rien d'autre.
3. **« Où en est cette location »** : le parcours, ci-dessous.
4. **« Le dossier »** : la liste des boîtes.

### Le parcours en cinq phases — **Décidé**

Les treize jalons existent déjà, dérivés et dans l'ordre (`Booking\BookingMilestones`). Rien à
inventer, seulement à mettre en scène — treize cases à cocher ne font pas un parcours lisible, le
changement d'année en a quatre. Ils se regroupent en cinq phases, chacune renvoyant à la boîte
correspondante plus bas :

1. **La demande** — reçue, dates bloquées, décision à prendre
2. **L'accord** — contrat envoyé, accepté, acompte reçu, réservation confirmée
3. **Avant le séjour** — solde, caution, informations pratiques
4. **Le séjour** — états des lieux, relevés de compteurs
5. **Après le séjour** — décompte final, caution restituée, clôture

La phase en cours est dépliée sur ses jalons ; les autres sont réduites à une ligne. Un jalon sans
objet sur cette installation reste grisé plutôt que de disparaître — une case invisible se lit
comme du travail oublié, une case grisée se lit comme « sans objet ».

**Les jalons restent dérivés, jamais stockés.** Aucune colonne, aucune tâche planifiée pour les
tenir à jour.

### La carte « État » disparaît — **Décidé**

Ses boutons de transition descendent dans la phase à laquelle ils appartiennent — les décisions sur
la demande en phase 1. L'information de statut est déjà en tête de page. Deux cartes qui répondent
à la même question est précisément ce qu'on corrige.

### « Le dossier » — **Décidé**

Aujourd'hui prix, paiements, documents, courrier, demandes, commentaires et historique sont tous
dépliés l'un sous l'autre : c'est ça, « trop de choses sur le même écran ». Ils deviennent une
liste, une ligne par boîte, avec son chiffre parlant (« 317,50 € dus », « 1 en attente »,
« 14 modifications »). Le parcours renvoie dessus.

### Ce qu'il ne faut pas casser

`public/assets/js/rental-booking.js` poste les formulaires en `fetch` et remplace le contenu des
enveloppes `data-booking-panel` à partir d'un rendu frais du **même** gabarit — c'est ce qui fait
qu'« Envoyer » coche « Contrat envoyé » sans rechargement, et ce qui garantit qu'il n'existe qu'un
seul moteur de rendu. Chaque région qu'une action peut changer garde son enveloppe, **toujours
présente**, y compris quand la région elle-même est vide.

---

## IT-06 — Les documents

Dépend d'IT-01.

### Le texte riche — **Décidé**

`views/management/document_editor.html.twig` est un `textarea` HTML **exprès** : le commentaire du
fichier explique qu'un `contenteditable` peut couper `{{ prix_total }}` entre deux éléments au fil
de la frappe, et transformer silencieusement un mot-clé en prose.

On passe malgré tout au composant de texte riche générique, avec les garde-fous d'IT-01 :

- un menu **« Insérer un mot-clé »** (la liste existe : `views/management/_keywords.html.twig`),
  pour qu'on ne les tape plus à la main ;
- la **passe de réparation serveur**, après assainissement et avant substitution : tout balisage
  inline trouvé à l'intérieur d'une séquence `{{ … }}` est retiré ;
- l'avertissement « mots-clés non reconnus » existant reste le filet — si la réparation échoue, ça
  se voit à l'écran plutôt que dans un contrat signé.

Les mots-clés rendus en jetons non éditables sont **écartés** : ils obligeraient à forker le
composant générique.

### La boîte Documents — **Décidé**

- **Les boutons « Rédiger le contrat » et « Rédiger la facture » disparaissent.** Le texte se
  rédige dans les gabarits du bien : un lien dans le paragraphe d'explication, pas un bouton.
- **Actions en icônes**, pas en mots, dans la table des documents. Chacune porte un `aria-label`,
  chacune fait au moins 44 px.
- **Un document est modifiable tant qu'il n'a pas été envoyé ; une fois envoyé, il est en lecture
  seule.** L'envoi est donc l'action qui verrouille, et sa confirmation doit le dire.
- La règle « régénérer n'écrase jamais » ne bouge pas : v2 apparaît à côté de v1, parce que v1 peut
  être signée.

### Le correctif « Ouvrir » en PWA — **Décidé**

`views/management/_documents.html.twig` pointe `file_url()` **sans** `target="_blank"
rel="noopener"`, là où `chefs/staffs.html.twig` et `members/show.html.twig` le font. En mode
autonome, la navigation sur un PDF laisse l'application installée sur un écran vide sans aucune
chrome pour revenir. **C'est le même lien pour le contrat et pour la facture : la facture a
exactement le même défaut.**

Ajoute `target="_blank" rel="noopener"`, et vérifie le comportement pour les deux types. Note aussi
que `/files/{id}` est `network-only` dans `public/sw.js` (SECURITY §6) : hors ligne, il n'y a rien
à servir — c'est voulu, mais l'écran doit le dire au lieu de rester blanc.

---

## IT-07 — Les rappels

### Le constat

Treize rappels (`Reminder\ReminderKind`), tous les délais en **constantes PHP**
(`Reminder\ReminderPlanner`), et une clé unique
`(subject_type, subject_id, reminder_key)` sur `rental_reminders_sent` qui signifie **une fois,
jamais deux**. Un gestionnaire qui a lu « Acompte non reçu » en diagonale ne sera plus jamais
relancé.

### À faire — **Décidé**

1. **« Nouvelle demande » quitte la liste des rappels.** C'est une notification d'évènement,
   immédiate ; la laisser parmi les autres laisse croire qu'elle a un délai réglable.
2. **Les délais deviennent réglables**, avec des valeurs par défaut au niveau du module,
   surchargeables bien par bien. Douze champs à remplir sur chaque bien, personne ne le fera : le
   champ laissé vide affiche « (défaut : 14 jours) » sous lui.
3. **Un rappel peut être désactivé sur un bien.** Une remorque n'a ni état des lieux ni caution :
   ces rappels-là y sont du bruit garanti. Désactiver compte autant que décaler.
4. **Pas de case à cocher supplémentaire** : un délai vide veut dire « jamais », `0` veut dire
   « le jour même ».
5. **Répétition ciblée, sur quatre rappels seulement** : acompte, solde et caution non reçus
   (relance hebdomadaire jusqu'à réception ou jusqu'à l'arrivée), et contrat non établi (une
   seconde fois à J-3). Les huit autres restent à un coup.

La répétition impose de **desserrer la clé unique de `rental_reminders_sent`** sur `sent_on` :
c'est une modification de `schema.sql` du module, donc **bump de `version` dans `module.json`
dans le même changement**.

Une section « Rappels » dans les réglages du bien : une ligne par rappel — libellé, délai, actif.

### Ce qui ne change pas

Rien ne se déclenche **sur** une date : chaque règle est « est-ce vrai aujourd'hui », pour qu'un
hébergement dont la tâche planifiée a pris du retard envoie aujourd'hui plutôt que jamais. Et la
page de configuration continue d'avertir quand aucun vrai cron n'est détecté.

### Documentation à reprendre

`specifications.md` §22.11 écrit « treize » en toutes lettres. Le nombre bouge : corrige-le.
`ARCHITECTURE.md` et `modules/rental/help/locations-reglages.md` également.

### La clôture du chantier

C'est la dernière itération. Sa PR porte **« Closes #357 »** — elle est la seule. Avant de la
fusionner, vérifie que rien de ce qui a été reporté pendant les sept itérations ne disparaît avec
l'issue : chaque report a sa propre issue ouverte, ou il n'y a pas de report.

---

## Écarté, explicitement

Pour que ça ne revienne pas par accident :

- **Demander les coordonnées de facturation au formulaire public.** Envisagé, écarté : un visiteur
  qui se renseigne sur un week-end n'a aucune raison de taper un numéro de TVA, et
  `specifications.md` §22.6 le posait déjà.
- **Un email dédié pour réclamer les coordonnées de facturation**, et toute relance à ce sujet.
- **Les mots-clés rendus en jetons non éditables** dans l'éditeur de texte riche (IT-06).
- **Réécrire les lignes d'historique déjà enregistrées en anglais** (IT-03).
- **Rendre l'organisation obligatoire** au formulaire public (IT-02).
