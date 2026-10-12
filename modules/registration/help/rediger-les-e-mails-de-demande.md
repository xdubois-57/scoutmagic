---
id: rediger-les-e-mails-de-demande
title: Rédiger les e-mails envoyés pour une demande d'inscription
summary: L'accusé de réception, l'alerte à l'unité, l'acceptation et le refus : leur texte, leurs variables et leur état.
category: Espace chefs d'U
role_min: admin
question: Comment rédiger l'e-mail d'acceptation d'une demande d'inscription ?
question: Pourquoi ne puis-je pas envoyer l'e-mail de décision à une famille ?
question: Comment revenir au texte d'origine de l'accusé de réception ?
paths: /config/inscriptions/gabarits, /config/inscriptions/gabarits/*
related: gerer-les-demandes, config-reinscription-emails
---

L'onglet « Gabarits » de la page « Formulaire d'inscription » rassemble
les quatre e-mails qu'une demande d'inscription peut déclencher :

- « Accusé de réception de la demande », envoyé à la famille dès le dépôt ;
- « Alerte à l'unité », envoyé à l'adresse d'alerte de l'unité à chaque
  nouvelle demande ;
- « Acceptation de la demande » et « Refus de la demande », envoyés à la
  famille depuis la fiche d'une demande, une fois la décision prise — ou
  tout seuls après un délai, si l'envoi automatique est activé.

## L'état de chaque e-mail

À côté de chaque e-mail, un badge dit où il en est :

- « Par défaut » : l'e-mail part avec le texte fourni par le site ;
- « Personnalisé » : l'e-mail part avec le texte que l'unité a écrit ;
- « À rédiger » : l'e-mail n'a pas encore de texte, et il ne peut pas
  partir.

L'acceptation et le refus n'ont pas de texte par défaut : une décision
se formule avec les mots de l'unité. Tant que l'un des deux est « À
rédiger », un avertissement le rappelle en haut de l'onglet, et le
bouton « Envoyer » de la fiche reste grisé. La famille continue alors de
voir sa demande « en attente » : elle ne découvre une décision que par
son e-mail.

## Modifier un texte

Le crayon d'une ligne ouvre l'éditeur, qui montre le texte qui part
aujourd'hui. Mettez-le en forme comme ailleurs sur le site, puis
« Enregistrer ». Un texte vide n'est pas accepté.

Les **variables** — le prénom de l'enfant, l'année scoute, le lien de
suivi propre à la famille, le nom de l'unité — s'insèrent avec « Insérer
un champ » et sont remplacées à l'envoi. Le tableau à côté de l'éditeur
liste celles que chaque e-mail connaît : l'alerte à l'unité, par
exemple, peut citer le créneau et le lien vers la fiche, mais jamais les
coordonnées de la famille. Une variable que l'e-mail ne connaît pas est
signalée au-dessus de l'éditeur, car elle partirait telle quelle.

## Revenir au texte par défaut

Pour l'accusé de réception et l'alerte à l'unité, un texte personnalisé
peut être abandonné avec « Rétablir le texte par défaut » : le texte
écrit par l'unité est alors perdu, et l'e-mail repart avec celui du site.

Les e-mails de la campagne de réinscription ne sont pas ici : ils se
règlent dans la gestion des réinscriptions.
