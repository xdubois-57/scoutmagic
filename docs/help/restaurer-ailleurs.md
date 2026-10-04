---
id: restaurer-ailleurs
title: Remonter le site après un sinistre
summary: La marche à suivre complète quand l'hébergement est perdu et qu'il faut repartir d'une sauvegarde portable.
category: Configuration
role_min: superadmin
discovery: off
question: Mon hébergeur a perdu mon site, comment je repars ?
question: Comment restaurer une sauvegarde portable sur une installation neuve ?
paths: /config/maintenance/sauvegarde-manuelle
related: sauvegarde-portable, sauvegardes, reinitialisation
---

Vous avez une sauvegarde portable et sa phrase de passe. Voici la suite.

## Ce qu'il vous faut

L'archive `.zip`, la phrase de passe notée en la créant, et un
hébergement en HTTPS — le même remis à neuf, ou un autre. Sans la
phrase, l'archive est définitivement illisible : personne ne peut la
rouvrir.

## Installez avec la sauvegarde

Déposez `bootstrap.php` par FTP et ouvrez-le. Recopiez le jeton de
`token.php` (lu par FTP), puis laissez-le vérifier l'accès HTTPS.

Choisissez ensuite votre sauvegarde. Seul son en-tête est lu, par votre
navigateur : son site d'origine, sa date et sa **version**. Le bootstrap
installe cette version-là, pas la dernière, puis envoie l'archive par
fragments, en reprenant là où il s'était arrêté si la connexion tombe.

## Restaurez dans l'assistant

L'assistant s'ouvre en mode restauration, sur la **Sauvegarde
déposée**. Tapez la phrase de passe et faites-la vérifier, puis
installez une base de données **vide**, et cliquez sur **Restaurer
cette sauvegarde**. Laissez la page ouverte.

Quand c'est terminé, connectez-vous avec vos identifiants habituels :
les comptes font partie de ce qui a été restauré. **Mettez ensuite le
site à jour** par Configuration › Maintenance : il avance d'une version
majeure à la fois, avec sa sauvegarde de sécurité.

L'archive reste sur le serveur jusqu'à la restauration ; **Abandonner
cette sauvegarde** la supprime, sinon elle l'est au bout de sept jours.
Trop grosse pour le navigateur ? Installez sans sauvegarde, déposez-la
par FTP sous `storage/restore/portable-restore.zip`, puis rechargez
l'assistant.

## Ce qui change, et pourquoi

Les identifiants de la base de données restent **ceux que vous venez de
saisir**, jamais ceux de l'ancien hébergeur.

Les abonnements aux notifications push sont vidés : ils étaient liés à
l'ancienne adresse. Chacun les réactivera depuis son navigateur.

Le site reçoit une nouvelle identité technique, tout en gardant trace
de celle qu'il remplace.

## Si le site refuse

Il dit lequel de ces cas vous êtes :

- **la phrase de passe ne correspond pas** — retapez-la, l'archive est
  toujours là ;
- **ce n'est pas une sauvegarde portable** — une sauvegarde complète
  ordinaire n'emporte pas les clés du site ;
- **l'archive vient d'une version plus récente**, ou son en-tête ne
  correspond pas à son contenu — elle a été modifiée, ou ce n'est pas la
  bonne installation.

Dans tous ces cas, rien n'a été modifié.

## Et après

Refaites une sauvegarde portable : celle qui vient de servir contient
l'état d'avant le sinistre.
