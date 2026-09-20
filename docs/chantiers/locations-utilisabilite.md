# Chantier — Utilisabilité du module Locations

Journal d'implémentation du document de chantier
[`CHANTIER-locations-utilisabilite.md`](CHANTIER-locations-utilisabilite.md)
(itérations IT-01 à IT-07, issue #357). Une section par itération : ce qui a
été livré, les décisions prises en autonomie, les divergences constatées
entre le document de chantier et le dépôt réel, et ce qui a été reporté.
Même format que `docs/chantiers/courrier-sortant.md`.

Il n'y a pas de maquette pour ce chantier : le document pose que la
description des écrans dans IT-05 et IT-06 fait autorité sur la hiérarchie
des pages et les libellés français.

---

## Écarté, explicitement — recopié du document de chantier

Recopié ici pour que ça ne revienne pas par accident.

- **Demander les coordonnées de facturation au formulaire public.** Un
  visiteur qui se renseigne sur un week-end n'a aucune raison de taper un
  numéro de TVA, et `specifications.md` §22.6 le posait déjà.
- **Un email dédié pour réclamer les coordonnées de facturation**, et toute
  relance à ce sujet.
- **Les mots-clés rendus en jetons non éditables** dans l'éditeur de texte
  riche (IT-06) — voir la divergence relevée en IT-01 : le composant
  générique les rend déjà ainsi, et c'est l'inverse qui coûterait un fork.
- **Réécrire les lignes d'historique déjà enregistrées en anglais** (IT-03).
- **Rendre l'organisation obligatoire** au formulaire public (IT-02).

---

## IT-01 — Le moteur de jetons partagé

**Livré.**

- `Core\Template\TokenSyntax` — ce qui a le droit de se trouver entre les
  accolades, en paramètre plutôt qu'en constante. `freeText()` pour un
  en-tête de colonne (accents, espaces, modificateur `u`),
  `identifiers()` pour un mot-clé déclaré (`[a-z0-9_]+`, sans `u`, comme
  le module Locations l'avait toujours écrit).
- `Core\Template\TokenEngine` — les quatre règles que les deux moteurs
  portaient chacun en double : le motif, la récupération du jeton
  percent-encodé, la détection des jetons inconnus, l'échappement à la
  substitution. Plus la pièce neuve : `repairTokensSplitByMarkup()`.
- `Core\Template\TokenCatalogue` — le catalogue fermé, et la palette
  `{keyword, placeholder, description}` que
  `partials/rich_text_form_field.html.twig` lit déjà sous le nom
  `placeholders`.
- `Modules\MassMail\Service\MergeRenderer` et
  `Modules\Rental\Document\DocumentKeywords` délèguent. Il ne leur reste
  que leur catalogue — et, pour le publipostage, les sections
  `{{#Colonne}} … {{/Colonne}}`.
- `tests/Core/Template/` : 22 tests sur le moteur et le catalogue.
- Documentation : `ARCHITECTURE.md` §8.114.

**La contrainte non négociable, tenue.** `mass_mail` est en production ;
l'extraction devait être à comportement strictement identique et ses tests
actuels passer verts sans être retouchés. `tests/Modules/MassMail/Service/
MergeRendererTest.php` n'a pas été ouvert : ses 20 tests passent sur le code
délégué. Idem pour les 20 tests de `DocumentKeywordsTest`.

Deux endroits demandaient de l'attention pour que « identique » le soit
vraiment :

- `MergeRenderer::findUnknownTokens()` dédoublonnait **après** avoir retiré
  le `#`/`/` d'un marqueur de section, pas avant. `TokenEngine::
  unknownTokens()` prend donc un normaliseur facultatif qui décide à la fois
  ce qu'on cherche et ce qu'on rapporte, et le dédoublonnage se fait sur le
  résultat.
- `findMissingValues()` regarde les noms **bruts**, sans trim ni
  normalisation, parce qu'une colonne dont l'en-tête est vide reste une
  colonne. D'où `rawTokenNames()` à côté du reste.

`MergeRenderer` garde son constructeur sans argument : quatre racines de
composition et un gestionnaire de tâche l'instancient par `new
MergeRenderer()`, et lui faire prendre le moteur en dépendance aurait été
une modification de câblage pour rien.

**Décisions prises seul.**

- **La passe de réparation ne rewrite que ce qui devient un vrai jeton.** Le
  balisage inline est retiré à l'essai, et le résultat est confronté au
  motif de nom de la syntaxe ; tout le reste est remis tel quel. C'est ce
  qui permet à un contrat dont la prose contient des accolades de traverser
  une passe de réparation sans y laisser un mot, et ce qui garde
  l'avertissement « mots-clés non reconnus » utile : quand la réparation
  renonce, ça se voit à l'écran.
- **Aucune frontière de bloc n'est soudée.** Un `{{` dans un paragraphe et
  un `}}` dans le suivant, ce ne sont pas deux moitiés d'un jeton, ce sont
  deux accolades perdues dans de la prose ; les souder réécrirait le
  document de quelqu'un.
- **L'espacement de l'auteur est conservé.** `{{ prix_total }}` reste
  espacé : le motif le tolère de toute façon, et le normaliser serait une
  seconde édition invisible.
- **La passe de réparation n'est branchée nulle part dans cette
  itération.** Le chantier la décrit comme « la pièce neuve, celle dont
  IT-06 a besoin » ; elle est livrée et testée ici, elle sera appelée là-bas,
  après assainissement et avant substitution.

**Divergences avec le document de chantier.**

- **« Un champ de texte riche avec palette de variables, alimenté par un
  catalogue déclaré, réutilisable par les deux modules » existe déjà.**
  `core/View/templates/partials/rich_text_form_field.html.twig` et
  `public/assets/js/rich-text-form-field.js` le font depuis leur création,
  et trois pages s'en servent (`modules/rental/views/management/
  templates.html.twig`, `modules/camps/views/camp_form.html.twig`,
  `modules/mass_mail/views/compose.html.twig`). Il n'y avait donc rien à
  écrire côté champ ; ce qui manquait, c'est le **type** du catalogue qui
  l'alimente, d'où `TokenCatalogue`.
- **Et il rend déjà les mots-clés en jetons non éditables.** IT-06 écarte
  cette idée au motif qu'elle « obligerait à forker le composant
  générique » : c'est exactement l'inverse dans ce dépôt. Chaque occurrence
  d'un mot-clé du catalogue y est rendue comme une puce
  `contenteditable="false"` que le navigateur traite comme un caractère
  indivisible, et c'est la raison pour laquelle le composant existe. Forker
  serait le coût de les **retirer**. La décision se prend donc toute seule :
  on prend le composant tel quel en IT-06, les puces viennent avec, et la
  passe de réparation serveur reste le filet pour ce que les puces ne
  couvrent pas — un collage, un auteur sans JavaScript, et les jetons du
  publipostage, qui ne peuvent pas être des puces parce qu'un en-tête de
  colonne n'est pas un identifiant.
- **Le publipostage ne reçoit pas de catalogue.** Ses variables sont les
  en-têtes d'un fichier téléversé : de la donnée, différente à chaque envoi,
  pas une liste déclarée. Il garde son propre contrôle d'insertion
  (`modules/mass_mail/views/partials/_variable_toolbar.html.twig`), qui est
  déjà branché sur le même emplacement d'extension du composant générique.
  Y toucher aurait été un changement de comportement, ce que l'itération
  interdit.

**Reporté.** Rien.

---

## IT-04 — La vue d'ensemble du gestionnaire

**Livré.**

- `Booking\BookingAttention` — la définition unique de « À traiter » pour
  tout le module, pure et sans base : elle reçoit les demandes en attente
  déjà chargées, groupées par réservation.
- `Booking\AttentionReason` — les trois raisons, avec leur libellé.
- `RentalChangeRequestRepository::findPendingForBookings()` — tout ce qui
  est en attente sur plusieurs réservations, en **une** requête, groupé par
  réservation.
- `management/_attention_row.html.twig` — la ligne, partagée par la vue
  d'ensemble et par « Mes locations ».
- Documentation : `ARCHITECTURE.md` §8.54ter, `specifications.md` §22.5,
  `modules/rental/help/gerer-les-locations.md`.
- Tests : `BookingAttentionTest` (11), `RentalChangeRequestRepositoryTest`
  (7), quatre de plus sur `RentalManagementControllerTest`.

**Le constat, et il était plus large que la liste.** `overview()` filtrait
sur `$b->status->needsAttention()`, c'est-à-dire sur le statut seul. Une
réservation confirmée portant une demande de modification en attente
n'apparaissait donc nulle part — alors que c'est exactement une chose à
traiter.

**Décisions prises seul.**

- **Les quatre lecteurs, pas les deux que le chantier nomme.** Le chantier
  demande que la liste et le compteur suivent la même définition. La même
  condition était écrite à **quatre** endroits : la liste de la vue
  d'ensemble, le chiffre au-dessus d'elle, le filtre « À traiter » de la
  liste des réservations, et la pastille par bien sur « Mes locations ».
  N'en élargir que deux aurait garanti la contradiction que l'itération
  corrige, un écran plus loin. Les quatre passent par
  `BookingAttention`.
- **La tuile s'appelle « À traiter », plus « Demandes en attente ».** Deux
  noms pour un même ensemble, l'un au-dessus de l'autre, laissaient le
  lecteur deviner s'ils comptaient la même chose. Ils la comptent.
- **Une réservation finale n'y revient jamais**, quoi qu'il reste
  d'enregistré contre elle. `RentalBookingService` refuse toute demande
  encore en attente au moment où une réservation se clôt ; une ligne qui
  aurait survécu à ça ne doit pas ressusciter un dossier clos sur la liste
  de quelqu'un.
- **Le filtre de la liste des réservations ne charge les demandes que
  lorsqu'il en a besoin** — `statut=a_traiter` et rien d'autre. Les autres
  filtres ne paient pas une requête pour une question qu'ils ne posent pas.
- **`RentalChangeRequestRepository` est nullable dans
  `RentalStatisticsService`.** Les tests de rétention construisent ce
  service pour les deux autres chiffres ; un `null` y ramène le compte au
  statut seul, c'est-à-dire à l'ancienne réponse, plus étroite mais pas
  fausse.

**Divergences avec le document de chantier.** Aucune.

**Reporté.** Rien.

---

## IT-05 — La page d'une réservation

**Livré.**

- `Booking\BookingPhase` — les cinq phases, et à quelle phase appartient
  chaque jalon. Un jalon dont personne n'a décidé la phase répond `null`
  plutôt qu'une supposition : `BookingJourneyTest` échoue dessus, la page le
  range quand même en fin de parcours. Une ligne qui disparaît se lirait
  comme du travail que personne n'a fait.
- `Booking\BookingBox` — les huit boîtes du dossier, leur libellé et
  l'ancre que vise le parcours. Et `forMilestone()`, qui dit où se règle un
  jalon : c'est la même table qui sert à « L'action suivante » et au lien
  d'une phase, donc elles ne peuvent pas désigner deux endroits différents.
- `Booking\BookingJourney` et `Booking\JourneyPhase` — la mise en scène,
  pure, dérivée d'une dérivation : elle prend ce que
  `BookingMilestones::for()` a déjà calculé et n'ajoute aucun fait.
- Les gabarits : `booking.html.twig` réécrit en quatre temps,
  `_dossier_header.html.twig` (l'en-tête d'une boîte repliée),
  `_transitions.html.twig`, et `_price`, `_changes`, `_comments` sortis du
  gabarit de page — trois cartes qui y étaient écrites en clair et qui
  devaient devenir des corps de boîte comme les trois autres.
- Documentation : `ARCHITECTURE.md` §8.53, `specifications.md` §22.5, et le
  nouveau sujet d'aide `modules/rental/help/locations-reservation.md`.
- Tests : `BookingJourneyTest` (20), cinq de plus sur
  `RentalManagementControllerTest`, le scénario Playwright du cycle de vie
  mis à jour.

**Le jalon que le chantier nomme et que le dépôt n'avait pas.** Le document
décrit la phase 1 comme « reçue, dates bloquées, **décision à prendre** ».
Les deux premières existaient ; la troisième, non. Or « Demande reçue » se
coche quand la demande *arrive*, pas quand quelqu'un l'a regardée : sur une
demande que personne n'avait traitée, le premier jalon applicable non fait
était « Contrat envoyé », et « L'action suivante » réclamait donc un contrat
sur un dossier dont personne n'avait encore dit oui.

D'où `decision`, cochée exactement quand `BookingTransition` n'offre plus
« Confirmée ». C'est une définition empruntée, pas une seconde liste de
statuts « en délibéré » qui dériverait de la première. Une proposition
envoyée n'est pas une décision prise : le locataire peut encore refuser, et
confirmer reste permis.

**Décisions prises seul.**

- **Les boutons de statut descendent dans leur phase, et « L'action
  suivante » remonte ceux qui la concernent.** Le chantier veut les deux ;
  pris à la lettre, le second rend le premier dangereux, parce qu'un bouton
  affiché deux fois sur une page laisse deviner lequel compte.
  `BookingJourney::liftedTransitions()` tranche en amont du gabarit : ce qui
  est remonté n'est plus rendu en bas. Un test le pose en comptant les
  occurrences dans le HTML, pas en lisant le code.
- **Annuler n'est pas une façon de terminer.** Sur une réservation
  confirmée, `allowedFrom()` offre « Clôturée » et « Annulée » ensemble. Seul
  « Clôturée » remonte : c'est le dernier jalon du parcours. « Annulée » reste
  dans « La demande », qui est la phase où l'on répond à la demande.
- **Le pli d'une boîte est en dehors de son enveloppe de rafraîchissement.**
  `data-booking-panel` est *dans* le `.collapse`, et le chiffre de l'en-tête
  porte sa propre enveloppe. Encaisser un paiement re-rend le corps et le
  chiffre en laissant la boîte ouverte à la ligne qu'on lisait ; re-rendre la
  carte entière l'aurait repliée sous les doigts du gestionnaire. Le scénario
  Playwright n'ouvre « Documents » qu'une fois, avant quatre actions : c'est
  ce qui surveille la régression.
- **Les phases, elles, se replient au rafraîchissement, et c'est voulu.**
  Une action qui fait avancer le dossier change la phase en cours ; le rendu
  frais ouvre la nouvelle. Ce n'est pas une place perdue, c'est la réponse.
- **Le parcours et « L'action suivante » visent la boîte où le travail se
  fait, pas la boîte de la phase.** Une phase dont le premier jalon en
  souffrance est l'acompte renvoie vers Paiements même si elle s'appelle
  « L'accord ». Une phase terminée renvoie quand même vers la boîte où sa
  dernière preuve est classée.
- **« Coordonnées de facturation » reste dans la boîte Documents.** Un
  numéro de TVA sonne « Paiements », mais rien ne se paie depuis là : la
  boîte qu'on ouvre pour faire une facture est celle qui doit contenir ce que
  la facture dit. Déplacé, c'eût été un choix de conception que le chantier
  ne tranche pas.
- **Le séjour est une ligne du dossier, pas une boîte.** C'est une page à
  part ; sa ligne porte une flèche au lieu d'un chevron, ce qui est la seule
  façon honnête de dire qu'un clic quitte la page.
- **Le sujet d'aide a été découpé, comme l'issue #401 le recommandait pour
  cette itération.** `gerer-les-locations` couvrait neuf écrans à son plafond
  de 500 mots ; `locations-reservation` prend le chemin
  `/mes-locations/*/reservations/*` et le lui retire — aucun chevauchement,
  donc pas de « lequel des deux s'ouvre ? » à trancher.

**Divergences avec le document de chantier.**

- **Treize jalons, dit le chantier ; il y en avait quatorze**, et il y en a
  quinze depuis cette itération (voir plus haut). Le regroupement est écrit
  sur la *clé* du jalon, jamais sur sa position dans la liste : §6.15 pose
  que chaque itération ultérieure remplit une ligne de plus, et un
  regroupement par index aurait silencieusement re-classé tous les jalons
  situés après celui qu'on insère.
- **La carte « Blocage des dates » ne disparaît pas, elle descend.** Le
  chantier ne parle que de la carte « État ». L'option bloque les dates *de
  cette demande* ; la laisser seule, en carte, au milieu de boîtes repliées
  aurait été la dernière carte dépliée de la page sans raison.

**Reporté.** Rien.

---

## IT-06 — Les documents

**Livré.**

- `views/management/document_editor.html.twig` passe au champ de texte riche
  générique, avec la palette de mots-clés. Il était un `textarea` **exprès**,
  et le commentaire qui disait pourquoi avait raison.
- `DocumentKeywords::repairSplitKeywords()` — la passe de réparation d'IT-01,
  branchée. Appelée **après assainissement et avant substitution**, à
  l'enregistrement du texte *et* à la génération.
- `RentalDocumentService::textIsLocked()` et `lockedRefusal()` — l'envoi
  verrouille ; `saveBookingText()` refuse côté serveur.
- `RentalDocumentRepository::hasSentDocumentOfType()`.
- `views/management/_documents.html.twig` : les boutons « Rédiger » deviennent
  des liens dans le paragraphe d'explication, les actions deviennent des
  icônes avec `aria-label`, et « Ouvrir » porte `target="_blank"
  rel="noopener"`.
- Documentation : `ARCHITECTURE.md` §8.55, `specifications.md` §22.6,
  `modules/rental/help/gerer-les-locations.md`.
- Tests : six de plus sur `RentalDocumentServiceTest`, un de plus sur
  `tests/js/offline-nav.test.js`.

**Le danger est répondu, plus évité.** Une surface `contenteditable` découpe
un texte entre plusieurs éléments au fil de la frappe, et
`{{ prix_total }}` devient `{{ pri<b>x</b>_total }}` dès qu'on met en gras un
mot qui la chevauche. Trois couches : chaque mot-clé du catalogue est rendu
comme une puce indivisible par le composant générique ; le **serveur** répare
ce qui passe quand même — un collage, un auteur sans JavaScript ; et
l'avertissement « mots-clés non reconnus » reste le filet, parce que la
réparation ne réécrit une zone que lorsque ce qu'elle deviendrait est un vrai
mot-clé.

**Décisions prises seul.**

- **Ce que « verrouillé » verrouille.** Le chantier dit « un document est
  modifiable tant qu'il n'a pas été envoyé ». Un PDF n'a jamais été
  modifiable ; ce qui l'est, c'est le **texte** dont il est fait. Le
  verrou porte donc sur le texte d'un type, dès qu'un document de ce type est
  parti — la seule lecture qui donne un sens à « l'envoi est l'action qui
  verrouille ». Envoyer le contrat ne dit rien de la facture.
- **Régénérer reste possible** : v2 apparaît à côté de v1, inchangé. Ce que
  le verrou arrête, c'est que la **source** bouge sous une version déjà lue
  et peut-être signée.
- **Le refus est côté serveur**, pas seulement une absence de formulaire :
  une page qui se contente de cacher un formulaire n'est pas une règle.
- **La réparation tourne aussi à la génération**, pas seulement à
  l'enregistrement : un texte stocké avant que la passe existe est toujours
  là.
- **Pas de classe `tap-target` sur les boutons-icônes.** Le bloc
  `pointer: coarse` d'`app.css` couvre déjà `.btn-sm` (44 px, et le centrage
  d'une icône seule) ; `tap-target` est documenté pour ce qui n'est **pas**
  un `.btn`. L'ajouter aurait été une seconde règle disant la même chose.
- **Chaque `aria-label` nomme son document.** « Supprimer » cinq fois dans
  une colonne ne dit pas à un lecteur d'écran sur quelle ligne il se trouve.

**Divergences avec le document de chantier.**

- **« Les mots-clés rendus en jetons non éditables sont écartés : ils
  obligeraient à forker le composant générique. »** C'est l'inverse dans ce
  dépôt, comme relevé dès IT-01 : le composant générique les rend **déjà**
  ainsi, et les retirer est ce qui coûterait un fork. On prend le composant
  tel quel.
- **« L'écran doit le dire au lieu de rester blanc » (hors ligne) existait
  déjà.** `offline-nav.js` intercepte en phase de capture tout clic sur un
  lien interne non inscrit à la liste blanche et ouvre le dialogue ;
  `/files/{id}` n'y est pas, étant `network-only`. Le `target="_blank"` ne
  change rien à cette interception — le gestionnaire ne regarde pas
  `target` — et un test Vitest le pose désormais, parce qu'un onglet neuf
  ouvert sur rien serait exactement l'écran blanc que l'attribut devait
  éviter.

**Le sujet d'aide a fini par être découpé, et c'est la fusion qui l'a
imposé.** `gerer-les-locations` couvrait neuf écrans et vivait collé à son
plafond de 500 mots ; `design.md` §7.11 dit qu'au-delà de 400 ce devrait
être deux sujets. J'avais d'abord raccourci de la prose existante pour faire
entrer la section « documents » — comme IT-04 l'avait fait pour la sienne —
et ouvert l'issue **#401** en recommandant de découper à IT-05. En fusionnant
`main` (qui portait IT-04), les deux sections se sont additionnées : 559 mots,
rouge. Un quatrième rabotage aurait commencé à retirer de l'information
réelle.

`modules/rental/help/locations-documents.md` sort donc du lot : rédaction du
texte, génération, envoi, et ce que l'envoi verrouille. Son chemin est celui
de l'éditeur de document, **retiré** de `gerer-les-locations` — aucun
chevauchement, donc pas besoin de trancher lequel des deux sujets s'ouvre
pour une page que les deux déclareraient. C'est l'option 2 de #401, appliquée
à l'itération dont c'est le sujet.

**Quatre choses trouvées en revue, et corrigées ici.**

- **Le verrou avait un trou : supprimer le document envoyé le rouvrait.**
  `textIsLocked()` demande si un document de ce type porte un `sent_at` ;
  supprimer le seul contrat parti rendait donc son texte source modifiable
  à nouveau, alors que le locataire tient toujours le PDF qui en est issu.
  Un document qui est parti ne se supprime plus — même raison que
  `claimNextVersion()`, qui ne réattribue jamais un numéro de version parce
  que « la v2 est peut-être déjà partie par email ». Le refus est côté
  serveur ; la page se contente de ne plus proposer ce qui serait refusé.
- **Le français ne s'assemble pas.** « Facture » est féminin, et la phrase
  de refus construite autour de `$type->label()` donnait « Le facture qu'il
  a reçu doit rester celui qu'il a reçu » — sur la bannière de l'éditeur
  comme dans le message qui refuse l'enregistrement, qu'une facture atteint
  exactement comme un contrat. Deux phrases écrites en toutes lettres.
- **Les puces de mots-clés s'affichaient sans style.** `.doc-keyword` n'est
  défini que dans `components.css`, que `base.html.twig` ne charge
  délibérément pas ; `templates.html.twig` l'ajoute pour cette raison et
  l'éditeur de document ne le faisait pas. L'aide de cette même page
  promettait « en bleu ».
- **Un test qui ne pouvait pas échouer.** L'assertion « le PDF généré ne
  contient pas `{{` » lisait les **octets** du fichier : dompdf compresse
  ses flux, si bien que la chaîne en est absente que le mot-clé ait été
  substitué ou non — et tout autant s'il avait été supprimé. Elle lit
  désormais la couche de texte (`Core\File\PdfTextExtractor`) et exige
  « Jeanne Martin ».

**Reporté.** Deux choses.

- **#401** reste ouverte : `gerer-les-locations` est à 487 mots, toujours
  au-dessus des ~400 de la charte, et couvre encore huit écrans. Le
  découpage restant — un sujet par écran — appartient à IT-05, qui
  réorganise la page d'une réservation.
- **La course entre enregistrer le texte et envoyer le document** (#405).
  `saveBookingText()` vérifie le verrou, puis écrit ; `sendDocument()`
  envoie le PDF existant et n'appelle `markSent()` qu'après. Entre les
  deux, un enregistrement passe le contrôle et modifie la source pendant
  que l'envoi est en cours. La fenêtre est de quelques millisecondes et
  demande deux gestionnaires à la fois ; la fermer demande de rendre la
  transition durable — une colonne sur `rental_booking_document_texts` —
  donc une modification de `schema.sql`, que le chantier réserve à IT-07,
  et qui ferait du verrou un drapeau stocké là où tout le module dérive.

## IT-07 — Les rappels

**Livré.**

- `Reminder\ReminderKind` : `NEW_REQUEST` retiré, `defaultDays()`,
  `settingKey()` et `repeatAfterDays()` ajoutés. Les six constantes de
  `ReminderPlanner` disparaissent — un délai vit sur le rappel, pas à côté.
- `Reminder\ReminderSchedule` — les trois niveaux (valeur livrée, défaut de
  l'unité, valeur du bien) résolus en un seul endroit, et pur comme le
  planificateur : on lui passe les réglages et les surcharges déjà lus.
- `schema.sql` : `rental_asset_reminders (asset_id, reminder_key,
  delay_days NULL, is_active)`, une ligne par différence.
- `Repository\RentalAssetReminderRepository` — `findForAsset()`,
  `findAll()` pour la passe quotidienne, `save()` et `clear()`.
- `RentalReminderRepository::claim()` prend une cadence.
- `module.json` : douze réglages `reminder_*_days`, la notification orpheline
  `rental.new_request` retirée, la route de la section, version 1.21.0.
- La section « Rappels » des réglages d'un bien
  (`views/management/_reminders.html.twig`,
  `RentalManagementController::reminderRows()`,
  `RentalPricingController::saveReminders()`).
- Documentation : `ARCHITECTURE.md` §8.60, `specifications.md` §22.11,
  `modules/rental/help/locations-reglages.md`.
- Tests : `ReminderScheduleTest` (11), `RentalAssetReminderRepositoryTest`
  (12), `RentalReminderSettingsTest` (11), cinq de plus sur
  `ReminderPlannerTest`, quatre sur `RentalReminderServiceTest`, quatre sur
  `RentalManagementControllerTest`.

**La clé unique ne pouvait pas être desserrée**, et c'est la divergence la
plus lourde du chantier. `SchemaComparator` compare les index **par leur
nom**, jamais par leurs colonnes, et rien dans ce dépôt n'en supprime un —
`drops.sql` est pour les colonnes et les clés étrangères, et le dit.
Redéfinir `idx_rental_reminder_once` sur `sent_on` aurait donc laissé
l'ancien index exactement en place sur chaque site installé, refusant la
seconde insertion, pendant qu'une installation neuve fonctionnait. La panne
aurait été invisible précisément là où elle comptait.

Un rappel qui se répète **reporte sa ligne** au lieu d'en ajouter une :
`claim()` tente d'abord un `UPDATE` gardé par `sent_on <= aujourd'hui −
cadence`, et retombe sur l'`INSERT`. L'index continue de faire le seul
travail pour lequel il a été écrit — deux passes qui se chevauchent ne
peuvent pas envoyer toutes les deux — et la cadence vit dans une clause
`WHERE` plutôt que dans l'absence d'une contrainte. La table ne grossit pas
non plus d'une ligne par envoi, ce que `RentalRetentionService` aurait ensuite
dû purger.

**Le point 4 du chantier a été abandonné, sur arbitrage.** Il demandait
« pas de case à cocher supplémentaire : un délai vide veut dire *jamais* » —
et le point 2, deux lignes plus haut, demande qu'un champ vide affiche
« (défaut : 14 jours) ». Les deux ne peuvent pas être vrais du même champ.
Arbitré : **vide = hérite du défaut de l'unité**, et la case « Actif »
éteint. C'est la lecture que le reste du chantier impose — douze champs sur
chaque bien sont douze champs que personne ne remplit, donc un champ laissé
vide doit continuer de fonctionner — et elle évite que `0`, « le jour même »,
soit à une faute de frappe de « jamais ». Le reste du point 4 tient : `0` veut
bien dire le jour même.

**Décisions prises seul.**

- **Le champ affiche la valeur du bien, jamais la valeur héritée.**
  Pré-remplir le nombre de l'unité fige ce défaut le jour où quelqu'un
  enregistre sans rien changer, et le bien cesse alors de suivre l'unité. Le
  nombre est écrit **sous** le champ.
- **Une ligne qui ne dit rien supprime sa ligne en base.** Une table de
  lignes signifiant « aucun changement » est une table dont la taille
  n'apprend plus rien à personne.
- **Une case décochée ne poste rien du tout** : c'est l'absence qui est le
  signal. Lire un champ manquant comme « laisse comme c'était » aurait rendu
  l'extinction impossible.
- **La seconde chance du contrat est dérivée du délai en vigueur**
  (`délai − 3 jours`, jamais moins de 1) plutôt que fixée à J-3 : une unité
  qui raccourcit son délai à cinq jours recevrait sinon les deux envois l'un
  sur l'autre.
- **La relance des trois rappels d'argent s'arrête à l'arrivée.** Le chantier
  dit « jusqu'à réception ou jusqu'à l'arrivée » ; la même phrase répétée une
  fois les locataires installés est un canal qui apprend à l'unité à
  l'ignorer.
- **Les deux dépendances des contrôleurs sont facultatives.** Nulles, l'écran
  rend chaque rappel au défaut de l'unité — ce que fait une installation qui
  n'a jamais ouvert la section — et `saveReminders()` refuse en français
  plutôt que d'écrire nulle part.

**Divergences avec le document de chantier.**

- **« Treize rappels »** : il y en a douze une fois « Nouvelle demande »
  partie, onze internes et un au locataire. `specifications.md` §22.11 et
  `ARCHITECTURE.md` sont corrigés, comme le chantier le demandait.
- **Le bump de `version` n'est plus imposé par `schema.sql`.** AGENTS.md
  § Schema a retiré cette règle : le schéma d'un module est appliqué sans
  elle. `module.json` passe tout de même en 1.21.0, parce que le module
  gagne douze réglages, une route et un écran — ce qui est, lui, un
  changement visible.
- **`rental.new_request` était une notification déclarée sans personne pour
  l'émettre.** Retirer `NEW_REQUEST` de l'énumération la laissait orpheline
  dans `module.json` ; elle est retirée aussi.

**Reporté.**

- **#405** reste ouverte : la course entre `saveBookingText()` et
  `sendDocument()`. L'issue recommandait de la fermer **ici**, IT-07 étant
  l'itération qui touche `schema.sql` — et en la relisant avec le code sous
  les yeux, cette recommandation est fausse. La colonne qu'elle propose
  serait écrite là où `markSent()` l'est déjà,
  `RentalManagementController::sendDocument()` ligne 1183, c'est-à-dire
  **après** l'appel qui poste l'email ligne 1175 : la fenêtre resterait
  exactement où elle est. Ce qui la ferme n'est pas une colonne mais le
  choix du moment où l'envoi prend le verrou — avant l'email, au risque
  d'un texte gelé sur un envoi qui a échoué, ou après, en gardant la
  fenêtre. C'est un arbitrage visible par l'utilisateur, pas une ligne de
  schéma, et il n'a pas sa place en passant dans l'itération des rappels.
  L'issue a été corrigée en ce sens.
- **#401** reste ouverte, et délibérément. Après le découpage d'IT-05 le
  sujet `gerer-les-locations` est à **399 mots** de corps — sous les ~400 de
  la charte, mais d'un seul mot, et il couvre encore six chemins. La
  première phrase qu'on y ajoutera le repassera au-dessus. Compté comme
  `HelpInvariantsTest` compte, c'est-à-dire le corps sans l'en-tête YAML ;
  un `wc -w` sur le fichier entier en annonce 460 et ne dit rien de la
  charte. Fermer l'issue sur cette marge-là, c'est la rouvrir à la
  prochaine itération qui touche un de ces six écrans.
