---
id: phrase-de-passe-distante
title: La phrase de passe de vos sauvegardes distantes
summary: La clé qui ouvre les archives envoyées hors site, pourquoi la recopier ailleurs, et ce que coûte une régénération.
category: Configuration
role_min: admin
discovery: off
question: Où est la phrase de passe de mes sauvegardes distantes ?
question: Pourquoi le site me demande-t-il de confirmer que j'ai noté la phrase ?
question: Que se passe-t-il si je régénère la phrase de passe ?
question: Comment ouvrir une sauvegarde récupérée sur Google Drive ?
paths: /config/maintenance
related: sauvegardes-distantes, restaurer-ailleurs, sauvegarde-hors-site
---

Ce qui part est chiffré. La phrase qui l'ouvre est **générée par le
site** — trente caractères, une fois pour votre unité — et s'affiche sur
la page Maintenance, bouton **Afficher la phrase de passe**.

**Recopiez-la hors du site.** Un carnet, un gestionnaire de mots de
passe, une enveloppe chez le trésorier : n'importe où sauf sur ce
serveur. Le jour où vous en aurez besoin, ce site ne sera probablement
plus accessible — c'est exactement la situation pour laquelle ces
sauvegardes existent. Sans la phrase, les archives de votre Drive ne
s'ouvrent pas, et personne ne peut rien y faire.

Elle reste consultable ici tant que le site fonctionne, et c'est
volontaire : elle doit rester sur le serveur pour que l'envoi de quatre
heures du matin chiffre sans que personne tape quoi que ce soit. La
cacher ne protégerait donc rien.

## « J'ai recopié cette phrase hors du serveur »

Sous la phrase, un bouton. Tant que personne ne l'a pressé, un point
d'attention le rappelle : **les archives partent chaque nuit, chiffrées
avec une clé qui n'existe qu'ici**, c'est-à-dire sur le serveur auquel
elles doivent justement survivre. Rien ne le signalait avant, et un site
pouvait envoyer des sauvegardes pendant un an sans que personne ait
jamais vu la phrase.

**Le site ne peut pas vérifier votre copie**, et ne prétend pas le
faire : il enregistre ce que vous déclarez. C'est aussi pourquoi
*afficher* la phrase ne suffit pas — ouvrir l'écran pour vérifier un
numéro de génération n'est pas recopier trente caractères dans un
gestionnaire de mots de passe, et confondre les deux ferait disparaître
l'avertissement pour exactement la personne à qui il s'adresse.

**La confirmation porte sur la génération en vigueur.** Après une
régénération, le point d'attention revient : la nouvelle phrase est une
nouvelle chose à noter, et celle que vous aviez mise de côté n'ouvre plus
que les archives déjà envoyées.

## Régénérer : ce que cela coûte

**La nouvelle phrase n'ouvre pas ce qui est déjà parti.** Rien ne
rechiffre les archives déjà chez Google : seule l'ancienne phrase les
ouvre encore, et ce site ne la conserve plus une fois régénérée.

C'est pourquoi le numéro de génération figure dans le nom de chaque
fichier envoyé : devant `scoutmagic-2026-09-13-020400-g2.zip`, vous savez
qu'il s'ouvre avec la deuxième phrase et non la première. Ne régénérez
que si vous avez une raison — une phrase qui a traîné dans un e-mail, par
exemple — et notez l'ancienne avant, ou supprimez les archives qu'elle
seule ouvrait.

