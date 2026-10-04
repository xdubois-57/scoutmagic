---
id: sante-hebergement
title: Santé de l'hébergement
summary: La page d'accueil de la maintenance : tout ce dont le site dépend chez l'hébergeur, ce qui cesse de marcher sans chaque élément, et quoi lui demander.
category: Configuration
role_min: superadmin
question: Comment savoir si la tâche cron du site tourne ?
question: Que demander à mon hébergeur quand une fonction ne marche pas ?
paths: /config/maintenance
related: mises-a-jour, actions-planifiees, installation-serveur
---

La maintenance du site est rangée en six pages, reliées par la rangée
d'onglets en haut de l'écran : Santé de l'hébergement, Mise à jour,
Sauvegarde manuelle, Sauvegarde automatique, Sauvegardes récentes et
Réinitialisation. Cette page-ci est la première, celle qui s'ouvre
depuis le menu Configuration.

## Une ligne par dépendance

Chaque ligne dit trois choses : si l'élément répond, ce qui cesse de
marcher sans lui, et — quand il manque — ce qu'il faut demander à votre
hébergeur. Un bandeau en haut compte les lignes à régler.

- **Tâche cron** — le site a besoin que l'hébergeur le réveille
  régulièrement. Sans elle, rien ne se passe en dehors des visites : ni
  sauvegarde automatique, ni mise à jour, ni rappel, ni notification, et
  aucune erreur ne le signale. Si elle n'a jamais été détectée, ou plus
  depuis un moment, la page affiche la ligne exacte à ajouter dans la
  rubrique « Tâches planifiées » ou « Cron ». Le mot `php` au début de la
  ligne est obligatoire.
- **Exécution de commandes**, deux lignes : le PHP qui répond aux
  visiteurs et celui du cron peuvent avoir des droits différents. La
  vidéo dépend de celui du cron, vérifié par le cron au plus toutes les
  dix minutes ; avant la première vérification, l'état est « pas encore
  vérifié », pas « absent ».
- **ffmpeg et ffprobe** — sans eux, la galerie et les groupes refusent
  les vidéos. La ligne recopie l'erreur exacte à transmettre.
- **Compression des PDF** — sans outil, les PDF ne sont pas compressés ;
  rien n'est refusé.
- **Chiffrement des archives** — sans lui, la sauvegarde complète et la
  sauvegarde portable sont indisponibles.
- **libsodium** — sans elle, les sauvegardes portables se chiffrent
  quand même, mais moins solidement : la ligne est orange plutôt que
  rouge.
- **GD** — sans elle, aucune image n'est transformée (vignettes, icônes
  de l'application, photos de section).
- **Courrier entrant (IMAP)** — les extensions de la relève du courrier.
- **PHP, base de données, écriture dans `storage/`** — la version de PHP,
  le moteur et la version de la base, et la possibilité d'enregistrer
  des fichiers.

## Ce que la page ne montre pas

L'espace disque se lit volume par volume sur Configuration › Stockage :
un second chiffre ici serait une seconde réponse à la même question.
L'état des mises à jour automatiques, et une dernière tentative
échouée, sont sur la page Mise à jour.

## Une migration restée incomplète

Si une mise à jour a dû abandonner une modification de la base de
données, un bandeau rouge le signale en haut de chacune des pages de
maintenance, avec les instructions concernées. Il disparaît de lui-même
dès qu'une migration suivante se termine sans erreur.
