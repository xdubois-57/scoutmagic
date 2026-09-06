---
id: support-github
title: Citer un ticket dans un signalement GitHub
summary: La référence d'un ticket de support, citée dans un signalement public, donne au triage automatique une copie anonymisée de l'archive.
category: Configuration
role_min: superadmin
question: Comment signaler un bug sur GitHub sans exposer mes journaux ?
question: À quoi sert la référence du ticket de support sur GitHub ?
question: Puis-je joindre le paquet de support à un signalement GitHub ?
paths: /config/support
related: support, support-sondes-email
---

Les dysfonctionnements du logiciel sont suivis publiquement sur GitHub,
et le premier examen d'un signalement y est fait automatiquement, dans
les minutes qui suivent son ouverture. Ce triage lit le code du logiciel,
pas votre serveur : sans autre information que le texte du signalement,
il ne peut souvent que poser une question.

## La référence fait le lien

Envoyez d'abord vos informations techniques depuis « Envoyer des
informations techniques », avec l'archive de diagnostic. La page affiche
alors la **référence** de cet envoi, six caractères derrière `SUP-`.
Ouvrez ensuite le signalement sur GitHub et recopiez cette référence dans
le champ « Référence du ticket de support » du formulaire. Dans cet
ordre : le triage part dès que le signalement est créé, et une référence
ajoutée après coup n'est lue que si le triage vous la demande dans sa
réponse — répondez-lui alors en la citant.

Le triage demande alors à l'équipe de support une **copie réduite et
anonymisée** de l'archive transmise avec cet envoi. Les adresses IP, les
comptes utilisateurs, les adresses e-mail et les identifiants de membres
reconnaissables y sont remplacés par des jetons sans correspondance
conservée ; la configuration détaillée du serveur, les paramètres du site
et le texte de votre description en sont retirés ; l'adresse de contact
n'y figure pas. L'adresse de votre site peut y rester là où le serveur
l'a écrite, dans le chemin d'un journal par exemple : elle désigne l'unité,
pas une personne. Cette copie est produite à la demande, n'est jamais
conservée, et est lue par un service d'intelligence artificielle, sur
l'infrastructure de GitHub, qui rédige la première réponse. Chaque envoi
est inscrit au journal de l'équipe de support.

Seule la personne qui a fait l'envoi en connaît la référence, et seuls
l'auteur du signalement et le mainteneur du logiciel peuvent la citer
sur celui-ci, dans le formulaire ou en réponse ; une référence écrite
par quelqu'un d'autre n'est pas lue. Personne ne peut donc faire lire au
triage l'archive d'une autre unité.

## Ce qu'il ne faut jamais faire

> **Ne joignez jamais le paquet de support à un signalement GitHub**, ni
> en pièce jointe, ni en copiant son contenu. Tout ce qui est déposé sur
> le dépôt est visible par tous, et le reste même après suppression. La
> référence suffit : c'est elle qui donne accès à la copie anonymisée,
> et rien d'autre ne doit sortir de votre serveur.

Ce que la case d'acceptation, sur la page Support, vous demande d'accepter
avant l'envoi d'une archive couvre aussi cette copie anonymisée — et
c'est elle qui compte : un envoi fait depuis une version du site dont
la case ne mentionnait pas encore le triage ne donne accès à aucune
copie, quelle que soit la référence citée. Refaites alors un envoi depuis
la version à jour.
