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

## L'intégrité

Le site relit régulièrement les sauvegardes qu'il garde et compare leur
contenu à ce qui avait été écrit. Une ligne peut donc porter :

- **Vérifiée** — relue, identique à l'original ;
- **Illisible** — le fichier est là mais son contenu a changé, le plus
  souvent une archive tronquée par un disque plein. Elle ne se restaurera
  pas : supprimez-la et créez-en une nouvelle tout de suite ;
- **Fichier absent** — le fichier n'est plus sur le serveur. Le site ne
  l'a pas supprimé, une suppression retire la ligne en même temps ;
- **Non vérifiable** — sauvegarde antérieure à cette vérification, sans
  empreinte à laquelle la comparer. Les suivantes en ont une.

Une ligne sans mention n'a pas encore été relue : la vérification prend
quelques sauvegardes par nuit plutôt que toutes d'un coup.

## Supprimer

« Supprimer » retire une sauvegarde après une confirmation qui la
nomme. Celle prise **avant une opération** est ce depuis quoi le site
revient en arrière seul : tant que cette opération tourne, la
suppression est refusée et le site dit pourquoi.
