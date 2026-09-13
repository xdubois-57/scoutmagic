---
id: courrier-sortant
title: Par où partent les e-mails du site
summary: Les fournisseurs d'envoi, les trois voies et leur chaîne de repli.
category: Configuration
role_min: superadmin
question: Pourquoi les e-mails du site ne partent-ils plus ?
question: Comment ajouter un deuxième fournisseur d'envoi ?
question: Pourquoi un publipostage empêche-t-il les liens de connexion de partir ?
question: À quoi sert la cadence d'un fournisseur ?
paths: /config/courrier-sortant, /config/courrier-sortant/acheminement, /config/courrier-sortant/fournisseurs/nouveau, /config/courrier-sortant/fournisseurs/*
related: installation-serveur, config-emails, adresses-email
---

Le site envoie du courrier pour trois raisons très différentes : vous
connecter, vous prévenir, et écrire aux familles. La page « Courrier
sortant » dit par où chacun de ces trois courriers passe, et ce qui se
produit quand ce chemin ne répond plus.

## Un fournisseur suffit pour commencer

Un **fournisseur** est un relais qui accepte vos messages et les remet.
Sur sa fiche : son nom, son serveur, son port et ses identifiants. Ceux-ci
sont conservés chiffrés en dehors de la base de données. Le **mot de passe**
ne vous est jamais réaffiché — laissez le champ vide pour conserver celui
qui est enregistré. L'**identifiant**, lui, reste visible : sans cela vous
ne sauriez pas quel compte sert, et l'effaceriez en enregistrant.

L'**envoi local** est toujours présent, en bas de liste : le serveur
expédie lui-même, sans relais. Il ne se supprime pas, seulement se
désactive ou se déplace. Gardez-le en dernier recours : beaucoup
d'hébergeurs bloquent la sortie, et la réputation de leur adresse IP ne
vous appartient pas.

## Trois voies, trois chaînes

Dans « Acheminement », chaque voie porte une liste ordonnée. On essaie le
premier fournisseur actif ; s'il échoue ou s'il a atteint son quota du
jour, on passe au suivant.

- **Authentification** — les liens de connexion et les confirmations
  d'adresse.
- **Transactionnel** — les notifications, les alertes, les accusés de
  réception.
- **Masse** — les publipostages, et rien d'autre.

Glissez pour réordonner sur un grand écran, utilisez les flèches sur un
téléphone. Une voie garde toujours **au moins un fournisseur actif** : en
vider une, c'est l'arrêter — et vider la voie « Authentification »
laisserait tout le monde à la porte du site, vous compris.

C'est ici que se règle le problème le plus courant : un publipostage de
quatre cents messages épuise en une fois le quota quotidien d'un relais
gratuit. Désactivez ce relais dans la voie « Masse » et le publipostage ne
peut plus consommer le quota dont dépendent vos liens de connexion.

## La cadence, et ce qu'elle ne fait pas

Derrière « Avancé », chaque fournisseur porte une cadence — tant de
messages toutes les tant de minutes — et un quota journalier. La cadence
ne s'applique qu'à la voie « Masse ». Une notification ou un lien de
connexion part immédiatement : un lien qui attendrait le prochain créneau
d'un lot ne serait plus un lien valide, il n'a qu'un quart d'heure à
vivre.

Quand une voie bascule sur le fournisseur suivant, elle adopte **sa**
cadence et **son** quota, jamais ceux du précédent.

> Un message classé en indésirables n'apparaît nulle part sur cette page :
> il a été accepté. Les compteurs disent ce qui est parti, pas ce qui a
> été lu.
