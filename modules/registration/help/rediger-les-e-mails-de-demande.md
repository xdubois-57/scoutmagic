---
id: rediger-les-e-mails-de-demande
title: Rédiger les e-mails envoyés pour une demande d'inscription
summary: L'accusé de réception, l'alerte à l'unité, l'acceptation et le refus : leur sujet, leur texte, leurs variables et le lien de suivi qu'ils doivent contenir.
category: Espace chefs d'U
role_min: admin
question: Comment rédiger l'e-mail d'acceptation d'une demande d'inscription ?
question: Pourquoi mon texte d'e-mail n'est-il pas enregistré ?
question: Comment revenir au texte d'origine d'un e-mail de demande ?
paths: /config/inscriptions/gabarits, /config/inscriptions/gabarits/*
related: lien-de-suivi-d-une-demande, gerer-les-demandes, config-emails, config-reinscription-emails
---

L'onglet « Gabarits » de la page « Formulaire d'inscription » rassemble
les quatre e-mails qu'une demande d'inscription peut déclencher :

- « Accusé de réception d'une demande d'inscription », envoyé à la
  famille dès le dépôt ;
- « Alerte à l'unité pour une nouvelle demande d'inscription », envoyé à
  l'adresse d'alerte de l'unité ;
- « Acceptation » et « Refus d'une demande d'inscription », envoyés à la
  famille depuis la fiche d'une demande, ou tout seuls après un délai si
  l'envoi automatique est activé.

Ce sont les mêmes e-mails que dans **Configuration › E-mails**, avec la
même page de modification : les changer ici ou là-bas revient au même.

## L'état de chaque e-mail

Les quatre e-mails ont un texte fourni par le site : aucun n'est à
rédiger avant de pouvoir partir. Un badge dit quel texte part :

- « Par défaut » : le texte fourni par le site, qui suivra ses
  améliorations ;
- « Personnalisé » : le sujet ou le texte que l'unité a écrit.

Le texte de l'acceptation dit que la demande « a été acceptée » ; celui
du refus dit qu'elle « n'a pas été retenue », sans en donner de motif.

## Modifier un e-mail

Touchez son nom pour ouvrir sa page :

- **le sujet**, une ligne de texte ;
- **le corps**, qui s'ouvre avec le crayon, dans l'éditeur habituel.
  Inutile de signer : la formule finale et le nom de l'unité sont
  ajoutés à l'envoi ;
- **l'aperçu** avec des valeurs d'exemple, et « M'envoyer un test ».

Les **variables** — le prénom de l'enfant, l'année scoute, le lien de
suivi — s'insèrent avec leurs boutons et sont
remplacées à l'envoi. Chaque e-mail ne propose que les siennes :
l'alerte à l'unité peut citer le créneau et le lien vers la fiche, mais
ni les coordonnées de la famille ni son lien de suivi. Écrit quand même
dans l'alerte, ce lien partirait tel quel, entre accolades, jamais
remplacé.

## Le lien de suivi est obligatoire

Dans l'accusé de réception, l'acceptation et le refus, le bouton du lien
de suivi est marqué **« obligatoire »** :

- un texte qui ne le contient pas n'est pas enregistré, et le message
  dit quelle variable manque ;
- un texte enregistré autrement qui l'aurait perdu n'est pas envoyé : la
  page de l'e-mail le signale en rouge, et la fiche de la demande
  affiche l'échec.

C'est le même lien dans les trois e-mails, créé au dépôt et jamais
renouvelé. Voir *Le lien de suivi d'une demande d'inscription*.

## Revenir au texte par défaut

« Revenir au gabarit par défaut » supprime le sujet et le texte de
l'unité : l'e-mail repart avec ceux du site, qui contiennent toujours le
lien de suivi.

Les e-mails de la campagne de réinscription se règlent dans la gestion
des réinscriptions.
