---
id: courrier-sortant-temoins-reglage
title: Déclarer et régler une boîte témoin
summary: Où déclarer les boîtes qui reçoivent une copie de vos publipostages, combien en prendre, et le dossier à leur ajouter sans quoi la mesure se trompe.
category: Configuration
role_min: superadmin
question: Comment déclarer une boîte témoin ?
question: Combien de boîtes témoins faut-il ?
question: Pourquoi tous mes envois sont-ils marqués « jamais arrivé » ?
paths: /config/courrier-sortant/temoins
related: courrier-sortant-temoins, courrier-sortant, courrier-entrant
---

## Les déclarer

Ce ne sont pas des boîtes d'un genre nouveau. Vous les déclarez dans
« Courrier entrant » comme n'importe quelle autre boîte, puis vous leur
ouvrez la portée « Courrier sortant — boîtes témoins ». C'est cette
portée qui fait d'une boîte une boîte témoin.

Prenez des boîtes chez des **fournisseurs différents** — une Gmail, une
Outlook, une chez votre opérateur. C'est la comparaison entre eux qui
vous apprend quelque chose : si tout arrive partout sauf chez un seul,
vous savez où chercher.

## Ajoutez-leur le dossier des indésirables

**C'est l'étape qu'on oublie, et sans elle la page se trompe.** Une
boîte est lue dans sa boîte de réception et nulle part ailleurs tant que
vous n'ajoutez pas d'autres dossiers à surveiller. C'est le bon réglage
pour toutes vos autres boîtes — le courrier que personne n'a envoyé à la
réception n'est pas du courrier à traiter — mais pour une boîte témoin
c'est exactement l'inverse : une copie classée en indésirables n'est
alors jamais vue, et deux jours plus tard le site la compte « jamais
arrivé ».

Vous liriez donc le pire verdict de la page pour un message pourtant
distribué, et c'est la seule différence que cette page existe pour
mesurer. Ouvrez chaque boîte témoin dans « Courrier entrant » et ajoutez
son dossier d'indésirables aux dossiers surveillés — il s'appelle
« Spam » chez la plupart, « Indésirables » chez d'autres, « Junk » chez
Google.

La page vous le rappelle d'elle-même tant qu'une boîte témoin ne le
surveille pas, et elle refuse d'automatiser le routage dans cet état :
un automatisme qui déplace le courrier de toute une unité ne peut pas
s'appuyer sur une mesure dont on sait qu'elle est aveugle.

## Trois à cinq suffisent, et au-delà c'est contre-productif

C'est le point qui surprend. Ces boîtes ne lisent jamais leur courrier,
ne répondent jamais, ne cliquent jamais. Or les grands fournisseurs
notent un expéditeur sur l'engagement de ses destinataires : plus vous
ajoutez de boîtes muettes, plus vous dégradez vous-même la
délivrabilité que vous cherchez à mesurer.

Trois à cinq boîtes chez des fournisseurs distincts donnent tout le
signal utile. Dix n'en donnent pas plus et vous coûtent quelque chose.
