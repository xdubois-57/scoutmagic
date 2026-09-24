# Chantier — Module Covoiturage

Journal d'exécution du chantier « Module Covoiturage » (issue #365,
itérations IT-01 à IT-06). Une section par itération : ce qui a été fait,
les décisions prises en autonomie, les divergences constatées entre le
document de chantier et le dépôt réel, et ce qui a été reporté. Même
format que `docs/chantiers/aide-contextuelle.md`.

Le document de chantier et sa maquette ont été fournis en pièces jointes ;
la maquette est enregistrée sous
`docs/chantiers/maquettes/maquette-covoiturage.jsx`. Chaque itération est
une pull request distincte, mergée sur `main` une fois la CI verte.

---

## Vérification préalable du document

Le document a été écrit sur le commit `89728cd` (24 septembre 2026), qui
est aussi celui sur lequel le chantier démarre : `main` n'a pas bougé
entre-temps. Chaque fait cité a néanmoins été relu dans le code. La
plupart tiennent tels quels (les quatre recherches-à-la-frappe, le
géocodage de `camps` et ses colonnes, les trois méthodes de
`CalendarEventLookupInterface`, `EventSummary` sans lieu, `MENU_GROUPS`
d'« Espace membres » réduit à `mes_membres` et `unite`, « Photos » dans
`unite` face à « Gérer les photos » dans `activites`, « Mes locations »
dans `mes_membres`, `HelpMenuCoverageTest`). Les écarts :

1. **La maquette est arrivée en `.tsx`**, le document la nomme
   `maquette-covoiturage.jsx`. Son contenu est du JSX sans la moindre
   annotation de type : elle est enregistrée en `.jsx`, comme toutes ses
   voisines.
2. **« Notre besoin ajoute ce qu'aucune n'a : le choix multiple »** n'est
   vrai qu'à moitié. La recherche des gestionnaires de `rental`
   (`public/assets/js/rental-managers.js`) pilote déjà un
   `select multiple` (`manager_member_ids[]`). Aucune des quatre ne fait
   en revanche du choix multiple *sur une recherche serveur avec des
   éléments retenus retirables* — c'est ce qu'apporte le composant.
3. **La citation de `stay_picker`** est une traduction : le commentaire du
   code est en anglais (« The same shape the finance module already uses
   for « quelle créance ? » and « quel mouvement ? » »). Même chose pour
   les citations de `MapTiles`, `RentalMenuHookService` et
   `CalendarPublicController::personalFeed()`. Le fond est exact.
4. **Le flux personnel enrichit déjà des évènements réels** (IT-03). Le
   document dit que `VirtualEventProviderInterface` ne sait que fabriquer,
   jamais enrichir — c'est exact pour cette interface, mais
   `PersonalFeedService::getEventsForToken()` ajoute déjà deux lignes aux
   descriptions (« Rétrospective : … », « Prendre les présences : … »). Il
   le fait dans l'autre sens que §7.6 : `calendar` importe les `Api\` de
   `retro` et de `presences`. Le point d'extension d'IT-03 est la
   direction §7.6 demandée ; convertir ces deux-là n'est pas dans le
   périmètre et sera noté comme migration dans `ARCHITECTURE.md`.
5. **Les types de notification d'un module ne se déclarent pas dans
   `NotificationRegistry`** (IT-05). Ce registre ne porte que les types du
   cœur ; un module déclare les siens dans la section `notifications` de
   son `module.json` (`ModuleManifest::validateNotification()`), que
   `ModuleManager` agrège. Les huit types iront là.
6. **La section « Données collectées » ne vit pas dans
   `RgpdContentService::getDefaultContent()`** (IT-04) : cette méthode lit
   `core/View/rgpd_default.html`, où les modules sont décrits en §2.4.
   C'est ce fichier qui sera modifié, avec le prompt de
   `buildSystemPrompt()`.
7. **`ARCHITECTURE.md` dit la carte des camps « repliée par défaut »**
   alors que `camps-map.js` la déplie sur grand écran et la replie sur
   téléphone depuis une modification ultérieure. Le texte sera corrigé
   quand IT-02 le déplacera.

---

## IT-01 — Le sélecteur de recherche générique, dans le cœur

**Livré.** `core/View/templates/partials/search_picker.html.twig` et
`public/assets/js/search-picker.js`, paramétrés par l'URL de recherche, le
mode (`single` ou `multiple`), le libellé, le texte d'aide, le message
« aucun résultat » et le nom du champ soumis (`field_name`, ou
`field_name[]` en mode multiple). Le contrat serveur est
`Core\View\SearchPickerResult` : identifiant, libellé, et sous-titre,
pastille et avertissement facultatifs, enveloppés par `payload()` dans
`{success, results}`. Documentation : `ARCHITECTURE.md` §8.30bis (avec la
migration des quatre sélecteurs existants, notée et non faite, comme
demandé) et `docs/module-development.md` § Search picker.

**Tests.** `Tests\Core\View\SearchPickerRenderingTest` soumet le balisage
comme le ferait un navigateur sans JavaScript (choix simple, choix
multiple, élément retenu absent des options, rien d'autre de soumis que la
liste de repli, contrôles étiquetés) ; `SearchPickerResultTest` fige la
forme de la réponse ; `tests/js/search-picker.test.js` couvre la frappe, le
délai, la réponse tardive à une requête dépassée, la sélection simple et
multiple, le retrait d'un élément, l'exclusion des éléments déjà retenus
et le message quand rien ne correspond.

**Décisions autonomes.**

1. **La liste de repli contient toujours les éléments déjà retenus**, même
   si l'appelant ne les a pas mis dans ses options : sinon un formulaire
   enregistré une fois sans JavaScript perdrait en silence une valeur
   retenue plus tôt.
2. **Un évènement `search-picker:change`** est émis à chaque choix et
   retrait, avec les lignes retenues et tous leurs champs. C'est ce qui
   permettra à l'écran d'organisation (IT-04) d'avertir quand les
   évènements retenus n'indiquent pas le même lieu — un avertissement sur
   une *combinaison*, que la ligne de résultat seule ne peut pas porter.
3. **Entrée choisit la première suggestion** au lieu de soumettre le
   formulaire à moitié rempli.
4. **La liste des résultats reprend la règle CSS des sélecteurs existants**
   (liste déroulante positionnée sous le champ, `components.css`) plutôt
   que d'en écrire une quatrième copie.
5. **Aucun consommateur dans cette itération**, conformément au document :
   le composant naît seul.
