---
id: lien-de-suivi-d-une-demande
title: Le lien de suivi d'une demande d'inscription
summary: Un seul lien par demande, repris dans les trois e-mails à la famille, valable 18 mois par défaut.
category: Espace chefs d'U
role_min: admin
question: Combien de temps le lien de suivi d'une famille fonctionne-t-il ?
question: Comment changer la durée de validité du lien de suivi ?
question: Que se passe-t-il si un texte d'e-mail n'a plus son lien de suivi ?
paths: /config/inscriptions/reglages
related: rediger-les-e-mails-de-demande, envoyer-l-e-mail-de-decision, suivre-une-demande
---

Chaque demande d'inscription a **un seul lien de suivi**, créé au dépôt
et repris tel quel dans l'accusé de réception, l'acceptation et le
refus. Il ne change ni à la décision, ni lors d'un renvoi ou d'un
nouvel essai après un échec d'envoi : la famille peut garder le premier
e-mail et s'en servir tout du long. Il n'y a ni régénération ni
révocation à gérer.

## Combien de temps il fonctionne

Le lien fonctionne **18 mois à compter du dépôt de la demande** (une
demande déposée le 18 octobre 2026 à 12 h : lien actif jusqu'au 18 avril
2028 à 12 h). Ensuite, ouvrir le lien affiche « introuvable », comme un
lien erroné. C'est le même délai pour toutes les demandes, y compris
celles déposées en cours d'année avec un code ou tardivement.

Il ne concerne que la page publique par lien : l'état de la demande et sa
conservation ne changent pas, et la famille qui se connecte avec
l'adresse de la demande la retrouve toujours dans « Espace membres ».

Un e-mail de décision envoyé après l'échéance part quand même : le lien
n'est ni renouvelé ni prolongé, et l'e-mail précise que le suivi public
n'est plus accessible.

## Changer la durée

Dans Configuration › Réglages › Inscriptions, « Durée de validité du lien
de suivi (mois) », de 1 à 120. Elle ne s'applique qu'aux demandes
déposées **après** la modification, jamais à celles qui existent déjà.

## Si un texte perd son lien

Un texte enregistré ailleurs que dans « Gabarits » peut ne plus contenir
la variable « lien de suivi ». Le site n'envoie alors **jamais** l'e-mail
sans lien : l'accusé de réception n'est pas envoyé (la demande, elle, est
bien enregistrée) et l'envoi d'une décision est noté « Échec » dans la
fiche, avec la raison. Corrigez le texte dans « Gabarits », puis
« Réessayer ».
