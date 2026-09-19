---
id: courrier-sortant-rebonds
title: Quand une adresse refuse vos messages
summary: Ce qu'est un rebond, pourquoi le site cesse d'écrire à une adresse, et comment la remettre en service.
category: Configuration
role_min: superadmin
question: Pourquoi une adresse est-elle suspendue ?
question: Un parent dit ne plus rien recevoir, que faire ?
question: Est-ce que le site voit si un message tombe dans les indésirables ?
question: Comment réactiver une adresse suspendue ?
paths: /config/courrier-sortant/rebonds
related: courrier-sortant, courrier-sortant-sonde, courrier-sortant-pannes
---

## Un rebond, c'est quoi

Quand un serveur n'arrive pas à remettre un message, il en renvoie un autre
pour le dire. Le site lit ces retours tout seul, si vous lui avez ouvert une
boîte du courrier entrant.

Deux sortes, et la différence compte :

- **Temporaire** — la boîte est pleine, le serveur d'en face était occupé.
  Ça peut très bien passer au prochain envoi. Le site le note, prévient la
  personne une fois, et continue d'écrire.
- **Définitif** — l'adresse n'existe pas, ou le serveur refuse nos
  messages. Le site le note, et **après deux refus définitifs il cesse
  d'écrire à cette adresse**.

## Pourquoi cesser d'écrire

Pour protéger l'acheminement du courrier de toute l'unité. Un expéditeur qui
écrit à des adresses mortes se fait remarquer des grands fournisseurs, et ce
sont **les messages de tout le monde** qui finissent en indésirables.

## Ce que voit la personne concernée

Sur sa propre page, à côté de l'adresse : la raison en français ordinaire —
boîte pleine, adresse inexistante, message refusé, serveur injoignable — et
ce qu'il y a à faire. Jamais le charabia que le serveur distant a renvoyé,
qui ne lui apprendrait rien.

Elle reçoit aussi une notification **quand le site sait à quel compte
l'adresser** — ce qui n'est pas toujours le cas pour une adresse secondaire
dont le compte est ailleurs. Pas par e-mail, évidemment : lui écrire pour
lui dire qu'on n'arrive pas à lui écrire n'aurait aucun sens.

## Remettre une adresse en service

**La personne peut le faire elle-même**, depuis la page de ses adresses. Le
site a posé la suspension, c'est donc à elle de décider que son adresse
refonctionne — elle vient peut-être de vider sa boîte.

Vous pouvez aussi le faire ici, ce qui est souvent nécessaire : beaucoup de
parents ne se connectent jamais. Dans les deux cas le compteur repart de
zéro.

Et si un message repart normalement vers cette adresse, le site oublie tout
de lui-même. C'est le seul signal fiable que le problème est réglé — mieux
qu'un délai, puisqu'une boîte est vidée quand son propriétaire y pense.

## Ce que le site refuse de croire

N'importe qui peut écrire dans une boîte surveillée, faux avis compris. Un
rebond n'est donc compté que pour une adresse à laquelle le site a vraiment
écrit. Même raison côté membre : une adresse secondaire jamais confirmée
ne montre rien et ne se réactive pas.

## Le piège, et c'est le plus coûteux de tout ce chantier

**Le traitement des rebonds est aveugle au classement en indésirables.**

Un rebond, c'est un serveur qui dit non explicitement. Or les grands
fournisseurs ne disent presque jamais non : ils **acceptent** le message et
le déplacent silencieusement dans les indésirables.

Donc zéro rebond ne veut **pas** dire que vos messages sont lus, mais qu'ils
ont été acceptés. Pour savoir où ils atterrissent, il n'y a qu'une méthode —
en envoyer un et aller regarder — et c'est la page « Sonde ».
