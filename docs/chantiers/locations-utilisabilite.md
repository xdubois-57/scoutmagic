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

## IT-02 — Le formulaire public de demande

**Livré.**

- **Deux exemples**, là où le chantier les demande :
  `placeholder: 'Week-end de section'` sur l'objet de la location,
  `placeholder: 'Unité du Petit Ry SV025'` sur l'organisation — plus une
  aide sous l'organisation qui dit qu'elle est facultative, et une sous le
  téléphone qui dit à quoi il sert.
- **Téléphone et objet de la location obligatoires**, côté client *et* côté
  serveur. Côté serveur dans `RentalBookingService::createFromPublicRequest()`,
  à côté du nom et de l'adresse email, qui y étaient déjà : c'est la porte
  unique par laquelle passe une demande publique.
- **L'organisation reste facultative**, et un test le pose explicitement pour
  que ça ne revienne pas par accident.
- **Les conditions de location à trois niveaux** :
  `StandardTemplates::conditions()` livre un texte belge complet — dix
  sections, de « ce qu'une demande engage » au droit applicable ;
  `Document\AssetConditions` décide lequel des deux est en vigueur ; une
  section « Conditions de location » sur les réglages du bien l'édite en
  texte riche, avec un « Revenir aux conditions standard ».
- La page publique du bien **affiche** les conditions au lieu de les offrir
  en `editable()`, et le formulaire de demande les montre **toujours**.
- Route `POST /mes-locations/{slug}/reglages/conditions` →
  `RentalPricingController::saveConditions()`, `version` du module montée de
  1.20.0 à 1.21.0.
- Documentation : `specifications.md` §22.5, `modules/rental/help/locations-demande.md`,
  `modules/rental/help/locations-reglages.md`.
- Tests : `tests/Modules/Rental/Document/AssetConditionsTest.php` (7),
  trois tests de plus sur `StandardTemplatesTest`, huit sur
  `RentalRequestControllerTest`, quatre sur `RentalRbacTest` dont la
  frontière de rôle de la nouvelle route.

**Le défaut réel, et il était pire que « pas documenté ».** Le texte des
conditions est un contenu `editable()` par bien, et **rien n'était livré**.
Tant que personne ne l'avait écrit, le formulaire n'affichait aucune
condition — mais présentait quand même la case « J'accepte les conditions de
location », obligatoire. Le locataire acceptait le vide, et
`conditions_hash` en attestait fidèlement : un mécanisme de preuve
fonctionnant parfaitement au-dessus de rien. Et la seule porte pour écrire ce
texte était le mode configuration sur la page **publique** du bien, qui
demande un superadmin : la seule personne capable de corriger n'était pas
celle qui loue le local.

**Décisions prises seul.**

- **La clé de stockage ne change pas.** Les conditions restent l'entrée
  `rental_asset_{id}_conditions` du magasin de contenu éditable générique :
  toute unité qui avait déjà écrit les siennes les garde, et il n'y a rien à
  migrer. Ce qui change, c'est qui a le droit d'écrire et depuis où.
- **Pas de nouveau service.** `AssetConditions` est statique et reçoit le
  magasin en argument. Un service aurait voulu dire un paramètre de
  constructeur dans trois contrôleurs et deux racines de composition, pour
  une classe sans état.
- **`EditableContentService` devient une dépendance *obligatoire*** de
  `RentalManagementController`, `RentalPricingController` et
  `RentalPublicController`, et non pas une nullable comme les dépendances
  inter-modules du module. Un `null` afficherait le texte standard par-dessus
  les conditions d'une unité, ce qui se lit comme une modification qui n'a pas
  été enregistrée.
- **Un gestionnaire ne peut pas vider les conditions.** Un texte vide
  remettrait la case à cocher au-dessus de rien, c'est-à-dire exactement le
  défaut qu'on corrige. L'enregistrement est refusé et l'ancien texte reste.
  C'est bien la route des gestionnaires qui est gardée, et non la seule qui
  existe : `POST /api/editable-content` n'est indexée par aucune clé et
  atteint ce contenu comme n'importe quel autre, donc un superadmin en mode
  configuration peut y écrire du vide sans passer par ce garde-fou. Ce qui
  tient la garantie est la sortie, pas l'entrée — `AssetConditions::
  textFor()` lit un corps vide comme « personne n'en a écrit » et sert le
  standard, et l'empreinte d'acceptation est prise sur cette même valeur.
  La case ne peut donc pas se retrouver au-dessus de rien, quelle que soit
  la porte par laquelle la ligne a été écrite.
- **Un texte fait uniquement d'espaces retombe sur le standard.** C'est ce
  qu'un gestionnaire laisse derrière lui en vidant l'éditeur, et le traiter
  comme un texte rouvrirait le même trou par une autre porte.
- **« Réinitialiser » repasse par la même route**, en postant le texte
  standard — le précédent de la page Gabarits. Une route de moins, et la
  réinitialisation est traçable comme l'édition qu'elle est.
- **La section s'édite dans un dialogue**, comme les trois autres de la page
  (`design.md` §1.9) : la page des réglages n'a aucun bouton primaire à elle,
  et un éditeur ouvert en permanence en aurait introduit un.
- **Le texte standard ne porte aucun jeton `{{ … }}`**, et un test l'exige :
  il est lu par un visiteur qui n'a pas encore de réservation, donc rien ne
  pourrait y être substitué — des accolades y seraient pires que dans un
  contrat.
- **Le garde-fou juge ce qui sera enregistré, pas ce qui a été envoyé.**
  Il lisait le corps brut du POST et jetait la chaîne que
  `EditableContentService::set()` rend — celle qui a vraiment été stockée.
  Or l'assainisseur retire le `src` d'un `<img src="data:…">`, ce qu'est une
  capture d'écran collée, puis supprime l'élément devenu vide ; et il retire
  `<script>`/`<style>`/`<form>` balise **et** contenu, là où le
  `strip_tags()` d'`isBlank()` garde le texte intérieur. Les deux formes se
  lisaient donc comme du contenu avant assainissement et comme rien après :
  la page disait « enregistrées », la ligne valait `<p></p>`, et
  `textFor()` servait en silence le texte standard par-dessus — exactement
  le silence que cette itération existe pour finir. Assaini d'abord, jugé
  ensuite, et le résultat passé à `set()` : une seconde passe idempotente,
  et **une** seule voie d'écriture, ce qu'un enregistrement-puis-annulation
  n'aurait pas été.

**Divergences avec le document de chantier.**

- **L'issue #357 demandait aussi l'organisation obligatoire ; le chantier
  l'écarte, et c'est le chantier qui fait autorité.** Signalé ici parce que
  les deux documents sont dans le dépôt et se contredisent sur ce point.
- **Le chantier dit « `Document\StandardTemplates`, même précédent ».**
  `StandardTemplates` n'expose jusqu'ici que des corps de `DocumentType` via
  `forType()`. Les conditions n'en sont pas un et n'en deviennent pas un :
  elles y rejoignent le contrat et la facture comme troisième texte livré,
  avec leur propre méthode et un docbloc qui dit pourquoi elles sont
  l'exception.
- **`ARCHITECTURE.md` §8.55 décrivait une réalité que le code contredisait
  déjà** : « The editors are plain HTML textareas, not the rich-text modal ».
  C'est faux depuis que la page Gabarits est passée au champ de texte riche
  générique — seul l'éditeur de la copie d'une réservation est encore un
  `textarea`. Corrigé ici, puisque c'est ici qu'on l'a vu ; IT-06 finira le
  travail en s'occupant du second éditeur.

**Ce que la relecture a trouvé, et qu'aucun test n'aurait vu.**

- **Le garde « conditions non vides » était contournable, et par la voie la
  plus ordinaire.** `trim(strip_tags($body))` laisse passer
  `<p>&nbsp;</p>` : `strip_tags()` ne décode pas les entités, `trim()` ne
  retire ni `&nbsp;` ni le U+00A0 qu'il devient. Et c'est exactement ce
  qu'une surface `contenteditable` rend pour un paragraphe qu'on a vidé.
  Plus bas, `AssetConditions::textFor()` faisait un `trim()` sur le HTML
  brut, qui contient encore `<p>` : le repli sur le texte standard ne se
  déclenchait donc jamais. Résultat : une boîte « Conditions de location »
  visuellement vide au-dessus d'une case obligatoire — le défaut même que
  l'itération ferme, rouvert par une autre porte. Le dépôt avait déjà le
  bon motif pour ce piège — la question « est-ce que ça n'affiche rien ? »
  que le module de courrier entrant pose déjà sur un corps de message — et
  `AssetConditions::isBlank()` le reprend, les deux appelants passant par
  lui. Le docbloc le nomme sans son espace de noms, délibérément : hors d'un
  module, seul son `Api\` est nommable, **commentaires compris**, et
  `RentalInboundMailWiringTest` lit ce fichier comme du texte. C'est lui qui
  l'a rappelé, sur la suite complète, après que les suites ciblées soient
  passées au vert. Sept formes de « rien » sont pinnées par un fournisseur de données.
  Une différence assumée avec le précédent : là-bas les images ont déjà
  disparu quand la question se pose, ici non — `strip_tags()` effacerait des
  conditions faites d'une page scannée et servirait le texte standard
  par-dessus, sans un mot. Une image est du contenu.
- **Le jeu de données de référence perdait deux réservations en silence.**
  `RentalBlueprint::BOOKINGS` déclarait `'phone' => null` sur deux entrées,
  et `RentalSeeder` attrape une `RentalException` par entrée pour continuer
  — parce qu'un refus est quelque chose qu'il modélise. Le téléphone devenu
  obligatoire, la construction produisait donc cinq réservations sur sept,
  **dont la seule refusée**, c'est-à-dire le seul état final qui ne soit pas
  un succès. Rien ne le disait : `ReferenceDatasetBuildTest` n'affirmait que
  `rental_bookings > 0`. Les deux entrées reçoivent un numéro de la série
  déjà utilisée, et le test affirme désormais le compte exact et la présence
  de la refusée — c'est l'exception assumée à la règle « pas un compte » de
  ce fichier, et le commentaire dit pourquoi.
- **Trois textes promettaient plus que le code ne tient.** « Le site
  conserve le texte tel qu'il a été montré » : non.
  `createFromPublicRequest()` enregistre `conditions_version` et une
  empreinte SHA-256 de `conditions_text`, jamais une copie du texte. La
  garantie réelle est qu'une réécriture produit une autre empreinte, donc
  que ce qui a été accepté ne peut pas être remplacé en silence. Les trois
  formulations sont reprises, y compris celle de `locations-demande.md`, qui
  portait déjà l'imprécision avant cette itération.

**Reporté.** Rien.

---

## IT-03 — La page de suivi du locataire

**Livré.**

- **La boîte « Modifier votre demande » est repliée**, et se déplie toute
  seule quand une proposition de l'unité attend une réponse. Elle prenait
  la moitié de la page pour quelque chose qui sert une fois sur dix, au
  dessus des dates et du prix qu'on vient lire.
- **Le type de demande a disparu du formulaire.** `ChangeRequestKind` est
  dérivé de ce qui diffère (`forChange()`), le formulaire est prérempli avec
  les valeurs de la réservation, et un envoi qui ne change rien est refusé.
  Le message devient obligatoire.
- **`ChangeRequestKind::DATES_AND_PERSONS`** — le cas que l'ancien
  formulaire ne savait pas exprimer. La table portait déjà `arrival`,
  `departure`, `units` et `persons` sur **une** ligne ; seul `kind`
  interdisait de les combiner, ce qui faisait de « d'autres dates ET moins
  de monde » deux demandes à répondre séparément, chacune valable seulement
  si l'autre était acceptée aussi.
- **`affectsAvailability()` lit « des dates sont présentes »**, plus
  « c'est le type dates ». Une demande qui déplace les dates *et* le groupe
  déplace les dates ; la lire comme un changement de participants l'aurait
  fait passer à côté du seul contrôle qui protège le calendrier.
- **L'annulation est un bouton distinct**, avec confirmation. Le mot qui
  l'accompagne vient de la boîte de dialogue (`data-confirm-note`) et reste
  facultatif.
- **La validation devient réelle.** `requestChange()` appelle
  `RentalAvailabilityService::validateRange()` — mêmes règles, mêmes
  messages que le formulaire public — pour la demande du **locataire**. La
  capacité passe par `validatePersons()`, seule quand seul le nombre
  change, et elle est demandée aux deux origines parce qu'elle est
  physique. La proposition d'un gestionnaire n'est pas validée ici :
  `acceptChange()` garde l'écriture avec `firmOnly: true`, exprès, pour
  qu'un blocage concurrent n'empêche pas l'arbitrage.
- **Les coordonnées de facturation se saisissent par le locataire**, sur sa
  page de suivi, dans les mêmes colonnes chiffrées que le gestionnaire
  remplit à la main. Le bloc se présente comme une tâche tant qu'il est
  vide. Nouvelle route `POST /locations/suivi/{id}/{token}/facturation`,
  `version` du module montée de 1.21.0 à 1.22.0.
- **Les trois `->value` de l'historique passent à `->label()`.**
- Documentation : `ARCHITECTURE.md` §8.53, `specifications.md` §22.5,
  `modules/rental/help/locations-suivi.md`.
- Tests : onze de plus sur `RentalRequestControllerTest`, et le test de
  disponibilité de `RentalOperationsServiceTest` coupé en deux.

**Le test coupé en deux, et pourquoi c'est le vrai sujet de l'itération.**
`testADateChangeIsRefusedWhenTheNewDatesAreTaken` posait que le refus
arrivait à l'acceptation. Il arrive maintenant à la demande — et il devait :
la personne qui apprend que les dates sont prises doit être celle qui les
demande, au moment où elle les demande. Le contrôle à l'acceptation **reste**
et a son propre test : entre une demande et une réponse, les dates peuvent
partir, et seul le contrôle pris dans le verrou le voit. Deux contrôles, deux
questions différentes.

**Décisions prises seul.**

- **Le message est obligatoire sur le changement, facultatif sur
  l'annulation.** Un gestionnaire qui lit « du 12/07 au 14/07 » sans un mot
  ne distingue pas une demande ferme d'une question, et répond à la
  mauvaise. Mais retenir quelqu'un qui a décidé d'annuler derrière un champ
  de texte, c'est le faire téléphoner à la place.
- **Une demande refusée à la saisie n'est pas enregistrée du tout.** Le
  chantier dit « n'est pas enregistrée » ; un test le pose, parce que
  l'alternative — l'enregistrer en « refusée » — remplirait la file du
  gestionnaire de choses qu'il n'a pas à lire.
- **La référence de la réservation est exclue de son propre contrôle.**
  Sans quoi décaler d'une nuit entre en collision avec les nuits que la
  réservation tient déjà.
- **Les deux dates voyagent ensemble dès que l'une bouge.** Une demande qui
  ne porterait que la nouvelle arrivée serait acceptée contre l'ancien
  départ.
- **Le bloc de facturation ne s'affiche pas sur une réservation refusée ou
  annulée**, mais reste sur une clôturée : une facture peut se corriger
  après le séjour, et rien ne se facture sur un dossier qui n'a pas eu lieu.
- **Le test « le locataire lit ses dates en français » compte au lieu de
  disparaître.** Le formulaire prérempli met forcément la forme ISO dans le
  `value` d'un `<input type="date">`, que le navigateur affiche dans la
  langue du lecteur. L'assertion vérifie donc que chaque date stockée
  n'apparaît **que** là — ce qu'elle voulait dire depuis le début.

**Divergences avec le document de chantier.** Aucune ; l'ambiguïté du
document portait sur IT-07, pas ici.

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
