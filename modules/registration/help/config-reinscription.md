---
id: config-reinscription
title: Piloter la campagne de réinscription
summary: Les dates, les rappels, l'interrupteur et le suivi de la campagne.
category: Configuration
role_min: superadmin
question: Comment ouvrir la campagne de réinscription aux familles ?
question: Comment relancer les familles qui n'ont pas répondu ?
paths: /config/reinscription
related: reinscription, departs, passage
---

Chaque année, l'unité demande aux familles si leur enfant revient. Cette
page décide quand la question est posée et montre où en sont les
réponses.

## Les dates

L'ouverture et la fermeture s'écrivent en **mois-jour**, sans année :
`03-01` est le 1er mars, `05-15` le 15 mai. La même configuration se
rejoue donc chaque année sans rien retaper.

**Une date manquée est manquée.** Si le site n'a reçu aucune visite le
jour prévu — hébergement en panne, unité en sommeil — la campagne ne
s'ouvre pas rétroactivement quelques jours plus tard. C'est volontaire :
une campagne ouverte en retard annoncerait une échéance déjà plus proche
que ce qu'elle dit. L'interrupteur manuel est là pour ce cas.

## Les rappels

Les deux rappels se comptent **en jours avant la fermeture**. Avec une
fermeture au 15 mai, un premier rappel à 14 jours part le 1er mai.

Un rappel dont la date calculée tomberait **avant l'ouverture** n'est
simplement pas envoyé : personne n'aurait encore pu répondre.

## L'interrupteur

Il force l'état, dans les deux sens, quelles que soient les dates.
Servez-vous-en pour ouvrir plus tôt, ou pour rouvrir après la fermeture
le temps qu'une famille en retard réponde.

Le fermer ici envoie l'email de clôture aux familles sans réponse,
exactement comme une fermeture programmée.

## Le suivi

Quatre chiffres : les réponses reçues sur le total d'animés, ceux sans
réponse, les départs annoncés, et l'année visée.

Ce sont des **chiffres, jamais des noms**. Une liste ici serait une liste
d'enfants dont les parents ont annoncé le départ, sur un écran de
configuration. Les décisions individuelles se lisent sur « Départs » et
sur « Passage ».

## Relancer à la main

Le bouton écrit tout de suite aux familles qui n'ont pas encore répondu
**pour tous** leurs enfants : une famille qui a répondu pour deux enfants
sur trois est relancée, et l'email ne cite que celui qui manque. Un email
par adresse, jamais un par enfant.

Il est indisponible campagne fermée : relancer quelqu'un vers un
formulaire qu'il ne peut plus remplir ne l'aiderait pas.
