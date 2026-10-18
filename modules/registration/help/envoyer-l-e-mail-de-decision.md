---
id: envoyer-l-e-mail-de-decision
title: Envoyer l'e-mail de décision d'une demande
summary: Le bloc « E-mail de décision » de la fiche : où en est l'e-mail, l'envoyer, le réessayer, le renvoyer.
category: Espace chefs d'U
role_min: admin
question: Comment envoyer l'e-mail d'acceptation ou de refus à une famille ?
question: Que faire quand l'e-mail de décision n'a pas pu partir ?
question: Comment renvoyer l'e-mail de décision à une famille ?
question: Enregistrer la note interne envoie-t-il un e-mail ?
paths: /config/inscriptions/demandes/*
related: envoi-automatique-des-e-mails-de-decision, gerer-les-demandes, decider-une-demande, rediger-les-e-mails-de-demande, copie-des-e-mails-d-inscription
---

La famille ne voit une décision **qu'une fois son e-mail parti**. D'ici
là, sa page de suivi dit toujours « en attente ». L'e-mail part à la
main, ou tout seul après un délai si l'envoi automatique est activé
(voir *Envoyer automatiquement les e-mails de décision*). Le message
affiché après chaque décision dit lequel des deux, qu'elle soit prise
depuis la liste, depuis la fiche ou avec « Tout refuser ».

## Le bloc « E-mail de décision »

Sur la fiche d'une demande acceptée ou refusée, ce bloc dit où en est
l'e-mail de la décision actuelle :

- « Non planifié » : rien n'est parti, « Envoyer maintenant » l'envoie ;
- « Prévu le », suivi de la date : l'envoi automatique est planifié,
  « Envoyer maintenant » le fait partir tout de suite ; il part même si
  l'envoi automatique a été désactivé depuis ;
- « Envoyé le », suivi de la date : la famille voit la décision ;
- « Échec le », suivi de la date : l'e-mail n'a pas pu partir,
  « Réessayer » relance l'envoi ;
- « Envoi interrompu » : un envoi resté sans réponse, peut-être parti,
  peut-être pas ; « Réessayer » le relance ;
- « Annulé suite à un changement de décision » : l'e-mail prévu pour
  l'ancienne décision ne partira pas.

Le bloc n'apparaît pas sur une demande en attente ou retirée : il n'y a
pas de décision à annoncer.

## Envoyer, réessayer, renvoyer

Chaque bouton demande une confirmation, puisqu'un e-mail parti ne se
rappelle pas. « Renvoyer l'e-mail » apparaît une fois l'e-mail envoyé :
utile quand une famille ne le retrouve pas. Chaque envoi, nouvel essai ou
renvoi part avec la copie à l'unité, si elle est activée dans
« Réglages ».

Rien n'est à rédiger avant d'envoyer : l'acceptation et le refus ont un
texte fourni par le site (voir *Rédiger les e-mails envoyés pour une
demande d'inscription*). **Chaque e-mail reprend le lien de suivi reçu
au dépôt**, sans en créer un : renvoyer ou réessayer ne le change pas. Un
texte qui aurait perdu son lien n'est jamais envoyé : l'essai est noté
« Échec », avec la raison. Si le lien a expiré, l'e-mail part quand même
et prévient la famille que le suivi public n'est plus accessible.

L'« Historique » garde chaque étape — planifié, envoyé, échec, annulé —
sans jamais en effacer une, avec la date de chacune. Un échec y porte une
phrase qui dit ce qui s'est passé ; le détail technique reste dans le
journal du site.

Après un changement de décision, la famille reste « en attente » jusqu'à
l'e-mail de la nouvelle décision, même si celui de l'ancienne était déjà
parti.

## La note interne

La « Note interne » se modifie à part, avec « Enregistrer la note ».
L'enregistrer n'envoie jamais d'e-mail et ne change pas la décision. La
famille ne la voit jamais.
