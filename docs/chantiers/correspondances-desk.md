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
pas un défaut.** `ARCHITECTURE.md` §8.75 l'écrit :

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

### Écart 7 — Il n'existe aucune table de fonctions connues

*Trouvé en écrivant IT-03.* D1 pose que « une ligne de la page centrale est
une table à compléter dans une version ». C'est vrai pour trois natures sur
quatre. Pour les **fonctions**, non : `MappingResolver::resolveFunction()`
crée toute fonction inédite au rôle `identified` sans consulter la moindre
liste, et il n'existe nulle part dans le dépôt de table de fonctions
reconnues — vérifié en cherchant les listes d'aiguilles, les `match (true)`
et toute constante candidate dans `core/`.

**Conséquence** : aucune version ne peut « apprendre » une fonction. Seule
l'unité la qualifie. La ligne reste utile — « sept unités portent une fonction
que personne n'a qualifiée » est un signal fédéral que rien d'autre ne donne —
mais ce n'est pas une table à compléter, et la page le dit en ces termes
plutôt que de promettre un correctif qui n'existe pas. C'est aussi pourquoi la
dérivation ne filtre jamais cette nature : il n'y a rien à comparer.

### Écart 8 — `parents` et `ancestors` ne sont pas interchangeables

*Trouvé en écrivant IT-03, par un test qui est tombé.* IT-03 demande
« le fil d'Ariane porte tous les niveaux » et ajoute que
`/support-dashboard` étant statique, « un `ancestors` classique suffit ».
La seconde phrase est exacte ; la première a été appliquée d'abord avec
`parents`, et `Tests\Core\View\UxConventionsTest` l'a refusée :

> Breadcrumb parent "Supervision" matches no menu label — it will render as
> inert grey text.

Un `parents` nomme un **menu** et l'ouvre ; il n'accepte donc que les cinq
libellés de menu. Un `ancestors` nomme une **page** par son chemin de route
et rend un lien — `Router::ancestorTrailFor()` lit au passage son `role_min`
pour ne jamais afficher une étape que le lecteur ne peut pas atteindre. Les
trois sous-pages déclarent donc `parents: ["Configuration"]` **et**
`ancestors: [{Supervision, /support-dashboard}]`. Un test tient la
distinction, parce que la confusion est invisible à la lecture : les deux
formes sont du JSON valide et l'une des deux ne rend rien de cliquable.

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
entier. **Reprendre la borne ne suffisait pas** — voir plus bas.

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

### Passer de deux listes à quatre avait défait la borne d'octets

La seconde revue a trouvé ce que les tests ne pouvaient pas trouver : la borne
s'appliquait **par liste**, et cette itération faisait passer de **deux** à
**quatre** le nombre de listes dont la taille est décidée par les données
d'une unité (`functions`, `fee_categories`, puis `branches` et
`desk_unresolved`). L'arithmétique qui rendait la borne sûre vivait dans un
commentaire — « 8 Ko par liste laisse les deux sous 16 Ko » — et pas dans le
code. Chaque test saturait **une** liste, ou deux, et passait ; aucun ne les
saturait toutes.

Deux défauts distincts, tous deux mesurés avant d'être corrigés.

**Le budget ne tenait pas compte de l'encodage transmis.** Il était compté sur
l'encodage compact, alors que le corps part en `JSON_PRETTY_PRINT`. Pour une
entrée de quatre lignes nichée à seize espaces d'indentation, l'indentation
coûte plus que les libellés : une entrée courte pèse ~126 octets et non ~60.
Le pire cas n'est donc **pas** le libellé le plus large — à cent octets par
champ la borne mord après trente entrées, à vingt caractères elle laisse
passer les cent, et cent entrées coûtent plus cher.

**Les quatre listes à leur borne donnaient 63 033 octets**, soit 2 503 sous les
65 536 du receveur — et ce, sur une installation **sans aucun module câblé**.
`modules` et `module_usage` sur les vingt-cinq modules réels coûtent ~5 500
octets de plus. Le rapport entier serait refusé en 413, ce que la borne
existait précisément pour empêcher.

**Deux bornes plutôt qu'une.** Un plafond total de 32 Ko pour tout le
document, et une part de 8 Ko par liste. Le total est ce qui protège le
rapport : une cinquième liste ajoutée demain puise dans la même bourse au lieu
de relever le plafond. La part garde les listes honnêtes entre elles — une
bourse unique en ordre de lecture laissait `functions` tout manger et
`branches` repartir **vide** avec `total` à cent cinquante, ce qui est le pire
des résultats disponibles : `branches` est dans cette charge parce qu'un rang
à 99 est la correspondance la plus coûteuse à manquer et celle dont personne ne
se plaint — un logo absent n'est signalé par personne, là où une fonction non
qualifiée l'est par celui qui a perdu ses accès. Pas de report du solde non dépensé d'une liste à la suivante : cela
ferait dépendre le contenu d'une liste de sa position dans `build()`.

Pire cas après correction : **32 775 octets**, la moitié de la limite, les
quatre listes servies.

**Trois garde-fous, chacun prouvé en le cassant.** Revenir à la mesure
compacte, supprimer la part par liste, ajouter une cinquième liste sans lui
donner de part : chaque mutation fait rougir un test, chaque restauration le
fait reverdir. Le troisième compte les listes de forme `{total, listed}` dans
la charge construite, donc une liste future est attrapée **en arrivant**, pas
parce que quelqu'un aura pensé à la nommer.

**Un test existant disait désormais faux.** Il affirmait que « les libellés
courts sont bornés par le nombre bien avant de l'être par les octets » et
épinglait exactement cent entrées. Mesurée sur l'encodage réel, la borne
d'octets mord à ~66. `MAX_VOCABULARY_ENTRIES` est donc rétrogradée à ce
qu'elle est : le `LIMIT` qui évite de lire une table emballée, pas la borne
qui opère. Le test assure maintenant que la liste est coupée, non vide, et que
`total` déclare la table entière — sans épingler un nombre qui n'était vrai
que par accident d'encodage.

### Ce que les tests tiennent

Une branche canonique voyage avec son rang, une branche inconnue avec 99. Le
rapport énonce ce qu'il n'a pas su rattacher. Une installation qui reconnaît
tout envoie un bloc **vide**, pas une absence de bloc ; sans service de
constat, le champ est **null**. Une charge de l'ancienne version reste
acceptée, une charge de la nouvelle aussi et sans champ inconnu, et le bloc
survit **verbatim** dans `support_installations.payload` — ce que la page
centrale lira à l'itération suivante. La troncature déclare ce qu'elle a
laissé. Rien d'autre qu'une nature et un libellé ne voyage.

**Suite complète verte**, PHPStan sans erreur.

### Reporté

Rien. La vérification de `RgpdContentService` que D9 demande a d'abord été
renvoyée à IT-04 ; la revue a eu raison de refuser ce report, et elle est
faite ici.

---

## IT-03 — La page centrale et sa notification

**Livré.** `/support-dashboard/correspondances`, troisième écran de
Supervision, et la notification qui fait qu'on n'a pas besoin d'y aller pour
apprendre quelque chose.

- Une troisième pastille au `page_picker` partagé, et les fils d'Ariane des
  trois écrans corrigés (écart 8).
- `support_desk_mapping_gaps`, unique sur `(kind, value_normalized)`, qui ne
  garde que les trois faits non recalculables (D6) : première apparition,
  notification déjà envoyée, valeur écartée.
- `DeskMappingGapReport` — la liste, **dérivée** des charges conservées :
  regroupement par valeur repliée (D7), comptage des installations, dernière
  apparition, et la plus ancienne version qui la remonte encore.
- `DeskMappingGapRecorder`, appelé sur le chemin d'acceptation d'un rapport :
  il retient une valeur jamais vue et l'annonce **une fois** (D8).
- `DeskMappingGapController` — la page, et « écarter » / « réactiver ».
- La notification `support_dashboard.desk_mapping_unknown`, déclarée dans le
  manifeste du module, donc présente uniquement là où
  `visible_when: ['statistics_receiver']` s'applique.
- Version du module montée en `1.15.0`.

### Les décisions prises en autonomie

**La notification se décide à la réception, pas à l'affichage.** La liste est
dérivée, donc rien n'« arrive » quand on ouvre la page — et une page que
personne n'ouvre n'annonce rien, ce qui est exactement le problème du
chantier pris par l'autre bout. L'enregistrement se fait donc sur le chemin
d'acceptation du rapport, après que le rapport est stocké et journalisé, et
il n'est **jamais fatal** : un receveur qui refuserait des rapports parce
qu'une notification a échoué échangerait ce qui compte contre ce qui ne
compte pas.

**Sans destinataire, rien n'est marqué comme notifié.** Si personne n'a
activé le type, la notification n'a pas eu lieu : les lignes gardent
`notified_at` à NULL et le prochain rapport les annoncera. Marquer quand même
ferait taire à jamais une valeur que personne n'a vue passer.

**L'enregistreur filtre avec la même question que la page.** Une valeur que
le code du receveur reconnaît déjà est une valeur qu'une version a corrigée :
l'annoncer reviendrait à envoyer une notification par installation en retard
de mise à jour. La reconnaissance est donc écrite **une fois**, en statique, et
appelée des deux côtés. Elle ne concerne que les branches : une fonction ne se
reconnaît par aucune table (écart 7), et un tarif n'est pas une nature
(écart 1).

**La liste des instances est triée alphabétiquement.** `findAll()` ordonne par
date du dernier rapport, ce qui rebattait les noms sous la page à chaque
envoi. Une liste qui change d'ordre entre deux affichages est du bruit.

**La page n'affiche pas de compte de personnes**, seulement un nombre
d'unités : c'est ce que la charge transmet (IT-02), et c'est la question du
mainteneur.

### Ce que la revue a trouvé, et que les tests ne tenaient pas

Quatre retours, quatre défauts réels. Les deux premiers sont des bugs que ma
propre documentation contredisait.

**Une valeur vue sans abonné n'était jamais annoncée.** Le commentaire disait
« le prochain rapport les annoncera » ; c'était faux. `record()` sortait
avant d'annoncer quand le rapport n'apportait rien de neuf, donc une valeur
restée en attente — personne d'abonné, ou des clés push cassées — ne pouvait
plus jamais être annoncée, quel que soit le nombre de rapports la portant. Et
l'autre moitié du même trou : quand une valeur *neuve* arrivait enfin, le
message ne comptait qu'elle mais `markNotified()` marquait **tout** l'arriéré,
donc la valeur en attente était silencieusement classée « annoncée » sans avoir
jamais été mentionnée. L'annonce part désormais de l'arriéré et non des
nouveautés de l'appel, sur **un seul instantané** : une ligne ne peut être
marquée que par le message qui l'a comptée.

*Corollaire trouvé en corrigeant* : une valeur écartée sur la page alors
qu'elle attendait encore serait annoncée ensuite. `idsAwaitingNotification()`
exclut donc les lignes écartées — écarter vaut réponse.

**« N unités » comptait des entrées de charge, pas des unités.** `instances`
dédoublonnait par hôte, `count` non. Or une même installation peut porter deux
entrées qui se replient sur la même ligne : `functions` est unique sur
`desk_code` et non sur `label`, et deux orthographes se replient **par
construction** (D7). Une seule unité pouvait donc afficher « 2 unités » — et la
page surligne en rouge au-delà de trois, donc quelques variantes sur une unité
pouvaient se lire comme un problème fédéral. Exactement l'inverse de ce à quoi
cette page sert. Le comptage se fait maintenant une fois par installation.

**Deux routes POST n'avaient pas leur frontière RBAC.** `AGENTS.md` demande,
pour chaque route, l'accès permis au `role_min` et refusé un cran en dessous.
Seul le GET l'avait : « écarter » n'était testé qu'en superadmin, et
« réactiver » n'était **jamais** atteint par la route — les tests appelaient le
dépôt directement, donc le routeur, le garde et la méthode n'étaient pas
couverts. Trois tests ajoutés, et vérifiés en abaissant `role_min` dans le
manifeste : ils tombent.

**Un commentaire Twig français que ce changement modifiait.** `AGENTS.md` le
dit : un commentaire Twig est un commentaire, donc en anglais, et on traduit
ceux d'un fichier où un changement nous emmène de toute façon. Celui-ci passait
« deux écrans » à « trois » — le cas exact que la règle vise. Traduit.

### Ce que les tests tiennent

Une valeur que ce code reconnaît n'apparaît jamais — la branche par
`canonicalSortOrder()`, sur les deux chemins, celui de la page et celui de
l'enregistrement. Deux orthographes voisines font une seule ligne, et une seule
notification. Une valeur notifiée ne renotifie pas le lendemain. Écarter la
retire de la vue par défaut, la laisse consultable derrière le filtre, et
**garde sa ligne** — jamais une suppression. La plus ancienne version affichée
est bien la plus ancienne. Une entrée malformée dans la charge d'un autre
site ne coûte qu'elle-même. Le superadmin lit la page, l'admin est refusé par
le garde, un POST sans jeton CSRF ne change rien. Et les fils d'Ariane des
trois écrans nomment la page dont ils dépendent, par un mécanisme qui rend un
lien.

**Suite complète verte**, PHPStan sans erreur.

### Reporté

Rien. La documentation et les sujets d'aide sont l'itération suivante. La
vérification RGPD que D9 demande n'y est plus : la revue d'IT-02 a refusé ce
report, et elle est faite là-bas.

---

## IT-04 — Documentation et aide

**Livré.**

- **`ARCHITECTURE.md` §8.51ter** — les trois natures et l'endroit du code qui
  décide de chacune, **pourquoi un tarif n'en est pas une** (écart 1),
  **pourquoi une fonction n'a pas de table** (écart 7), le bloc de charge, et
  **pourquoi rien n'est catalogué côté central** (D5) : l'instance réceptrice
  fait tourner le même logiciel, donc elle compare à ses propres tables, et un
  catalogue de « valeurs connues » devrait être tenu à jour à la main pour
  toujours — et serait faux exactement quand ça compte.
- **`specifications.md`** — la troisième sous-page de Supervision et son écran
  décrits, l'encadré de Correspondances Desk ajouté à sa ligne de §4.5, et la
  phrase « les deux écrans » corrigée en trois.
- **Deux sujets d'aide** : celui de Correspondances Desk pointe vers un nouveau
  sujet qui explique l'encadré, et la page centrale a le sien.

**La vérification RGPD que D9 demande ne fait pas partie de cette itération.**
Elle y était prévue, et c'était une erreur que la revue d'IT-02 a refusée à
juste titre : `AGENTS.md` exige la mise à jour de `RgpdContentService` dans le
**même changement** que le nouveau flux sortant, et IT-02 est le changement qui
commence à envoyer. La reporter ici aurait laissé une phrase fausse devant un
lecteur pendant deux itérations, sur la page dont c'est précisément le rôle de
dire ce qui quitte l'installation. Faite en IT-02, donc, avec son test de
couverture sur les deux surfaces.

### Trois phrases étaient déjà périmées en arrivant

Cette itération a été écrite avant la revue d'IT-03, et cette revue a changé le
comportement qu'elle décrit. Trois textes annonçaient donc **« une notification,
à l'insertion »**, ce qui n'est plus vrai : l'annonce suit l'arriéré des lignes
jamais notifiées, donc une valeur vue alors que personne n'était abonné est
annoncée par un rapport **ultérieur**. Corrigés dans les trois : `ARCHITECTURE.md`
§8.51ter, `specifications.md` §4.5, et le sujet d'aide de la page centrale.

C'est exactement la panne que cette itération existe pour empêcher, arrivée à
l'itération qui l'empêche — et elle vaut d'être notée : une documentation écrite
en même temps que le code qu'elle décrit reste juste, une documentation écrite
*avant* la dernière revue de ce code ne l'est que par chance.

### La charte de l'aide a tranché à ma place

L'ajout de l'encadré au sujet `config-desk` l'a porté à 515 mots, et
`HelpInvariantsTest` l'a refusé : au-delà de ~400 mots, `design.md` §7.11 veut
deux sujets. C'est le bon arbitrage — le sujet traitait déjà des rôles, des
sections et des branches — donc les valeurs non reconnues ont le leur,
`config-desk-valeurs-inconnues`, et l'ancien y renvoie.

Deux autres règles de l'aide ont corrigé le tir : un texte entre guillemets doit
être un libellé réel de l'interface (« je ne vois plus rien » n'en est pas un,
reformulé), et `discovery: 0` réclame la première place de « Le saviez-vous ? » —
la page centrale, qui n'existe que chez le mainteneur, est en `discovery: off`.

**Suite complète verte**, PHPStan sans erreur.

### Ce que la revue a trouvé, et que rien ne tenait

Quatre retours sur une itération purement documentaire, et tous portaient sur
le fond. C'est la démonstration de ce à quoi sert cette itération : rien dans
la suite de tests ne vérifie qu'une phrase est **vraie**.

**Une citation de section fausse, répétée cinq fois.** J'attribuais la règle
sur les tarifs à §8.74, qui est « the fees module and the roster snapshot » ;
la phrase citée vit en **§8.75**, « Justesse des tarifs ». La revue en a vu
deux, celles du diff. Les trois autres étaient déjà fusionnées — dans
`DeskMappingGapKind`, dans `DeskMappingGapServiceTest` et dans ce journal
même, où la citation servait à justifier le retrait de la nature « Tarif ».
Les cinq sont corrigées : laisser les trois anciennes fausses parce qu'elles
sont hors diff, c'est laisser un lecteur chercher la règle au mauvais endroit.

**Le sujet d'aide envoyait le chef d'unité faire un geste sans effet.** Il
donnait « choisir un logo pour la branche » comme la correction, puis disait
que la ligne s'en va « dès qu'une branche est reconnue ». Or `setLogo()` écrit
`logo_file_id` et rien d'autre : le rang de 99 vient de
`canonicalSortOrder()`, une table du logiciel, jamais d'un geste dans
l'interface. Quelqu'un aurait donc téléversé un logo et vu la ligne rester,
sans explication. Réécrit : le logo corrige la page des animés, la ligne
attend une version.

**Et il promettait un correctif qui ne peut pas venir.** Les libellés étaient
signalés au mainteneur « pour qu'il ajoute les correspondances manquantes dans
une version suivante » — vrai pour une branche, faux pour une fonction
(écart 7), et le sujet frère de la même PR le disait déjà explicitement.
Quelqu'un lisant celui-ci aurait attendu une mise à jour au lieu d'attribuer
le rôle qui, seul, résout la ligne. La promesse est restreinte aux branches.

Le sujet est passé à 452 mots en gagnant ces nuances, puis resserré à 434 —
au-dessus des ~400 de la limite souple, sous les 500 de la limite dure, ce que
`HelpInvariantsTest` accepte. Un sujet né d'une scission pour dépassement
méritait qu'on y regarde deux fois.

### Second tour de revue : sept autres phrases fausses

Onze retours en tout sur cette itération documentaire, et aucun sur le code.
La leçon tient en une ligne : **aucun test ne vérifie qu'une phrase est vraie**,
et une itération qui ne fait qu'écrire des phrases n'a donc que la relecture
pour filet.

**Le garde anti-fuite était documenté à moitié.** §8.51ter créditait la
journalisation des en-têtes CSV au seul ratio des deux tiers, « preuve que la
ligne est celle du schéma ». Or `DeskCsvParser` en exige **deux** : le ratio
*et* une borne sur le nombre de cellules. Le commentaire du code dit pourquoi —
un fichier dont la ligne d'en-têtes et la première ligne de données ont fusionné
faute de saut de ligne porte soixante-dix cellules dont trente-quatre sont
encore des noms attendus, passe le ratio, et livrerait au journal le nom, la
date de naissance, le téléphone et l'adresse d'un membre réel. C'est le second
trou que la revue d'IT-01 m'avait fait boucher, et ma documentation n'en gardait
que la première moitié. Le danger est précis : quelqu'un lisant cette section
retire la borne de largeur comme redondante et rouvre le trou. Les deux
conditions sont désormais énoncées ensemble, avec la raison de la seconde.

**Le tableau des natures se contredisait onze lignes plus loin.** Il disait
qu'une branche à 99 échoue « **and no signal at all** », alors que la même
section explique que toute valeur non résolue est journalisée et remonte dans
l'encadré. La phrase datait de l'énoncé du problème de #356 et IT-01 l'avait
rendue fausse. Quelqu'un s'y fiant aurait réimplémenté un signalement qui
existe. L'énoncé juste n'est pas « aucun signal » mais **« des conséquences dont
personne ne se plaint »** : un logo absent n'est signalé par personne, là où une
fonction non qualifiée l'est par celui qui a perdu ses accès. Corrigé dans le
tableau, et dans les trois autres endroits où j'avais réemployé la formule
périmée — `StatisticsPayloadBuilder`, son test, et l'entrée IT-03 de ce journal.

**Le sujet d'aide affirmait qu'un import ne s'arrête jamais.** Faux pour une
colonne : un en-tête attendu absent lève `ImportException`. Restreint aux
fonctions et aux branches, avec la différence nommée — une colonne arrête
l'import et le dit tout de suite, il n'y a rien à découvrir plus tard.

**Et qu'« une ligne indique combien de fiches sont concernées ».** Le gabarit ne
l'affiche que si `affected > 0` ; sinon il dit « personne ne porte cette
fonction cette année ». Reformulé pour couvrir les deux cas.

**« Donnez-lui un rôle plus bas dans la page » se lisait comme un comparatif.**
Trois lignes plus haut, le même texte dit que la fonction est créée « au rôle le
plus bas » — donc « un rôle plus bas » se comprend d'abord comme *un rôle
inférieur*, conseil impossible puisqu'elle est déjà au plus bas. Une virgule
suffit : « donnez-lui un rôle, plus bas dans la page ».

**« En cochant "Montrer les valeurs écartées" »** nomme un geste que la page
n'offre pas : c'est un lien, et le gabarit ne contient aucune case à cocher.
Corrigé en « par le lien ».

**Et l'encadré n'est pas un compte rendu du dernier import.** `specifications.md`
disait « what the last import could not match » ; la liste est l'état **courant**,
donc une fonction créée il y a des mois et jamais qualifiée y figure encore. Un
administrateur aurait attribué un manque ancien au dernier import.

Le sujet d'aide a gonflé de 373 à 487 mots en absorbant ces nuances — treize sous
la limite dure, ce qui ne laissait aucune marge. Resserré à **455** à contenu
constant, en fusionnant deux sections qui disaient la même chose de deux façons.

### Reporté

Rien. Le chantier est clos.

---
