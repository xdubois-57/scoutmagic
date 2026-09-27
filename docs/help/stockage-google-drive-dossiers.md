---
id: stockage-google-drive-dossiers
title: Les dossiers du site dans votre Drive
summary: Où le site range ses fichiers dans Google Drive, ce que contient le fichier LISEZMOI d'un album, et ce qui arrive au dossier quand l'emplacement est renommé ou supprimé.
category: Configuration
role_min: superadmin
question: Où sont rangées les photos dans mon Google Drive ?
question: À quoi sert le fichier LISEZMOI dans chaque dossier d'album ?
question: Que devient le dossier Drive quand je supprime l'emplacement ?
paths: /config/stockage/emplacements
related: stockage-google-drive, sauvegarde-hors-site, stockage
---

Chaque emplacement Google Drive a **son propre dossier**, et tous se
rangent sous un même dossier **ScoutMagic**, en haut de votre Drive.

## L'arborescence

Le dossier d'un emplacement porte le **nom de l'emplacement**. Dans celui
des galeries, chaque album a un sous-dossier qui porte son **numéro** :

- **ScoutMagic**
  - **Photos des galeries**
    - **5** — les photos de l'album 5, en plusieurs tailles, et son
      fichier **LISEZMOI.txt**
    - **12**
    - **.scoutmagic** — les fichiers techniques du site
  - **Sauvegardes hors site** — les archives de sauvegarde

Le numéro ne change jamais : renommer un album ne déplace rien.

## Le fichier LISEZMOI

Un dossier numéroté ne dit pas de quel album il s'agit. Le fichier
**LISEZMOI.txt** de chaque album le dit : son nom, la date de l'activité
et l'adresse de l'album sur le site. Le site le récrit quand le nom ou la
date de l'album changent, et l'emporte quand l'album change d'emplacement.

Ce fichier est écrit sur tous les types d'emplacement, pas seulement sur
Drive : il rend le même service dans un Nextcloud ou sur le disque du
serveur.

## N'y touchez pas

> Ne renommez pas ces dossiers, n'y déplacez rien et n'y déposez rien.
> Le site retrouve chaque fichier par son dossier : une photo déplacée
> devient introuvable pour lui, et un fichier que vous ajoutez lui reste
> invisible.

Le dossier d'un emplacement fait exception pour son nom : le site le
retrouve par son identifiant, pas par son nom. Mais inutile de le
renommer vous-même : renommez plutôt l'emplacement dans **Modifier**, et
le dossier suit.

## Quand l'emplacement est supprimé

Le bouton **Supprimer** place le dossier de l'emplacement dans la
**corbeille** de Google Drive, avec tout ce qu'il contient. Vous pouvez
l'en sortir pendant 30 jours ; ensuite Google l'efface définitivement.
La suppression reste refusée tant qu'un usage s'appuie sur
l'emplacement.

Si Google ne répond pas, l'emplacement est tout de même supprimé : le
message le dit, et le dossier reste en place dans votre Drive.

## Un emplacement raccordé avant ce rangement

Un emplacement Drive raccordé avant cette organisation écrit encore dans
l'ancien dossier unique, que le site ne sait plus lire. Rien n'est
déplacé automatiquement : créez un nouvel emplacement et raccordez-le.
Supprimer l'ancien placera son dossier dans la corbeille : récupérez-y
d'abord ce que vous voulez garder.
