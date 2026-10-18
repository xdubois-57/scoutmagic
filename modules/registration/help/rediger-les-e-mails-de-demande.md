---
id: rediger-les-e-mails-de-demande
title: Rédiger les e-mails envoyés pour une demande d'inscription
summary: L'accusé de réception, l'alerte à l'unité, l'acceptation et le refus : leur texte, leurs variables et le lien de suivi qu'ils doivent contenir.
category: Espace chefs d'U
role_min: admin
question: Comment rédiger l'e-mail d'acceptation d'une demande d'inscription ?
question: Pourquoi mon texte d'e-mail n'est-il pas enregistré ?
question: Comment revenir au texte d'origine d'un e-mail de demande ?
paths: /config/inscriptions/gabarits, /config/inscriptions/gabarits/*
related: lien-de-suivi-d-une-demande, gerer-les-demandes, config-reinscription-emails
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

Les quatre e-mails ont un texte fourni par le site : aucun n'est à
rédiger avant de pouvoir partir, ni à la main ni automatiquement. À
côté de chaque e-mail, un badge dit quel texte part :

- « Par défaut » : l'e-mail part avec le texte fourni par le site ;
- « Personnalisé » : l'e-mail part avec le texte que l'unité a écrit.

Le texte de l'acceptation dit que la demande « a été acceptée » ; celui
du refus dit qu'elle « n'a pas été retenue », sans en donner de motif.
Si ces mots ne sont pas ceux de l'unité, elle les remplace par les
siens.

## Modifier un texte

Le crayon d'une ligne ouvre l'éditeur, qui montre le texte qui part
aujourd'hui. Mettez-le en forme comme ailleurs sur le site, puis
« Enregistrer ». Un texte vide n'est pas accepté.

Les **variables** — le prénom de l'enfant, l'année scoute, le lien de
suivi propre à la famille, le nom de l'unité — s'insèrent avec « Insérer
un champ » et sont remplacées à l'envoi. Le tableau à côté de l'éditeur
liste celles que chaque e-mail connaît : l'alerte à l'unité, par
exemple, peut citer le créneau et le lien vers la fiche, mais jamais les
coordonnées de la famille ni le lien de suivi. Une variable que l'e-mail
ne connaît pas est signalée au-dessus de l'éditeur, car elle partirait
telle quelle.

## Le lien de suivi est obligatoire

L'accusé de réception, l'acceptation et le refus doivent contenir la
variable « lien de suivi » : un texte qui ne l'a pas n'est pas enregistré,
et le site dit comment la remettre. C'est le même lien dans les trois
e-mails, créé au dépôt de la demande et jamais renouvelé. Sa durée de
validité, son réglage et ce qui se passe quand un texte le perd sont
détaillés dans *Le lien de suivi d'une demande d'inscription*.

## Revenir au texte par défaut

Un texte personnalisé peut être abandonné avec « Rétablir le texte par
défaut » : le texte écrit par l'unité est alors perdu, et l'e-mail repart
avec celui du site — qui contient toujours le lien de suivi.

Les e-mails de la campagne de réinscription ne sont pas ici : ils se
règlent dans la gestion des réinscriptions.
