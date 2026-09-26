---
id: courrier-sortant-temoins
title: Savoir où vos publipostages atterrissent
summary: Des boîtes aux lettres à vous qui reçoivent une copie de chaque envoi, pour voir s'il arrive en réception ou en indésirables.
category: Configuration
role_min: superadmin
question: Comment savoir si mes emails tombent dans les indésirables ?
question: Qu'est-ce qu'une boîte témoin ?
question: Pourquoi mes publipostages partent-ils aussi vers d'autres adresses ?
paths: /config/courrier-sortant/temoins
related: courrier-sortant-temoins-reglage, courrier-sortant, courrier-sortant-sonde, courrier-sortant-dmarc, courrier-sortant-rebonds
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

Vous les déclarez dans « Courrier entrant » comme n'importe quelle autre
boîte, puis vous leur ouvrez la portée « Courrier sortant — boîtes
témoins ». Prenez-en trois à cinq, chez des fournisseurs différents, et
**ajoutez-leur leur dossier d'indésirables** : sans ce réglage, une
copie classée en indésirables n'est jamais vue et finit comptée « jamais
arrivé ». Tout cela est expliqué dans « Déclarer et régler une boîte
témoin ».

## Ce que vous y verrez

Une ligne par publipostage, une colonne par fournisseur, et dans chaque
case l'un de ces états :

- **Boîte de réception** — la copie est arrivée normalement.
- **Indésirables** — elle est arrivée, mais mise de côté. Le nom du
  dossier du fournisseur est affiché à côté, pour que vous le
  reconnaissiez en allant voir.
- **Arrivé, dossier inconnu** — elle est arrivée dans un dossier que le
  site ne sait pas classer, souvent une règle de tri que vous avez
  vous-même posée. Le nom du dossier est affiché : c'est une arrivée,
  pas un problème de délivrabilité.
- **En attente** — elle est partie, on ne l'a pas encore trouvée. C'est
  normal pendant qu'un envoi se déroule.
- **Jamais arrivé** — deux jours ont passé et rien n'est venu. C'est le
  plus grave de tous : un message refusé en silence. Vérifiez d'abord
  que la boîte surveille bien son dossier d'indésirables, sans quoi ce
  verdict y remplace simplement « Indésirables ».

Une colonne est un fournisseur, reconnu aux enregistrements MX du
domaine : une boîte sur un domaine personnel hébergé chez Google compte
dans gmail.com.

Une colonne reste affichée tant qu'elle porte des résultats, même si
vous avez retiré la boîte témoin de ce fournisseur entre-temps : ce qui
a été mesuré a été mesuré.

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
