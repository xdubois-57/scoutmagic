# Chantier — Correspondances Desk non résolues (#356)

Journal d'implémentation du document de chantier « Correspondances Desk non
résolues » (itérations IT-01 à IT-04). Une section par itération : ce qui a
été fait, les décisions prises en autonomie, les divergences constatées entre
le document de chantier et le dépôt réel, et ce qui a été reporté. Même format
que `docs/chantiers/aide-contextuelle.md`.

---

## Les faits de la roadmap, vérifiés contre le code

La roadmap dit d'elle-même qu'elle a été écrite sur le commit `08d830b` et que
c'est le code qui fait foi si les deux divergent. Vérification faite avant
d'écrire une ligne.

**Le code n'a pas bougé.** Huit commits séparent `08d830b` de la base de ce
chantier ; aucun ne touche un des fichiers que la roadmap cite. Les écarts
ci-dessous ne sont donc pas de la dérive : ce sont des descriptions qui étaient
déjà inexactes au moment où la roadmap a été écrite. Cela change ce qu'il faut
en faire — il n'y a rien à « remettre à jour », il y a des décisions à
reprendre sur les bons faits.

### Écart 1 — La table qui décide d'un tarif n'est pas celle que D1 nomme

D1 donne `FederalScaleLookupService::FIELD_BY_CATEGORY` comme la table du code
à compléter pour la nature « Tarif ». Cette constante ne connaît pas Desk :

```php
private const FIELD_BY_CATEGORY = [
    'normal' => 'normale', 'couple' => 'couple', 'family' => 'familiale',
];
```

Elle traduit les trois catégories de ménage du site vers **le vocabulaire de la
page fédérale**, pour lire les montants dessus (`amountCentsOrNull($answer[…])`,
son unique usage). Un code tarif venu de Desk ne la rencontre jamais.

Ce qui décide réellement, c'est `FeeCategoryClassifier::NEEDLES` — une
heuristique sur le libellé replié — et, au-dessus, la correspondance explicite
qu'une unité peut poser (`fees_household_tariffs`,
`HouseholdTariffService::mapping()`). `classify()` rend `null` quand aucun des
deux ne reconnaît la valeur, et son docblock dit que ce `null` *est* une
réponse : le membre reste simplement hors de la comparaison.

**Conséquence sur le chantier** : la ligne « Tarif » de la page centrale
désigne `FeeCategoryClassifier::NEEDLES`, pas `FIELD_BY_CATEGORY`. Corriger la
mauvaise aurait produit exactement le bug que ce chantier existe pour rendre
visible — une correspondance qu'on croit faite et qui ne l'est pas.

### Écart 2 — L'en-tête CSV bloquant est l'inverse de ce qui est décrit

D1 et IT-01 disent qu'un en-tête **inattendu** arrête l'import, et la maquette
en fait sa quatrième ligne (« Courriel — colonne inattendue : l'import
s'arrête »). `DeskCsvParser::validateHeaders()` ne regarde que l'absence :

```php
foreach (self::EXPECTED_HEADERS as $expected) {
    if (!in_array($expected, $headers, true)) { $missing[] = $expected; }
}
```

Vérifié en exécutant la méthode : les 35 en-têtes attendus **plus** une colonne
`Courriel` en trop passent sans rien déclencher ; c'est la disparition d'un
attendu qui lève `ImportException`.

**Ce que l'écart ne change pas**, et c'est pourquoi la ligne reste au chantier :
le cas réel que la maquette décrit — la fédération renomme `Email Tiers` en
`Courriel` — bloque bien l'import. Simplement, ce qui le bloque est la colonne
**manquante**, et la colonne inattendue en est le symptôme. C'est justement ce
qui rend la valeur brute indispensable au journal : le message d'erreur nomme
aujourd'hui les 34 colonnes manquantes et pas une fois celle qui est arrivée à
leur place.

### Écart 3 — `MappingResolver` ne tient plus « seulement un compteur »

IT-01 décrit quatre `resolve*()` qui créent en silence, « seul un compteur
étant tenu pour les fonctions ». Le compteur existe toujours, mais il est
l'accessoire : `$created` collectionne les identifiants des quatre natures, et
`getNewMappings()` les rend à `ImportDiffCalculator` sous forme de
`NewMappings`. Ce qui manque n'est donc pas la collecte, c'est qu'elle ne sorte
jamais du rapport d'import — un écran qu'on lit une fois, le jour de l'import.

### Écart 4 — La normalisation de D7 a déjà un nom dans ce dépôt

D7 demande « minuscules, espaces coupés, accents repliés — la même
normalisation que `canonicalSortOrder()`, plus les accents ». C'est la
définition de `Core\Service\TextNormalizerService::fold()`, que le dépôt
désigne comme sa forme de comparaison unique (§8.0), avec l'argument explicite
qu'une seconde finirait par diverger de la première sur un hôte. Aucun
normaliseur n'est donc écrit : D7 est appliqué en appelant `fold()`.

### Écart 5 — D4 rencontre le contrat `Api\` sur la nature « Tarif »

D4 veut que l'émetteur envoie un constat plutôt qu'une liste. Pour les
fonctions et les branches, le cœur sait : `functions.confirmed` et
`canonicalSortOrder()` lui appartiennent. Pour les tarifs, non — et
`StatisticsPayloadBuilder` le dit déjà de lui-même :

> Deliberately NOT classified before being sent. Doing that would mean naming
> `Modules\Fees` from core, which the `Api\` contract forbids
> (ARCHITECTURE.md §7.5).

Le verdict « ce tarif ne correspond à aucun barème » passe donc par une
capacité `Api\` publiée par le module `fees` et consommée en dépendance
nullable, sur le modèle de `Modules\UsageStats\Api\ModuleUsageInterface`.
Module absent ou désactivé : aucun constat de tarif, ce qui est la réponse
juste — sans le module des cotisations, il n'y a pas de barème à rater.

### Écart 6 — Le site signale déjà les fonctions, et lui seul

`Core\Attention\CoreAttentionProvider` produit déjà « N fonctions attendent
d'être qualifiées », avec l'action « Qualifier dans Correspondances Desk ».
La moitié « fonction » de l'encadré n'est donc pas le premier signal du site.
Les branches et les tarifs, eux, n'en ont aucun : une branche à 99 est
exactement le cas que D1 décrit comme échouant **sans aucun signal**, et c'est
vrai. L'encadré ne réécrit pas ce que le point d'attention dit déjà ; il couvre
ce qui n'est dit nulle part.

### Ce qui est vérifié conforme

`visible_when: ['statistics_receiver']`, la notification `ticket_received`, le
rail `page_picker` en `match_prefix: true`, les bornes
`MAX_VOCABULARY_ENTRIES` / `MAX_VOCABULARY_BYTES`, le bloc `desk_vocabulary`
et son commentaire sur `confirmed`, `support_installations.payload` en JSON,
`SUPPORTED_SCHEMA_VERSIONS = [1]`, et les fils d'Ariane de Tickets et du détail
d'un ticket qui sautent bien le niveau Supervision — tout cela est exact.

---

## IT-01 — Détecter et journaliser, côté unité

**Livré.** Le site dit maintenant ce qu'il ne sait pas, à trois endroits et
sans rien envoyer nulle part.

- `Core\Import\DeskMappingGapKind` — les quatre natures, chacune portant son
  type de journal et **la table du code à compléter**. C'est là qu'est
  corrigé l'écart 1 : la nature « Tarif » désigne
  `FeeCategoryClassifier::NEEDLES`.
- `Core\Import\DeskMappingGap` et `Core\Import\DeskMappingGapService` — la
  liste de ce qui n'est pas résolu, **dérivée et jamais stockée**. Une
  fonction qualifiée, une branche apprise par une version, un tarif enfin
  associé : la valeur quitte la liste d'elle-même, sans rien à défaire.
- `MappingResolver` journalise en `info` : `desk_function_unknown`,
  `desk_branch_not_canonical`, `desk_fee_without_scale`. Une fois par valeur
  et par import.
- `DeskCsvParser` journalise la ligne d'en-têtes refusée —
  `desk_csv_header_unexpected` — avec **les colonnes réellement vues** à côté
  des attendues manquantes.
- `Core\Support\Collector\DeskMappingsCollector` — `desk-mappings.json` dans
  le paquet de support, avec sa description sur l'écran qui dit à
  l'administrateur ce qu'il transmet.
- L'encadré en tête de Correspondances Desk, une ligne par valeur, avec son
  effet en français et le bouton qui mène là où on la corrige.
- `Modules\Fees\Api\HouseholdTariffRecognitionInterface` — la capacité qui
  répond à l'écart 5.

### Les décisions prises en autonomie

**Une fonction toujours non qualifiée est redite au prochain import.** La
roadmap ne demandait que les *créations* pour les fonctions, mais exigeait
pour les branches « chaque branche dont `canonicalSortOrder()` rend 99 »,
créée ou non. Cette asymétrie suit le code (une fonction a un `confirmed`,
une branche n'a rien) et non le besoin. Ce qui rend une valeur non résolue
est son **état**, pas le jour de sa création — un rapport qui ne regarderait
que les créations se tairait précisément sur les valeurs auxquelles personne
n'a eu le temps de toucher. Les deux natures sont donc traitées sur l'état.

**L'encadré remplace l'alerte « N fonction(s) à confirmer ».** Elle disait la
même chose d'un sous-ensemble. Deux blocs voisins disant l'un « 2 fonctions à
confirmer » et l'autre « 3 valeurs que le site ne connaît pas » auraient
obligé le lecteur à comprendre pourquoi les nombres diffèrent.

**La phrase « ces valeurs sont aussi signalées au mainteneur » n'est pas
écrite.** La maquette la place sous l'encadré, et elle est exacte — à partir
d'IT-02. L'écrire ici ferait dire à l'interface quelque chose de faux pendant
une itération. Elle arrivera avec l'envoi, et conditionnée à
`statistics_enabled` : une installation qui a coupé les rapports ne signale
rien à personne, et le lui dire serait faux dans l'autre sens.

**La capacité `Api\` a deux méthodes, pas une.** `recognisesWording()` est la
question qu'on pose d'une valeur rencontrée à l'instant — la ligne vient
d'être créée, aucune correspondance manuelle ne peut exister, et la question
« réglée » répondrait « inconnue » y compris pour une cotisation normale tout
à fait ordinaire. `unmappedFeeCategoryIds()` est la réponse arrêtée pour les
lignes déjà stockées, correspondance explicite comprise. La seconde n'est pas
le complément de la première : une unité qui désigne « le couple, c'est ce
code-ci » retire du même geste la revendication à celui que l'heuristique
avait deviné. Un test tient précisément ce cas.

**La pile d'import Desk a été déplacée dans le composition root.** Le
résolveur prend désormais la capacité du module des cotisations, donc il ne
peut plus être construit avant que `$moduleManager` existe. `$importService`,
son seul consommateur, n'est lu que bien plus bas ; l'assemblage entier est
descendu sous la définition de `$isEnabled`, avec le commentaire qui dit
pourquoi. Le service du barème est construit **une fois** et partagé avec le
bloc du module, pour que la requête ne porte pas deux caches du même barème.

**`AgeBranchRepository::UNKNOWN_SORT_ORDER`.** Le 99 était un littéral dans
`canonicalSortOrder()` ; il a maintenant un second lecteur, qui s'en sert pour
trouver les branches que ce code ne reconnaît pas.

### Ce que les tests tiennent

Une fonction inconnue journalise une fois et une seule par import, et le
prochain import la redit. Une branche à 99 journalise, une branche canonique
non, **et une branche laissée à 99 par un import d'il y a six mois journalise
aussi**. Sans le module des cotisations, aucun tarif n'est jamais signalé. Le
contexte du journal ne contient que le libellé et la table à compléter — les
clés sont vérifiées une à une. Une ligne d'en-têtes refusée nomme `Courriel`,
la colonne réellement vue ; une colonne en trop toute seule ne refuse ni ne
journalise rien (écart 2, tenu dans les deux sens). L'encadré nomme la
branche et disparaît dès que tout est résolu, sans qu'on ait marqué quoi que
ce soit comme lu.

**Suite complète verte** : 20 403 tests, PHPStan sans erreur.

### Reporté

Rien. L'écran central, l'envoi et la documentation sont les itérations
suivantes, comme prévu.

---
