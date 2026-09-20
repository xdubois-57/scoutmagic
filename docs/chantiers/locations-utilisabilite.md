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
