---
id: courrier-sortant-dmarc
title: Qui envoie du courrier en votre nom
summary: Ce que racontent les rapports DMARC, comment les recevoir, et le piège à éviter avant de durcir votre politique.
category: Configuration
role_min: superadmin
question: Qui envoie des messages avec l'adresse de mon unité ?
question: Pourquoi je ne reçois aucun rapport DMARC ?
question: Un serveur inconnu envoie en mon nom, est-ce grave ?
question: Puis-je passer ma politique DMARC à reject ?
paths: /config/courrier-sortant/dmarc
related: courrier-sortant, courrier-sortant-authentification, courrier-sortant-sonde, courrier-sortant-rebonds
---

## À quoi sert cette page

Chaque grand fournisseur de boîtes aux lettres — Gmail, Outlook, Yahoo —
tient le compte des messages qu'il reçoit **en votre nom** : ceux dont
l'expéditeur annonce le domaine de votre unité. Une fois par jour, il
vous envoie un résumé : quels serveurs ont écrit chez lui avec votre
adresse, combien de messages, et s'ils étaient correctement authentifiés
par SPF ou par DKIM.

Cette page rassemble ces résumés, et répond à une seule question : **qui
envoie du courrier en votre nom, et est-ce que ça passe ?**

## Recevoir les rapports

Si la page dit « Aucun rapport reçu », il manque l'une des trois :

1. **Votre DNS les demande.** C'est le champ `rua` de votre
   enregistrement DMARC, que la page « Authentification » vous montre.
   Sans lui, personne ne vous écrit.
2. **L'adresse du `rua` existe.** Une adresse inventée ne reçoit rien, et
   personne ne vous préviendra.
3. **Le site relève cette boîte**, par le module « Courrier entrant ».

Comptez un à deux jours entre la publication du `rua` et le premier
rapport.

## Lire le tableau

Chaque ligne est un **serveur expéditeur**, jamais une personne : ces
rapports ne nomment aucun destinataire, ce sont des compteurs.

Un badge portant un nom de fournisseur est un de vos relais déclarés :
rien à faire. Un badge « Autre » veut dire que nous ne l'avons pas
reconnu — ce qui ne veut pas dire malveillant.

## Le piège, avant de durcir

Un serveur inconnu qui envoie en votre nom **et dont les messages sont
authentifiés** n'est presque jamais une usurpation : un usurpateur n'a
pas vos clés, ses messages échouent. C'est neuf fois sur dix un outil de
l'unité que plus personne n'a en tête — ancienne plateforme
d'inscription, service d'infolettre, boîte personnelle paramétrée avec
l'adresse de l'unité, formulaire de l'ancien site.

**Identifiez-les avant de passer votre politique à `p=reject`**, qui
demande aux fournisseurs de jeter tout ce qui n'est pas authentifié. Le
jour où vous durcissez, ces outils cessent de fonctionner, et vous
l'apprenez par la personne qui ne reçoit plus rien, des semaines plus
tard. L'ordre qui marche : `p=none`, attendre, faire le tour des serveurs
inconnus qui réussissent, durcir ensuite.

À l'inverse, un serveur inconnu dont **tout échoue** n'est pas une
urgence : ces messages sont déjà écartés chez le destinataire.

## Ce que ces rapports ne disent pas

**Authentifié n'est pas lu.** Un message parfaitement signé peut très
bien être dans les indésirables. Seule la page « Sonde » répond à cette
question-là.

Les rapports qui contiendraient vos messages eux-mêmes, dits
« forensiques », ne sont ni demandés ni acceptés par ce site.

## Combien de temps c'est gardé

Quatre-vingt-dix jours. La page en montre trente ; les autres servent à
dater un serveur que vous venez de remarquer.
