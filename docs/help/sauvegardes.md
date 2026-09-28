---
id: sauvegardes
title: Sauvegarder le site
summary: Les sauvegardes à la demande et automatiques, et l'espace disque qui les décide.
category: Configuration
role_min: superadmin
discovery: off
question: Comment sauvegarder le site avant une opération risquée ?
question: Quelle sauvegarde choisir ?
question: Pourquoi ma sauvegarde est-elle refusée faute de place ?
paths: /config/maintenance/sauvegarde-manuelle, /config/maintenance/sauvegarde-automatique
related: sauvegarde-portable, sauvegardes-conserver, restaurer, mises-a-jour
---

Le bloc « Sauvegarde manuelle » de la page Maintenance copie la base de
données et les fichiers à la demande ; « Sauvegarde automatique », juste
en dessous, fait la même chose seul. Les blocs de cette page arrivent
repliés : cliquez sur un titre pour l'ouvrir.

## L'espace disque, avant tout le reste

Ce que le site occupe, volume par volume, et sur quoi chaque pourcentage
est calculé se lit sur Configuration › Stockage, une page réservée au
super-administrateur. C'est la contrainte qui décide si les boutons de
sauvegarde vont fonctionner : une sauvegarde qui n'a pas la
place d'aller au bout est refusée d'emblée, avec le nombre d'octets qui
manquent — mieux vaut un refus net qu'une archive tronquée que
personne ne découvrira avant d'en avoir besoin.

Tant que vous n'avez rien déclaré, le site ne peut mesurer que le
volume de votre hébergeur : il est partagé avec d'autres comptes et
bien plus grand que votre part, donc le pourcentage ne vous concerne
pas vraiment. Renseignez « Quota disque déclaré » dans Configuration >
Réglages, d'après votre contrat d'hébergement — « 10 Go » ou « 500 Mo »
suffisent comme écriture — et le calcul portera sur votre espace à
vous.

## Quatre portées, un seul bouton

Choisissez ce que vous sauvegardez, puis « Lancer la sauvegarde ». Elle
se fait en arrière-plan : une notification vous prévient quand elle est
prête, et elle apparaît dans « Sauvegardes récentes », avec son mot de
passe à côté du téléchargement. Notez-le.

- **Configuration seule** : les paramètres et la structure, sans aucune
  donnée de membre.
- **Site complet** : tout, sans les clés du site ; se restaure ici,
  reste illisible ailleurs.
- **Base de données seule** : l'export SQL, sans les fichiers. Les
  données personnelles y restent chiffrées.
- **Sauvegarde portable** : tout, clés comprises. La seule qui se
  restaure sur une installation neuve ; le sujet « Emporter le site
  ailleurs » explique ses règles.

Si votre hébergeur ne sait pas chiffrer les archives, la page le dit :
les trois premières portées sont enregistrées en clair, la portable est
indisponible, et l'avertissement dit quoi demander à l'hébergeur.

## Les sauvegardes automatiques

La partie « Sur ce serveur » de « Sauvegarde automatique » porte la
fréquence : le site génère seul une sauvegarde complète. Ces
sauvegardes-là ne sont pas portables : elles restent sans les clés. Il prend aussi une sauvegarde de sécurité avant chaque mise à
jour et chaque action de réinitialisation, sans que vous ayez rien à
faire.

Cette fréquence ne décide que de ce qui reste sur le serveur : les
envois hors site ont la leur, et « Ce que le site envoie sur Drive »
l'explique.

Ce qui devient de ces copies — combien le site en garde, comment les
télécharger et les supprimer — est le sujet « Conserver et supprimer
les sauvegardes ».

## Restaurer

La restauration se fait dans « Sauvegardes récentes », sous la liste —
voyez le sujet « Restaurer une sauvegarde ». Une sauvegarde de ce
serveur se restaure sans mot de passe.
