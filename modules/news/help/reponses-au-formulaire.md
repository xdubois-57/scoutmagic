---
id: reponses-au-formulaire
title: Dépouiller les réponses à un formulaire
summary: Lire les inscriptions reçues, suivre les paiements, exporter, écrire aux répondants.
category: Espace animateurs
role_min: intendant
question: Où voir qui s'est inscrit à notre activité ?
question: Comment exporter les inscriptions reçues en Excel ?
paths: /news/*/form/responses
related: publier-une-actualite, modifier-une-reponse, publipostage, recus
---

Cette page rassemble toutes les réponses reçues au formulaire d'un
article : une ligne par réponse, une colonne par question. Elle s'ouvre
depuis l'article, et son accès dépend du réglage « qui peut consulter
les réponses » choisi à la création du formulaire — souvent l'intendant
de la section concernée.

## Lire les réponses

Chaque ligne montre l'adresse de contact utilisée pour répondre et les
réponses données, question par question. Une même personne peut
apparaître plusieurs fois si le formulaire autorise plusieurs réponses.

Le bouton « Modifier » n'apparaît que sur les lignes que vous pouvez
corriger : les vôtres tant que le formulaire est ouvert, et — pour un
chef d'unité seulement — n'importe laquelle, même après la fermeture.
Corriger à la place de quelqu'un sert à rectifier une erreur manifeste,
jamais à décider pour lui.

## Suivre les paiements

Si l'évènement est payant, deux colonnes s'ajoutent : **Attendu**, la
créance ouverte au moment de l'inscription, et **Reçu**, ce qui est
réellement arrivé sur le compte. L'état résume les deux — **Payé**,
**Partiel** ou **Non payé**. Une réponse modifiée après coup ne change
pas la créance : ajustez-la dans les Finances si le dû a bougé.

Ce rapprochement vient des extraits bancaires importés dans les
Finances, via la communication structurée générée à l'inscription :
tant qu'un extrait n'a pas été importé, un paiement pourtant effectué
s'affiche encore comme non reçu. Ce n'est pas une relance à envoyer,
c'est un import à faire.

Un **Partiel** signale le plus souvent un virement fait en une fois
pour plusieurs enfants. Regardez le mouvement bancaire avant de
relancer la famille.

## Exporter et écrire

« Exporter en Excel » télécharge l'ensemble, réponses et états de
paiement compris. Le fichier obtenu est directement réutilisable comme
audience de publipostage — les colonnes deviennent les variables du
message.

« Écrire aux répondants » va plus vite : le bouton prépare un brouillon
de publipostage adressé à tous ceux qui ont répondu, chaque champ du
formulaire disponible comme variable, et vous dépose dans l'écran de
composition. Rien ne part avant que vous ne l'envoyiez. Il demande le
rôle animateur et n'apparaît pas si le module Envoi de mails est
désactivé.

> Les réponses contiennent des données de familles : noms, adresses,
> parfois des informations de santé. Un export part sur votre
> ordinateur, hors du site et hors de sa protection — effacez-le une
> fois le travail terminé.
