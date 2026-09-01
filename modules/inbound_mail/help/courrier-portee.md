---
id: courrier-portee
title: Ce que chaque module fait d'une boîte
summary: Boîte partagée ou dédiée, qui analyse, qui peut lire, et combien de temps le courrier est conservé.
category: Configuration
role_min: superadmin
question: Comment réserver une boîte e-mail à un seul usage ?
question: Qui a le droit de lire le courrier entrant ?
paths: /config/courrier-entrant/boites/*/portee
related: courrier-entrant, courrier-unite
---

## À quoi sert cette boîte ?

C'est la première question de l'écran de portée, et elle commande tout
le reste.

**Boîte partagée** — l'adresse publique de l'unité, où l'on trouve de
tout. Chaque module y reçoit sa propre réponse : est-il autorisé à
*analyser* ce courrier, et jusqu'où ses utilisateurs peuvent-ils le
*lire* ? Ce sont bien deux questions distinctes : un module peut très
bien rattacher un message à une réservation sans que ses utilisateurs
aient à voir le reste de la boîte.

**Boîte dédiée** — une adresse créée pour un seul usage. Le module
choisi lit et classe tout, aucun autre module n'y touche.

### Qui peut lire ce courrier ?

- **Personne** — le module classe automatiquement, mais n'ouvre aucune
  liste. Les messages restent visibles depuis la fiche à laquelle ils
  sont rattachés. C'est le réglage par défaut, et le plus prudent.
- **Messages concernés** — les utilisateurs du module voient ce qu'il a
  rattaché à un élément qu'ils gèrent, *et* ce qu'il propose d'y
  rattacher : une proposition n'a de sens que si quelqu'un peut la
  confirmer.
- **Tout le courrier** — normal sur une boîte dédiée, **lourd sur une
  boîte partagée** : vous confiez alors aux utilisateurs de ce module
  les questions des parents, les documents médicaux et les
  candidatures. L'écran affiche combien de personnes cela représente
  exactement.

Un module pour lequel personne n'a répondu ne fait **rien**. Un module
installé après la configuration d'une boîte reste donc inerte tant que
vous ne dites pas le contraire.

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
