---
id: courrier-portee
title: Courrier général ou adresse réservée
summary: Les deux usages d'une boîte, qui la lit, et combien de temps le courrier est conservé.
category: Configuration
role_min: superadmin
discovery: 3
question: Comment réserver une boîte e-mail à un seul usage ?
question: Qui a le droit de lire le courrier entrant ?
related: courrier-entrant, courrier-unite
---

## Deux usages

Chaque boîte a l'un de ces deux usages, choisi dans son formulaire, et
c'est tout ce qu'il y a à régler.

**Courrier général de l'unité** — l'adresse publique, où l'on trouve de
tout. Tous les modules actifs y reconnaissent leurs messages. Le Staff
d'U voit tout dans « Courrier reçu ». Les responsables d'un module ne
voient que les messages rattachés à un dossier qu'ils gèrent : quand un
module hésite et fait une **proposition**, elle reste dans « Courrier
reçu », où le Staff d'U la confirme ou l'écarte. Une fois confirmé, le
message est rattaché et le module le voit.

**Réservée à un module** — une adresse créée pour un seul usage. Seul
ce module la lit, et ses responsables voient tout ce qui y arrive,
propositions comprises, comme le Staff d'U. Un module n'a qu'une seule
adresse réservée : le site refuse d'en réserver une deuxième au même
module.

Une boîte ajoutée fonctionne dès qu'elle est enregistrée.

## Conservation et espace disque

Deux réglages, dans *Configuration > Réglages* :

- **Conservation du courrier sans association** (90 jours par défaut) —
  au terme de ce délai, un message qu'aucun module ne rattache et sur
  lequel aucune proposition ne subsiste est supprimé définitivement,
  pièces jointes comprises. Le délai se compte depuis la date du
  message, jamais depuis le moment où quelqu'un l'a détaché ; un
  message détaché bénéficie toutefois d'un plancher de trente jours,
  le temps qu'une erreur soit remarquée.
- **Espace maximal des pièces jointes** (500 Mo par défaut) — au-delà,
  les pièces jointes ne sont plus enregistrées (le message, lui, est
  conservé et la pièce jointe est signalée comme non conservée), les
  plus anciens messages sans association sont purgés, et le
  superadministrateur est averti au plus une fois par jour.
