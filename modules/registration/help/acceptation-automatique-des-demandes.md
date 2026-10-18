---
id: acceptation-automatique-des-demandes
title: Accepter automatiquement les demandes « Oui »
summary: Laisser le site accepter lui-même, dans l'ordre de priorité, les demandes recommandées « Oui ».
category: Espace chefs d'U
role_min: admin
question: Comment faire accepter automatiquement les demandes recommandées « Oui » ?
question: Pourquoi l'acceptation automatique n'a-t-elle pas accepté cette demande ?
question: Pourquoi ne puis-je pas activer l'acceptation automatique ?
paths: /config/inscriptions/reglages
related: envoi-automatique-des-e-mails-de-decision, capacites-et-listes-d-attente, decider-une-demande, envoyer-l-e-mail-de-decision
---

Le site peut accepter lui-même les demandes que sa recommandation classe
« Oui », sans attendre qu'un membre du Staff d'U les décide. Il ne
refuse jamais rien.

## L'activer

Dans l'onglet « Réglages », la sous-boîte « Décisions automatiques » de
« Capacités par branche » porte l'interrupteur « Accepter automatiquement
les demandes « Oui » » et la date « À partir du ». Cochez-le, choisissez
la date, puis « Enregistrer ».

**Il reste grisé tant qu'il lui manque quelque chose** : la case « Gérer
les listes d'attente » (sans elle, aucune recommandation) et l'envoi
automatique des e-mails de décision (sans lui, la famille acceptée
resterait sans réponse). La phrase sous l'interrupteur dit ce qui
manque. Décocher « Gérer les listes
d'attente » désactive aussi l'acceptation automatique ; il faut la
réactiver ensuite.

## Ce que le site accepte

Chaque heure, pour l'année en cours et l'année visée, année de parcours
par année de parcours, le site suit la file de la recommandation, dans
l'ordre des « Priorités » : il accepte la première demande « Oui »,
recalcule la recommandation, puis passe à la suivante. Il s'arrête à la
première demande qui n'est pas « Oui ».

Il ne dépasse jamais le nombre de places, sauf pour une demande « Toujours
Oui ». Si le seuil « Oui » va au-delà de 100 %, une demande classée « Oui »
qui ferait dépasser la capacité reste donc à décider à la main.

Ne sont jamais acceptées automatiquement :

- les demandes « Peut-être » et « Non » ;
- une demande déposée **avant** la date « À partir du » ;
- un doublon possible : pour un enfant déposé deux fois, seule sa plus
  ancienne demande peut l'être, et aucune s'il a déjà une place ;
- une demande hors des tranches d'âge, ou d'une année passée.

Quand l'acceptation automatique prendra une demande, son dialogue de
décision le dit. Vous pouvez toujours décider avant.

## Et ensuite ?

Une acceptation automatique est une décision comme une autre : la fiche
la montre « Acceptée », l'e-mail d'acceptation est planifié et part après
le « Délai avant envoi », et vous pouvez changer la décision d'ici là
sans que rien ne soit envoyé. Le journal la marque « acceptation
automatique ». Une décision prise à la main n'est jamais remplacée par
le site.
