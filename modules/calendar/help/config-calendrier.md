---
id: config-calendrier
title: Configurer le calendrier
summary: Qui voit et qui modifie chaque calendrier, valeurs par défaut, flux d'agenda et rappels.
category: Configuration
role_min: superadmin
paths: /config/calendar
related: calendrier, calendrier-animateurs
---

Cette page règle le calendrier d'ensemble : qui voit et qui modifie
quels calendriers, les valeurs préremplies à la création d'un
évènement, les liens d'abonnement et les rappels.

## Les calendriers, qui les voit et qui les modifie

Chaque section a son calendrier, créé automatiquement ; vous pouvez y
ajouter des calendriers supplémentaires — « Animateurs » existe
d'office. Chacun porte deux réglages : l'un pour la lecture, l'autre
pour l'écriture.

« Vu par » dit qui consulte le calendrier : **tous** (y compris les
familles et les visiteurs), **les animateurs** (l'encadrement) ou
**les chefs d'unité** (le Staff d'Unité). Les calendriers des sections
d'animés naissent vus par tous ; ceux du Staff d'U et des branches
aînées, réservés à l'encadrement.

« Modifié par » dit qui y encode des activités : **ses animateurs**,
le réglage d'origine, ou **les chefs d'unité**. Sur un calendrier de
section, « ses animateurs » signifie les animateurs de cette
section-là et personne d'autre ; les chefs d'unité encodent partout
dans tous les cas. C'est ce second réglage qui permet un calendrier
que toute l'équipe consulte mais que seule l'équipe d'unité tient à
jour.

Les deux réglages ne sont pas indépendants dans un sens : on ne peut
pas confier la modification à des personnes qui ne voient pas le
calendrier. Restreindre « Vu par » aux chefs d'unité remonte donc
« Modifié par » avec lui ; l'inverse est refusé avec un message.

Les deux se règlent de la même façon pour un calendrier de section et
pour un calendrier supplémentaire, et **s'enregistrent dès que vous
les changez** : pas de bouton « Enregistrer », un message confirme.

## Les valeurs par défaut

Le titre, les heures et le lieu proposés quand un animateur crée un
évènement se règlent ici — « Réunion », 14:00, 17:45 et « Local »
d'origine. Adaptez-les à vos réunions : autant de saisie économisée
chaque semaine.

## Les liens d'agenda

Chaque calendrier supplémentaire a son lien d'abonnement ; le lien
« Unité complète » regroupe tout, calendriers de section compris, et
les membres ont leur lien personnel sur la page publique.

> « Vu par » ne vaut que pour les pages du site : ces liens, eux, ne
> demandent aucune connexion et montrent leur contenu à qui les
> possède. Un lien qui a fuité se neutralise avec « Régénérer » — les
> agendas abonnés devront être réabonnés.

## Les rappels

Un seul rappel se règle ici : le **rappel avant un évènement de
plusieurs jours** — un e-mail aux responsables de la section, un
nombre de jours à l'avance (quatorze par défaut). Désactivé d'origine :
activez-le si vos week-ends et camps méritent une piqûre de rappel
d'intendance.

Le **rappel de veille** (« votre activité a lieu demain ») part tout
seul à 18 h et n'a rien à régler ici : chaque membre choisit s'il le
reçoit dans ses préférences de notification.
