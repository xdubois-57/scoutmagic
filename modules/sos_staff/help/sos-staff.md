---
id: sos-staff
title: Organiser les gardes SOS
summary: La grille de garde mensuelle et le renvoi du numéro d'urgence.
category: Espace chefs d'U
role_min: admin
paths: /admin/sos, /admin/sos/transitions
related: config-sos
---

Le numéro SOS de l'unité sonne toujours chez quelqu'un : la page
« SOS Staff d'U » décide chez qui, jour par jour. Le renvoi d'appel se
programme tout seul d'après la grille de garde.

## La grille de garde

La grille du mois croise les jours et les membres du Staff d'Unité.
Touchez une case pour la faire tourner entre trois états : vide, **de
garde** (coche verte), **indisponible** (croix rouge). Tout
s'enregistre aussitôt — le statut « Enregistré. » le confirme.

Les colonnes orange, à gauche, montrent les activités des sections ce
jour-là : pratique pour donner la garde à quelqu'un qui n'est pas déjà
en week-end avec sa section.

## Le numéro par défaut

Les jours sans garde attribuée, le numéro SOS renvoie vers le
**numéro par défaut** : choisissez le membre du Staff d'Unité concerné
dans la liste — seuls ceux dont un numéro de mobile est connu sont
proposés. Le bouton « Enregistrer » ne s'active que si vous changez
réellement la valeur : ce champ décide où sonnent les urgences, il ne
se modifie pas par mégarde.

## Vérifier que le renvoi suit

Le bandeau du haut affiche l'état **réel** de la redirection, lu en
direct chez l'opérateur téléphonique : vers qui la ligne renvoie en ce
moment. La liste « Redirections planifiées » montre les bascules à
venir, calculées depuis la grille et l'heure de changement de garde
que vous avez choisie.

> Les bascules s'exécutent en arrière-plan : sans tâche planifiée
> réelle chez l'hébergeur, elles peuvent partir en retard. Si l'état
> affiché ne correspond pas à la grille, c'est le premier point à
> vérifier — puis la configuration de la téléphonie.

## Les gardes sur le calendrier

Si le module Calendrier est actif, les périodes de garde apparaissent
sur le calendrier « Animateurs », une entrée par série de jours
consécutifs. Ces évènements sont **calculés en direct depuis la
grille** : ils suivent chaque enregistrement sans rien copier, et ne
se modifient donc pas depuis la page du calendrier — c'est la grille
qui fait foi.

Si votre unité utilisait ce module avant que les gardes deviennent des
évènements calculés, les anciens évènements « SOS Staff d'U : … »
écrits à l'époque dans le calendrier « Animateurs » y sont restés en
double. Supprimez-les une fois depuis la page du calendrier, comme
n'importe quel évènement ; ils ne reviendront pas.

Le raccordement à l'opérateur (identifiants, choix de la ligne) se
fait sur la page de configuration du module, réservée à
l'administration du site.
