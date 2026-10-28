---
id: envoi-automatique-des-e-mails-de-decision
title: Envoyer automatiquement les e-mails de décision
summary: Faire partir l'e-mail d'acceptation ou de refus tout seul, quelques heures après la décision.
category: Espace chefs d'U
role_min: admin
question: Comment envoyer automatiquement l'e-mail d'acceptation ou de refus ?
question: Combien de temps après la décision la famille reçoit-elle l'e-mail ?
question: Que deviennent les e-mails prévus si je désactive l'envoi automatique ?
paths: /config/inscriptions/reglages
related: echec-envoi-decision, envoyer-l-e-mail-de-decision, acceptation-automatique-des-demandes, decider-une-demande, rediger-les-e-mails-de-demande, capacites-et-listes-d-attente
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

Rien n'est à rédiger d'abord : l'acceptation et le refus ont chacun un
texte fourni par le site, que « Gabarits » permet de personnaliser. Ces
textes contiennent toujours le lien de suivi de la famille, le même que
celui de son accusé de réception.

## Ce qui se passe ensuite

Une acceptation ou un refus pris après l'activation — depuis le dialogue
de décision, la fiche ou « Tout refuser » —, sur une demande de l'année
en cours ou de l'année visée, planifie son e-mail pour l'heure de la décision plus le délai. Le dialogue et le
message après la décision donnent la date ; la fiche montre « Prévu le »,
suivi de cette date, et « Envoyer maintenant » le fait partir tout de
suite.

Tant que l'e-mail n'est pas parti, vous pouvez changer d'avis : changer
la décision annule l'e-mail prévu, et celui de la nouvelle décision
attend à nouveau le délai complet. La famille voit « en attente »
jusqu'à l'envoi.

Les autres décisions ne sont pas planifiées : celles prises **avant**
l'activation, et celles qui closent une demande d'une année passée. Leur
e-mail s'envoie depuis la fiche, avec « Envoyer maintenant ».

Deux envois du même e-mail ne peuvent pas partir en même temps. Mais si
un envoi a été interrompu après que le serveur d'envoi l'a accepté, la
fiche dit « Envoi interrompu » et la famille l'a peut-être déjà reçu :
vérifiez auprès d'elle avant de choisir « Réessayer ». Un échec reste
visible dans l'« Historique » de la fiche ; l'e-mail dont le texte aurait
perdu son lien de suivi n'est jamais envoyé sans lui et y est noté
« Échec ».

## Si l'envoi échoue

Les erreurs temporaires donnent lieu à deux réessais bornés, puis à une
notification aux administrateurs si l'échec persiste. Voir l'aide
**Résoudre un échec d'e-mail de décision**, qui explique aussi quand
intervenir à la main.

## Le désactiver

Décochez l'interrupteur et « Enregistrer ». Les nouvelles décisions ne
planifient plus rien, mais **les e-mails déjà prévus partent quand même
à leur échéance** : leur fiche le rappelle et propose « Envoyer
maintenant ».

L'acceptation automatique des demandes « Oui », juste en dessous, en
dépend : décocher l'envoi automatique la décoche aussi.
