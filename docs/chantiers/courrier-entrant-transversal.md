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
