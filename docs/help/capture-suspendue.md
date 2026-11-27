---
id: capture-suspendue
title: Quand une capture armée n'agit plus
summary: Pourquoi le site bloque l'envoi quand le bac à sable e-mail ou la téléphonie simulée ne fonctionne plus, et comment en sortir.
category: Configuration
role_min: superadmin
question: Pourquoi les e-mails ne partent plus après une mise à jour ?
question: Que veut dire « capture suspendue » ?
question: Comment rouvrir l'envoi réel des e-mails ?
paths: /config/capture-suspendue
related: courrier-sortant
---

## Ce qui s'est passé

Sur l'installation de référence ou sur une installation locale, le bac à
sable e-mail retient tous les messages au lieu de les envoyer, et la
téléphonie simulée remplace la vraie ligne SOS. Ils ne fonctionnent que si
trois conditions tiennent : le profil d'installation déclaré dans
`config/app.php`, l'adresse du site qui lui correspond, et le module Outils
de test activé.

Si l'une d'elles disparaît alors que l'outil est encore armé — une mise à
jour qui a perdu la ligne `installation_profile`, une adresse du site
changée, le module désactivé — le site **n'envoie plus d'e-mails** plutôt
que de renvoyer le courrier vers les vraies adresses des membres et des
parents. Il le note une fois au journal et l'affiche dans les points
d'attention.

Une seule exception : si le bac à sable était réglé pour laisser passer
les liens de connexion, ils partent encore. C'est ce qui vous permet de
vous connecter pour décider.

## En sortir

Le plus simple est de réparer : rétablir le profil, l'adresse ou le
module. Le blocage se lève alors de lui-même.

Si ces envois doivent vraiment partir, le bouton « Rouvrir l'envoi réel »
le décide. C'est noté au journal avec votre compte, et cela ne vaut que
pour cette panne : si la capture refonctionne puis se perd de nouveau, le
site bloque de nouveau.
