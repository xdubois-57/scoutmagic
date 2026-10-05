---
id: config-reinscription
title: Piloter la campagne de réinscription
summary: Les dates, les rappels, l'interrupteur et le suivi de la campagne.
category: Espace chefs d'U
role_min: admin
question: Comment ouvrir la campagne de réinscription aux familles ?
question: Comment relancer les familles qui n'ont pas répondu ?
paths: /config/reinscription, /config/reinscription/reglages
related: config-reinscription-emails, reinscription, departs, passage
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

## Les e-mails de la campagne

La campagne écrit d'elle-même aux familles — ouverture, deux rappels,
clôture — tant que l'interrupteur **« Envoyer les e-mails de la
campagne »** est actif. Ce que chacun fait, et ce que coupe
l'interrupteur, est détaillé dans *Les e-mails de la campagne de
réinscription*.

## Les rappels

Les deux rappels se comptent **en jours avant la fermeture**. Avec une
fermeture au 15 mai, un premier rappel à 14 jours part le 1er mai.

Un rappel dont la date calculée tomberait **avant l'ouverture** n'est
simplement pas envoyé : personne n'aurait encore pu répondre.

## L'interrupteur

Il force l'état, dans les deux sens, quelles que soient les dates.
Servez-vous-en pour ouvrir plus tôt, ou pour rouvrir après la fermeture
le temps qu'une famille en retard réponde.

L'ouvrir ici envoie l'e-mail d'ouverture s'il n'est pas encore parti
pour cette campagne, et le fermer envoie l'e-mail de clôture aux
familles sans réponse, exactement comme le feraient les dates — tant que
les e-mails de la campagne sont actifs.

## Le suivi

Quatre chiffres : les réponses reçues sur le total d'animés, ceux sans
réponse, les départs annoncés, et l'année visée.

Ce sont des **chiffres, jamais des noms**. Une liste ici serait une liste
d'enfants dont les parents ont annoncé le départ, sur un écran de
configuration. Les décisions individuelles se lisent sur « Départs » et
sur « Passage ».

## Relancer à la main

Le bouton écrit, dans les minutes qui suivent, aux familles qui n'ont pas encore répondu
**pour tous** leurs enfants : une famille qui a répondu pour deux enfants
sur trois est relancée, et l'email ne cite que celui qui manque. Un email
par adresse, jamais un par enfant.

Il est indisponible campagne fermée — relancer quelqu'un vers un
formulaire qu'il ne peut plus remplir ne l'aiderait pas — et quand les
e-mails de la campagne sont désactivés. Ce qu'il fait par rapport aux
rappels automatiques est expliqué dans *Les e-mails de la campagne de
réinscription*.
