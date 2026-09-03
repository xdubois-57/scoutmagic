---
id: courrier-orienter
title: Orienter un message
summary: Confirmer une proposition, rattacher à la main, détacher, et ce qui disparaît quand.
category: Espace chefs d'U
role_min: admin
question: Comment classer un e-mail reçu dans le bon dossier ?
question: Comment rattacher à la main un e-mail qu'aucun module n'a reconnu ?
question: Comment défaire un rattachement fait par erreur ?
paths: /courrier/*
related: courrier-unite, courrier-entrant
---

## Ouvrir un message

Vous y trouverez le message tel qu'il est arrivé, ses pièces jointes,
et trois blocs.

**Les rattachements** — ce à quoi le message est rattaché. Chaque ligne
mène à l'élément concerné, séjour, réservation ou compte. « Détacher »
enlève **un** rattachement : ni le message, ni les autres rattachements
ne disparaissent. C'est voulu — détacher est presque toujours une
correction, et faire disparaître le message empêcherait de le
rattacher au bon dossier.

**Les propositions** — ce qu'un module pense pouvoir rattacher, sans
en être sûr. Chacune dit sur quoi elle repose. « Rattacher » la
confirme ; « Écarter » la retire définitivement. Quand plusieurs
cibles sont possibles, le site n'en choisit aucune : mettre un message
sur le mauvais dossier est pire que ne pas le rattacher, car le
gestionnaire qui lit le mauvais dossier n'a aucun moyen de s'en
apercevoir.

**« Rattacher à… »** — pour un message qu'aucun module n'a reconnu ni
proposé. Choisissez le module, tapez ce que vous diriez à voix haute (un
lieu et un mois, le nom d'un locataire, une référence) et choisissez la
cible dans la liste. Rattacher n'enlève rien : un même message peut
appartenir à un séjour et à une facture à la fois.

Écarter une proposition **ne conserve pas** le message. « Écarter »
veut dire « ce message ne concerne pas ce module » — le prendre pour
une raison de garder le message ferait dire à ce bouton le contraire
de ce qu'il annonce.

## Un message sans texte

Les images placées **dans le corps** d'un message ne sont jamais
affichées : les charger préviendrait l'expéditeur que vous avez ouvert
son message, et depuis quel réseau. Un message dont tout le contenu
était une image — une photo de reçu envoyée depuis un téléphone, par
exemple — s'affiche donc sans texte, et le dit. L'image elle-même
n'est pas perdue pour autant : elle est dans les pièces jointes, où
elle se télécharge.

## Une pièce jointe non conservée

Un fichier trop volumineux, d'un type refusé, ou arrivé alors que
l'espace de stockage était saturé, n'est pas conservé — mais son nom,
son type, sa taille et la raison le sont, et s'affichent. Vous savez
donc que l'expéditeur avait bien joint quelque chose, et où aller le
chercher : le site ne touche jamais à la boîte d'origine, le fichier
y est toujours.

Les logos de signature, eux, sont écartés sans être signalés : ce ne
sont pas des pièces jointes.

## Ce qui disparaît, et quand

Un message rattaché à un dossier suit la durée de vie de ce dossier.
Un message que **rien** ne rattache — ni association, ni proposition
encore en attente — est supprimé au terme du délai réglé dans
*Configuration > Réglages* (90 jours par défaut).

Le délai se compte depuis la date du message, jamais depuis le moment
où quelqu'un l'a détaché : détacher un vieux message ne lui offre pas
une nouvelle période de conservation. Un message détaché dispose
néanmoins d'un plancher de trente jours, le temps qu'une fausse
manœuvre soit remarquée.
