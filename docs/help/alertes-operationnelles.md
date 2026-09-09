---
id: alertes-operationnelles
title: Être prévenu quand le site va mal
summary: Les alertes que le site envoie de lui-même, et pourquoi il ne les répète pas.
category: Configuration
role_min: admin
discovery: off
question: Pourquoi ai-je reçu un e-mail disant que le disque est presque plein ?
question: Le site peut-il me prévenir si les sauvegardes ne se font plus ?
question: Pourquoi l'alerte ne revient-elle pas alors que le problème dure ?
paths: /admin/points-attention
related: sauvegardes, mises-a-jour
---

Le site surveille quelques points qui, s'ils lâchent, ne se voient nulle
part avant qu'il soit trop tard : le disque se remplit, les sauvegardes
s'arrêtent, la tâche planifiée ne tourne plus, les e-mails ne partent
plus. Quand l'un d'eux bascule, les administrateurs sont prévenus — par
la cloche, par notification et **par e-mail**.

## Pourquoi l'e-mail compte ici

Si le disque est plein ou si la tâche planifiée s'est arrêtée, personne
ne va visiter le site pour voir une pastille. L'alerte doit sortir du
site pour servir à quelque chose. C'est le seul type de notification du
site dont l'e-mail est activé par défaut.

Une exception : l'alerte « les envois d'e-mail échouent » ne part pas par
e-mail, pour une raison qui se passe d'explication. Vous la trouverez
dans la cloche et dans les points d'attention.

## Vous ne recevrez le message qu'une fois

C'est voulu. Une alerte qui répète « disque à 92 % » à chaque passage de
la tâche planifiée est coupée en trois jours — et le jour où elle aurait
servi, elle n'existe plus.

Le site vous prévient donc **au moment où la situation bascule**, et se
tait ensuite. Il ne recommencera que si le problème a d'abord été
réellement réglé : il faut repasser nettement sous le seuil, pas juste
osciller autour. Redescendre à 84 % puis remonter à 86 % ne compte pas.

Le retour à la normale n'est pas annoncé non plus : un site qui va de
nouveau bien n'a pas besoin de vous le dire.

## Où voir ce qui ne va pas en ce moment

Le message part une fois, mais la page **Points d'attention** montre tout
ce qui est encore vrai, tant que ça l'est. C'est là qu'il faut regarder
si vous avez manqué un message, ou si vous venez d'arriver dans l'unité.

> Une alerte disparaît de cette page parce que le problème a cessé, jamais
> parce que quelqu'un l'a lue. Rien ne se coche et rien ne s'archive.

## Ce qui est surveillé

- **Espace disque** — au-delà d'un certain remplissage, une sauvegarde ou
  un envoi de photo peut être refusé.
- **Sauvegardes** — quand la dernière sauvegarde réussie devient trop
  ancienne, ou qu'il n'y en a jamais eu.
- **Tâche planifiée** — le moteur du site. Sans elle, plus rien ne part.
- **Envoi d'e-mails** — quand les envois échouent à répétition.
- **Mode développement** — s'il a été laissé activé, le site se réinstalle
  à chaque nouveau commit, ce qui n'est pas fait pour le site d'une unité.
- **Connexion sécurisée** — si le site est servi en HTTP, sans chiffrement.
