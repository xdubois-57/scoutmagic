---
id: rappels
title: Envoyer un rappel de paiement
summary: Préparer le publipostage de rappel d'une campagne, et marquer les familles notifiées.
category: Espace animateurs
role_min: intendant
discovery: 1
question: Comment relancer les familles qui n'ont pas encore payé ?
question: Comment éviter de relancer deux fois la même famille ?
paths: /finance/campaigns/*
related: campagnes, rapprochement, mes-paiements, publipostage
---

Le bouton « Brouillon de rappel », sur l'écran d'une campagne, prépare un
publipostage pour chaque enfant dont la famille doit encore quelque
chose : son montant, sa communication et son code QR.

## Il n'envoie rien

Le bouton **prépare** un message, il ne l'expédie pas. Vous le relisez,
vous l'ajustez, et vous l'envoyez depuis la page de publipostage comme
n'importe quel autre message. Un rappel d'argent se relit avant de
partir.

Les créances déjà réglées ou abandonnées sont laissées de côté, et le
montant demandé est ce qui **reste** dû : une famille qui a payé la
moitié la semaine passée ne se voit pas redemander le tout.

## Un e-mail par enfant

Chaque enfant reçoit son propre e-mail, avec son propre montant et son
propre code. Une famille de trois enfants reçoit donc trois e-mails, et
une fratrie de sept en reçoit sept. C'est ce qui invite à un virement
par enfant plutôt qu'à un seul virement groupé — et un virement par
créance, c'est un paiement qui se rattache tout seul à son arrivée.

Chaque rappel est rattaché à l'enfant : il part aux adresses connues sur
sa fiche et apparaît dans les communications de cette fiche. Un enfant
sans adresse dans Desk n'est pas repris dans le brouillon.

Le montant, l'IBAN et la communication sont répétés en toutes lettres
sous le code : beaucoup de logiciels de messagerie bloquent les images
par défaut, et un code invisible ne doit jamais laisser un parent sans
moyen de payer.

> Le texte du brouillon est un point de départ, pas un formulaire. Vous
> pouvez le réécrire entièrement — gardez les variables (prénom,
> montant, communication, QR, bénéficiaire, IBAN), qui remplissent
> l'e-mail de chaque enfant : sans l'IBAN en toutes lettres, un parent
> dont la messagerie bloque les images ne peut pas payer.

## Notifier les familles

Quand le message est parti, revenez sur la campagne et cliquez sur
« Notifier les familles ». Le site ne peut pas le deviner, puisque c'est
vous qui envoyez le message depuis la page de publipostage.

Le geste est séparé pour cette raison, et la date qu'il enregistre est ce
qui vous dit, la fois suivante, si les familles ont déjà été relancées.

## Conservation des variables

Les colonnes libres de votre fichier sont copiées dans le publipostage
lors de la préparation du rappel. Cette copie suit la conservation du
publipostage : 18 mois après le dernier envoi qui l'utilise. Elle reste
conservée tant qu'un brouillon, un test ou un envoi en cours l'utilise ;
une copie sans message associé est supprimée après sept jours.

Le fichier source de la campagne et ses colonnes conservées dans la
campagne suivent la durée des imports Desk, deux années scoutes par
défaut. Leur suppression ne supprime pas les variables d'un rappel déjà
préparé : vous pouvez toujours envoyer ce brouillon. Les nouveaux rappels
préparés après cette suppression ne disposent plus de ces colonnes libres.
