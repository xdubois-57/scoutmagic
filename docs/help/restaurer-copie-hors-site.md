---
id: restaurer-copie-hors-site
title: Restaurer une copie hors site
summary: Revenir à une copie envoyée sur la destination hors site, sans la télécharger soi-même.
category: Configuration
role_min: superadmin
discovery: off
question: Comment restaurer une copie envoyée sur Google Drive ?
question: Que veut dire « Ancienne phrase de passe » ?
paths: /config/maintenance/sauvegardes-recentes
related: restaurer, sauvegardes-distantes, phrase-de-passe-distante
---

La carte **Copies hors site**, dans « Sauvegardes récentes », montre
les copies telles que la destination les liste, avec son emplacement
réel. Elle se charge après la page : la destination peut être lente.

## Lisible ou ancienne phrase

Chaque copie porte un badge :

- **Lisible avec la phrase actuelle** : la phrase en vigueur l'ouvre,
  il n'y a rien à saisir ;
- **Ancienne phrase de passe** : elle a été chiffrée avant un changement
  de phrase, et il faut saisir celle de l'époque.

## Restaurer une copie

Cliquez sur **Restaurer**, tapez RESTAURER, puis confirmez. Le site
rapatrie la copie par morceaux, vérifie la phrase, puis restaure comme
pour un fichier téléversé : copie de sécurité d'abord, liste des
sauvegardes et journal d'aujourd'hui gardés. Vous pouvez fermer la page
pendant le rapatriement.

Si la phrase est fausse, rien n'est modifié : la copie rapatriée est
gardée un jour, le temps de ressaisir la phrase sans tout rapatrier à
nouveau.

S'il manque de place sur le serveur, le site refuse tout de suite et dit
combien il en faut.

## Qui peut écrire dans la destination

Réservez l'écriture dans le dossier de destination au seul compte qui
sert au site. Une personne qui peut y écrire, sans connaître la phrase
de passe, peut glisser des fichiers dans une copie : retirez le partage
à un ancien animateur dès son départ.

## Une copie modifiée est refusée

Chaque fichier d'une copie est authentifié par sa phrase de passe : une
copie à laquelle quelqu'un a ajouté ou remplacé un fichier est refusée,
sans rien modifier. Réservez tout de même l'accès en écriture à la
destination hors site aux personnes qui en ont besoin.

Une copie faite par une version de ScoutMagic antérieure à cette
protection ne peut plus être restaurée : faites-en une nouvelle depuis
le site, tant qu'il est en service.

## Sur une installation neuve

Téléchargez une copie depuis la destination, puis déposez-la dans
« Restaurer une sauvegarde » avec la phrase de passe notée (« Remonter le
site après un sinistre »).
