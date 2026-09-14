---
id: stockage-apres-un-sinistre
title: Après un sinistre : remettre le site sur ses fichiers
summary: La marche à suivre quand le serveur est perdu et que les fichiers sont ailleurs.
category: Configuration
role_min: superadmin
question: J'ai perdu le serveur, comment retrouver les photos ?
question: Comment restaurer le site quand les fichiers sont sur un stockage externe ?
paths: /config/stockage, /config/stockage/emplacements, /config/maintenance
related: stockage, maintenance
---

Cette page existe pour être lue **le jour où ça arrive**, pas avant.
Elle suppose le cas le plus dur : l'hébergement est perdu, et ce qui
reste est une sauvegarde de la base de données et un stockage externe
qui, lui, n'a rien perdu.

## Ce qu'il faut comprendre d'abord

Les fichiers d'un emplacement de stockage **ne sont pas dans les
archives de sauvegarde du site**. C'est délibéré : un emplacement a sa
propre vie, il peut contenir des dizaines de gigaoctets, et les
recopier dans chaque archive rendrait les sauvegardes inutilisables.

Autrement dit, après un sinistre vous avez deux choses à remettre
ensemble, et elles ne viennent pas du même endroit :

- **la base de données**, qui sait quels albums existent et quel
  fichier va avec quelle photo — elle est dans l'archive de sauvegarde ;
- **les fichiers eux-mêmes**, qui sont restés sur leur emplacement.

Le travail consiste à rebrancher l'une sur les autres.

## La marche à suivre

**1. Réinstallez le site**, à vide, sur le nouvel hébergement.

**2. Restaurez la base de données** depuis votre sauvegarde, par
Configuration › Maintenance. Si vous disposez de la sauvegarde
portable — celle qui emporte les clés de chiffrement du site — c'est
elle qu'il faut utiliser : sans les clés, les données chiffrées de
l'ancienne base restent illisibles sur la nouvelle installation.

**3. Rouvrez l'emplacement restauré** — ne le recréez pas. La base
restaurée contient déjà vos emplacements, et chaque album désigne le
sien par un numéro interne. Un emplacement **recréé** en reçoit un
nouveau : les albums restaurés désigneraient toujours l'ancien et ne
trouveraient plus leurs fichiers.

Dans Configuration › Stockage › Emplacements, retrouvez
l'emplacement, « Modifier », et ne corrigez que ce qui a changé.
Absente de la liste ? L'étape 2 n'a pas repris la base attendue.

**4. Testez-le.** « Tester » écrit un fichier témoin et le relit. Tant
qu'il n'est pas au vert, inutile d'aller plus loin.

**5. Attribuez-le à son usage.** Un emplacement déclaré ne sert encore
à rien : c'est la page du module qui décide. Pour les photos,
Configuration › Galerie, onglet « Général ».

> Ce champ ne concerne que les **nouveaux** albums. Les albums restaurés
> pointent déjà, par la base de données, vers l'emplacement où leurs
> fichiers ont toujours été — c'est pourquoi l'étape 3 corrige
> l'emplacement existant au lieu d'en créer un autre.

## Si les identifiants ont été perdus aussi

Un stockage externe se rouvre depuis la console du fournisseur :
générez de nouveaux identifiants, sans toucher au contenu. C'est la
seule étape qui ne se fait pas depuis le site.

## Si l'emplacement a une copie de secours

« Rapatrier depuis la copie », sur sa fiche, remet en place ce que la
source a perdu. Rien n'est écrasé.

## Vérifier que c'est bon

Ouvrez un album ancien. Si les photos s'affichent, la base et les
fichiers se sont retrouvés. Si l'album est vide alors que le stockage
ne l'est pas, l'emplacement déclaré à l'étape 3 ne désigne pas le même
dossier qu'avant — c'est presque toujours ça.
