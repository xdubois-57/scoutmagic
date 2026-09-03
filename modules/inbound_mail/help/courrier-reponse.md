---
id: courrier-reponse
title: L'adresse de réponse signée
summary: Pourquoi les e-mails du site portent une adresse de réponse à rallonge, et quand la désactiver.
category: Configuration
role_min: superadmin
question: Pourquoi l'adresse de réponse du site contient-elle un « + » et une référence ?
question: Comment faire reconnaître une réponse sans référence dans l'objet ?
question: Les réponses à nos e-mails reviennent en erreur, que faire ?
paths: /config/courrier-entrant
related: courrier-entrant, courrier-portee, locations-courrier
---

Quand le site écrit au sujet d'un dossier — l'accusé de réception d'une
demande de location, un contrat, les informations pratiques —, l'e-mail
porte une **adresse de réponse** de la forme
*locations+rental.LOC-2027-0042.9f3a1b2c4d5e@votre-unite.be*.

Un simple « Répondre » renvoie le message à cette adresse, et le site y
lit le dossier concerné avant même d'en avoir lu un mot : la réponse est
rattachée à la réservation **dès son arrivée**, même si l'objet a été
réécrit, même si c'est le trésorier du groupe qui répond à la place du
locataire, même sans aucune conversation connue.

## Pourquoi une signature

La partie *+rental.LOC-2027-0042* désigne le dossier ; les douze
caractères qui suivent sont une signature que seul le site sait
calculer. Une adresse fabriquée à la main avec une référence lue sur un
contrat ne vaut rien : sans la bonne signature, c'est une adresse
ordinaire, et le message suit les règles habituelles.

## Ce qu'il faut pour que ça marche

- une boîte relevée par le site, ouverte au module concerné, dont le
  **compte est une adresse e-mail** (locations@votre-unite.be, pas un
  identifiant technique) ;
- un fournisseur qui accepte les adresses « +étiquette » : Gmail,
  Outlook, Infomaniak, OVH et la plupart des autres le font. Le message
  arrive alors dans la boîte ordinaire, avec l'adresse complète en
  destinataire.

Sans boîte qui convienne, aucune adresse n'est ajoutée et les e-mails
partent avec l'expéditeur habituel du site.

## Désactiver

Le réglage « Adresse de réponse signée sur les e-mails envoyés », dans
*Configuration > Réglages*, est activé par défaut. Désactivez-le si les
réponses de vos correspondants reviennent en erreur : c'est le signe
d'un fournisseur qui refuse les « + ». Les autres règles de
rattachement — référence dans l'objet, conversation, adresse de
l'expéditeur — restent en place, et les réponses aux e-mails déjà
envoyés continuent d'être reconnues.
