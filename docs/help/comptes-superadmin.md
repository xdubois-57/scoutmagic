---
id: comptes-superadmin
title: Gérer les comptes superadmin
summary: Les comptes qui administrent le site en dehors du Desk : en ajouter un, retirer le droit, suspendre un accès, et les changements que le site refuse.
category: Configuration
role_min: superadmin
paths: /config/superadmins
related: config-desk, mon-compte, journal
---

Un compte superadmin administre le site. C'est le seul accès qui ne
dépend d'aucune fonction dans le Desk : il reste valable même si un
import remplace tout le Staff d'Unité. Les animateurs et les chefs
d'unité, eux, tiennent leur accès de leur fonction et ne se gèrent pas
depuis cette page.

## Ajouter un compte

Vous n'avez besoin que d'une adresse e-mail. Si aucun compte n'existe
pour cette adresse, il est créé ; s'il en existe déjà un, il reçoit le
droit en plus de ce qu'il a. Ajouter une adresse déjà superadmin ne crée
pas de doublon.

Il n'y a ni mot de passe à choisir, ni invitation à accepter. La
personne demande un lien magique depuis la page de connexion, et le clic
sur ce lien prouve à lui seul que l'adresse est la sienne.

## Retirer le droit

« Retirer le droit » enlève l'accès d'administration, et rien d'autre.
Le compte reste : mot de passe, clés numériques et préférences de
notification sont conservés. Un chef d'unité qui garde par ailleurs un
accès légitime le garde entièrement.

## Suspendre un accès sans le retirer

L'interrupteur « Actif » suspend un accès sans toucher au droit. Un
compte désactivé ne peut plus se connecter, par aucun moyen, et la
session qu'il avait ouverte prend fin au clic suivant.

C'est ce que vous utilisez pour un départ dont vous n'êtes pas encore
sûr, ou le temps de tirer au clair un accès suspect. L'enregistrement
est automatique : il n'y a pas de bouton « Enregistrer ». Le compte
suspendu reste dans la liste, en grisé.

## Deux changements que le site refuse

- **Votre propre accès.** Vous ne pouvez ni vous retirer le droit, ni
  vous désactiver vous-même. Demandez-le à quelqu'un d'autre.
- **Le dernier accès actif.** Le site garde toujours au moins un compte
  superadmin capable de se connecter.

Ces refus valent pour le retrait comme pour la désactivation, et c'est
le site lui-même qui les applique.

## Les personnes concernées sont prévenues

Un e-mail part automatiquement quand un accès est accordé — il dit qui
l'a accordé et comment se connecter —, quand il est retiré, et quand un
compte est désactivé. La réactivation n'envoie rien : l'accès
refonctionne, simplement.

> Un accès superadmin ouvre toute la configuration du site, y compris
> les données personnelles des membres. N'en accordez qu'aux personnes
> qui en ont réellement besoin.

Chaque changement est enregistré dans le journal, ainsi que les
tentatives refusées.
