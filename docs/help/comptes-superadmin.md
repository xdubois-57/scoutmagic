---
id: comptes-superadmin
title: Gérer les comptes superadmin
summary: Les comptes qui administrent le site en dehors du Desk : en ajouter un, lui retirer le droit, et pourquoi certains retraits sont refusés.
category: Configuration
role_min: superadmin
paths: /config/superadmins
related: config-desk, mon-compte, journal
---

Un compte superadmin administre le site. C'est le seul accès qui ne
dépend d'aucune fonction dans le Desk : il reste valable même si un
import remplace tout le Staff d'Unité. Les animateurs et les chefs
d'unité, eux, tiennent leur accès de leur fonction, et ne se gèrent pas
depuis cette page.

## Ajouter un compte

Vous n'avez besoin que d'une adresse e-mail. Si aucun compte n'existe
pour cette adresse, il est créé. Si un compte existe déjà — un chef
d'unité, par exemple — il reçoit simplement le droit en plus de ce
qu'il a déjà.

Il n'y a ni mot de passe à choisir, ni invitation à accepter. La
personne se connecte comme tout le monde : elle demande un lien magique
depuis la page de connexion, et le clic sur ce lien prouve à lui seul
que l'adresse est bien la sienne.

Ajouter une adresse qui est déjà superadmin ne crée pas de doublon : le
site vous le signale et ne change rien.

## Retirer le droit

« Retirer le droit » enlève l'accès d'administration, et rien d'autre.
Le compte reste : son mot de passe, ses clés numériques et ses
préférences de notification sont conservés, et un chef d'unité qui
garde par ailleurs un accès légitime le garde entièrement.

## Deux retraits que le site refuse

- **Votre propre accès.** Vous ne pouvez pas vous retirer vous-même.
  Demandez-le à un autre compte superadmin.
- **Le dernier accès actif.** Le site garde toujours au moins un
  compte superadmin capable de se connecter. Ajoutez d'abord quelqu'un
  d'autre, puis retirez.

Ces deux refus sont vérifiés par le site lui-même, pas seulement par
le bouton grisé à l'écran.

## Ce que vous voyez dans la liste

Pour chaque compte : son adresse, la date de création, la dernière
connexion — « Jamais » tant que la personne ne s'est pas connectée — et
son état.

> Un accès superadmin ouvre toute la configuration du site, y compris
> les données personnelles des membres. N'en accordez qu'aux personnes
> qui en ont réellement besoin.

Chaque ajout et chaque retrait est enregistré dans le journal, ainsi
que les tentatives refusées.
