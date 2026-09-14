---
id: stockage-copie-de-secours
title: La copie de secours d'un emplacement
summary: Ce qui protège les fichiers d'un emplacement, puisque les sauvegardes ne les reprennent pas.
category: Configuration
role_min: superadmin
question: Comment sauvegarder les photos de la galerie ?
question: Qu'est-ce que le délai de grâce ?
question: Comment récupérer des fichiers perdus après une restauration ?
paths: /config/stockage/emplacements, /config/stockage
related: stockage, stockage-apres-un-sinistre, sauvegardes
---

Les archives de sauvegarde ne reprennent le contenu d'aucun emplacement
de stockage. Ce qui protège un emplacement, c'est **un autre
emplacement** : vous en désignez un, et le site y recopie chaque nuit ce
qui manque.

Cela se déclare sur la fiche de l'emplacement à protéger, dans
Configuration › Stockage › Emplacements, sous « Copie de secours ».

## Les trois réglages

- **La destination** — l'emplacement qui recevra la copie. Une même
  destination peut protéger plusieurs sources ; une source n'a qu'une
  destination.
- **Le délai de grâce** — combien de temps un fichier disparu de la
  source est encore gardé dans la copie. C'est ce délai qui fait la
  différence entre une copie et un miroir : un album supprimé par erreur
  ce matin est encore là demain.
- **La cadence** — toutes les combien d'heures la copie se met à jour.

## Ce que le site refuse, et pourquoi

Un emplacement ne peut pas être sa propre copie, et une chaîne ne peut
pas revenir sur elle-même : chaque passe recopierait la copie de
l'autre, indéfiniment.

Une destination qui distribue des adresses publiques et permanentes ne
peut pas protéger une source qui n'en distribue pas. La copie
deviendrait lisible par quiconque en a l'adresse, alors que l'original
ne l'est pas.

## Le délai de grâce et vos sauvegardes

Le site vous dit l'âge de votre plus ancienne sauvegarde restaurable.
**Choisissez un délai au moins aussi long.** Sinon, restaurer cette
sauvegarde ferait réapparaître des albums dont les fichiers auraient
déjà été effacés de la copie : les fiches reviennent, les photos non.

## Quand quelque chose disparaît en masse

Si une grande partie de la source disparaît d'un coup, le site
**s'arrête et vous prévient** au lieu de conclure. C'est presque
toujours le signe que la source ne répond plus correctement — des
identifiants expirés, un montage tombé — et non que les fichiers ont été
supprimés. Rien n'est effacé de la copie tant que vous n'avez pas
vérifié.

## Rapatrier depuis la copie

Après avoir restauré une sauvegarde, la base connaît des albums dont les
fichiers ont été supprimés depuis. Le bouton **« Rapatrier depuis la
copie »** remet en place ceux que la copie a encore.

Rien n'est écrasé : seuls les fichiers réellement absents de la source
sont remis. Le travail se fait en arrière-plan, et le journal dit
combien ont été récupérés.
