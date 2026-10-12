---
id: envoi-automatique-des-e-mails-de-decision
title: Envoyer automatiquement les e-mails de décision
summary: Faire partir l'e-mail d'acceptation ou de refus tout seul, quelques heures après la décision.
category: Espace chefs d'U
role_min: admin
question: Comment envoyer automatiquement l'e-mail d'acceptation ou de refus ?
question: Combien de temps après la décision la famille reçoit-elle l'e-mail ?
question: Que deviennent les e-mails prévus si je désactive l'envoi automatique ?
question: Pourquoi ne puis-je pas activer l'envoi automatique des e-mails de décision ?
paths: /config/inscriptions/reglages
related: envoyer-l-e-mail-de-decision, decider-une-demande, rediger-les-e-mails-de-demande, capacites-et-listes-d-attente
---

Par défaut, l'e-mail d'acceptation ou de refus s'envoie à la main,
depuis la fiche de la demande. Le site peut aussi le faire partir tout
seul, après un délai qui laisse le temps de corriger une erreur.

## L'activer

Dans l'onglet « Réglages », la boîte « Capacités par branche » commence
par la sous-boîte « Décisions automatiques ». Cochez « Envoyer
automatiquement les e-mails de décision », choisissez le « Délai avant
envoi » (en heures, 24 par défaut, de 1 à 720), puis « Enregistrer ». Le
message qui s'affiche dit à partir de quand les e-mails partiront.

**L'interrupteur reste grisé tant que les gabarits d'acceptation et de
refus sont « À rédiger »** : un e-mail sans texte ne peut pas partir.
Rédigez-les d'abord dans « Gabarits ».

## Ce qui se passe ensuite

Chaque acceptation ou refus — depuis le dialogue de décision, la fiche ou
« Tout refuser » — planifie son e-mail pour l'heure de la décision plus
le délai. Le dialogue et le message après la décision donnent la date ;
la fiche montre « Prévu le », suivi de cette date, et « Envoyer
maintenant » le fait partir tout de suite.

Tant que l'e-mail n'est pas parti, vous pouvez changer d'avis : changer
la décision annule l'e-mail prévu, et celui de la nouvelle décision
attend à nouveau le délai complet. La famille voit « en attente »
jusqu'à l'envoi.

Les décisions prises **avant** l'activation ne sont pas planifiées :
leur e-mail s'envoie toujours depuis la fiche.

Le site n'envoie jamais deux fois le même e-mail. Si un envoi a été
interrompu sans qu'on sache s'il est parti, la fiche le dit, « Envoi
interrompu », et c'est à vous de choisir « Réessayer ». Un échec reste
visible dans l'« Historique » de la fiche.

## Le désactiver

Décochez l'interrupteur et « Enregistrer ». Les nouvelles décisions ne
planifient plus rien, mais **les e-mails déjà prévus partent quand même
à leur échéance** : leur fiche le rappelle et propose « Envoyer
maintenant ».
