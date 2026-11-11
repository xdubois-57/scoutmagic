---
id: migration-calendrier-ics
title: Copier le calendrier de l'ancien site
summary: Copier une fois les événements d'un agenda en ligne (fichier ICS) dans un calendrier de ScoutMagic.
category: Espace chefs d'U
role_min: admin
discovery: off
question: Comment récupérer les événements de notre ancien agenda en ligne ?
question: Pourquoi les événements récurrents ne sont-ils pas importés ?
question: Les événements copiés depuis un fichier ICS se mettent-ils à jour ?
paths: /admin/migration-donnees/calendrier
related: migration-de-donnees, calendrier-animateurs
---

Cet import copie, une seule fois, les événements d'un agenda en ligne
(Google Agenda, Outlook, l'ancien site…) dans un calendrier de
ScoutMagic. Il est proposé quand le module Calendrier est activé.

## Ce qu'il faut indiquer

- **« Adresse du calendrier (ICS) »** : l'adresse du fichier ICS que
  l'ancien agenda donne pour s'y abonner. Une adresse webcal:// convient
  aussi. Le fichier doit être servi comme un calendrier et faire au plus
  5 Mo.
- **« Calendrier de destination »** : seuls les calendriers que vous
  pouvez modifier sont proposés.
- **« Importer les événements à partir du »** : par défaut, deux ans
  avant aujourd'hui. Les événements plus anciens sont laissés de côté.

« Lire le calendrier » télécharge le fichier et l'analyse. Rien n'est
écrit avant votre confirmation.

## Ce qui est copié, et ce qui ne l'est pas

Le titre, les dates, les heures, le lieu et la description. Les heures
sont converties à l'heure de Bruxelles ; un événement sans heure devient
« toute la journée ».

Ne sont **pas** copiés : les événements récurrents et leurs occurrences
modifiées (recréez-les dans ScoutMagic), les événements annulés, et les
événements illisibles ou incomplets. L'aperçu et le bilan les comptent
séparément.

## Une copie, pas un abonnement

Ce qui changera plus tard dans l'ancien agenda n'arrivera pas ici.
Relancer l'import avec la même adresse ne recrée pas les événements déjà
copiés : seuls les nouveaux sont ajoutés. L'import n'envoie aucune
notification, et aucun rappel n'est programmé pour un événement passé.

> L'adresse elle-même n'est pas conservée : elle peut donner accès à un
> agenda privé. Le fichier téléchargé est effacé au plus tard 24 heures
> après la fin de l'import.
