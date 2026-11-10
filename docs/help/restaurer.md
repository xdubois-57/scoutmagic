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

Ensuite, toutes les personnes connectées doivent se reconnecter, vous compris.

## Depuis ce serveur

Choisissez une sauvegarde, tapez RESTAURER, puis confirmez. **Aucun mot de passe n'est demandé** : le site connaît
celui qu'il a généré pour chacune de ses archives. Seule une sauvegarde
complète d'avant les mots de passe générés demande encore celui que vous
aviez choisi : le champ apparaît quand vous la sélectionnez.

La liste ne propose que ce qui peut être restauré : ni les portables,
qui servent à repartir sur une installation neuve (« Remonter le site
après un sinistre »), ni les exports « Base de données », ni une
sauvegarde dont un fichier a quitté le serveur. Si un fichier disparaît
entre-temps, la restauration est refusée avant de rien modifier.

Une sauvegarde « Configuration seule » ne ramène que les réglages et
l'état des modules : les membres, les finances et tout le reste ne
bougent pas. La phrase sous la liste le rappelle.

## Depuis un fichier téléversé

Pour une archive venue d'ailleurs, choisissez « Depuis un fichier
téléversé » : le champ du mot de passe apparaît alors. C'est celui que
vous avez noté en téléchargeant l'archive. Un gros fichier s'envoie
automatiquement par morceaux, et une barre montre la part déjà envoyée.

L'archive est ensuite vérifiée avec ce mot de passe, avant que rien ne
commence. S'il est faux, le site le dit aussitôt, supprime le fichier
et ne lance rien : choisissez-le à nouveau.

## Une seule restauration à la fois

Tant qu'une restauration attend ou se déroule, le formulaire n'en
propose pas d'autre.

## Les emplacements de stockage

Une restauration ne ramène pas les **emplacements de stockage** à leur
état d'avant : les fichiers écrits depuis la sauvegarde sont restés dans
le dossier Drive, le partage ou le disque d'aujourd'hui. Le site garde
donc les emplacements tels qu'ils étaient juste avant la restauration :

- un emplacement modifié depuis la sauvegarde garde sa liaison actuelle
  (dossier, identifiants et nom) ;
- un emplacement créé depuis la sauvegarde est conservé ;
- un emplacement que seule la sauvegarde connaît revient, mais sa cible
  n'a pas été vérifiée.

Chacun de ces emplacements porte un avertissement sur la page des
emplacements. Vérifiez-le, puis enregistrez-le depuis **Modifier** : le
message disparaît. Rien n'est créé, déplacé ni supprimé dans le stockage
lui-même.

## Ce qui reste celui d'aujourd'hui

La liste des sauvegardes, le journal et la destination hors site ne
reviennent pas en arrière. Un envoi hors site en cours est interrompu,
et les tâches planifiées repartent de zéro.

## Avant de confirmer

Le site prend d'abord une copie « Avant restauration », et revient seul
en arrière si la restauration échoue. Pour annuler une restauration
réussie, cliquez sur « Annuler cette restauration » sous cette copie.
Tout ce qui a été fait **après** la date choisie sera perdu :
inscriptions, messages, contenus. Vérifiez la date.
