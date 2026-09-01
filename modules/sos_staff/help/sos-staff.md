---
id: sos-staff
title: Organiser les gardes SOS
summary: La grille de garde mensuelle et le renvoi du numéro d'urgence.
category: Espace chefs d'U
role_min: admin
question: Qui est de garde ce week-end ?
question: Comment remplir la grille de garde du mois ?
paths: /admin/sos, /admin/sos/transitions
related: config-sos
---

Le numéro SOS de l'unité sonne toujours chez quelqu'un : la page
« SOS Staff d'U » décide chez qui, jour par jour. Le renvoi d'appel se
programme tout seul d'après les gardes attribuées.

## Sur ordinateur : la grille

La grille du mois croise les jours et les membres du Staff d'Unité.
Cliquez une case pour la faire tourner entre trois états : vide, **de
garde** (coche verte), **indisponible** (croix rouge). Tout
s'enregistre aussitôt — le statut « Enregistré. » le confirme. Les
colonnes orange, à gauche, montrent les activités des sections : de
quoi éviter de donner la garde à quelqu'un déjà en week-end.

## Sur téléphone : la liste des jours

Le mois se lit comme une liste : une ligne par jour, avec le nom de la
personne qui reçoit réellement les appels et, en dessous, les activités
des sections. Un jour sans garde affiche « Par défaut — » suivi du nom
concerné. Si plusieurs personnes sont marquées de garde le même jour, la
ligne le signale : seule la première de la liste reçoit les appels.

Touchez une ligne pour ouvrir la journée : chaque membre du Staff
d'Unité y a ses trois boutons **Garde**, **Indispo** et **Rien**. Le
mois en cours commence à aujourd'hui ; le lien « Aller à aujourd'hui »
y ramène.

L'onglet « Ma disponibilité » montre le même mois pour vous seul. Il ne
restreint rien : depuis « Le mois », vous planifiez toujours les gardes
de tout le monde.

## Le numéro par défaut

Les jours sans garde attribuée, le numéro SOS renvoie vers le **numéro
par défaut**, choisi dans « Réglages », en bas de la page — seuls les
membres dont un mobile est connu sont proposés. Le bouton
« Enregistrer » ne s'active que si vous changez réellement la valeur.
Si la journée en cours n'a pas de garde, un avertissement vous prévient
que l'enregistrement redirige les appels immédiatement.

## Être prévenu, et vérifier

À chaque changement de destinataire, la personne qui prend la garde et
celle qui la termine sont notifiées ; chacune choisit ses canaux dans
ses préférences de notifications. Le réglage « Notifications de garde »
est l'interrupteur global.

Le bandeau du haut affiche l'état **réel** de la redirection, lu chez
l'opérateur. La liste « Redirections planifiées » montre les bascules à
venir.

> Les bascules s'exécutent en arrière-plan : sans tâche planifiée réelle
> chez l'hébergeur, elles peuvent partir en retard. Si l'état affiché ne
> correspond pas aux gardes, c'est le premier point à vérifier.

## Les gardes sur le calendrier

Si le module Calendrier est actif, les périodes de garde y apparaissent,
**calculées en direct** : elles suivent chaque enregistrement et ne se
modifient pas depuis le calendrier. D'anciens évènements
« SOS Staff d'U : … » écrits avant ce calcul peuvent subsister en
double ; supprimez-les une fois, ils ne reviendront pas.

Le raccordement à l'opérateur se fait sur la page de configuration du
module, réservée à l'administration du site.
