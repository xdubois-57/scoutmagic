# Chantier — Courrier entrant transversal

Journal des choix laissés à l'agent par la feuille de route (annexe B), à
lire avant de reprendre le chantier. Chaque section dit **ce qui a été
décidé** et **pourquoi**, pas ce qu'il reste à faire : la feuille de route
s'en charge.

---

## IT-01 — Modèle d'association découplé

### Le retrait des trois colonnes est différé d'une release

`consumer_id`, `business_reference` et `link_origin` ont quitté le code :
plus rien ne les écrit, et `inbound_message_links` porte désormais chaque
association. Mais elles restent **déclarées** dans `schema.sql`, rendues
nullables, et `drops.sql` ne les supprime pas encore.

La raison est un ordre d'exécution, pas une hésitation. Sur la première
requête qui suit le déploiement :

1. `Core\Database\MigrationRunner` applique `schema.sql` **puis**
   `drops.sql` ;
2. la racine de composition (`public/index.php`) est atteinte **ensuite**,
   et c'est elle qui lance la reprise
   (`InboundMessageRepository::backfillLinks()`, gardée par le réglage
   `inbound_mail_links_migrated`).

Un `DROP COLUMN` livré dans la même release effacerait donc chaque
association existante avant que quoi que ce soit ait pu la lire. Les
colonnes partent dans la release **suivante**, quand toute installation a
exécuté la reprise au moins une fois. `drops.sql` porte l'explication à
l'endroit où les instructions seront ajoutées.

C'est exactement le séquencement dont `rental_assets.calendar_id` a eu
besoin, pour la même raison, et son `drops.sql` le documente déjà de la
même manière.

Les colonnes sont rendues **nullables** dans le même mouvement : sans ça,
un `INSERT` qui ne les nomme plus échouerait sur toute installation
existante, où elles sont `NOT NULL` sans valeur par défaut.

### Le dédoublonnage change de nom d'index, pas seulement de colonnes

`SchemaComparator` compare un index **par son nom seulement**
(`ARCHITECTURE.md` §10) : redéfinir `idx_message_dedup` sur place aurait
été un no-op silencieux sur chaque site installé. Le nouvel index unique
s'appelle donc `idx_message_box_dedup (mailbox_id, message_id_blind_index)`.
L'ancien subsiste sur les installations existantes ; il n'y gêne rien
— les colonnes qu'il couvre en plus sont désormais nulles pour toute
nouvelle ligne, et MySQL considère deux `NULL` comme distincts.

### `InboundMessage` garde ses trois champs d'angle de lecture

`$consumerId`, `$businessReference` et `$linkOrigin` ne disparaissent pas
de l'objet : ils décrivent **l'association par laquelle cette lecture a été
faite**, puisque toute méthode de l'API reste scopée à un consumer et une
référence. Ce qui s'y ajoute est `$links`, la liste complète des
associations du message. Un consumer qui a besoin de savoir ce que le
message est *par ailleurs* lit `$links` ; celui qui n'en a pas besoin n'a
rien à changer, ce qui était la condition d'« aucun changement de
comportement visible » pour cette itération.

### Le comptage rendu par `purgeReference()`

C'est un nombre d'**associations retirées**, pas de messages détruits. Un
message qu'un autre module reconnaît encore reste en place ; répondre
« rien n'a été retiré » à un appelant dont l'objet ne le porte plus serait
la mauvaise réponse à la question posée.

---

## IT-02 — Propriété des pièces jointes et cloisonnement d'accès

### Le registre de lecture du chemin web est un second registre, paresseux

`Service\InboundMessageAccessRegistry` doit interroger un consumer pour
répondre à « cette personne peut-elle télécharger cette pièce jointe ? ».
Seule une racine de composition peut fournir des consumers — mais le
graphe des consumers de synchronisation est délibérément construit en
**fabrique paresseuse** dans `public/scheduler-bootstrap.php`, pour
n'être assemblé que quand une tâche de synchronisation est réellement due,
jamais sur une vue de page.

Le construire à chaque affichage pour répondre à une question posée une
fois sur mille annulerait exactement cette précaution. `MessageConsumerRegistry`
gagne donc `registerFactory()` et `find()` : `public/index.php` enregistre
des fermetures, et seule celle que nomme une association est instanciée —
donc aucune sur une page ordinaire, et une seule sur un téléchargement.

`Tests\Modules\InboundMail\CompositionRootWiringTest` a été **affiné**, pas
affaibli : ce qu'il protégeait (le graphe de synchronisation reconstruit
dans un point d'entrée, ce qui avait déjà fait tourner le cron avec un
registre vide) est désormais exprimé comme « aucun point d'entrée ne
construit `SyncMailboxesHandler` ni n'enregistre un consumer de manière
empressée », et un nouveau test exige que le registre de lecture du chemin
web soit exclusivement à fabriques.

### Le Chef d'Unité passe toujours

La feuille de route dit « aucun lien du tout : seul le rôle `admin`
accède ». C'est un plancher, pas une interdiction ailleurs : le Chef
d'Unité tient la boîte générale d'IT-06, où il voit **tout** le courrier,
et D16 lui donne même le droit de suppression définitive. Une pièce jointe
visible à l'écran mais refusant de s'ouvrir serait une page cassée, pas
une protection. `admin` est donc accordé inconditionnellement, et c'est
documenté dans `SECURITY.md` §6.

### `canRead()` prend le rôle en `string`

Signature reprise telle quelle de la feuille de route. Elle garde le
contrat `Api\` libre d'une énumération du cœur ; les consumers convertissent
par `Role::tryFrom()`. `rental` reçoit en plus trois dépendances optionnelles
(service d'autorisation, année scoute, e-mail du demandeur) : nulles sur le
chemin planifié — une synchronisation ne télécharge rien — et fournies sur
le chemin web, ce qui est la raison même de l'enregistrement par fabrique.

### La propriété d'un fichier partagé est transmise, pas laissée pendante

La déduplication fait pointer plusieurs messages vers un même fichier
stocké, alors que `files.owner_id` n'en nomme qu'un. Quand celui-là est
détruit, `InboundMailService::deleteUnreferencedFiles()` transmet la
propriété à un message qui le détient encore. Sans ça le fichier
désignerait un message disparu, le registre ne trouverait aucune
association à interroger, et les personnes légitimes seraient enfermées
dehors.

---

## IT-03 — Contrat consumer v2

### `AnalysisResult` porte des `MessageLink`, dont le `consumerId` est ignoré

Un consumer construit ses liens avec son propre identifiant, mais
`AnalysisResultApplier` **réécrit** celui-ci avec l'id du consumer qui a
répondu. Sans ça un module pourrait classer un message sous la référence
d'un autre, et les règles d'accès d'IT-02 répondraient au sujet d'une
association que le module concerné n'a jamais faite.

### Un adaptateur de test, pas un adaptateur de production

`Tests\Modules\Camps\Mail\CampsConsumerV1Adapter` rend `claim()` et
`onMessageStored()` par-dessus le contrat v2. Le contrat a changé de forme
dans le même changement, mais le **comportement** de `camps` non — et c'est
précisément ce que la suite existante doit continuer de prouver. Réécrire
quinze appels aurait signifié réécrire les assertions autour, et une
assertion réécrite pendant un refactor ne prouve plus rien sur le refactor.
Tout ce qui est propre à v2 (propositions, `onUnlinked()`, les
déclarations d'audience) est testé contre le vrai contrat.

### La tâche différée marque *avant* de travailler

`AnalyzeStoredMessagesHandler` pose `stored_analysis_at` avant d'appeler
les consumers. Un message dont l'analyse lève ne doit pas être rejoué
indéfiniment en tête de file, bloquant tout ce qui est derrière — même
raison que le curseur de synchronisation qui avance sur un message
inutilisable.

### `findAnyForAnalysis()` est la seule lecture non scopée

Elle existe parce que la passe différée doit présenter un message à des
consumers qui ne lui sont pas encore associés — c'est tout l'objet de la
question. Elle reste sur le repository, hors de `Api\InboundMailInterface` :
l'exposer publiquement annulerait la règle du §7.11 selon laquelle l'accès
d'un gestionnaire à une location ne devient jamais une fenêtre sur la boîte
de l'unité.

### Le graphe de consumers est construit une fois pour les deux tâches

`public/scheduler-bootstrap.php` extrait la construction dans une fermeture
partagée par `SyncMailboxesHandler` et `AnalyzeStoredMessagesHandler`. Deux
copies de ce câblage inter-modules seraient deux endroits où il peut
diverger — et il diverge silencieusement : un consumer enregistré pour une
passe et oublié pour l'autre ne propose simplement jamais rien.

### `onUnlinked` de camps ne défait pas les complétions de champs

Il retire les documents que `onLinked` avait déposés sur le séjour, mais
laisse les valeurs de champs complétées depuis le message. Un chef a pu en
valider une, et revenir en silence sur une valeur validée par quelqu'un est
pire que laisser un champ rempli depuis un message qui a bougé.
