---
id: sauvegardes-conserver
title: Conserver et supprimer les sauvegardes
summary: Combien de sauvegardes le site garde, et comment en télécharger ou en supprimer une.
category: Configuration
role_min: admin
discovery: off
question: Où télécharger la dernière sauvegarde du site ?
question: Combien de sauvegardes le site conserve-t-il ?
paths: /config/maintenance
related: sauvegardes, mises-a-jour, reinitialisation
---

La section « Sauvegardes récentes » de la page Maintenance montre ce
que le site a gardé, et c'est de là qu'on télécharge ou supprime une
sauvegarde.

## La liste

« Sauvegardes récentes » les liste toutes, la plus récente en premier,
avec leur type, leur date, leur taille et leur état. « Voir plus »
révèle les lignes au-delà des cinq premières.

Le site en garde **trois de chaque sorte**, comptées séparément : les
vôtres et les planifiées — une série de mises à jour n'efface donc plus
la sauvegarde que vous veniez de faire. Une seule archive contenant la
galerie est gardée, toutes sortes confondues : c'est la plus lourde. Les
sauvegardes prises avant une opération contiennent la galerie, donc une
seule est conservée. Ces nombres se règlent dans Configuration ›
Réglages.

> Téléchargez régulièrement une copie et gardez-la hors du serveur —
> une sauvegarde qui vit sur le serveur ne protège pas d'un problème
> d'hébergement.

« Supprimer » retire une sauvegarde après une confirmation qui la
nomme. Celle prise **avant une opération** est ce depuis quoi le site
revient en arrière seul : tant que cette opération tourne, la
suppression est refusée et le site dit pourquoi.
