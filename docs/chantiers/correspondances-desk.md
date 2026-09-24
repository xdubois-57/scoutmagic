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

### Écart 1 — La nature « Tarif » n'est pas une classe de défaut

D1 donne `FederalScaleLookupService::FIELD_BY_CATEGORY` comme la table du code
à compléter pour la nature « Tarif ». Cette constante ne connaît pas Desk :
elle traduit les trois catégories de ménage du site vers **le vocabulaire de
la page fédérale**, pour y lire des montants. Un code tarif venu de Desk ne la
rencontre jamais.

Ce qui décide réellement est `FeeCategoryClassifier::NEEDLES`, une heuristique
sur le libellé replié — et c'est en regardant *cette* table que l'écart se
révèle bien plus profond que le mauvais nom. **Un tarif hors des trois n'est
pas un défaut.** `ARCHITECTURE.md` §8.74 l'écrit :

> **A tariff outside the three is not judged.** […] Reporting them would be a
> false positive on every unit, on the first screen a treasurer opens.

Et `FeeCategoryClassifierTest` épingle « Cotisation invités », « Cotisation de
solidarité » et « COT_iAM_LOCAL » comme devant rendre `null` **pour toujours**.
Le docblock de `classify()` le dit aussi : « `null` is a real answer, not a gap
to close ».

Le point décisif est que **le site ne peut pas distinguer** un tarif qui n'est
légitimement pas un des trois d'un des trois orthographié autrement — c'est
exactement pourquoi `classify()` refuse de deviner. Il ne peut donc signaler
ni l'un ni l'autre sans se tromper sur le second.

**Conséquence** : la nature « Tarif » est retirée du chantier. Pas renommée,
pas corrigée vers la bonne table — retirée. Avec elle disparaissent la
capacité `Api\` que le cœur consommait pour poser la question, son
enregistrement dans le bootstrap du planificateur, le déplacement de la pile
d'import dans le composition root qu'elle imposait, et l'événement de journal
`desk_fee_without_scale`.

C'est la correction la plus coûteuse du chantier et la plus utile : livrée
telle quelle, la page centrale aurait affiché un avertissement **permanent et
insoluble** à chaque unité utilisant « Cotisation invités », et le paquet de
support aurait dit à un mainteneur d'élargir la table que le code lui interdit
d'élargir.

*(Trouvé par la revue de #501, pas par moi. J'avais lu `FeeCategoryClassifier`
— j'en cite le docblock plus bas pour justifier la capacité `Api\` — sans voir
que la phrase « `null` is a real answer, not a gap to close » disait déjà que
ce chantier n'avait rien à y faire.)*

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

### Écart 5 — D4 rencontrait le contrat `Api\` sur la nature « Tarif »

D4 veut que l'émetteur envoie un constat plutôt qu'une liste. Pour les
fonctions et les branches, le cœur sait : `functions.confirmed` et
`canonicalSortOrder()` lui appartiennent. Pour les tarifs, non — et
`StatisticsPayloadBuilder` le dit déjà de lui-même :

> Deliberately NOT classified before being sent. Doing that would mean naming
> `Modules\Fees` from core, which the `Api\` contract forbids
> (ARCHITECTURE.md §7.5).

Cet écart a d'abord été résolu par une capacité `Api\` publiée par le module
`fees`. **L'écart 1 l'a rendu sans objet** : il n'y a pas de constat de tarif à
envoyer, donc pas de question à poser au module, donc pas de capacité. Gardé
ici parce que la contrainte reste vraie et se reposera au premier chantier qui
voudra faire dire au cœur quelque chose qui appartient à un module.

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

- `Core\Import\DeskMappingGapKind` — les trois natures, chacune portant son
  type de journal et l'endroit du code qui décide. La nature « Tarif » n'y est
  pas : voir l'écart 1.
- `Core\Import\DeskMappingGap` et `Core\Import\DeskMappingGapService` — la
  liste de ce qui n'est pas résolu, **dérivée et jamais stockée**. Une
  fonction qualifiée, une branche apprise par une version, un tarif enfin
  associé : la valeur quitte la liste d'elle-même, sans rien à défaire.
- `MappingResolver` journalise en `info` : `desk_function_unknown` et
  `desk_branch_not_canonical`. Une fois par valeur et par import.
- `DeskCsvParser` journalise la ligne d'en-têtes refusée —
  `desk_csv_header_unexpected` — avec **les colonnes réellement vues** à côté
  des attendues manquantes.
- `Core\Support\Collector\DeskMappingsCollector` — `desk-mappings.json` dans
  le paquet de support, avec sa description sur l'écran qui dit à
  l'administrateur ce qu'il transmet.
- L'encadré en tête de Correspondances Desk, une ligne par valeur, avec son
  effet en français et le bouton qui mène là où on la corrige.

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

**`AgeBranchRepository::UNKNOWN_SORT_ORDER`.** Le 99 était un littéral dans
`canonicalSortOrder()` ; il a maintenant un second lecteur, qui s'en sert pour
trouver les branches que ce code ne reconnaît pas.

### Ce que les tests tiennent

Une fonction inconnue journalise une fois et une seule par import, et le
prochain import la redit. Une branche à 99 journalise, une branche canonique
non, **et une branche laissée à 99 par un import d'il y a six mois journalise
aussi**. Un tarif hors des trois n'est jamais signalé, quel qu'il soit. Le
contexte du journal ne contient que le libellé et la table à compléter — les
clés sont vérifiées une à une. Une ligne d'en-têtes refusée nomme `Courriel`,
la colonne réellement vue ; une colonne en trop toute seule ne refuse ni ne
journalise rien (écart 2, tenu dans les deux sens). L'encadré nomme la
branche et disparaît dès que tout est résolu, sans qu'on ait marqué quoi que
ce soit comme lu.

**Suite complète verte** : 20 403 tests, PHPStan sans erreur.

### Ce que la revue a trouvé, et que les tests ne tenaient pas

**Le journal des en-têtes pouvait écrire un membre.** `parse()` prend la
ligne 0 pour la ligne d'en-têtes sans rien avoir pour en juger : un export
dont l'en-tête a été retiré, ou un délimiteur mal détecté, lui fait passer
une ligne de données. `journalRefusedHeaders()` en écrivait les cellules
dans `event_log.context` — que `EventJournalCollector` recopie dans un
paquet de support, qui quitte l'installation. `SECURITY.md` §13 l'interdit
en toutes lettres.

**Et le test était pire que muet : il affirmait le contraire.** Sa fixture
était `"Nom;Prenom;Courriel"` — trois colonnes, deux noms attendus
reconnus, c'est-à-dire exactement la forme indistinguable d'une ligne de
données. Il ne se contentait pas de laisser passer la fuite ; il vérifiait
que cette forme-là journalise ses valeurs, et il passait. Écrire une
fixture courte « pour aller vite » avait choisi, sans le dire, le cas que
la règle de sécurité visait.

Corrigé : les valeurs ne sont écrites que si **au moins deux tiers** des 35
en-têtes attendus sont présents. Le cas pour lequel l'entrée existe en garde
34 sur 35 ; une ligne de membre n'en a aucun. Les deux tests refaits sur les
vrais cas, et celui de la fuite vérifié en retirant la garde — il tombe.

### Reporté

Rien. L'écran central, l'envoi et la documentation sont les itérations
suivantes, comme prévu.

---

## IT-02 — Enrichir la charge envoyée

**Livré.** Le rapport quotidien porte maintenant de quoi voir le problème
depuis l'autre bout.

- **Les branches entrent dans `desk_vocabulary`** — code, libellé, et le rang
  que `canonicalSortOrder()` leur a donné. Le rang est la colonne
  intéressante : 99 dit qu'aucune des sept aiguilles n'a mordu.
- **Un bloc `desk_unresolved`** à côté du vocabulaire (D4) : ce que
  l'émetteur *sait* ne pas avoir su rattacher. Il est le seul à pouvoir le
  dire — il tient `functions.confirmed` et il sait ce que
  `canonicalSortOrder()` a répondu.
- **Les deux bornes existantes s'appliquent au nouveau bloc**, et `total`
  déclare ce qui a été laissé de côté.
- **La version du schéma ne monte pas**, et c'est une correction : voir
  plus bas.
- **La mise à jour RGPD** des deux surfaces, dans le même changement que
  l'envoi.
- La phrase de transparence qu'IT-01 avait différée est posée sous
  l'encadré, conditionnée à `statistics_enabled`.

### Les décisions prises en autonomie

**Le bloc ne porte pas de comptage.** La maquette de la page centrale montre
« 7 unités », et c'est le receveur qui l'obtient en comptant les rapports où
la valeur apparaît. Envoyer en plus « 3 personnes portent cette fonction »
n'ajouterait rien à cette colonne et ferait voyager un dénombrement de
personnes par unité. Une nature et une valeur brute, et rien d'autre (D9).

**Deux nullités différentes, gardées distinctes.** `desk_unresolved` à `null`
veut dire « cette installation ne mesure pas » — aucun service de constat
câblé. `{"total": 0, "listed": []}` veut dire « je reconnais tout ». La règle
1 de `StatisticsPayloadBuilder` l'exige, et un receveur qui confondrait les
deux irait chercher un problème inexistant. Deux tests les séparent.

**La troncature compte les entrées *et* les octets.** Le bloc reprend
`MAX_VOCABULARY_ENTRIES` et `MAX_VOCABULARY_BYTES` parce que c'est la même
nature de risque : ce sont les seules parties de la charge dont la taille est
décidée par les données d'une unité, et la borne qui mord vraiment est les
65 536 octets que le receveur mesure sur le corps brut. Une unité au
vocabulaire délirant doit coûter à ce champ sa complétude, jamais au rapport
entier.

**La version du schéma n'est plus écrite en dur dans les tests.** Trois
assertions épinglaient `1`. Elles lisent désormais la constante : un numéro
de version recopié dans un test est un test qui échoue à chaque montée sans
rien avoir vérifié.

### La montée de version était une erreur, et la règle était déjà écrite

La première version de cette itération montait `STATISTICS_SCHEMA_VERSION` à
2 et faisait accepter `[1, 2]` au receveur, en se justifiant ainsi : « une
liste qui grandit, jamais qui se déplace — les installations ne se mettent pas
à jour le même jour ». **Le raisonnement était juste et regardait dans le
mauvais sens.**

La version voyage de l'émetteur vers le receveur, et la liste des versions
acceptées vit chez le **receveur**. Une montée ne protège donc pas un vieil
émetteur d'un nouveau receveur : elle casse un **nouvel** émetteur contre un
receveur qui n'a pas encore été mis à jour. Toute unité installant cette
version avant `scoutmagic.be` aurait vu ses rapports refusés en 400 — et les
données que cette fonctionnalité collecte perdues jusqu'à ce que le receveur
rattrape.

Et la règle était déjà écrite, pour ce cas exact, à propos de l'ajout de
`desk_vocabulary` (`ARCHITECTURE.md` §8.49) :

> the schema version is unchanged, since an added field is what that list's
> tolerance exists for and a bump would make every receiver still on the
> previous release reject the report outright

Un champ ajouté ne demande donc aucune montée : un champ inconnu est conservé
tel quel dans la charge et signalé, jamais refusé. Ce qu'une montée sert à
dire, c'est qu'un vieux receveur lirait le document **de travers** — un champ
dont le sens ou le type change, une suppression dont quelque chose dépend.
Rien ici ne fait cela. Relevé par la revue de #521.

### La documentation RGPD appartient à cette itération, pas à IT-04

`AGENTS.md` demande la mise à jour de `RgpdContentService` — contenu par
défaut **et** prompt de génération — dans **le même changement** que le
nouveau flux sortant. Ce changement-ci est celui qui commence à envoyer les
branches et le bloc des valeurs non résolues ; la reporter à IT-04 laissait
une phrase fausse au lecteur pendant deux itérations, et `AGENTS.md` exige en
plus qu'un report conscient devienne une issue. Relevé par la revue, et
corrigé en déplaçant la mise à jour ici plutôt qu'en ouvrant une issue pour un
report qui n'avait pas lieu d'être.

Ce qui se trouvait déjà juste, et n'a pas bougé : la règle interdit
explicitement de qualifier ce rapport d'anonyme, puisqu'il porte l'adresse du
site. Ce qui était absent : les deux surfaces énuméraient ce qui part —
« uniquement des compteurs agrégés et des informations techniques » — et le
vocabulaire Desk n'est ni l'un ni l'autre. **Le manque est antérieur au
chantier** : les fonctions et les catégories de tarif partaient déjà. Un test
de couverture tient les énoncés sur les deux surfaces, parce qu'une
régénération par un prompt qui ignorerait le vocabulaire réécrirait
tranquillement l'ancienne version.

### Ce que les tests tiennent

Une branche canonique voyage avec son rang, une branche inconnue avec 99. Le
rapport énonce ce qu'il n'a pas su rattacher. Une installation qui reconnaît
tout envoie un bloc **vide**, pas une absence de bloc ; sans service de
constat, le champ est **null**. Une charge de l'ancienne version reste
acceptée, une charge de la nouvelle aussi et sans champ inconnu, et le bloc
survit **verbatim** dans `support_installations.payload` — ce que la page
centrale lira à l'itération suivante. La troncature déclare ce qu'elle a
laissé. Rien d'autre qu'une nature et un libellé ne voyage.

**Suite complète verte** : 20 414 tests, PHPStan sans erreur.

### Reporté

Rien. La vérification de `RgpdContentService` que D9 demande a d'abord été
renvoyée à IT-04 ; la revue a eu raison de refuser ce report, et elle est
faite ici.

---
