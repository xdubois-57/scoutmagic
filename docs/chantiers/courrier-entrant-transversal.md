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

---

## IT-04 — Conservation, quota, et ce que « détacher » veut dire

### Le détachement ne détruit plus

C'est le changement le plus lourd de l'itération, et il n'était pas
explicitement demandé : il est la conséquence forcée du reste.

Avant, `detach()` supprimait le message dès que la dernière association
partait, faute de file d'attente où le laisser tomber. Or un détachement
est presque toujours une **correction** — quelqu'un s'aperçoit que le
message est classé sous la mauvaise réservation. L'ancien comportement
détruisait donc exactement ce qu'on s'apprêtait à reclasser : la correction
supprimait ce qu'elle corrigeait.

Maintenant que tout est conservé, la file d'attente existe. `detach()`
retire l'association, et rien d'autre. Le message retombe dans le courrier
général, `PurgeUnlinkedMessagesHandler` s'en charge si personne ne le
réoriente, et `last_unlinked_at` lui donne un plancher de trente jours (A4)
pour qu'un clic malheureux ait une fenêtre où être remarqué.

`purgeReference()` reste destructeur, et la distinction est le fond du
sujet : c'est l'effacement RGPD d'un objet métier par le module qui le
possède, où la promesse faite à la personne concernée est que le courrier
attaché à son dossier part avec son dossier. Un délai de conservation ne
s'applique pas à un effacement demandé.

### Conséquence non évidente : la pièce jointe reclassée

Une pièce jointe qu'un gestionnaire a transformée en document de son
module partage la ligne `files` du message (ARCHITECTURE.md §8.3). Sous
l'ancien modèle, `$preserveFileIds` suffisait : le message mourait tout de
suite, on épargnait le fichier, fin. Sous le nouveau, la purge de rétention
passe quatre-vingt-dix jours plus tard et supprime les fichiers que les
lignes de pièces jointes désignent encore — **elle emporterait le contrat
signé d'une réservation avec l'email dans lequel il est arrivé**.

Deux corrections, indissociables :

1. `releaseAttachmentFile()` — la ligne de pièce jointe cesse de désigner
   le fichier et dit pourquoi (`AttachmentOmission::RECLASSIFIED`). La
   ligne reste : l'écran doit toujours pouvoir dire que le message portait
   ce fichier. C'est ce qui garde la purge honnête sans lui apprendre les
   tables de documents de chaque module — ce qui remettrait la connaissance
   des consumers à l'intérieur d'`inbound_mail`, la seule chose que §8.58
   interdit.
2. Le consumer reprend `files.owner_id`. `RentalCommunicationService`
   repointe le fichier sur `rental_document` / l'id de la réservation.
   Sans cela le fichier continuerait de répondre à un contrôle d'accès
   portant sur un message que les gestionnaires de la réservation ne voient
   plus : ils perdaient l'accès à leur propre contrat. Ce défaut existait
   déjà avant l'itération — le message était détruit, `owner_id` pendait
   dans le vide et seul un chef d'unité passait — il devient simplement
   visible ici.

### `AttachmentOmission::RECLASSIFIED` dans le même enum

Un fichier reclassé n'est pas une pièce jointe « écartée » : il a bien été
conservé, il a changé de propriétaire. Il partage néanmoins l'enum parce
que l'écran pose une seule question — pourquoi ce message n'offre-t-il plus
ce fichier ? — et qu'une seconde liste ne serait qu'un second endroit à
oublier. `explanation()` bifurque pour ce cas : la phrase générique renvoie
le lecteur vers la boîte d'origine, ce qui serait un mensonge dans l'autre
sens pour un fichier que ScoutMagic détient toujours.

### Camps : « supprimé » devenait un mensonge

`CampsMailController::discard()` affichait « Message supprimé. » et
`PurgeUnsortedMailHandler` journalisait « effacé(s) ». Ni l'un ni l'autre
n'efface plus quoi que ce soit : les deux retirent une association et le
message retombe dans le courrier général. Les libellés disent maintenant
« retiré du courrier non classé ». La garantie d'effacement n'a pas
disparu, elle a changé de module — et les tests de la tâche camps assertent
désormais sur l'association, pas sur la ligne, sous peine d'asserter sur la
rétention d'un autre module.

### RGPD (A19, bloquant)

Trois passages de `core/View/rgpd_default.html` étaient devenus faux et ont
été réécrits : « un message qu'aucun dossier ne reconnaît est ignoré »,
la conservation de la section 2.4, et la ligne « Courrier entrant » de la
section 3.1. La règle 29 du prompt système de `RgpdContentService` interdit
maintenant explicitement de réintroduire l'ancienne formulation, et impose
les trois garanties comme un bloc indissociable — écran, rétention,
responsable — parce que c'est précisément ce qui rend l'archive
défendable. Le paragraphe du module Locations sur le détachement, qui
promettait une suppression immédiate, a été corrigé de la même façon.

### Reprise camps (A8) lue à chaud plutôt que migrée

`PurgeUnlinkedMessagesHandler::retentionDays()` lit
`camps_unsorted_retention_months` à chaque exécution au lieu d'écrire 180
une fois pour toutes. Une unité qui n'ouvre jamais le nouveau réglage garde
les six mois qu'elle avait choisis ; une qui l'ouvre trouve un champ déjà
rempli plutôt qu'une valeur par défaut qui aurait raccourci sa rétention en
silence. Une installation neuve démarre à 90 jours.

### Le quota est vérifié avant chaque écriture, pas à la purge

`poor_mans_cron` n'avance que sur les vues de page. Un plafond appliqué par
une tâche nocturne laisserait un après-midi chargé remplir le disque de
l'hébergeur. `StorageQuotaService::accepts()` est donc interrogé avant
chaque écriture de pièce jointe, avec la taille que l'écriture ajouterait —
une vérification faite après coup est une vérification qui a déjà laissé
passer.

L'horodatage de la dernière alerte (`inbound_mail_quota_alerted_at`) est
écrit par `SettingRepository::updateValue()` et non par
`SettingService::set()` : c'est de la comptabilité interne, déclarée non
éditable pour ne jamais apparaître sur la page Réglages, et `set()` refuse
par construction un réglage non éditable.

## IT-05 — les libellés des trois modes

### Deux questions, jamais une

L'écran v1 posait une case à cocher et trois boutons radio dont les sens se
recouvraient. La v2 sépare franchement : **analyser** (ce module
reconnaît-il ce courrier ?) et **qui peut lire** (ses utilisateurs
obtiennent-ils une liste, et jusqu'où ?). Ce sont deux pouvoirs différents —
un module peut très bien rattacher un message à une réservation sans que ses
utilisateurs aient à voir le reste de la boîte.

`MailboxScope::effectiveReadMode()` fait respecter la dépendance dans le
seul sens qui existe : un module qui n'analyse pas ne lit pas. Les deux
colonnes sont écrites indépendamment, et « personne ne classe ce courrier
mais tout le monde peut le lire » n'est pas un état que l'écran peut
produire ni que quiconque a voulu.

### L'usage est stocké, pas déduit

Une boîte dédiée s'exprime pourtant en termes de portées : un module qui
analyse avec `ReadMode::ALL`, les autres éteints. On aurait donc pu la
déduire. On ne le fait pas : le premier resserrement manuel d'une portée
ferait taire l'aveu « dédiée » sans que personne comprenne pourquoi la page
s'est réorganisée.

Inversement, les portées d'une boîte dédiée sont **calculées** à partir de
l'usage (`Mailbox::impliedScopes()`) et non relues en base. Une ligne
périmée — laissée par une boîte autrefois partagée — ressusciterait sinon un
module que l'exploitant a précisément mis dehors.

### L'absence de ligne est une réponse

« Ne rien faire ». Un module installé après la configuration d'une boîte est
inerte dessus tant que personne ne dit le contraire. Les alternatives —
hériter de ce que le précédent avait, ou démarrer sur « analyse tout » —
élargissent en silence qui voit le courrier d'une unité, à l'occasion d'une
mise à jour dont personne n'a lu les notes.

### Le périmètre est un réglage, pas une suggestion

`MessageConsumerRegistry::analyzeAll()` prend désormais la liste des
consumers autorisés. On restreint **la question**, pas les réponses : un
module ne voit jamais un message qu'il n'a pas le droit d'analyser, donc il
ne peut pas agir dessus par erreur. Filtrer après coup aurait laissé la
décision dans les mains du module et rendu l'écran consultatif.

### La garde « aucun consumer » disparaît

`MailboxSyncService` sautait la connexion quand aucun module n'était
enregistré, au motif que tout serait lu puis jeté. Depuis IT-04 tout est
conservé : une boîte que personne ne classe est exactement ce à quoi sert le
courrier général, et l'écran le dit — « Aucun module — le courrier est
conservé mais rien ne le classe ».

### Reprise camps migrée une fois, contrairement à la rétention

`camps_dedicated_mailbox_ids` est lu **une seule fois** et écrit dans le
nouveau modèle, là où `camps_unsorted_retention_months` reste lu à chaud
(IT-04). La différence n'est pas une incohérence : la rétention est une
valeur que l'unité a choisie et qu'il serait malhonnête de raccourcir ;
l'usage d'une boîte est une réponse structurelle dont le nouvel écran est
désormais propriétaire. Deux endroits déclarant une boîte dédiée finiraient
par se contredire au premier passage sur l'écran.

Le marqueur est posé même quand il n'y avait rien à migrer : « rien à faire »
est une migration terminée, et relire un réglage hérité à chaque vue de page
jusqu'à la fin de l'installation n'en est pas une.

### Le verrou de « Rafraîchir maintenant »

Le bouton lance une synchronisation **dans la requête**. Deux clics à une
seconde d'intervalle ouvriraient deux sessions IMAP sur la même boîte,
liraient les mêmes messages et se courraient après sur le curseur — et
l'écriture perdante le ferait reculer, de sorte que le run planifié suivant
relirait ce qui avait déjà été lu.

Le verrou est un réglage plutôt qu'une table : une ligne, aucun schéma, et
lisible depuis le chemin planifié aussi. Il **expire** au bout de dix
minutes, parce qu'une requête tuée par `max_execution_time` ne le libère
jamais et qu'un bouton verrouillé pour toujours est une fonctionnalité qui a
disparu en silence.

`ManualRefreshService` reçoit une **closure** et non le service de
synchronisation : construire ce graphe est la seule chose qu'une vue de page
ordinaire ne doit jamais faire, et cette classe est construite à chacune
d'elles pour que le bouton existe.

### Le script de l'écran ne contrôle rien

Les deux moitiés du formulaire sont rendues et soumises ; le serveur lit
celle que l'usage choisi désigne. `inbound-mail-scopes.js` masque ce qui ne
s'applique pas, et c'est tout — un test vérifie explicitement qu'il ne
*désactive* jamais un champ, car un radio désactivé ne soumet rien et le
serveur lirait « Personne » pour un module auquel l'exploitant n'a pas
touché.


La feuille de route se contredit sur un point, et il faut trancher avant
d'écrire l'écran.

Sa section « Vocabulaire d'interface » annonce : `none` → « Aucun tri »,
`relevant` → « Messages concernés uniquement », `all` → « Tous les messages
de la boîte ».

Mais **le corps d'IT-05 et la maquette v2 disent tous les deux autre
chose** : « un choix segmenté "qui peut le lire" à trois options présentées
au même niveau — Personne / Messages concernés / Tout le courrier ».

Ce sont ces derniers qui l'emportent, pour deux raisons. La maquette est
désignée comme faisant foi pour les libellés français ; et le corps d'IT-05,
qui décrit précisément le contrôle à construire, emploie exactement les
mêmes mots qu'elle. La section « Vocabulaire » décrit la v1 de l'écran,
celle que la v2 remplace.

Retenu, donc, pour le contrôle segmenté :

| mode | libellé |
|---|---|
| `none` | Personne |
| `relevant` | Messages concernés |
| `all` | Tout le courrier |

Les pastilles de l'index disent autre chose encore, et c'est voulu : elles
résument un état plutôt que d'offrir un choix — « classement seul »,
« messages concernés », « tout le courrier ».
