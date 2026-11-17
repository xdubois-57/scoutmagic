---
id: courrier-orienter
title: Orienter un message
summary: Confirmer une proposition, rattacher à la main, détacher, et ce qui disparaît quand.
category: Espace chefs d'U
role_min: admin
discovery: 3
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
mène à l'élément concerné : séjour, réservation ou compte. « Détacher »
enlève **un** rattachement ; ni le message ni les autres rattachements
ne disparaissent, pour qu'une correction puisse le placer au bon
endroit.

**Les propositions** — ce qu'un module pense pouvoir rattacher, sans en
être sûr, avec ce sur quoi il s'appuie. « Rattacher » la confirme ;
« Écarter » la retire définitivement. Quand plusieurs cibles sont
possibles, le site n'en choisit aucune : un message sur le mauvais
dossier est pire qu'un message non rattaché.

Sur l'adresse générale de l'unité, c'est **ici seulement** que ces
propositions se traitent : les responsables du module ne les voient
pas. Une fois confirmé, le message rejoint le dossier, et le module le
voit. Sur une adresse réservée à un module, ses responsables les
traitent eux-mêmes.

**« Rattacher à… »** — pour un message qu'aucun module n'a reconnu ni
proposé. Choisissez le module, tapez ce que vous diriez à voix haute (un
lieu et un mois, le nom d'un locataire, une référence) et choisissez la
cible. Un même message peut appartenir à un séjour et à une facture à la
fois.

Écarter une proposition **ne conserve pas** le message : « Écarter »
veut dire « ce message ne concerne pas ce module ».

## Un message sans texte

Les images **dans le corps** d'un message ne sont pas chargées : cela
renseignerait l'expéditeur sur votre lecture. Si tout le message était
une image, le site le signale. Une image jointe reste téléchargeable.

Les photos reçues depuis cette version sont converties en JPEG, sans
métadonnées ni position GPS ; le HEIC l'est si le serveur sait le lire.
Les pièces jointes plus anciennes gardent leur format d'origine, et
peut-être leur position GPS.

## Une pièce jointe non conservée

Une image illisible, un fichier trop volumineux, d'un type refusé ou
arrivé après saturation du stockage n'est pas conservé. Son nom, son
type, sa taille et la raison s'affichent : le fichier reste dans la
boîte d'origine. Les logos de signature sont écartés sans être signalés.

## Ce qui disparaît, et quand

Un message rattaché suit la durée de vie de son dossier. Un message que
**rien** ne rattache — ni association, ni proposition en attente — est
supprimé au terme du délai réglé dans *Configuration > Réglages* (90
jours par défaut), compté depuis la date du message. Détacher un vieux
message ne lui rend pas ce délai, mais lui laisse trente jours, le temps
qu'une fausse manœuvre soit remarquée.
