---
id: sauvegardes
title: Sauvegarder le site
summary: Les sauvegardes à la demande et automatiques, et l'espace disque qui les décide.
category: Configuration
role_min: admin
discovery: off
question: Comment sauvegarder le site avant une opération risquée ?
question: Pourquoi ma sauvegarde est-elle refusée faute de place ?
paths: /config/maintenance
related: sauvegarde-portable, sauvegardes-conserver, mises-a-jour, reinitialisation
---

Le bloc « Sauvegarde manuelle » de la page Maintenance protège votre
site : il produit des copies de la base de données et des fichiers, à la
demande. Le bloc suivant, « Sauvegarde automatique », fait la même chose
seul, à la fréquence que vous choisissez.

Les blocs de cette page arrivent repliés, sauf les deux premiers
(« État » et « Mise à jour ») : cliquez sur le titre d'un bloc pour
l'ouvrir.

## L'espace disque, avant tout le reste

En tête du bloc, « Espace disque » indique ce que le site occupe et sur
quoi ce pourcentage est calculé. C'est la contrainte qui décide si les
boutons en dessous vont fonctionner : une sauvegarde qui n'a pas la
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

## Trois formes de sauvegarde

- **Base de données seule** : un export complet, généré sur-le-champ.
  Les données personnelles y restent chiffrées, mais le fichier reste
  sensible — il est réservé aux chefs d'unité.
- **Sauvegarde complète (chiffrée)** : une archive protégée par le mot
  de passe que vous choisissez, générée en arrière-plan — une
  notification vous prévient quand elle est prête. Trois portées au
  choix : la configuration seule, le site complet sans la galerie
  photo, ou avec elle.

- **Sauvegarde portable** : la même chose, plus les clés de
  chiffrement du site — la seule archive qui se restaure sur une
  installation neuve, chez un autre hébergeur. Elle a ses propres
  règles, et le sujet « Emporter le site ailleurs » les explique.

Si votre hébergeur ne sait pas chiffrer les archives, la page le
signale : la sauvegarde de la base seule reste disponible.

## Les sauvegardes automatiques

Le bloc « Sauvegarde automatique » porte la fréquence (quotidienne à
mensuelle) : le site génère seul une sauvegarde complète, sans la
galerie. Ces sauvegardes-là ne sont pas portables : elles restent sans
les clés. Il prend aussi une sauvegarde de sécurité avant chaque mise à
jour et chaque action de réinitialisation, sans que vous ayez rien à
faire.

Cette fréquence ne décide **que** de ce qui s'écrit sur votre serveur.
Elle ne règle en rien les envois vers un compte distant : ceux-là ont
leur propre cadence, dans le bloc « Sauvegarde hors site », et le sujet
« Envoyer les sauvegardes chez Google » explique pourquoi les deux ne
se rejoignent pas.

Ce qui devient de ces copies — combien le site en garde, comment les
télécharger et les supprimer — est le sujet « Conserver et supprimer
les sauvegardes ».

## Restaurer

La restauration d'une sauvegarde se fait depuis le bloc
Réinitialisation, plus bas sur la même page — voyez le sujet
« Réinitialiser ou restaurer le site », car elle remplace les données
actuelles.
