---
id: config-calendrier
title: Configurer le calendrier
summary: Qui voit et qui modifie chaque calendrier, valeurs par défaut, flux d'agenda et rappels.
category: Configuration
role_min: superadmin
paths: /config/calendar
related: calendrier, calendrier-animateurs
---

Cette page règle le fonctionnement d'ensemble du calendrier : qui voit
et qui modifie quels calendriers, les valeurs préremplies à la
création d'un évènement, les liens d'abonnement et les rappels.

## Les calendriers, qui les voit et qui les modifie

Chaque section a son calendrier, créé automatiquement ; vous pouvez y
ajouter des calendriers supplémentaires — « Animateurs » existe
d'office. Chacun porte deux réglages distincts, l'un pour la lecture,
l'autre pour l'écriture.

« Vu par » dit qui consulte le calendrier : **tous** (y compris les
familles et les visiteurs), **les animateurs** (l'encadrement) ou
**les chefs d'unité** (le Staff d'Unité). Les calendriers des sections
d'animés naissent vus par tous ; ceux du Staff d'U et des branches
aînées naissent réservés à l'encadrement — ajustez selon les usages de
votre unité.

« Modifié par » dit qui y encode des activités : **ses animateurs**,
le réglage d'origine, ou **les chefs d'unité**. Sur un calendrier de
section, « ses animateurs » signifie les animateurs de cette
section-là et personne d'autre ; les chefs d'unité encodent partout
dans tous les cas. C'est ce second réglage qui permet un calendrier
que toute l'équipe consulte mais que seule l'équipe d'unité tient à
jour — l'ancien réglage unique ne savait pas l'exprimer, il fallait
cacher le calendrier pour en interdire l'écriture.

Les deux réglages ne sont pas indépendants dans un sens : on ne peut
pas confier la modification à des personnes qui ne voient pas le
calendrier. Restreindre « Vu par » aux chefs d'unité remonte donc
« Modifié par » avec lui ; l'inverse est refusé avec un message.

## Les valeurs par défaut

Le titre, l'heure de début et l'heure de fin proposés quand un
animateur crée un évènement se règlent ici — « Réunion », 14:00,
17:45 d'origine. Adaptez-les à l'horaire réel de vos réunions : c'est
autant de saisie économisée chaque semaine.

## Les liens d'agenda

Chaque calendrier a son lien d'abonnement, et un lien « unité »
regroupe tout ; les membres ont en plus leur lien personnel sur la
page publique du calendrier. Les jetons de ces liens se régénèrent
ici en cas de fuite — les agendas abonnés devront alors être
réabonnés.

## Les rappels

Deux rappels automatiques se règlent sur cette page :

- le **rappel de veille**, envoyé à tous la veille de chaque activité
  à l'heure choisie (18 h par défaut) ;
- le **rappel avant un évènement de plusieurs jours** — un e-mail aux
  responsables de la section, un nombre de jours à l'avance (quatorze
  par défaut). Désactivé d'origine : activez-le si vos week-ends et
  camps méritent une piqûre de rappel d'intendance.
