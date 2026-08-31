---
id: sauvegardes
title: Sauvegarder le site
summary: Les sauvegardes à la demande et automatiques, et leur téléchargement.
category: Configuration
role_min: admin
question: Comment sauvegarder le site avant une opération risquée ?
question: Où télécharger la dernière sauvegarde du site ?
paths: /config/maintenance
related: mises-a-jour, reinitialisation
---

Le bloc Sauvegardes de la page Maintenance protège votre site : il
produit des copies de la base de données et des fichiers, à la demande
ou automatiquement.

## Deux formes de sauvegarde

- **Base de données seule** : un export complet, généré sur-le-champ.
  Les données personnelles y restent chiffrées, mais le fichier reste
  sensible — il est réservé aux chefs d'unité.
- **Sauvegarde complète (chiffrée)** : une archive protégée par le mot
  de passe que vous choisissez, générée en arrière-plan — une
  notification vous prévient quand elle est prête. Trois portées au
  choix : la configuration seule, le site complet sans la galerie
  photo, ou avec elle.

Si votre hébergeur ne sait pas chiffrer les archives, la page le
signale : la sauvegarde de la base seule reste disponible.

## Les sauvegardes automatiques

Choisissez une fréquence (quotidienne à mensuelle) : le site génère
seul une sauvegarde complète, sans la galerie. Le site prend aussi une
sauvegarde de sécurité avant chaque mise à jour et chaque action de
réinitialisation, sans que vous ayez rien à faire.

## Conserver et télécharger

La liste du bas montre les sauvegardes récentes, leur type, leur date
et leur état ; « Télécharger » n'apparaît que sur une sauvegarde
terminée.

> Seules les cinq sauvegardes les plus récentes sont conservées, les
> automatiques comprises : les plus anciennes s'effacent d'elles-mêmes.
> Téléchargez régulièrement une copie et gardez-la hors du serveur —
> une sauvegarde qui vit sur le serveur ne protège pas d'un problème
> d'hébergement.

## Restaurer

La restauration d'une sauvegarde se fait depuis le bloc
Réinitialisation, plus bas sur la même page — voyez le sujet
« Réinitialiser ou restaurer le site », car elle remplace les données
actuelles.
