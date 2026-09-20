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
- **Les conditions ne peuvent pas être vidées.** Un texte vide remettrait la
  case à cocher au-dessus de rien, c'est-à-dire exactement le défaut qu'on
  corrige. L'enregistrement est refusé et l'ancien texte reste.
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
