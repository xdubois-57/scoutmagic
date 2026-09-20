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

**Reporté.** Le sujet d'aide `gerer-les-locations` vit collé à son plafond de
500 mots alors qu'il couvre neuf écrans : `design.md` §7.11 dit qu'au-delà de
400 ce devrait être deux sujets. IT-04 comme IT-06 ont dû raccourcir de la
prose existante pour faire entrer leur propre section. Issue **#401**, avec
la recommandation de le découper au moment d'IT-05, qui réorganise justement
la page d'une réservation.
