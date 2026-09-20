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
