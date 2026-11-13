---
id: stockage-dossier-local
title: Placer un emplacement local sur le serveur
summary: Un sous-dossier du site ou un chemin absolu, et pourquoi un dossier servi par le serveur web est refusé.
category: Configuration
role_min: superadmin
question: Comment déclarer un disque réseau ?
question: Pourquoi mon dossier est-il refusé parce qu'il est servi par le serveur web ?
question: Où placer un emplacement local quand tout le site est dans la racine web ?
paths: /config/stockage/emplacements/nouveau, /config/stockage/emplacements/*/modification
related: stockage, stockage-espace
---

Un emplacement local vise un sous-dossier du site, ou un **chemin
absolu** — un disque réseau, un second disque.

## Ce qui est refusé

Un dossier situé dans la partie que le serveur web sert lui-même est
refusé : tout ce qui y serait déposé deviendrait téléchargeable par
n'importe qui, sans aucun contrôle d'accès.

Cette partie dépend de l'installation :

- **Le site à côté de la racine web** : seul le dossier public du site
  est servi. Un dossier voisin convient.
- **Tout le site dans la racine web** : presque toute l'installation est
  servie. Seuls `storage/` et les dossiers internes du site (`core/`,
  `modules/`, `config/`, `vendor/`…) sont protégés. Choisissez alors un
  dossier sous `storage/`, ou hors de l'installation.

## Un emplacement déjà enregistré dans la partie servie

Le site ne l'utilise plus : rien n'y est écrit ni lu, et rien n'y est
effacé. Un point d'attention urgent le signale. Déplacez son contenu
dans un dossier qui convient, puis indiquez ce nouveau chemin sur la
fiche de l'emplacement.
