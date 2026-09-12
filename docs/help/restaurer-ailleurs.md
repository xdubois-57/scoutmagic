---
id: restaurer-ailleurs
title: Remonter le site après un sinistre
summary: La marche à suivre complète quand l'hébergement est perdu et qu'il faut repartir d'une sauvegarde portable.
category: Configuration
role_min: admin
discovery: off
question: Mon hébergeur a perdu mon site, comment je repars ?
question: Comment restaurer une sauvegarde portable sur une installation neuve ?
paths: /config/maintenance
related: sauvegarde-portable, sauvegardes, reinitialisation
---

Vous avez une sauvegarde portable et sa phrase de passe. Voici la suite.

## Ce qu'il vous faut

L'archive `.zip`, la phrase de passe notée en la créant, et un
hébergement — le même remis à neuf, ou un autre. Sans la phrase de
passe, l'archive est définitivement illisible : personne, ici compris,
ne peut la rouvrir.

## Installez ScoutMagic, puis arrêtez-vous

Installez ScoutMagic normalement sur le nouvel hébergement, jusqu'à
l'assistant de configuration. Créez une base de données **vide** et
saisissez ses identifiants comme d'habitude, puis cliquez sur
**Installer la base de données**.

**Ne remplissez pas la suite du formulaire.** Le nom de l'unité, le
compte administrateur, la configuration des emails : tout cela est déjà
dans votre sauvegarde, et ce que vous saisiriez serait écrasé quelques
secondes plus tard.

## Restaurez

Juste en dessous, le bloc **Repartir d'une sauvegarde portable**
attend. Choisissez l'archive, tapez la phrase de passe, cliquez sur
**Restaurer cette sauvegarde**, et laissez la page ouverte : sur une
grosse archive, l'envoi puis la restauration prennent plusieurs
minutes.

Quand c'est terminé, connectez-vous avec vos identifiants habituels.
Ce sont ceux d'avant : les comptes font partie de ce qui a été
restauré.

## Ce qui change, et pourquoi

Les identifiants de la base de données restent **ceux que vous venez de
saisir**, jamais ceux de l'ancien hébergeur — sinon le site neuf
pointerait vers une base qui n'existe plus, ou pire, vers celle de
quelqu'un d'autre.

Les abonnements aux notifications push sont vidés. Ils étaient liés à
l'ancienne adresse du site et n'auraient plus jamais fonctionné ;
chacun les réactivera depuis son navigateur.

Le site reçoit une nouvelle identité technique, tout en gardant trace
de celle qu'il remplace, pour qu'un déménagement ne se lise pas comme
un abandon.

## Si le site refuse

Il vous dira lequel des trois cas vous êtes :

- **la phrase de passe ne correspond pas** — c'est la phrase, pas le
  fichier ;
- **ce n'est pas une sauvegarde portable** — une sauvegarde complète
  ordinaire n'emporte pas les clés du site, donc elle ne se remonte pas
  telle quelle ailleurs : il faudrait y recopier aussi `storage/keys/`
  et `storage/config/` ;
- **l'archive vient d'une version plus récente** — mettez d'abord à
  jour ce site, puis recommencez.

Dans les trois cas, rien n'a été modifié.

## Et après

Refaites une sauvegarde portable : celle qui vient de servir contient
l'état d'avant le sinistre.
