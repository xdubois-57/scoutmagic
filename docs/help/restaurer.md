---
id: restaurer
title: Restaurer une sauvegarde
summary: Revenir à l'état d'une sauvegarde de ce serveur, ou d'une archive téléversée.
category: Configuration
role_min: superadmin
discovery: off
question: Comment restaurer le site à partir d'une sauvegarde ?
question: Faut-il un mot de passe pour restaurer une sauvegarde ?
paths: /config/maintenance/sauvegardes-recentes
related: sauvegardes-conserver, sauvegardes, restaurer-ailleurs
---

La restauration se fait dans **Sauvegardes récentes**, sous la liste :
c'est à cela que sert une sauvegarde. Elle remplace la base de données
et les fichiers actuels par ceux de la sauvegarde choisie.

## Depuis ce serveur

Choisissez une sauvegarde dans la liste déroulante, tapez RESTAURER,
puis confirmez. **Aucun mot de passe n'est demandé** : le site connaît
celui qu'il a généré pour chacune de ses archives.

Les sauvegardes portables n'y figurent pas : elles servent à repartir
sur une installation neuve, pas à écraser celle-ci — voyez « Remonter
le site après un sinistre ».

## Depuis un fichier téléversé

Pour une archive venue d'ailleurs, choisissez « Depuis un fichier
téléversé » : le champ du mot de passe apparaît alors. C'est celui que
vous avez noté en téléchargeant l'archive. Un gros fichier s'envoie
automatiquement par morceaux.

## Avant de confirmer

Le site prend d'abord une sauvegarde de sécurité, et revient seul en
arrière si la restauration échoue. Mais tout ce qui a été fait
**après** la date de la sauvegarde choisie sera perdu : inscriptions,
messages, modifications de contenu. Vérifiez la date avant de
confirmer.
