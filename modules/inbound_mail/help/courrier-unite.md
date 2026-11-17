---
id: courrier-unite
title: Le courrier de l'unité
summary: Voir tout le courrier reçu, confirmer ce que les modules proposent, et savoir ce qui disparaît quand.
category: Espace chefs d'U
role_min: admin
question: Où voir tous les e-mails reçus par l'unité ?
question: Combien de temps le courrier reçu est-il conservé ?
question: Que faire quand le stockage du courrier entrant est plein ?
paths: /courrier, /courrier/*
related: courrier-orienter, courrier-entrant, camps-courrier
---

Cette page montre **tout** le courrier relevé dans les adresses de
l'unité, y compris les messages qu'aucun module n'a su rattacher. Le
Staff d'U est seul à la voir : un gestionnaire de bien ou un chef de
camp ne voit que le courrier des dossiers dont il a la charge.

## Le seul endroit des propositions du courrier général

Sur l'adresse générale de l'unité, quand un module pense reconnaître un
message sans en être sûr, sa **proposition** n'est montrée qu'ici. Un
responsable de module ne la voit jamais : un parent qui a loué le local
le mois dernier peut écrire au sujet de son enfant, et ce message n'a
pas à atterrir chez les gestionnaires des biens. Vous confirmez ou
écartez ; une fois confirmé, le message est rattaché et le module le
voit.

Sur une adresse réservée à un module, ses responsables traitent eux-mêmes
les propositions.

## Les filtres

Trois filtres, et **aucune recherche sur le contenu** : chercher dans le
texte supposerait de garder en clair un index de tout ce que l'on vous a
écrit.

- **Rattachements** — « Sans rattachement » par défaut, parce que c'est
  la pile sur laquelle vous avez quelque chose à faire. « Avec
  proposition à confirmer » isole ce qu'un module attend de vous.
- **Boîte** — quand plusieurs adresses sont relevées.
- **Courrier automatique** — lettres d'information, réponses
  automatiques, rapports de non-remise. Reconnus d'après les en-têtes,
  jamais d'après le contenu, et masqués par défaut plutôt que supprimés.

« Relever le courrier maintenant » relève les adresses tout de suite,
sans attendre le prochain passage. Un seul relevé à la fois.

## Conservation et espace disque

Deux réglages, dans *Configuration > Réglages* :

- **Conservation du courrier sans association** (90 jours par défaut) —
  un message qu'aucun module ne rattache et sur lequel aucune
  proposition ne subsiste est ensuite supprimé, pièces jointes
  comprises. Le délai se compte depuis la date du message.
- **Espace maximal des pièces jointes** (500 Mo par défaut) — au-delà,
  les pièces jointes ne sont plus enregistrées (le message, lui, est
  conservé), les plus anciens messages sans association sont purgés, et
  le superadministrateur est averti au plus une fois par jour.

Confirmer, rattacher à la main ou détacher un message : voir « Orienter
un message ».
