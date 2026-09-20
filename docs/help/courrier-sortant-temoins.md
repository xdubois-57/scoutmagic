---
id: courrier-sortant-temoins
title: Savoir où vos publipostages atterrissent
summary: Des boîtes aux lettres à vous qui reçoivent une copie de chaque envoi, pour voir s'il arrive en réception ou en indésirables.
category: Configuration
role_min: superadmin
question: Comment savoir si mes emails tombent dans les indésirables ?
question: Qu'est-ce qu'une boîte témoin ?
question: Combien de boîtes témoins faut-il ?
question: Pourquoi mes publipostages partent-ils aussi vers d'autres adresses ?
paths: /config/courrier-sortant/temoins
related: courrier-sortant, courrier-sortant-sonde, courrier-sortant-dmarc, courrier-sortant-rebonds
---

## Le problème que ça règle

Aucun site ne peut voir, depuis l'extérieur, si ses messages arrivent en
boîte de réception ou en indésirables. Le fournisseur ne le dit à
personne. La seule façon de le savoir est d'avoir soi-même une boîte chez
ce fournisseur et d'aller regarder.

Une **boîte témoin** fait exactement ça, automatiquement : c'est une
boîte aux lettres de votre unité qui reçoit une copie de chaque
publipostage. Le site regarde ensuite dans quel dossier la copie a
atterri, note le résultat, puis efface le message.

## Les déclarer

Ce ne sont pas des boîtes d'un genre nouveau. Vous les déclarez dans
« Courrier entrant » comme n'importe quelle autre boîte, puis vous leur
ouvrez la portée « Courrier sortant — boîtes témoins ». C'est cette
portée qui fait d'une boîte une boîte témoin.

Prenez des boîtes chez des **fournisseurs différents** — une Gmail, une
Outlook, une chez votre opérateur. C'est la comparaison entre eux qui
vous apprend quelque chose : si tout arrive partout sauf chez un seul,
vous savez où chercher.

## Trois à cinq suffisent, et au-delà c'est contre-productif

C'est le point qui surprend. Ces boîtes ne lisent jamais leur courrier,
ne répondent jamais, ne cliquent jamais. Or les grands fournisseurs
notent un expéditeur sur l'engagement de ses destinataires : plus vous
ajoutez de boîtes muettes, plus vous dégradez vous-même la
délivrabilité que vous cherchez à mesurer.

Trois à cinq boîtes chez des fournisseurs distincts donnent tout le
signal utile. Dix n'en donnent pas plus et vous coûtent quelque chose.

## Ce que vous y verrez

Une ligne par publipostage, une colonne par fournisseur, et dans chaque
case l'un de ces quatre états :

- **Boîte de réception** — la copie est arrivée normalement.
- **Indésirables** — elle est arrivée, mais mise de côté. Le nom du
  dossier du fournisseur est affiché à côté, pour que vous le
  reconnaissiez en allant voir.
- **En attente** — elle est partie, on ne l'a pas encore trouvée. C'est
  normal pendant qu'un envoi se déroule.
- **Jamais arrivé** — deux jours ont passé et rien n'est venu. C'est le
  plus grave des quatre : un message refusé en silence.

## Ce que ça ne vous dit pas

Une boîte témoin vous dit où **sa** copie a atterri, chez **son**
fournisseur. Ce n'est pas l'expérience de chaque famille : deux comptes
Gmail ne filtrent pas pareil, parce que le classement dépend aussi de ce
que chaque personne a ouvert, répondu ou signalé auparavant.

Lisez ces résultats comme une tendance par fournisseur, jamais comme une
garantie destinataire par destinataire.

## Vie privée

Une copie contient le message réel, donc les données personnelles qu'il
porte. Elle ne part que vers **vos propres boîtes**, jamais vers un
service extérieur, et l'option est éteinte tant que vous ne l'activez
pas. Le site retire le message de la boîte dès qu'il a noté le résultat.
