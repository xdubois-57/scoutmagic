---
id: config-sos
title: Raccorder la ligne SOS
summary: Relier le numéro d'urgence à l'opérateur téléphonique, en trois étapes.
category: Configuration
role_min: superadmin
paths: /config/sos
related: sos-staff
---

Cette page relie le site à la ligne téléphonique de l'unité, pour que
la grille de garde pilote réellement le renvoi d'appel. Le
raccordement se fait une fois, en trois étapes guidées ; l'opérateur
pris en charge aujourd'hui est OVH.

## Étape 1 — Les clés de l'application

Créez une application sur le site d'OVH (le lien est sur la page),
puis recopiez ici la clé et le secret obtenus.

> OVH n'affiche ces valeurs qu'une seule fois, au moment de la
> création : copiez-les immédiatement. Le site les conserve chiffrées
> et ne les réaffichera pas non plus.

## Étape 2 — L'autorisation

« Générer une Consumer Key » prépare la demande d'autorisation : la
page vous donne un lien à ouvrir chez OVH pour la valider, puis
« J'ai validé, vérifier » confirme le tout. Cette autorisation permet
au site de programmer les renvois d'appel — rien d'autre.

## Étape 3 — La ligne

« Récupérer mes lignes OVH » liste les lignes du compte :
sélectionnez celle qui porte le numéro SOS de l'unité. Le bandeau
rappelle ensuite la ligne raccordée.

« Tester la connexion » vérifie l'ensemble : si le test réussit, la
grille de garde de la page SOS Staff d'U pilote désormais la ligne.

## Les sections du planning

Les cases à cocher du bas choisissent quelles sections apparaissent
dans les colonnes d'activités de la grille de garde — utile pour
écarter une section sans activités classiques. La section Staff d'U
en est toujours exclue : c'est elle qui assure les gardes.

En cas de renvoi qui ne suit plus la grille, refaites « Tester la
connexion » : une autorisation expirée chez l'opérateur est la cause
la plus fréquente, et l'étape 2 se rejoue sans toucher au reste.
