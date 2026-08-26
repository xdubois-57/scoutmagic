---
id: gerer-les-demandes
title: Gérer les demandes d'inscription
summary: Décider, écrire aux familles, rapprocher avec la fédération.
category: Espace chefs d'U
role_min: admin
paths: /config/inscriptions, /config/inscriptions/demandes/*
related: inscrire-un-enfant, passage, annee-scoute
---

La page « Gestion des inscriptions » rassemble tout le traitement des
demandes : l'ouverture du formulaire, les capacités par branche, la
liste des demandes et leur fiche.

## Le cycle d'une demande

En attente → Acceptée ou Refusée/Retirée, puis, pour une acceptée,
**Encodée dans Desk** une fois rapprochée d'un vrai membre. « Revenir
en attente » rattrape une décision, sauf une demande déjà encodée.

**La famille ne voit une décision que lorsque son e-mail est parti** :
accepter une demande sans envoyer l'e-mail la laisse « en attente »
aux yeux de la famille. Les e-mails d'acceptation et de refus
s'envoient depuis la fiche, jamais automatiquement — et leur texte
doit d'abord être rédigé dans les Réglages, sans quoi le bouton reste
gris.

## La fiche d'une demande

Tout ce que la famille a soumis, en lecture seule, plus trois champs
du staff : la **section prévue** (jamais montrée à la famille — c'est
le même champ que sur la page Passage), la **catégorie tarifaire**
(avec une suggestion selon la taille du foyer, à confirmer
explicitement) et des **notes internes**, jamais visibles ni
journalisées.

## Sortir la liste

« Exporter » télécharge un tableur des demandes **telles que l'écran
les affiche** : le filtre d'année, l'état et la recherche s'y
appliquent, et le bouton porte le nombre de lignes qui partiront. Pour
en exporter d'autres, changez de filtre d'abord.

Le fichier reprend ce que la famille a écrit et ce que l'unité a
décidé. Les notes internes n'y figurent jamais : un fichier exporté
circule par e-mail, se retrouve dans un dossier partagé et survit au
départ de celui qui l'a produit.

## Le rapprochement avec la fédération

À chaque import Desk, les demandes acceptées sont comparées aux
membres importés (nom, prénom, date de naissance) : une correspondance
unique encode la demande toute seule. En cas d'ambiguïté — jumeaux,
homonymes — rien n'est deviné : la demande reste dans l'encart « non
rapprochées », et vous l'encodez à la main avec le numéro de tiers
Desk de l'enfant.

## Avant le changement d'année

L'encart « Demandes non clôturées » compte ce qui reste ouvert, avec
« Tout refuser » et « Tout retirer » en masse. Tant qu'il en reste, la
bascule du site vers la nouvelle année scoute est bloquée — la page
« Année scoute » vous y renverra.
